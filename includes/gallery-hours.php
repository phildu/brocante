<?php

// Horaires d'ouverture, fermetures exceptionnelles et alerte aux visiteurs d'une galerie (fonctionnalité « horaires » des types de galerie,
// réglable dans le portail → Types de galerie). Le calcul (état du jour, résumé, prochaine ouverture, bandeau) est celui des boutiques
// (includes/shop-hours.php) : on y substitue les données de la galerie le temps d'un calcul (shop_hours_source()).
//
// Données : data/galeries/<id>.hours.json (jamais envoyé par le déploiement) :
//   ['schedule' => grille hebdomadaire|null, 'closures' => [{from,to,type,label}…], 'notice' => message libre, 'alerts' => [clé de période => envoi]].
//
// Alerte de fermeture : l'organisateur choisit le public — abonnés confirmés aux nouveautés, membres du programme de fidélité, exposants ayant
// une place attribuée — relit le message et confirme ; jamais automatique. Un e-mail par adresse (dédoublonnée), avec un lien de désinscription
// ajouté d'office pour les abonnés et les membres, et le lien de l'espace exposant pour les exposants (message de service lié à leur place).

require_once __DIR__ . '/gallery-admin.php';
require_once __DIR__ . '/gallery-types.php';
require_once __DIR__ . '/gallery-booking.php';
require_once __DIR__ . '/gallery-loyalty.php';
require_once __DIR__ . '/closure-text.php';

const GH_MAX_RECIPIENTS = 500;   // comme les nouveautés aux abonnés : un envoi ne dépasse pas 500 destinataires
const GH_GROUPS = [
    'subs' => 'Abonnés confirmés aux nouveautés',
    'members' => 'Membres du programme de fidélité',
    'exposants' => 'Exposants ayant une place attribuée',
];

/** La galerie propose-t-elle les horaires ? (fonctionnalité du type de galerie, activée par défaut). */
function gh_enabled(array $gallery): bool
{
    return gt_feature($gallery, 'horaires');
}

/** Données enregistrées, normalisées : ['schedule' => ?array, 'closures' => array, 'notice' => string, 'alerts' => array]. */
function gh_read(string $slug): array
{
    $d = ga_read($slug, 'hours', []);
    $schedule = $d['schedule'] ?? null;
    return [
        'schedule' => is_array($schedule) && $schedule ? shop_hours_normalize_schedule($schedule) : null,
        'closures' => shop_hours_normalize_closures((array) ($d['closures'] ?? [])),
        'notice' => mb_substr(trim((string) ($d['notice'] ?? '')), 0, 300),
        'alerts' => is_array($d['alerts'] ?? null) ? $d['alerts'] : [],
    ];
}

/** Enregistre la grille (null = aucun horaire structuré), les fermetures et le message libre ; le journal des alertes est conservé. */
function gh_save(string $slug, ?array $schedule, array $closures, string $notice): bool
{
    return ga_write($slug, 'hours', [
        'schedule' => $schedule === null ? null : shop_hours_normalize_schedule($schedule),
        'closures' => shop_hours_normalize_closures($closures),
        'notice' => mb_substr(trim($notice), 0, 300),
        'alerts' => gh_read($slug)['alerts'],
    ]);
}

/** Exécute $fn avec les horaires de CETTE galerie à la place de ceux de la boutique ; rend la main ensuite, même en cas d'erreur. */
function gh_with(array $gallery, callable $fn): mixed
{
    $d = gh_read((string) $gallery['slug']);
    $previous = shop_hours_source();
    shop_hours_source(['schedule' => $d['schedule'], 'closures' => $d['closures'], 'notice' => $d['notice']]);
    try {
        return $fn($d);
    } finally {
        if ($previous === null) shop_hours_source(null, true);
        else shop_hours_source($previous);
    }
}

/** Grille proposée dans le formulaire : celle enregistrée, ou lundi-samedi 10 h-19 h tant que rien n'existe. */
function gh_editor_schedule(array $gallery): array
{
    return gh_with($gallery, static fn () => shop_hours_editor_schedule());
}

/** Fermetures à venir ou en cours, les plus proches d'abord. */
function gh_upcoming_closures(array $gallery, int $limit = 5): array
{
    $now = shop_hours_now();
    $out = array_values(array_filter(gh_read((string) $gallery['slug'])['closures'], static fn ($c) => closure_alert_is_upcoming($c, $now)));
    return array_slice($out, 0, $limit);
}

/**
 * Bloc public de la page de la galerie : bandeau d'annonce (fermeture en cours ou à venir, message libre) et horaires de la semaine.
 * '' quand rien n'est configuré ou que le type de galerie n'a pas la fonctionnalité.
 */
function gh_public_html(array $gallery): string
{
    if (!gh_enabled($gallery)) return '';
    $h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    return (string) gh_with($gallery, static function (array $d) use ($gallery, $h): string {
        $summary = shop_hours_summary_text();
        $notice = shop_hours_announcement();
        if ($summary === '' && !$notice) return '';
        $out = '';
        if ($notice) {
            $warn = $notice['kind'] === 'warning';
            $out .= '<div role="status" class="gh-notice" style="margin:12px 0;padding:12px 14px;border:1px solid ' . ($warn ? '#d9a3a0' : '#e2d6a8') . ';border-radius:10px;background:'
                . ($warn ? '#fdf0ef' : '#fbf6e4') . ';color:' . ($warn ? '#6d1f1b' : '#5b4a14') . ';">' . ($warn ? '<strong>Fermeture&nbsp;: </strong>' : '') . $h($notice['text']) . '</div>';
        }
        if ($summary !== '') {
            $status = shop_hours_status();
            $out .= '<p class="gh-hours" style="margin:8px 0;color:inherit;"><strong>Horaires</strong> · ' . $h($summary)
                . ($status['label'] !== '' && !$status['closure_active'] ? ' <span style="opacity:.75;">(' . $h($status['label']) . ')</span>' : '') . '</p>';   // une fermeture en cours est déjà dans le bandeau
        }
        return $out;
    });
}

// ── Alerte de fermeture ─────────────────────────────────────────────────────

/** Fermeture enregistrée par sa clé (dates Du/Au), ou null. */
function gh_closure_find(string $slug, string $key): ?array
{
    foreach (gh_read($slug)['closures'] as $c) {
        if (closure_alert_key($c) === $key) return $c;
    }
    return null;
}

/** Périodes à venir, absentes de $knownKeys (clés d'avant l'enregistrement) et jamais annoncées. */
function gh_new_pending(string $slug, array $knownKeys): array
{
    $d = gh_read($slug);
    $now = shop_hours_now();
    $out = [];
    foreach ($d['closures'] as $c) {
        $key = closure_alert_key($c);
        if (in_array($key, $knownKeys, true) || isset($d['alerts'][$key]) || !closure_alert_is_upcoming($c, $now)) continue;
        $out[$key] = $c;
    }
    return $out;
}

/**
 * Message proposé (modifiable) : celui des boutiques sans son pied de page, que l'envoi ajoute lui-même (lien de désinscription ou espace exposant).
 * La réouverture est calculée avec les horaires de la galerie.
 * @return array{subject: string, body: string}
 */
function gh_alert_message(array $gallery, array $closure): array
{
    $m = gh_with($gallery, static fn () => closure_alert_message($closure));
    $body = preg_replace('/\n\n—\nPour gérer vos notifications.*$/su', '', $m['body']);
    return ['subject' => str_replace('{site}', (string) $gallery['name'], $m['subject']), 'body' => trim(str_replace('{site}', (string) $gallery['name'], (string) $body))];
}

/**
 * Destinataires des groupes choisis, une seule fois par adresse (abonnés d'abord : ils ont un lien de désinscription, puis membres, puis exposants).
 * @return array<string, array{name: string, group: string, optout: string, link: string}>
 */
function gh_recipients(array $gallery, array $groups): array
{
    $slug = (string) $gallery['slug'];
    $valid = static fn ($e): bool => is_string($e) && filter_var($e, FILTER_VALIDATE_EMAIL) !== false && strlen($e) <= 160;
    $out = [];
    if (in_array('subs', $groups, true)) {
        foreach (ga_subs($slug) as $s) {
            $email = mb_strtolower(trim((string) ($s['email'] ?? '')));
            if (empty($s['confirmed']) || !$valid($email) || isset($out[$email])) continue;
            $out[$email] = ['name' => '', 'group' => 'subs', 'optout' => ga_optout_url($slug, 'sub', (string) $s['id']), 'link' => ''];
        }
    }
    if (in_array('members', $groups, true)) {
        foreach (gl_read($slug)['members'] as $email => $m) {
            $email = mb_strtolower(trim((string) $email));
            if (!empty($m['alerts_off']) || !$valid($email) || isset($out[$email]) || (string) ($m['token'] ?? '') === '') continue;
            $out[$email] = ['name' => mb_substr(trim((string) ($m['name'] ?? '')), 0, 60), 'group' => 'members', 'optout' => ga_optout_url($slug, 'member', (string) $m['token']), 'link' => ''];
        }
    }
    if (in_array('exposants', $groups, true)) {
        foreach (gb_rows($gallery) as $r) {
            $email = mb_strtolower(trim((string) ($r['email'] ?? '')));
            if (($r['status'] ?? '') !== 'taken' || !$valid($email) || isset($out[$email])) continue;
            $out[$email] = ['name' => mb_substr(trim((string) ($r['name'] ?? '')), 0, 80), 'group' => 'exposants', 'optout' => '', 'link' => gb_app_url($gallery, $r)];
        }
    }
    return $out;
}

/** Nombre de personnes par groupe (avant dédoublonnage) et nombre d'adresses distinctes pour l'ensemble. */
function gh_audience(array $gallery): array
{
    $counts = [];
    foreach (GH_GROUPS as $key => $label) $counts[$key] = ['label' => $label, 'count' => count(gh_recipients($gallery, [$key]))];
    return ['groups' => $counts, 'unique' => count(gh_recipients($gallery, array_keys(GH_GROUPS)))];
}

/** Texte d'un e-mail pour un destinataire : « Bonjour, » sans nom, puis le pied de page propre à son groupe. */
function gh_render(array $gallery, string $body, array $recipient): string
{
    $name = trim($recipient['name']);
    $text = $name === '' ? str_replace('Bonjour {prenom},', 'Bonjour,', $body) : $body;
    $text = strtr($text, ['{prenom}' => $name, '{nom}' => $name, '{site}' => (string) $gallery['name']]);
    $footer = "\n\n—\n« " . $gallery['name'] . ' » : ' . gallery_public_url($gallery) . "\n";
    if ($recipient['optout'] !== '') $footer .= 'Se désinscrire de ces alertes : ' . $recipient['optout'] . "\n";
    elseif ($recipient['link'] !== '') $footer .= 'Votre espace exposant : ' . $recipient['link'] . "\n";
    return rtrim($text) . $footer;
}

/**
 * Envoie l'alerte d'une période de fermeture au public choisi (500 destinataires au plus). Refuse un second envoi sans $force.
 * @return array{error: ?string, sent: int, failed: int, recipients: int, skipped: int}
 */
function gh_alert_send(array $gallery, string $key, array $groups, string $subject, string $body, string $actor = '', bool $force = false): array
{
    $res = ['error' => null, 'sent' => 0, 'failed' => 0, 'recipients' => 0, 'skipped' => 0];
    $slug = (string) $gallery['slug'];
    if (!gh_enabled($gallery)) return ['error' => 'Les horaires ne sont pas proposés pour ce type de galerie.'] + $res;
    $closure = gh_closure_find($slug, $key);
    if (!$closure) return ['error' => 'Fermeture introuvable : enregistrez-la d’abord dans les horaires.'] + $res;
    if (!closure_alert_is_upcoming($closure)) return ['error' => 'Cette fermeture est terminée : il n’y a plus rien à annoncer.'] + $res;
    $groups = array_values(array_intersect($groups, array_keys(GH_GROUPS)));
    if (!$groups) return ['error' => 'Choisissez au moins un public à prévenir.'] + $res;
    $subject = mb_substr(trim(preg_replace('/[\r\n]+/', ' ', $subject)), 0, 150);
    if ($subject === '' || mb_strlen(trim($body)) < 10) return ['error' => 'Rédigez un objet et un message.'] + $res;
    $prev = gh_read($slug)['alerts'][$key] ?? null;
    if ($prev && !$force) return ['error' => 'Les visiteurs ont déjà été prévenus le ' . date('d/m/Y', (int) $prev['at']) . ' pour cette fermeture.'] + $res;

    $recipients = gh_recipients($gallery, $groups);
    if (!$recipients) return ['error' => 'Personne à prévenir : aucun abonné confirmé, membre ou exposant avec une adresse valide dans ce public.'] + $res;
    $res['recipients'] = count($recipients);
    $res['skipped'] = max(0, count($recipients) - GH_MAX_RECIPIENTS);
    foreach (array_slice($recipients, 0, GH_MAX_RECIPIENTS, true) as $email => $r) {
        if (ga_mail($email, $subject, gh_render($gallery, $body, $r))) $res['sent']++;
        else $res['failed']++;
    }
    if ($res['sent'] === 0) return ['error' => 'Aucun message n’a pu partir : vérifiez la messagerie du serveur.'] + $res;

    $d = gh_read($slug);
    $d['alerts'][$key] = ['at' => time(), 'by' => mb_substr($actor, 0, 120), 'sent' => $res['sent'], 'failed' => $res['failed'], 'groups' => $groups];
    if (count($d['alerts']) > 60) {
        uasort($d['alerts'], static fn ($a, $b) => (int) ($b['at'] ?? 0) <=> (int) ($a['at'] ?? 0));
        $d['alerts'] = array_slice($d['alerts'], 0, 60, true);
    }
    ga_write($slug, 'hours', ['schedule' => $d['schedule'], 'closures' => $d['closures'], 'notice' => $d['notice'], 'alerts' => $d['alerts']]);
    gb_audit($slug, 'closure_alert', ['by' => $actor, 'sent' => $res['sent'], 'failed' => $res['failed'], 'from' => $closure['from'], 'to' => $closure['to']]);
    return $res;
}
