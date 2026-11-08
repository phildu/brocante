<?php

// Extension « Force de vente » : un carnet de prospects par propriétaire (portail, galerie ; boutique à venir).
//
// Le moteur (portail/vente/_bootstrap.php, fonctions fv_*) est le même partout. Ce fichier dit DANS QUEL carnet il travaille :
//   - portail : data/vente/vente.db (équipe commerciale de la plateforme, inchangé) ;
//   - galerie : data/vente/galeries/<identifiant>.db, un carnet séparé par galerie, jamais partagé avec une autre.
// Le carnet d'une galerie est servi à /galerie/<identifiant>/vente/<page>.php (réécrit vers portail/vente/<page>.php?g=<identifiant>, voir
// .htaccess, router.php, LocalValetDriver.php). Il est activé comme les autres extensions de galerie (module « vente »,
// includes/gallery-modules.php).
//
// Reprise des prospects d'avant l'extension : data/galeries/<identifiant>.prospects.json est copié une fois dans le carnet (sauvegarde
// du fichier à côté, jamais supprimé ni modifié). Ensuite le carnet fait foi : ga_prospects() le relit, et un lien de désinscription déjà
// envoyé dans un e-mail continue de fonctionner (colonne legacy_id).

require_once __DIR__ . '/galleries.php';
require_once __DIR__ . '/gallery-admin.php';
require_once __DIR__ . '/gallery-modules.php';

/** Pages du carnet proposées à une galerie (les autres pages de portail/vente/ sont celles de l'équipe commerciale du portail). */
const VS_GALLERY_PAGES = ['prospects.php', 'prospect-action.php', 'prospects-import.php', 'tournees.php', 'tournee-geo.php', 'activite-action.php'];

/** Dossier des carnets. VENTE_DATA_DIR : dossier jetable pour les tests. */
function vs_data_dir(): string
{
    return getenv('VENTE_DATA_DIR') ?: dirname(__DIR__) . '/data/vente';
}

function vs_gallery_db_file(string $slug): string
{
    return gallery_slug_valid($slug) ? vs_data_dir() . '/galeries/' . $slug . '.db' : '';
}

/** Adresse d'une page du carnet d'une galerie. */
function vs_gallery_url(string $slug, string $page = 'prospects.php'): string
{
    return '/galerie/' . rawurlencode($slug) . '/vente/' . $page;
}

/** Statuts d'un prospect de galerie (ancien format -> carnet) et retour. */
const VS_STATUS_IN = ['nouveau' => 'a_contacter', 'contacte' => 'contacte', 'relance' => 'contacte', 'inscrit' => 'boutique_creee', 'refus' => 'perdu'];
const VS_STATUS_OUT = ['a_contacter' => 'nouveau', 'contacte' => 'contacte', 'interesse' => 'relance', 'rdv' => 'relance', 'boutique_creee' => 'inscrit', 'perdu' => 'refus'];

/** Début des messages de recrutement dans le journal d'activités (sert à compter les relances). */
const VS_SENT_NOTE = 'Message de recrutement';

// ── Contexte de la requête ──────────────────────────────────────────────────

/**
 * Carnet visé par la requête : ['type' => 'portail'] ou ['type' => 'galerie', 'slug', 'gallery'].
 * Une galerie est désignée par ?g=<identifiant> (posé par la réécriture /galerie/<identifiant>/vente/…). Identifiant inconnu : 404.
 */
function vs_scope(): array
{
    static $scope = null;
    if ($scope !== null) return $scope;
    $slug = strtolower((string) ($_GET['g'] ?? ''));
    if ($slug === '') return $scope = ['type' => 'portail', 'slug' => '', 'gallery' => null];
    $gallery = gallery_slug_valid($slug) ? gallery_load($slug) : null;
    if (!$gallery) {
        http_response_code(404);
        exit('Galerie introuvable.');
    }
    return $scope = ['type' => 'galerie', 'slug' => $slug, 'gallery' => $gallery];
}

function vs_is_gallery(): bool
{
    return vs_scope()['type'] === 'galerie';
}

/** L'extension est-elle active pour cette galerie ? */
function vs_gallery_enabled(array $gallery): bool
{
    return gm_enabled($gallery, 'vente');
}

/** L'utilisateur peut-il administrer cette galerie ? ($operator : exploitant connecté au portail, ou poste local.) */
function vs_gallery_access(string $slug, bool $operator): bool
{
    if ($operator) return true;
    $admin = ga_current();
    return $admin !== null && in_array($slug, $admin['slugs'], true);
}

// ── Base du carnet d'une galerie ────────────────────────────────────────────

/** Base d'une galerie déjà créée (null sinon) : lecture par les pages de la galerie, sans créer de carnet. */
function vs_gallery_pdo_existing(string $slug): ?PDO
{
    $file = vs_gallery_db_file($slug);
    if ($file === '' || !is_file($file)) return null;
    try {
        $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    } catch (Throwable $e) {
        error_log('vente carnet ' . $slug . ' : ' . $e->getMessage());
        return null;
    }
}

/** La reprise du fichier JSON est-elle faite ? Le carnet fait alors foi. */
function vs_gallery_migrated(string $slug): bool
{
    $pdo = vs_gallery_pdo_existing($slug);
    if (!$pdo) return false;
    try {
        return $pdo->query("SELECT 1 FROM settings WHERE name = 'migration_json'")->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

/** Nombre de prospects de l'ancienne liste (fichier JSON) : une galerie qui en a déjà garde l'accès à l'extension. */
function vs_gallery_legacy_count(string $slug): int
{
    return count(ga_read($slug, 'prospects', []));
}

/**
 * Copie les prospects du fichier JSON dans le carnet (une seule fois, rejouable sans doublon grâce à legacy_id). Le fichier est sauvegardé à
 * côté (.bak-AAAAMMJJ-HHMMSS) et n'est jamais modifié. -> [prospects copiés, messages repris].
 */
function vs_gallery_migrate(PDO $pdo, string $slug): array
{
    if ($pdo->query("SELECT 1 FROM settings WHERE name = 'migration_json'")->fetchColumn() !== false) return [0, 0];
    $list = ga_read($slug, 'prospects', []);
    $file = ga_file($slug, 'prospects');
    if ($list && is_file($file)) @copy($file, $file . '.bak-' . date('Ymd-His'));
    $added = 0;
    $sent = 0;
    $pdo->beginTransaction();
    try {
        $have = $pdo->prepare('SELECT 1 FROM prospects WHERE legacy_id = ? LIMIT 1');
        $ins = $pdo->prepare('INSERT INTO prospects (name, business, phone, email, source, status, notes, custom_fields, optout, legacy_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $act = $pdo->prepare("INSERT INTO activities (prospect_id, member_id, kind, outcome, notes, points, duration_min, occurred_at) VALUES (?, 0, 'email', 'envoye', ?, 0, 0, ?)");
        foreach ($list as $p) {
            $legacy = (string) ($p['id'] ?? '');
            if ($legacy === '' || trim((string) ($p['name'] ?? '')) === '') continue;
            $have->execute([$legacy]);
            if ($have->fetchColumn() !== false) continue;
            $created = date('Y-m-d H:i:s', (int) ($p['created'] ?? 0) ?: time());
            $custom = ga_prospect_custom_fields_normalize((array) ($p['custom_fields'] ?? []));
            $ins->execute([
                '', mb_substr(trim((string) $p['name']), 0, 160), mb_substr((string) ($p['phone'] ?? ''), 0, 40), mb_substr(mb_strtolower((string) ($p['email'] ?? '')), 0, 190),
                'autre', VS_STATUS_IN[(string) ($p['status'] ?? '')] ?? 'a_contacter', mb_substr((string) ($p['note'] ?? ''), 0, 4000),
                json_encode($custom, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), !empty($p['optout']) ? 1 : 0, $legacy, $created, $created,
            ]);
            $id = (int) $pdo->lastInsertId();
            $added++;
            foreach ((array) ($p['contacts'] ?? []) as $at) {
                $act->execute([$id, VS_SENT_NOTE . ' (repris de l\'ancienne liste)', date('Y-m-d H:i:s', (int) $at ?: time())]);
                $sent++;
            }
        }
        $pdo->prepare("INSERT INTO settings (name, value) VALUES ('migration_json', ?)")->execute([json_encode(['at' => date('c'), 'prospects' => $added, 'messages' => $sent])]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return [$added, $sent];
}

// ── Lecture par le reste de l'administration de la galerie ──────────────────

/** Prospects du carnet au format de l'ancienne liste (assistant, tableau de bord, recherche ⌘K). [] si le carnet n'est pas encore créé. */
function vs_gallery_prospects_legacy_shape(string $slug): array
{
    $pdo = vs_gallery_pdo_existing($slug);
    if (!$pdo) return [];
    $sent = [];
    foreach ($pdo->query("SELECT prospect_id, occurred_at FROM activities WHERE kind = 'email' AND notes LIKE '" . VS_SENT_NOTE . "%' ORDER BY occurred_at")->fetchAll() as $a) {
        $sent[(int) $a['prospect_id']][] = (int) strtotime((string) $a['occurred_at']);
    }
    $out = [];
    foreach ($pdo->query('SELECT * FROM prospects ORDER BY id')->fetchAll() as $r) {
        $custom = json_decode((string) $r['custom_fields'], true);
        $out[] = [
            'id' => (string) ($r['legacy_id'] !== '' ? $r['legacy_id'] : $r['id']),
            'name' => (string) ($r['business'] !== '' ? $r['business'] : $r['name']),
            'email' => (string) $r['email'], 'phone' => (string) $r['phone'], 'note' => (string) $r['notes'],
            'custom_fields' => is_array($custom) ? $custom : [],
            'status' => VS_STATUS_OUT[(string) $r['status']] ?? 'nouveau',
            'created' => (int) strtotime((string) $r['created_at']),
            'contacts' => $sent[(int) $r['id']] ?? [],
            'optout' => !empty($r['optout']),
        ];
    }
    return $out;
}

/** Désinscription d'un prospect (lien d'un e-mail) dans le carnet. -> true si trouvé. $id : identifiant du lien (ancien identifiant ou numéro). */
function vs_gallery_optout(string $slug, string $id): bool
{
    $pdo = vs_gallery_pdo_existing($slug);
    if (!$pdo) return false;
    $st = $pdo->prepare('UPDATE prospects SET optout = 1, updated_at = ? WHERE legacy_id = ? OR CAST(id AS TEXT) = ?');
    $st->execute([date('Y-m-d H:i:s'), $id, $id]);
    return $st->rowCount() > 0;
}

// ── Messages de recrutement ─────────────────────────────────────────────────

/**
 * Envoie le message de recrutement à un prospect du carnet. -> message d'erreur ou null. Pas plus de 4 messages par prospect (3 relances), pas
 * deux en moins de 3 jours, aucun après désinscription ; {desinscription} est obligatoire.
 */
function vs_gallery_send(PDO $pdo, array $gallery, int $prospectId, string $subject, string $body): ?string
{
    $subject = mb_substr(trim($subject), 0, 150);
    $body = trim($body);
    if ($subject === '' || mb_strlen($body) < 20) return 'Rédigez un objet et un message.';
    if (!str_contains($body, '{desinscription}')) return 'Le message doit contenir {desinscription} : le lien pour ne plus être contacté est obligatoire.';
    $st = $pdo->prepare('SELECT * FROM prospects WHERE id = ?');
    $st->execute([$prospectId]);
    $p = $st->fetch();
    if (!$p) return 'Prospect introuvable.';
    if ($p['email'] === '') return "Ce prospect n'a pas d'adresse e-mail.";
    if (!empty($p['optout'])) return 'Ce prospect a demandé à ne plus être contacté.';
    $sent = $pdo->prepare("SELECT occurred_at FROM activities WHERE prospect_id = ? AND kind = 'email' AND notes LIKE '" . VS_SENT_NOTE . "%' ORDER BY occurred_at");
    $sent->execute([$prospectId]);
    $times = array_map(static fn ($r) => (int) strtotime((string) $r['occurred_at']), $sent->fetchAll());
    if (count($times) >= GA_RELANCE_MAX + 1) return 'Déjà ' . (GA_RELANCE_MAX + 1) . " messages envoyés : il n'est plus relancé.";
    if ($times && time() - max($times) < GA_RELANCE_DELAY) return 'Un message a déjà été envoyé il y a moins de 3 jours.';
    // Le lien de désinscription porte l'ancien identifiant quand la fiche vient de l'ancienne liste.
    $linkId = (string) ($p['legacy_id'] !== '' ? $p['legacy_id'] : $p['id']);
    $name = (string) ($p['business'] !== '' ? $p['business'] : $p['name']);
    $text = strtr($body, ['{nom}' => $name, '{galerie}' => $gallery['name'], '{lien}' => gallery_public_url($gallery), '{desinscription}' => ga_optout_url($gallery['slug'], 'prospect', $linkId)]);
    if (!ga_mail($p['email'], $subject, $text)) return "L'e-mail n'a pas pu partir.";
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO activities (prospect_id, member_id, kind, outcome, notes, points, duration_min, occurred_at) VALUES (?, 0, 'email', 'envoye', ?, 0, 0, ?)")
        ->execute([$prospectId, VS_SENT_NOTE . ' : ' . $subject, $now]);
    $status = (string) $p['status'] === 'a_contacter' ? 'contacte' : (string) $p['status'];
    $pdo->prepare('UPDATE prospects SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, $now, $prospectId]);
    return null;
}
