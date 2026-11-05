<?php

// Appels à l'action (CTA) du site public : registre central, variantes A/B pondérées et compteurs
// d'affichages / clics. Les libellés se règlent dans /portail/ctas.php ; les compteurs sont centraux
// (data/saas/cta.db) : un déploiement compte ce qu'il sert.
//
// Un emplacement sans variante enregistrée affiche son libellé d'origine (variante « default ») et reste
// compté. Une variante de poids 0 est conservée pour l'historique mais n'est plus tirée.
// Ce fichier est autonome : il ne charge ni functions.php ni saas.php.

const CTA_MAX_VARIANTS = 6;
const CTA_MAX_WEIGHT = 100;
const CTA_MAX_LABEL = 80;
const CTA_MAX_PER_MINUTE = 240;

/**
 * Tous les CTA du site public. group : section de la page portail ; where : emplacement lisible ;
 * page : adresse à ouvrir pour voir le bouton ; url : destination (indicative, certaines sont dynamiques) ;
 * label : libellé d'origine, référence quand aucune variante n'est enregistrée.
 */
function cta_registry(): array
{
    return [
        'site_header_cta' => [
            'group' => 'Toutes les pages', 'where' => 'En-tête du site public', 'page' => '/',
            'url' => '/inscription/', 'label' => 'Je veux vendre en ligne',
        ],
        'home_hero_primary' => [
            'group' => 'Accueil', 'where' => "Bandeau d'ouverture", 'page' => '/',
            'url' => '/inscription/', 'label' => 'Je veux vendre en ligne',
        ],
        'home_hero_gallery' => [
            'group' => 'Accueil', 'where' => "Bandeau d'ouverture", 'page' => '/',
            'url' => '/galerie/creer.php', 'label' => 'Organiser un marché ou une galerie',
        ],
        'home_hero_login' => [
            'group' => 'Accueil', 'where' => "Bandeau d'ouverture", 'page' => '/',
            'url' => '/portail/login.php', 'label' => 'Se connecter',
        ],
        'home_hero_help' => [
            'group' => 'Accueil', 'where' => "Bandeau d'ouverture", 'page' => '/',
            'url' => '/#parcours', 'label' => 'Que souhaitez-vous faire ?',
        ],
        'home_type_create' => [
            'group' => 'Accueil', 'where' => 'Cartes des types de galerie', 'page' => '/',
            'url' => '/galerie/creer.php?type=…', 'label' => 'Créer mon espace',
            'dynamic' => true, 'default_note' => 'un libellé par type (« Créer mon vide-grenier », « Créer ma galerie »…)',
        ],
        'home_types_cta' => [
            'group' => 'Accueil', 'where' => 'Bloc « Pas sûr du type ? »', 'page' => '/',
            'url' => '/galerie/creer.php', 'label' => 'Voir tous les types et créer',
        ],
        'home_final_primary' => [
            'group' => 'Accueil', 'where' => 'Bandeau de fin', 'page' => '/',
            'url' => '/inscription/', 'label' => 'Je veux vendre en ligne',
        ],
        'home_final_gallery' => [
            'group' => 'Accueil', 'where' => 'Bandeau de fin', 'page' => '/',
            'url' => '/galerie/creer.php', 'label' => 'Organiser un marché ou une galerie',
        ],
        'lp_hero_create' => [
            'group' => "Pages d'atterrissage", 'where' => 'Bandeau des six pages', 'page' => '/vide-greniers/',
            'url' => '/galerie/creer.php?type=…', 'label' => 'Créer mon espace',
            'dynamic' => true, 'default_note' => 'le libellé du type de galerie de la page',
        ],
        'lp_hero_pricing' => [
            'group' => "Pages d'atterrissage", 'where' => 'Bandeau des six pages', 'page' => '/vide-greniers/',
            'url' => '#tarifs', 'label' => 'Voir les tarifs',
        ],
        'lp_hero_live' => [
            'group' => "Pages d'atterrissage", 'where' => 'Bandeau des six pages (si exemples)', 'page' => '/vide-greniers/',
            'url' => '#exemples', 'label' => 'Déjà en ligne',
            'dynamic' => true, 'default_note' => '« <type> déjà en ligne »',
        ],
        'lp_plan_choose' => [
            'group' => "Pages d'atterrissage", 'where' => 'Cartes de tarifs', 'page' => '/vide-greniers/#tarifs',
            'url' => '/galerie/creer.php?…', 'label' => 'Choisir cette formule',
            'dynamic' => true, 'default_note' => '« Choisir <offre> »',
        ],
        'lp_final_create' => [
            'group' => "Pages d'atterrissage", 'where' => 'Bandeau de fin', 'page' => '/vide-greniers/',
            'url' => '/galerie/creer.php?type=…', 'label' => 'Créer mon espace',
            'dynamic' => true, 'default_note' => 'le libellé du type de galerie de la page',
        ],
        'lp_final_galleries' => [
            'group' => "Pages d'atterrissage", 'where' => 'Bandeau de fin', 'page' => '/vide-greniers/',
            'url' => '/galerie/', 'label' => 'Voir les galeries',
        ],
        'annuaire_cta' => [
            'group' => 'Annuaire', 'where' => 'Bandeau de fin', 'page' => '/annuaire/',
            'url' => '/inscription/', 'label' => 'Je veux vendre en ligne',
        ],
        'galerie_hero_cta' => [
            'group' => 'Galeries', 'where' => "Bandeau d'ouverture de la liste", 'page' => '/galerie/',
            'url' => '/inscription/', 'label' => 'Je veux vendre en ligne',
        ],
        'galerie_band_cta' => [
            'group' => 'Galeries', 'where' => 'Bandeau de fin', 'page' => '/galerie/',
            'url' => '/inscription/ ou /inscription/?galerie=…', 'label' => 'Je veux vendre en ligne',
        ],
        'galerie_float_cta' => [
            'group' => 'Galeries', 'where' => 'Bouton flottant', 'page' => '/galerie/',
            'url' => '/inscription/ ou /inscription/?galerie=…', 'label' => 'Je veux vendre en ligne',
        ],
        'galerie_create_cta' => [
            'group' => 'Galeries', 'where' => 'Bloc « Créer votre propre galerie »', 'page' => '/galerie/',
            'url' => '/galerie/creer.php', 'label' => 'Créer ma galerie',
        ],
    ];
}

function cta_h($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Dossier des données (même arborescence que les autres suivis centraux) ; CTA_DATA_DIR permet aux tests d'isoler leur base. */
function cta_data_dir(): string
{
    $dir = defined('CTA_DATA_DIR') ? (string) CTA_DATA_DIR : dirname(__DIR__) . '/data/saas';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/** Clé secrète des jetons de suivi (créée au premier usage, jamais servie : data/ est interdit au web). */
function cta_secret(): string
{
    $file = cta_data_dir() . '/cta.secret';
    if (!is_file($file)) @file_put_contents($file, bin2hex(random_bytes(32)));
    @chmod($file, 0600);
    return trim((string) @file_get_contents($file)) ?: 'cta-secret';
}

function cta_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . cta_data_dir() . '/cta.db');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 3000');
        $pdo->exec('CREATE TABLE IF NOT EXISTS cta_variants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slot TEXT NOT NULL,
            label TEXT NOT NULL,
            weight INTEGER NOT NULL DEFAULT 1,
            position INTEGER NOT NULL DEFAULT 0
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cta_variants_slot ON cta_variants(slot)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS cta_counters (
            day TEXT NOT NULL, slot TEXT NOT NULL, variant TEXT NOT NULL,
            impressions INTEGER NOT NULL DEFAULT 0, clicks INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (day, slot, variant)
        )');
        $pdo->exec('CREATE TABLE IF NOT EXISTS cta_hits (who TEXT NOT NULL, minute INTEGER NOT NULL, n INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (who, minute))');
    }
    return $pdo;
}

/** Libellé saisi : balises et caractères de contrôle retirés, espaces normalisés, longueur bornée. */
function cta_clean_label(string $value, int $max = CTA_MAX_LABEL): string
{
    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    $value = trim((string) preg_replace('/\s+/u', ' ', $value));
    return mb_substr($value, 0, max(1, $max));
}

function cta_clean_weight($value): int
{
    return max(0, min(CTA_MAX_WEIGHT, (int) $value));
}

/** Variantes enregistrées d'un CTA, dans l'ordre du formulaire. */
function cta_variants(string $slot): array
{
    try {
        $stmt = cta_db()->prepare('SELECT id, slot, label, weight, position FROM cta_variants WHERE slot = ? ORDER BY position, id');
        $stmt->execute([$slot]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Variante affichée pour ce CTA : tirage pondéré parmi les variantes actives. Sans variante enregistrée,
 * le libellé d'origine ($fallback, sinon celui du registre) est servi en variante « default ».
 * Le choix est figé pour la requête quand il vient des variantes, pour qu'un même CTA répété reste cohérent.
 */
function cta_pick(string $slot, string $fallback = ''): array
{
    static $picked = [];
    if (isset($picked[$slot])) return $picked[$slot];
    $rows = array_values(array_filter(cta_variants($slot), static fn (array $r): bool => (int) $r['weight'] > 0));
    if (!$rows) {
        $meta = cta_registry()[$slot] ?? [];
        $label = $fallback !== '' ? $fallback : (string) ($meta['label'] ?? $slot);
        return ['variant' => 'default', 'label' => $label, 'id' => 0];
    }
    $total = 0;
    foreach ($rows as $row) $total += (int) $row['weight'];
    $roll = random_int(1, max(1, $total));
    $chosen = $rows[count($rows) - 1];
    foreach ($rows as $row) {
        $roll -= (int) $row['weight'];
        if ($roll <= 0) { $chosen = $row; break; }
    }
    return $picked[$slot] = [
        'variant' => 'v' . (int) $chosen['id'],
        'label' => (string) $chosen['label'],
        'id' => (int) $chosen['id'],
    ];
}

/** Libellé affiché : la variante tirée, sinon le libellé d'origine passé par la page. */
function cta_label(string $slot, string $fallback = ''): string
{
    return cta_pick($slot, $fallback)['label'];
}

/** Attributs de suivi à poser sur l'élément (lien ou bouton) qui porte le CTA. */
function cta_attrs(string $slot, string $fallback = ''): string
{
    $pick = cta_pick($slot, $fallback);
    return ' data-cta="' . cta_h($slot) . '" data-cta-variant="' . cta_h($pick['variant'])
        . '" data-cta-token="' . cta_h(cta_token($slot, $pick['variant'])) . '"';
}

/** Lien complet, prêt à afficher (classe + éventuels attributs supplémentaires). */
function cta_link(string $slot, string $href, string $class = 'btn', array $extra = [], string $fallback = ''): string
{
    $attrs = '';
    foreach ($extra as $name => $value) {
        if ($value === null || $value === false) continue;
        $attrs .= ' ' . cta_h($name) . ($value === true ? '' : '="' . cta_h($value) . '"');
    }
    return '<a class="' . cta_h($class) . '" href="' . cta_h($href) . '"' . $attrs . cta_attrs($slot, $fallback)
        . '>' . cta_h(cta_label($slot, $fallback)) . '</a>';
}

/** Jeton d'un couple emplacement + variante : une page servie par la plateforme est seule à pouvoir en produire. */
function cta_token(string $slot, string $variant): string
{
    return substr(hash_hmac('sha256', $slot . '|' . $variant, cta_secret()), 0, 20);
}

function cta_token_valid(string $slot, string $variant, string $token): bool
{
    return $token !== '' && hash_equals(cta_token($slot, $variant), $token);
}

/** Robots d'indexation et outils : jamais comptés. */
function cta_is_bot(?string $ua = null): bool
{
    $ua = (string) ($ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget|python|headless|lighthouse|GTmetrix/i', $ua);
}

/** Frein par adresse IP (240 événements par minute) : un script ne gonfle pas les compteurs. */
function cta_rate_ok(): bool
{
    try {
        $who = substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . cta_secret()), 0, 16);
        $minute = intdiv(time(), 60);
        $db = cta_db();
        $db->prepare('INSERT INTO cta_hits (who, minute, n) VALUES (?, ?, 1) ON CONFLICT(who, minute) DO UPDATE SET n = n + 1')->execute([$who, $minute]);
        $n = (int) $db->query('SELECT n FROM cta_hits WHERE who = ' . $db->quote($who) . ' AND minute = ' . $minute)->fetchColumn();
        if (random_int(1, 50) === 1) $db->exec('DELETE FROM cta_hits WHERE minute < ' . ($minute - 10));
        return $n <= CTA_MAX_PER_MINUTE;
    } catch (Throwable $e) {
        return true;
    }
}

/** Ajoute des affichages ou des clics (jour courant). Faux si l'emplacement ou la variante sont inconnus. */
function cta_record(string $slot, string $variant, int $impressions, int $clicks): bool
{
    if (!isset(cta_registry()[$slot]) || !preg_match('/^(default|v[0-9]{1,9})$/', $variant)) return false;
    $impressions = max(0, $impressions);
    $clicks = max(0, $clicks);
    if ($impressions === 0 && $clicks === 0) return true;
    try {
        cta_db()->prepare('INSERT INTO cta_counters (day, slot, variant, impressions, clicks) VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(day, slot, variant) DO UPDATE SET impressions = impressions + excluded.impressions, clicks = clicks + excluded.clicks')
            ->execute([gmdate('Y-m-d'), $slot, $variant, $impressions, $clicks]);
        return true;
    } catch (Throwable $e) {
        error_log('cta_record: ' . $e->getMessage());
        return false;
    }
}

/**
 * Compteurs sur une période. $slot limite à un emplacement. Retourne
 * [['slot', 'variant', 'impressions', 'clicks', 'ctr'], …], par emplacement puis affichages décroissants.
 */
function cta_stats(string $from, string $to, string $slot = ''): array
{
    $sql = 'SELECT slot, variant, SUM(impressions) AS impressions, SUM(clicks) AS clicks
        FROM cta_counters WHERE day BETWEEN ? AND ?';
    $params = [$from, $to];
    if ($slot !== '') { $sql .= ' AND slot = ?'; $params[] = $slot; }
    $sql .= ' GROUP BY slot, variant ORDER BY slot, impressions DESC, clicks DESC';
    try {
        $stmt = cta_db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    foreach ($rows as &$row) {
        $row['impressions'] = (int) $row['impressions'];
        $row['clicks'] = (int) $row['clicks'];
        $row['ctr'] = $row['impressions'] > 0 ? round($row['clicks'] / $row['impressions'] * 100, 1) : 0.0;
    }
    return $rows;
}

/** Compteurs indexés par « emplacement|variante ». */
function cta_stats_map(string $from, string $to, string $slot = ''): array
{
    $map = [];
    foreach (cta_stats($from, $to, $slot) as $row) $map[$row['slot'] . '|' . $row['variant']] = $row;
    return $map;
}

/**
 * Enregistre les variantes d'un CTA telles que soumises par le formulaire. Les lignes portant un
 * identifiant existant sont mises à jour (les compteurs restent attachés à la même variante) ; les
 * identifiants absents sont supprimés ; les lignes sans identifiant sont ajoutées.
 * Retourne ['ok' => bool, 'error' => string, 'count' => int].
 */
function cta_save_variants(string $slot, array $ids, array $labels, array $weights): array
{
    if (!isset(cta_registry()[$slot])) return ['ok' => false, 'error' => 'Emplacement inconnu.', 'count' => 0];
    $rows = [];
    foreach (array_values($labels) as $i => $label) {
        $label = cta_clean_label((string) $label);
        if ($label === '') continue;
        if (count($rows) >= CTA_MAX_VARIANTS) return ['ok' => false, 'error' => 'Six variantes au maximum par appel à l\'action.', 'count' => 0];
        $rows[] = ['id' => (int) ($ids[$i] ?? 0), 'label' => $label, 'weight' => cta_clean_weight($weights[$i] ?? 1)];
    }
    if (!$rows) return ['ok' => false, 'error' => 'Ajoutez au moins un libellé, ou utilisez « Revenir au libellé par défaut ».', 'count' => 0];
    if (!array_filter($rows, static fn (array $r): bool => $r['weight'] > 0)) {
        return ['ok' => false, 'error' => 'Au moins une variante doit avoir un poids supérieur à zéro.', 'count' => 0];
    }
    try {
        $db = cta_db();
        $existing = [];
        foreach (cta_variants($slot) as $row) $existing[(int) $row['id']] = $row;
        $kept = [];
        $position = 0;
        $db->beginTransaction();
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if ($id > 0 && isset($existing[$id])) {
                $db->prepare('UPDATE cta_variants SET label = ?, weight = ?, position = ? WHERE id = ? AND slot = ?')
                    ->execute([$row['label'], $row['weight'], $position, $id, $slot]);
                $kept[$id] = true;
            } else {
                $db->prepare('INSERT INTO cta_variants (slot, label, weight, position) VALUES (?, ?, ?, ?)')
                    ->execute([$slot, $row['label'], $row['weight'], $position]);
            }
            $position++;
        }
        foreach (array_keys($existing) as $id) {
            if (!isset($kept[$id])) $db->prepare('DELETE FROM cta_variants WHERE id = ? AND slot = ?')->execute([$id, $slot]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => 'Impossible d\'enregistrer les variantes (base de données).', 'count' => 0];
    }
    return ['ok' => true, 'error' => '', 'count' => count($rows)];
}

/** Supprime les variantes : le CTA retrouve son libellé d'origine. L'historique des compteurs est conservé. */
function cta_clear_variants(string $slot): bool
{
    if (!isset(cta_registry()[$slot])) return false;
    try {
        cta_db()->prepare('DELETE FROM cta_variants WHERE slot = ?')->execute([$slot]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
