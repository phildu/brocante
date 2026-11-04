<?php
// Recherche d'un administrateur de galerie (mode restreint, voir GA_LIMITED dans
// _bootstrap.php) : la palette ⌘K ne voit que SA galerie. Les pages proposées
// sont celles de GA_PAGES, les fiches sont celles des modules activés pour cette
// galerie (mêmes conditions que _galerie-nav.php). Réutilise les helpers de
// portail/_search.php (normalisation, index des pages, aperçus).

require_once __DIR__ . '/_search.php';

// Fichier de fonctions : il n'est jamais servi directement.
if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === '_search-galerie.php') {
    http_response_code(404);
    exit;
}

/** Bibliothèques galerie chargées une fois (certaines sont déjà incluses par ailleurs). */
function portail_search_gallery_libs(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (['tenant', 'galleries', 'gallery-types', 'gallery-modules', 'gallery-admin', 'gallery-messages', 'gallery-booking', 'gallery-bourse', 'gallery-waitlist', 'gallery-assistant', 'saas'] as $name) {
        $file = PORTAIL_ROOT . '/includes/' . $name . '.php';
        if (is_file($file)) require_once $file;
    }
}

/** Clés jamais indexées : mots de passe hachés, jetons, copies de pièces d'identité. */
function portail_search_secret_key(string $key): bool
{
    return (bool) preg_match('/password|passwd|hash|token|secret|signature|csrf|otp|cni|^sig$/i', $key);
}

/** Texte indexable d'une fiche : scalaires concaténés, clés sensibles écartées. */
function portail_search_flatten($value, int $depth = 0): string
{
    if (is_scalar($value)) return (string) $value;
    if (!is_array($value) || $depth >= 4) return '';
    $out = [];
    $length = 0;
    foreach ($value as $key => $item) {
        if (is_string($key) && portail_search_secret_key($key)) continue;
        $text = trim(portail_search_flatten($item, $depth + 1));
        if ($text === '') continue;
        $out[] = $text;
        $length += mb_strlen($text);
        if ($length > 1200) break;
    }
    return implode(' ', $out);
}

/** La page est-elle ouverte à un administrateur de galerie ? */
function portail_search_gallery_page_ok(string $href): bool
{
    return in_array(basename((string) parse_url($href, PHP_URL_PATH)), GA_PAGES, true);
}

/** Boutons de la navigation galerie, filtrés comme _galerie-nav.php. */
function portail_palette_gallery_items(string $slug): array
{
    portail_search_gallery_libs();
    $gallery = gallery_load($slug);
    if (!$gallery) return [];

    $on = static fn (string $feature): bool => !function_exists('gt_feature') || gt_feature($gallery, $feature);
    $mod = static fn (string $module): bool => function_exists('gm_enabled') && gm_enabled($gallery, $module);
    $members = function_exists('gt_word') ? ucfirst((string) gt_word($gallery, 'members')) : 'Exposants';

    $items = [['group' => 'Galerie', 'label' => 'Présentation et commerces', 'hint' => (string) $gallery['name'], 'href' => '/portail/galeries.php']];
    if ($on('plan')) $items[] = ['group' => 'Galerie', 'label' => 'Plan', 'hint' => '', 'href' => '/portail/galerie-plan.php'];
    if ($on('exposants')) {
        $items[] = ['group' => 'Galerie', 'label' => $members, 'hint' => '', 'href' => '/portail/galerie-exposants.php'];
        if ($mod('attente')) $items[] = ['group' => 'Galerie', 'label' => "File d'attente", 'hint' => '', 'href' => '/portail/galerie-attente.php'];
    }
    if ($on('bourse')) $items[] = ['group' => 'Galerie', 'label' => 'Bourse d’échanges', 'hint' => '', 'href' => '/portail/galerie-bourse.php'];
    if ($on('messages')) $items[] = ['group' => 'Galerie', 'label' => 'Messages', 'hint' => 'Messagerie interne', 'href' => '/portail/galerie-messages.php'];
    if ($on('tableau')) $items[] = ['group' => 'Galerie', 'label' => 'Tableau de bord', 'hint' => '', 'href' => '/portail/galerie-tableau.php'];
    if ($on('prospects')) $items[] = ['group' => 'Galerie', 'label' => 'Prospects', 'hint' => '', 'href' => '/portail/galerie-prospects.php'];
    $items[] = ['group' => 'Galerie', 'label' => 'Assistant', 'hint' => '', 'href' => '/portail/galerie-assistant.php'];
    $items[] = ['group' => 'Galerie', 'label' => 'Mon offre', 'hint' => '', 'href' => '/portail/galerie-offre.php'];
    $items[] = ['group' => 'Galerie', 'label' => 'Mon compte', 'hint' => '', 'href' => '/portail/galerie-compte.php'];
    return $items;
}

/** Fiches de la galerie : galerie, commerces, comptes, prospects, places, bourse, plan, sondage. */
function portail_search_gallery_entries(string $slug): array
{
    portail_search_gallery_libs();
    $gallery = gallery_load($slug);
    if (!$gallery) return [];

    $items = [];
    $add = static function (string $group, string $label, string $hint, string $href, string $extra = '') use (&$items): void {
        $label = trim($label);
        if ($label === '' || $href === '' || !portail_search_gallery_page_ok($href)) return;
        $items[] = [
            'group' => $group,
            'label' => $label,
            'hint' => $hint,
            'href' => $href,
            '_search' => portail_search_norm($group . ' ' . $label . ' ' . $hint . ' ' . $extra),
        ];
    };
    $on = static fn (string $feature): bool => !function_exists('gt_feature') || gt_feature($gallery, $feature);
    $mod = static fn (string $module): bool => function_exists('gm_enabled') && gm_enabled($gallery, $module);
    $members = function_exists('gt_word') ? ucfirst((string) gt_word($gallery, 'members')) : 'Exposants';
    $day = static fn ($timestamp): string => ($timestamp = (int) $timestamp) > 0 ? date('d/m/Y', $timestamp) : '';

    $tagline = trim((string) $gallery['tagline']);
    $add(
        'Galerie',
        (string) $gallery['name'],
        $tagline !== '' ? $tagline : 'Galerie · ' . $slug,
        '/portail/galeries.php',
        $slug . ' ' . (string) $gallery['type'] . ' ' . (string) $gallery['domain'] . ' ' . (string) $gallery['description']
    );

    if ($on('exposants')) {
        foreach ((array) $gallery['members'] as $member) {
            if (($member['type'] ?? '') === 'local') {
                try {
                    $shop = tenant_load((string) ($member['slug'] ?? ''));
                } catch (Throwable $error) {
                    $shop = [];   // commerce supprimé : le rattachement reste trouvable par son slug
                }
                $add(
                    $members,
                    (string) ($shop['name'] ?? $member['slug']),
                    $members . ' · commerce rattaché' . (!empty($member['featured']) ? ' · mis en avant' : ''),
                    '/portail/galerie-exposants.php',
                    (string) ($member['slug'] ?? '') . ' ' . (string) ($shop['city'] ?? '') . ' ' . (string) ($shop['address'] ?? '') . ' ' . (string) ($shop['description'] ?? '')
                );
            } elseif (($member['type'] ?? '') === 'remote') {
                $add(
                    $members,
                    (string) ($member['name'] ?? $member['url']),
                    $members . ' · page extérieure' . (!empty($member['featured']) ? ' · mis en avant' : ''),
                    '/portail/galerie-exposants.php',
                    (string) ($member['url'] ?? '')
                );
            }
        }
    }

    foreach ((array) $gallery['admins'] as $admin) {
        // gallery_admins_normalize() fournit aussi un hash : il n'est jamais indexé.
        $last = $day($admin['last_login'] ?? 0);
        $add(
            'Comptes',
            (string) ($admin['name'] !== '' ? $admin['name'] : $admin['email']),
            'Compte administrateur' . ($last !== '' ? ' · dernière connexion le ' . $last : ' · jamais connecté'),
            '/portail/galerie-compte.php',
            (string) $admin['email']
        );
    }

    if ($on('prospects') && function_exists('ga_prospects')) {
        foreach (ga_prospects($slug) as $prospect) {
            $status = defined('GA_PROSPECT_STATUS') ? (string) (GA_PROSPECT_STATUS[$prospect['status'] ?? ''] ?? '') : '';
            $hint = 'Prospect' . ($status !== '' ? ' · ' . $status : '') . ((string) ($prospect['email'] ?? '') !== '' ? ' · ' . $prospect['email'] : '');
            $add(
                'Prospects',
                (string) ($prospect['name'] ?? ''),
                $hint,
                '/portail/galerie-prospects.php',
                (string) ($prospect['phone'] ?? '') . ' ' . (string) ($prospect['note'] ?? '') . ' ' . portail_search_flatten($prospect['custom_fields'] ?? [])
            );
        }

        foreach (ga_signups($slug) as $signup) {
            $request = (array) ($signup['req'] ?? []);
            $name = trim((string) ($request['shop_name'] ?? '')) ?: trim((string) ($request['first_name'] ?? '') . ' ' . (string) ($request['last_name'] ?? ''));
            if ($name === '') $name = (string) ($request['email'] ?? '');
            $add(
                'Inscriptions',
                $name,
                'Inscription en cours · ' . (string) ($request['email'] ?? '') . ((string) ($request['status'] ?? '') !== '' ? ' · ' . $request['status'] : ''),
                '/portail/galerie-prospects.php',
                (string) ($request['city'] ?? '') . ' ' . (string) ($request['phone'] ?? '') . ' ' . (string) ($request['slug'] ?? '')
            );
        }

        foreach (ga_subs($slug) as $sub) {
            $confirmed = (int) ($sub['confirmed'] ?? 0) > 0;
            $add(
                'Abonnés',
                (string) ($sub['email'] ?? ''),
                'Visiteur abonné aux nouveautés · ' . ($confirmed ? 'confirmé le ' . $day($sub['confirmed']) : 'en attente de confirmation'),
                '/portail/galerie-prospects.php'
            );
        }
    }

    if ($on('exposants')) {
        foreach (function_exists('gb_rows') ? gb_rows($gallery) : [] as $row) {
            $status = (string) ($row['status'] ?? '');
            $client = (array) ($row['client'] ?? []);
            $label = trim((string) ($client['firstname'] ?? '') . ' ' . (string) ($client['lastname'] ?? ''));
            if ($label === '') $label = trim((string) ($client['org_name'] ?? ''));
            if ($label === '') $label = (string) ($row['name'] ?? $row['email'] ?? '');
            $add(
                'Réservations',
                $label,
                'Place ' . (string) ($row['slot'] ?? '') . ' · ' . ($status === 'taken' ? 'attribuée' : ($status === 'refused' ? 'refusée' : 'en attente')) . ' · ' . (string) ($row['email'] ?? ''),
                '/portail/galerie-exposants.php',
                (string) ($row['phone'] ?? '') . ' ' . (string) ($row['kind'] ?? '') . ' ' . portail_search_flatten($row['client'] ?? [])
            );
        }
    }

    if ($on('exposants') && $mod('attente') && function_exists('gwl_waiting')) {
        $statuses = defined('GWL_STATUS_LABELS') ? GWL_STATUS_LABELS : [];
        foreach (gwl_waiting($gallery) as $entry) {
            $add(
                "File d'attente",
                (string) ($entry['name'] ?? $entry['email'] ?? ''),
                'En attente d\'une place · ' . (int) ($entry['places'] ?? 1) . ' place(s)' . ((string) ($entry['zone'] ?? '') !== '' ? ' · zone ' . $entry['zone'] : '') . ' · ' . (string) ($statuses[$entry['status'] ?? ''] ?? ''),
                '/portail/galerie-attente.php',
                (string) ($entry['email'] ?? '') . ' ' . (string) ($entry['phone'] ?? '') . ' ' . (string) ($entry['message'] ?? '') . ' ' . (string) ($entry['kind'] ?? '')
            );
        }
        $offers = defined('GWL_OFFER_LABELS') ? GWL_OFFER_LABELS : [];
        foreach (gwl_offers($gallery) as $offer) {
            $holder = (array) ($offer['holder'] ?? []);
            $name = trim((string) ($holder['name'] ?? ''));
            if ($name === '') $name = (string) ($offer['email'] ?? '');
            $add(
                'Places voisines',
                $name,
                'Place ' . gwl_slot_name_by_id($gallery, (string) ($offer['slot'] ?? '')) . ' · ' . (string) ($offers[$offer['status'] ?? ''] ?? $offer['status'] ?? ''),
                '/portail/galerie-attente.php',
                (string) ($offer['email'] ?? '') . ' ' . (string) ($holder['email'] ?? '')
            );
        }
    }

    if ($on('bourse')) {
        foreach (function_exists('gbo_list') ? gbo_list($slug, '', '', true) : [] as $item) {
            $price = function_exists('gbo_money') ? gbo_money($item['price_cents'] ?? null) : '';
            $add(
                'Bourse d’échanges',
                (string) ($item['title'] ?? ''),
                (function_exists('gbo_kind_label') ? gbo_kind_label((string) ($item['kind'] ?? '')) : 'Annonce')
                    . ' · ' . (function_exists('gbo_status_label') ? gbo_status_label((string) ($item['status'] ?? '')) : (string) ($item['status'] ?? ''))
                    . ($price !== '' ? ' · ' . $price : '')
                    . ((string) ($item['contact_name'] ?? '') !== '' ? ' · ' . $item['contact_name'] : ''),
                '/portail/galerie-bourse.php',
                (string) ($item['description'] ?? '') . ' ' . (string) ($item['category'] ?? '') . ' ' . (string) ($item['condition'] ?? '')
                    . ' ' . (string) ($item['email'] ?? '') . ' ' . (string) ($item['phone'] ?? '') . ' ' . (string) ($item['location'] ?? '') . ' ' . (string) ($item['notes'] ?? '')
            );
        }
    }

    if ($on('plan')) {
        $zones = defined('GALLERY_ZONE_TYPES') ? GALLERY_ZONE_TYPES : [];
        foreach ((array) ($gallery['map']['items'] ?? []) as $zone) {
            $label = trim((string) ($zone['label'] ?? ''));
            $numero = trim((string) ($zone['num'] ?? ''));
            if ($label === '' && $numero === '') continue;   // tracé sans nom (bâtiment, repère) : rien à chercher
            if ($label === '') $label = $numero;
            $add(
                'Plan',
                $label,
                'Zone du plan · ' . (string) ($zones[$zone['ztype'] ?? ''] ?? $zone['ztype'] ?? ''),
                '/portail/galerie-plan.php',
                (string) ($zone['note'] ?? '') . ' ' . (string) ($zone['shop'] ?? '') . ' ' . (string) ($zone['service'] ?? '') . ' ' . (string) ($zone['surface'] ?? '')
            );
        }
    }

    if (function_exists('gass_survey_responses')) {
        foreach (gass_survey_responses($slug) as $response) {
            $email = (string) ($response['email'] ?? '');
            $add(
                'Sondage',
                $email !== '' ? $email : 'Réponse du ' . $day($response['created'] ?? 0),
                'Réponse au sondage visiteurs · ' . ((int) ($response['consent'] ?? 0) ? 'accepte d\'être recontacté' : 'sans contact'),
                '/portail/galerie-assistant.php',
                portail_search_flatten($response['answers'] ?? [])
            );
        }
    }

    return $items;
}

/** Résultats de la recherche d'une galerie, les plus pertinents d'abord. */
function portail_search_gallery_query(string $slug, string $query, int $limit = 30): array
{
    $needle = portail_search_norm($query);
    $words = array_values(array_filter(explode(' ', $needle), static fn (string $word): bool => mb_strlen($word) >= 2));
    if ($needle === '' || !$words) return [];

    $results = [];
    foreach (portail_search_gallery_entries($slug) as $item) {
        $results[] = $item + ['score' => 0];
    }

    // Texte des pages que cet administrateur peut ouvrir. Les boutons de la
    // navigation (portail_palette_gallery_items) sont déjà dans la palette :
    // ils restent cherchables côté navigateur, inutile de les renvoyer.
    foreach (portail_search_page_index()['pages'] as $page) {
        $href = (string) ($page['href'] ?? '');
        if ($href === '' || !portail_search_gallery_page_ok($href)) continue;
        $results[] = [
            'group' => 'Pages',
            'label' => (string) ($page['label'] ?? ''),
            'hint' => '',
            'href' => $href,
            '_search' => (string) ($page['terms'] ?? ''),
            '_page' => $page,
            'score' => 0,
        ];
    }

    $out = [];
    foreach ($results as $result) {
        $haystack = (string) ($result['_search'] ?? '');
        $matched = true;
        foreach ($words as $word) {
            if (!str_contains($haystack, $word)) {
                $matched = false;
                break;
            }
        }
        if (!$matched) continue;

        $label = portail_search_norm((string) $result['label']);
        $score = (int) $result['score'];
        if ($label === $needle) $score += 160;
        elseif (str_starts_with($label, $needle)) $score += 100;
        elseif (str_contains($label, $needle)) $score += 60;
        $score += 25;

        if (isset($result['_page'])) {
            $snippet = portail_search_snippet($result['_page'], $words, $needle);
            if ($snippet !== '') $result['hint'] = $snippet;
            $score += min(40, substr_count($haystack, ' ' . $words[0]) * 4);
            unset($result['_page']);
        }
        if ($result['hint'] === '') $result['hint'] = 'Correspondance dans la page';

        $result['score'] = $score;
        $out[] = $result;
    }

    usort($out, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcasecmp($a['label'], $b['label']));

    $seen = [];
    $items = [];
    foreach ($out as $result) {
        $key = $result['href'] . '|' . $result['label'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        unset($result['score'], $result['_search']);
        $items[] = $result;
        if (count($items) >= $limit) break;
    }
    return $items;
}
