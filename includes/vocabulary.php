<?php

/**
 * Vocabulaire des « extensions » : le mot que la plateforme emploie pour ses fonctions complémentaires (plugins des boutiques, options
 * des galeries, modules), et leur portée (boutique, galerie ou les deux).
 *
 * Un seul mot dans tous les écrans (portail, administration d'une boutique, espace d'une galerie, inscription, accueil), réglable par
 * l'exploitant dans le portail → « Extensions · nom et portée » (portail/vocabulaire.php). Réglages dans data/saas/vocabulary.json
 * (jamais envoyé par le déploiement) ; sans ce fichier : « extension » / « extensions », féminin.
 *
 * Les textes s'écrivent avec des jetons, remplacés par ext_t() en respectant le genre du mot choisi (« une extension », « un plugin »,
 * « cette option », « ce module », « l’extension », « du plugin »…) :
 *
 *   {ext} {exts}  {Ext} {Exts}       le mot au singulier / au pluriel, en minuscules / avec majuscule initiale
 *   {un ext}      {Un ext}           « une extension » / « un plugin »
 *   {le ext}      {Le ext}           « l’extension » / « le plugin » / « la fonction » (élision devant une voyelle)
 *   {ce ext}      {Ce ext}           « cette extension » / « ce plugin » / « cet outil »
 *   {du ext}      {Du ext}           « de l’extension » / « du plugin » / « de la fonction »
 *   {au ext}      {Au ext}           « à l’extension » / « au plugin » / « à la fonction »
 *   {les exts}    {Les exts}         « les extensions »      {des exts} {Des exts}   « des extensions »
 *   {e} {es}                         accords : « e » / « es » si le mot est féminin, sinon rien / « s » (« activé{e} » -> activé ou activée ;
 *                                    « proposé{es} » -> proposés ou proposées)
 *   {?masculin|féminin}              choix selon le genre, pour les accords irréguliers : « {?inclus|incluse} », « {?ils|elles} », « {?le|la} »
 *
 * Ce fichier ne dépend d'aucun autre : il peut être chargé par n'importe quelle page.
 */

const VOCAB_GENDERS = ['f' => 'féminin (une extension)', 'm' => 'masculin (un plugin)'];
const VOCAB_SCOPES = ['shop' => 'Boutique', 'gallery' => 'Galerie', 'both' => 'Les deux'];

function vocab_file(): string
{
    // BROCANTE_VOCAB_FILE : fichier de réglages jetable pour les tests (scripts/test-vocabulary.php).
    return getenv('BROCANTE_VOCAB_FILE') ?: dirname(__DIR__) . '/data/saas/vocabulary.json';
}

/** Mots proposés d'office dans le portail : clé => [singulier, pluriel, genre]. Le champ libre reste possible. */
function vocab_presets(): array
{
    return [
        'extension' => ['extension', 'extensions', 'f'],
        'option' => ['option', 'options', 'f'],
        'plugin' => ['plugin', 'plugins', 'm'],
        'module' => ['module', 'modules', 'm'],
        'complement' => ['complément', 'compléments', 'm'],
        'fonction' => ['fonction', 'fonctions', 'f'],
    ];
}

function vocab_defaults(): array
{
    return ['singular' => 'extension', 'plural' => 'extensions', 'gender' => 'f', 'scopes' => []];
}

/** Un mot : lettres, espaces, tiret ou apostrophe, 1 à 30 caractères ; apostrophe droite ramenée à l'apostrophe typographique. -> mot ou ''. */
function vocab_clean_word(mixed $word): string
{
    $word = trim(str_replace("'", '’', (string) $word));
    $word = preg_replace('/\s+/u', ' ', $word) ?? '';
    return preg_match('/^[\p{L}][\p{L} ’-]{0,29}$/u', $word) ? mb_strtolower($word) : '';
}

/** Réglages d'après le fichier, complétés par les valeurs d'origine. Mis en cache pour la requête ($reload : relire). */
function vocab_load(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $v = vocab_defaults();
    $file = vocab_file();
    $raw = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    if (is_array($raw)) {
        if (($w = vocab_clean_word($raw['singular'] ?? '')) !== '') $v['singular'] = $w;
        if (($w = vocab_clean_word($raw['plural'] ?? '')) !== '') $v['plural'] = $w;
        if (in_array($raw['gender'] ?? '', ['f', 'm'], true)) $v['gender'] = (string) $raw['gender'];
        foreach ((array) ($raw['scopes'] ?? []) as $id => $scope) {
            if (is_string($id) && preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $id) && isset(VOCAB_SCOPES[$scope])) $v['scopes'][$id] = (string) $scope;
        }
    }
    return $cache = $v;
}

/**
 * Enregistre le vocabulaire et les portées. $input : singular, plural, gender, scopes (id => portée). Seules les portées qui diffèrent de la
 * portée d'origine ($defaults : id => portée) sont conservées. -> message d'erreur ou null.
 */
function vocab_save(array $input, array $defaults = []): ?string
{
    $singular = vocab_clean_word($input['singular'] ?? '');
    $plural = vocab_clean_word($input['plural'] ?? '');
    if ($singular === '') return 'Le mot au singulier est invalide (lettres, espaces, tiret ou apostrophe, 30 caractères au plus).';
    if ($plural === '') return 'Le mot au pluriel est invalide (lettres, espaces, tiret ou apostrophe, 30 caractères au plus).';
    $gender = in_array($input['gender'] ?? '', ['f', 'm'], true) ? (string) $input['gender'] : 'f';
    $scopes = [];
    foreach ((array) ($input['scopes'] ?? []) as $id => $scope) {
        if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $id) || !isset(VOCAB_SCOPES[$scope])) continue;
        if (($defaults[$id] ?? null) !== $scope) $scopes[$id] = (string) $scope;
    }
    ksort($scopes);
    $file = vocab_file();
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return "Impossible de créer le dossier $dir.";
    $json = json_encode(['singular' => $singular, 'plural' => $plural, 'gender' => $gender, 'scopes' => $scopes, 'updated_at' => gmdate('c')],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
        return "Impossible d'écrire " . basename($file) . ' (droits du dossier data/saas).';
    }
    vocab_load(true);
    return null;
}

/** Première lettre en majuscule (UTF-8). */
function vocab_ucfirst(string $s): string
{
    return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
}

/** Le mot commence-t-il par une voyelle (ou un h muet) : élision « l’ », « cet » ? */
function vocab_vowel_start(string $word): bool
{
    return (bool) preg_match('/^[aeiouyhàâäéèêëîïôöùûüœ]/iu', $word);
}

/** Le mot, au singulier ou au pluriel ($form : 'singular' | 'plural'), avec ou sans majuscule initiale. $v : vocabulaire d'aperçu (sinon celui qui est réglé). */
function ext_word(string $form = 'singular', bool $capital = false, ?array $v = null): string
{
    $v ??= vocab_load();
    $w = $form === 'plural' ? $v['plural'] : $v['singular'];
    return $capital ? vocab_ucfirst($w) : $w;
}

/** Le mot précédé de son article : $article = un | le | ce | du | les | des ; $capital : majuscule initiale. */
function ext_phrase(string $article, string $form = 'singular', bool $capital = false, ?array $v = null): string
{
    $v ??= vocab_load();
    $feminine = $v['gender'] === 'f';
    $word = $form === 'plural' ? $v['plural'] : $v['singular'];
    $vowel = vocab_vowel_start($word);
    if ($form === 'plural') {
        $out = match ($article) {
            'les' => 'les ' . $word,
            'des', 'du' => 'des ' . $word,
            'ce' => 'ces ' . $word,
            'au' => 'aux ' . $word,
            'un' => 'des ' . $word,
            default => $word,
        };
    } else {
        $out = match ($article) {
            'un' => ($feminine ? 'une ' : 'un ') . $word,
            'le' => $vowel ? 'l’' . $word : ($feminine ? 'la ' : 'le ') . $word,
            'ce' => $feminine ? 'cette ' . $word : ($vowel ? 'cet ' : 'ce ') . $word,
            'du' => $vowel ? 'de l’' . $word : ($feminine ? 'de la ' : 'du ') . $word,
            'au' => $vowel ? 'à l’' . $word : ($feminine ? 'à la ' : 'au ') . $word,
            'les' => 'les ' . $v['plural'],
            'des' => 'des ' . $v['plural'],
            default => $word,
        };
    }
    return $capital ? vocab_ucfirst($out) : $out;
}

/** Remplace les jetons d'un texte (voir l'en-tête du fichier). Sans jeton, le texte est rendu tel quel. */
function ext_t(string $text, ?array $v = null): string
{
    if (!str_contains($text, '{')) return $text;
    $v ??= vocab_load();
    $pattern = '/\{(?:\?(?<m>[^{}|?]*)\|(?<f>[^{}|?]*)|(?<art>Un|un|Le|le|Ce|ce|Du|du|Au|au|Les|les|Des|des) (?<aw>ext|exts)|(?<w>Ext|Exts|ext|exts)|(?<acc>es|e))\}/';
    return (string) preg_replace_callback($pattern, static function (array $m) use ($v): string {
        if ($m[0][1] === '?') return $v['gender'] === 'f' ? $m['f'] : $m['m'];
        if (($m['acc'] ?? '') !== '') return $v['gender'] === 'f' ? $m['acc'] : ($m['acc'] === 'es' ? 's' : '');
        if (($m['art'] ?? '') !== '') {
            return ext_phrase(mb_strtolower($m['art']), $m['aw'] === 'exts' ? 'plural' : 'singular', $m['art'] === vocab_ucfirst($m['art']), $v);
        }
        $plural = $m['w'] === 'Exts' || $m['w'] === 'exts';
        return ext_word($plural ? 'plural' : 'singular', $m['w'][0] === 'E', $v);
    }, $text);
}

/** Pour ob_start() : remplace les jetons dans toute la page (voir ext_page()). */
function ext_t_buffer(string $buffer): string
{
    return ext_t($buffer);
}

/**
 * À appeler en tête d'une page dont le texte HTML contient des jetons ({ext}, {Exts}, {un ext}…) : ils sont remplacés à l'envoi de la page,
 * sans envelopper chaque phrase. Les messages renvoyés hors HTML (JSON, e-mails) passent, eux, par ext_t() là où ils sont écrits.
 */
function ext_page(): void
{
    ob_start('ext_t_buffer');
}

// ── Portée : boutique, galerie ou les deux ──────────────────────────────────

/**
 * Portée d'une extension : réglage de l'exploitant, sinon $default (« shop » pour un plugin de boutique, « gallery » pour une option de
 * galerie). -> shop | gallery | both.
 */
function ext_scope(string $id, string $default = 'shop'): string
{
    return vocab_load()['scopes'][$id] ?? (isset(VOCAB_SCOPES[$default]) ? $default : 'shop');
}

function ext_scope_label(string $scope): string
{
    return VOCAB_SCOPES[$scope] ?? VOCAB_SCOPES['shop'];
}

/** Étiquette de portée (petite pastille) : « Boutique », « Galerie » ou « Les deux ». */
function ext_scope_badge(string $scope): string
{
    $scope = isset(VOCAB_SCOPES[$scope]) ? $scope : 'shop';
    $colors = ['shop' => '#1f5a86', 'gallery' => '#8a4b12', 'both' => '#5a3d8a'];
    return '<span class="ext-scope ext-scope-' . $scope . '" title="Concerne : ' . htmlspecialchars(mb_strtolower(VOCAB_SCOPES[$scope]), ENT_QUOTES, 'UTF-8') . '"'
        . ' style="display:inline-block;vertical-align:middle;margin-left:6px;padding:1px 8px;border:1px solid ' . $colors[$scope] . ';border-radius:999px;'
        . 'font-size:.68rem;font-weight:600;letter-spacing:.03em;line-height:1.5;color:' . $colors[$scope] . ';white-space:nowrap;">'
        . htmlspecialchars(VOCAB_SCOPES[$scope], ENT_QUOTES, 'UTF-8') . '</span>';
}
