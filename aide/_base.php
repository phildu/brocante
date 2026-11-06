<?php
// Socle du centre d'aide : chargement du contenu, recherche accent/insensible à la casse,
// assistant documentaire et helpers d'affichage. Aucun appel réseau n'est fait ici.
require_once __DIR__ . '/../includes/saas.php';

/**
 * Point d'extension pour une réponse générative future.
 * Tant que la constante reste à false, l'assistant répond uniquement avec le contenu local.
 * Pour brancher un service, appeler ce service dans aide_answer() derrière ce garde-fou.
 */
const AIDE_IA_ENABLED = false;

/** Seuil minimal de pertinence de l'assistant (score + couverture des mots de la question). */
const AIDE_CHAT_MIN_SCORE = 10;
const AIDE_CHAT_MIN_COVERAGE = 0.5;

function aide_roles(): array
{
    return [
        'vendeur' => 'Vendeur',
        'organisateur' => 'Organisateur',
        'visiteur' => 'Visiteur',
        'commune' => 'Commune',
        'tous' => 'Général',
    ];
}

function aide_role_label(string $role): string
{
    return aide_roles()[$role] ?? 'Tous';
}

function aide_content_dir(): string
{
    return __DIR__ . '/contenu';
}

/** Lit un fichier JSON de la base de contenu. Un fichier absent ou invalide ne bloque pas la page. */
function aide_json_file(string $file): array
{
    $path = aide_content_dir() . '/' . $file;
    if (!is_file($path)) return [];
    $raw = file_get_contents($path);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Convertit la base FAQ structurée (faq.json) vers le schéma du centre d'aide. */
function aide_import_faq(): array
{
    $rows = [];
    foreach ((array) (aide_json_file('faq.json')['questions'] ?? []) as $q) {
        if (!is_array($q)) continue;
        $liens = [];
        foreach ((array) ($q['liens'] ?? []) as $lien) {
            if (!is_array($lien)) continue;
            $url = trim((string) ($lien['url'] ?? ''));
            if (!preg_match('#^/[a-z0-9]#i', $url)) continue;
            $liens[] = [
                'libelle' => trim((string) ($lien['libelle'] ?? 'Ouvrir la page concernée')),
                'url' => $url,
            ];
        }
        $rows[] = [
            'slug' => (string) ($q['id'] ?? ''),
            'role' => (string) ($q['role'] ?? 'tous'),
            'titre' => (string) ($q['question'] ?? ''),
            'resume' => (string) ($q['reponse'] ?? ''),
            'mots_cles' => (array) ($q['mots_cles'] ?? []),
            'url_cible' => $liens[0]['url'] ?? '',
            'liens' => $liens,
            'tutoriel' => (string) ($q['tutoriel'] ?? ''),
        ];
    }
    return $rows;
}

/** Convertit le glossaire structuré (glossaire.json) vers le schéma du centre d'aide. */
function aide_import_glossaire(): array
{
    $rows = [];
    foreach ((array) (aide_json_file('glossaire.json')['termes'] ?? []) as $terme) {
        if (!is_array($terme)) continue;
        $nom = trim((string) ($terme['terme'] ?? ''));
        $definition = trim((string) ($terme['definition'] ?? ''));
        if ($nom === '' || $definition === '') continue;
        $exemple = trim((string) ($terme['exemple'] ?? ''));
        $rows[] = [
            'slug' => aide_slug($nom),
            'role' => 'tous',
            'titre' => $nom,
            'resume' => $definition . ($exemple !== '' ? ' Exemple : ' . $exemple : ''),
            'mots_cles' => array_merge([$nom], (array) ($terme['voir'] ?? [])),
        ];
    }
    return $rows;
}

/** Convertit les tutoriels structurés, avec leurs captures et leurs vidéos. */
function aide_import_tutoriels(): array
{
    $capturesParFichier = [];
    foreach ((array) (aide_json_file('captures.json')['captures'] ?? []) as $capture) {
        if (is_array($capture) && !empty($capture['fichier'])) $capturesParFichier[(string) $capture['fichier']] = $capture;
    }
    $videosParSlug = [];
    foreach ((array) (aide_json_file('videos.json')['videos'] ?? []) as $video) {
        if (is_array($video) && !empty($video['slug'])) $videosParSlug[(string) $video['slug']] = $video;
    }

    $ecarts = [
        'exporter-un-plan' => "L'éditeur de plan n'a pas d'export image ou PDF. La liste des exposants s'exporte en CSV, et le registre en Excel, CSV ou PDF via l'impression du navigateur. Pour une image du plan, ouvrez la page publique et utilisez l'impression ou une capture d'écran.",
    ];

    $rows = [];
    foreach ((array) (aide_json_file('tutoriels.json')['tutoriels'] ?? []) as $t) {
        if (!is_array($t)) continue;
        $etapes = [];
        foreach ((array) ($t['etapes'] ?? []) as $step) {
            if (!is_array($step)) continue;
            $lien = trim((string) ($step['lien'] ?? ''));
            if ($lien !== '' && !preg_match('#^/[a-z0-9]#i', $lien)) $lien = '';
            $etapes[] = [
                'titre' => (string) ($step['titre'] ?? ''),
                'texte' => (string) ($step['texte'] ?? ''),
                'ecran' => (string) ($step['a_voir'] ?? ''),
                'lien' => $lien,
            ];
        }
        $erreurs = [];
        foreach ((array) ($t['erreurs'] ?? []) as $err) {
            if (!is_array($err)) continue;
            $symptome = trim((string) ($err['symptome'] ?? ''));
            $cause = trim((string) ($err['cause'] ?? ''));
            $solution = trim((string) ($err['solution'] ?? ''));
            $parts = [];
            if ($symptome !== '') $parts[] = '« ' . $symptome . ' »';
            if ($cause !== '') $parts[] = $cause;
            if ($solution !== '') $parts[] = $solution;
            if ($parts) $erreurs[] = implode(' ', $parts);
        }
        $captures = [];
        foreach ((array) ($t['captures'] ?? []) as $fichier) {
            $fichier = (string) $fichier;
            $detail = $capturesParFichier[$fichier] ?? [];
            $captures[] = [
                'fichier' => $fichier,
                'legende' => (string) ($detail['alt'] ?? ''),
                'url' => (string) ($detail['url'] ?? ''),
                'compte' => (string) ($detail['compte'] ?? ''),
                'action' => (string) ($detail['action'] ?? ''),
                'cadrage' => (string) ($detail['cadrage'] ?? ''),
                'remplacer' => (string) ($detail['remplacement'] ?? 'Capture à faire'),
            ];
        }
        $video = null;
        $videoSlug = (string) ($t['video'] ?? '');
        if ($videoSlug !== '' && isset($videosParSlug[$videoSlug])) {
            $v = $videosParSlug[$videoSlug];
            $secondes = (int) ($v['duree_s'] ?? 0);
            $duree = $secondes >= 60
                ? intdiv($secondes, 60) . ' min ' . str_pad((string) ($secondes % 60), 2, '0', STR_PAD_LEFT) . ' s'
                : $secondes . ' s';
            $plans = [];
            foreach ((array) ($v['storyboard'] ?? []) as $plan) {
                if (!is_array($plan)) continue;
                $plans[] = [
                    'debut' => (string) ($plan['debut'] ?? ''),
                    'fin' => (string) ($plan['fin'] ?? ''),
                    'plan' => (string) ($plan['plan'] ?? ''),
                    'action' => (string) ($plan['action'] ?? ''),
                    'texte' => (string) ($plan['texte_ecran'] ?? ''),
                ];
            }
            $video = ['slug' => $videoSlug, 'duree' => $duree, 'plans' => $plans];
        }
        $rows[] = [
            'slug' => (string) ($t['slug'] ?? ''),
            'role' => (string) ($t['role'] ?? 'tous'),
            'titre' => (string) ($t['titre'] ?? ''),
            'resume' => (string) ($t['resume'] ?? ''),
            'prerequis' => (array) ($t['prerequis'] ?? []),
            'etapes' => $etapes,
            'erreurs' => $erreurs,
            'captures' => $captures,
            'video' => $video,
            'mots_cles' => (array) ($t['mots_cles'] ?? []),
            'url_cible' => (string) ($t['url_cible'] ?? ''),
            'ecart' => $ecarts[(string) ($t['slug'] ?? '')] ?? '',
        ];
    }
    return $rows;
}


/** Charge un fichier de contenu qui retourne un tableau. Un fichier absent ne bloque pas la page. */
function aide_load_content(string $file): array
{
    $path = aide_content_dir() . '/' . $file;
    if (!is_file($path)) return [];
    $data = require $path;
    return is_array($data) ? $data : [];
}

function aide_faq(): array
{
    static $items = null;
    if ($items === null) $items = aide_normalize_many(aide_load_content('faq.php'), 'faq');
    return $items;
}

function aide_tutoriels(): array
{
    static $items = null;
    if ($items === null) $items = aide_normalize_many(aide_load_content('tutoriels.php'), 'tutoriel');
    return $items;
}

function aide_glossaire(): array
{
    static $items = null;
    if ($items === null) $items = aide_normalize_many(aide_load_content('glossaire.php'), 'glossaire');
    return $items;
}

/** Toutes les fiches indexées : FAQ, tutoriels, glossaire. */
function aide_articles(): array
{
    static $items = null;
    if ($items !== null) return $items;
    $items = array_merge(aide_tutoriels(), aide_faq(), aide_glossaire());
    return $items;
}

function aide_find(string $slug): ?array
{
    foreach (aide_articles() as $article) {
        if ($article['slug'] === $slug) return $article;
    }
    return null;
}

function aide_slug(string $text): string
{
    $slug = function_exists('saas_slug') ? saas_slug($text) : preg_replace('/[^a-z0-9]+/', '-', strtolower($text));
    $slug = trim((string) $slug, '-');
    return $slug !== '' ? $slug : 'fiche';
}

function aide_safe_file(string $file): string
{
    $file = basename(trim($file));
    return preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $file) ? $file : '';
}

function aide_normalize_many(array $rows, string $type): array
{
    $out = [];
    foreach ($rows as $row) {
        $item = aide_normalize_article(is_array($row) ? $row : [], $type);
        if ($item !== null) $out[] = $item;
    }
    return $out;
}

function aide_normalize_article(array $a, string $type): ?array
{
    $titre = trim((string) ($a['titre'] ?? ''));
    $resume = trim((string) ($a['resume'] ?? ''));
    if ($titre === '' || $resume === '') return null;

    $role = (string) ($a['role'] ?? 'tous');
    if (!isset(aide_roles()[$role])) $role = 'tous';

    $slug = preg_match('/^[a-z0-9][a-z0-9-]{1,80}$/', (string) ($a['slug'] ?? '')) ? (string) $a['slug'] : aide_slug($titre);
    $mots = array_values(array_filter(array_map(static fn ($m) => trim((string) $m), (array) ($a['mots_cles'] ?? []))));
    $etapes = [];
    foreach ((array) ($a['etapes'] ?? []) as $step) {
        if (is_array($step)) {
            $stepTitre = trim((string) ($step['titre'] ?? ''));
            $stepTexte = trim((string) ($step['texte'] ?? ''));
            $stepEcran = trim((string) ($step['ecran'] ?? ''));
            $stepLien = trim((string) ($step['lien'] ?? ''));
            if ($stepLien !== '' && !preg_match('#^/[a-z0-9]#i', $stepLien)) $stepLien = '';
            if ($stepTitre !== '' || $stepTexte !== '') {
                $etapes[] = ['titre' => $stepTitre, 'texte' => $stepTexte, 'ecran' => $stepEcran, 'lien' => $stepLien];
            }
        } else {
            $step = trim((string) $step);
            if ($step !== '') $etapes[] = ['titre' => '', 'texte' => $step, 'ecran' => '', 'lien' => ''];
        }
    }
    $erreurs = array_values(array_filter(array_map(static fn ($e) => trim((string) $e), (array) ($a['erreurs'] ?? []))));
    $prerequis = array_values(array_filter(array_map(static fn ($p) => trim((string) $p), (array) ($a['prerequis'] ?? []))));

    $urlCible = trim((string) ($a['url_cible'] ?? ''));
    if ($urlCible !== '' && !preg_match('#^/[a-z0-9]#i', $urlCible)) $urlCible = '';
    $urlPublique = trim((string) ($a['url_publique'] ?? ''));
    if ($urlPublique !== '' && !preg_match('#^/[a-z0-9]#i', $urlPublique)) $urlPublique = '';

    $captures = [];
    foreach ((array) ($a['captures'] ?? []) as $c) {
        if (!is_array($c)) continue;
        $file = aide_safe_file((string) ($c['fichier'] ?? ''));
        if ($file === '') continue;
        $captures[] = [
            'fichier' => $file,
            'legende' => trim((string) ($c['legende'] ?? '')),
            'url' => trim((string) ($c['url'] ?? '')),
            'compte' => trim((string) ($c['compte'] ?? '')),
            'action' => trim((string) ($c['action'] ?? '')),
            'cadrage' => trim((string) ($c['cadrage'] ?? '')),
            'remplacer' => trim((string) ($c['remplacer'] ?? '')),
        ];
    }

    $video = null;
    if (!empty($a['video']) && is_array($a['video'])) {
        $v = $a['video'];
        $vSlug = preg_match('/^[a-z0-9][a-z0-9-]{1,80}$/', (string) ($v['slug'] ?? '')) ? (string) $v['slug'] : $slug;
        $plans = [];
        foreach ((array) ($v['plans'] ?? []) as $p) {
            if (!is_array($p)) continue;
            $plans[] = [
                'debut' => trim((string) ($p['debut'] ?? '')),
                'fin' => trim((string) ($p['fin'] ?? '')),
                'plan' => trim((string) ($p['plan'] ?? '')),
                'action' => trim((string) ($p['action'] ?? '')),
                'texte' => trim((string) ($p['texte'] ?? '')),
            ];
        }
        $video = [
            'slug' => $vSlug,
            'duree' => trim((string) ($v['duree'] ?? '')),
            'fichier' => '/aide/assets/videos/' . $vSlug . '.mp4',
            'sous_titres' => '/aide/assets/videos/' . $vSlug . '.vtt',
            'plans' => $plans,
        ];
    }

    $liens = [];
    foreach ((array) ($a['liens'] ?? []) as $lien) {
        if (!is_array($lien)) continue;
        $url = trim((string) ($lien['url'] ?? ''));
        if (!preg_match('#^/[a-z0-9]#i', $url)) continue;
        $libelle = trim((string) ($lien['libelle'] ?? ''));
        $liens[] = ['libelle' => $libelle !== '' ? $libelle : 'Ouvrir la page concernée', 'url' => $url];
    }
    if (!$liens && $urlCible !== '') {
        $liens[] = ['libelle' => 'Ouvrir la page concernée', 'url' => $urlCible];
    }

    $tutoriel = trim((string) ($a['tutoriel'] ?? ''));
    if ($tutoriel !== '' && !preg_match('/^[a-z0-9][a-z0-9-]{1,80}$/', $tutoriel)) $tutoriel = '';

    $section = $type === 'tutoriel' ? 'Tutoriels' : ($type === 'glossaire' ? 'Glossaire' : aide_role_label($role));
    $url = $type === 'tutoriel'
        ? '/aide/tutoriel.php?slug=' . rawurlencode($slug)
        : ($type === 'glossaire' ? '/aide/#glossaire-' . rawurlencode($slug) : '/aide/?open=' . rawurlencode($slug) . '#' . rawurlencode($slug));

    return [
        'slug' => $slug,
        'type' => $type,
        'role' => $role,
        'role_label' => aide_role_label($role),
        'titre' => $titre,
        'resume' => $resume,
        'mots_cles' => $mots,
        'etapes' => $etapes,
        'erreurs' => $erreurs,
        'prerequis' => $prerequis,
        'url_cible' => $urlCible,
        'url_publique' => $urlPublique,
        'captures' => $captures,
        'video' => $video,
        'ecart' => trim((string) ($a['ecart'] ?? '')),
        'liens' => $liens,
        'tutoriel' => $tutoriel,
        'section' => $section,
        'url' => $url,
    ];
}

function aide_step_text(array $step): string
{
    return trim(($step['titre'] !== '' ? $step['titre'] . ' — ' : '') . $step['texte']);
}

// ── Normalisation et recherche ──────────────────────────────────────────────

function aide_stopwords(): array
{
    static $words = null;
    if ($words !== null) return $words;
    $words = [
        'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'd', 'l', 'et', 'ou', 'mais', 'donc', 'or', 'ni', 'car',
        'a', 'au', 'aux', 'en', 'dans', 'par', 'pour', 'sur', 'sous', 'avec', 'sans', 'chez', 'vers', 'entre',
        'est', 'sont', 'etre', 'avoir', 'ai', 'as', 'ont', 'avait', 'ete', 'cette', 'ces', 'cet', 'ce', 'celui',
        'celle', 'ceux', 'celles', 'qui', 'que', 'quoi', 'dont', 'ou', 'il', 'elle', 'ils', 'elles', 'on',
        'nous', 'vous', 'je', 'tu', 'me', 'te', 'se', 'sa', 'son', 'ses', 'leur', 'leurs', 'mon', 'ma', 'mes',
        'ton', 'ta', 'tes', 'notre', 'votre', 'nos', 'vos', 'y', 'plus', 'moins', 'tres', 'trop', 'peu', 'bien',
        'mal', 'fait', 'faire', 'peut', 'pouvez', 'dois', 'doit', 'comment', 'pourquoi', 'quand', 'quel',
        'quelle', 'quels', 'quelles', 'svp', 'merci', 'bonjour', 'salut', 'stp',
    ];
    return $words;
}

function aide_fold(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = strtr($text, [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae',
    ]);
    $text = preg_replace("/[^a-z0-9]+/u", ' ', $text) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

function aide_stem(string $word): string
{
    $len = mb_strlen($word);
    if ($len > 4 && preg_match('/[sx]$/u', $word)) {
        $word = mb_substr($word, 0, -1);
    }
    return $word;
}

function aide_tokens(string $text): array
{
    $words = explode(' ', aide_fold($text));
    $stop = aide_stopwords();
    $out = [];
    foreach ($words as $word) {
        if ($word === '' || mb_strlen($word) < 2 || in_array($word, $stop, true)) continue;
        $word = aide_stem($word);
        if (!in_array($word, $out, true)) $out[] = $word;
    }
    return $out;
}

/** Champs normalisés d'une fiche, calculés une fois. */
function aide_article_fields(array $article): array
{
    static $cache = [];
    $slug = $article['type'] . ':' . $article['slug'];
    if (isset($cache[$slug])) return $cache[$slug];
    $titre = aide_fold($article['titre']);
    $mots = aide_fold(implode(' ', $article['mots_cles']));
    $resume = aide_fold($article['resume']);
    $etapes = aide_fold(implode(' ', array_map('aide_step_text', $article['etapes'])));
    $erreurs = aide_fold(implode(' ', $article['erreurs']));
    $prerequis = aide_fold(implode(' ', $article['prerequis']));
    $ecart = aide_fold($article['ecart']);
    $liens = aide_fold(implode(' ', array_map(static fn (array $l): string => $l['libelle'], $article['liens'] ?? [])));
    $cache[$slug] = [
        'titre' => $titre,
        'mots' => $mots,
        'resume' => $resume,
        'etapes' => $etapes,
        'erreurs' => $erreurs,
        'prerequis' => $prerequis,
        'ecart' => $ecart,
        'liens' => $liens,
        'all' => trim($titre . ' ' . $mots . ' ' . $resume . ' ' . $etapes . ' ' . $erreurs . ' ' . $prerequis . ' ' . $ecart . ' ' . $liens),
    ];
    return $cache[$slug];
}

function aide_contains_token(string $haystack, string $token): bool
{
    if ($haystack === '') return false;
    return preg_match('/(?:^| )' . preg_quote($token, '/') . '[sx]?(?: |$)/u', $haystack) === 1;
}

/**
 * Recherche classée dans les fiches. $role limite à un rôle (les fiches « tous » restent visibles).
 * Retourne [article, score, coverage, matched].
 */
function aide_search(string $query, ?string $role = null, int $limit = 20): array
{
    $tokens = aide_tokens($query);
    if (!$tokens) return [];
    $phrase = aide_fold($query);
    $out = [];
    foreach (aide_articles() as $article) {
        if ($role !== null && $article['role'] !== $role && !($role !== 'tous' && $article['role'] === 'tous')) continue;
        $fields = aide_article_fields($article);
        $score = 0;
        $matched = 0;
        foreach ($tokens as $token) {
            $hit = false;
            if (aide_contains_token($fields['titre'], $token)) { $score += 9; $hit = true; }
            if (aide_contains_token($fields['mots'], $token)) { $score += 6; $hit = true; }
            if (aide_contains_token($fields['resume'], $token)) { $score += 3; $hit = true; }
            if (aide_contains_token($fields['etapes'], $token)) { $score += 2; $hit = true; }
            if (aide_contains_token($fields['liens'], $token)) { $score += 2; $hit = true; }
            if (aide_contains_token($fields['erreurs'], $token) || aide_contains_token($fields['prerequis'], $token) || aide_contains_token($fields['ecart'], $token)) { $score += 1; $hit = true; }
            if ($hit) $matched++;
        }
        if ($score <= 0) continue;
        if ($phrase !== '' && str_contains($fields['titre'], $phrase)) $score += 8;
        $coverage = $matched / max(1, count($tokens));
        $out[] = ['article' => $article, 'score' => $score, 'coverage' => $coverage, 'matched' => $matched];
    }
    usort($out, static function (array $a, array $b): int {
        if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
        if ($a['coverage'] !== $b['coverage']) return $b['coverage'] <=> $a['coverage'];
        $ta = $a['article']['type'] === 'tutoriel' ? 0 : 1;
        $tb = $b['article']['type'] === 'tutoriel' ? 0 : 1;
        if ($ta !== $tb) return $ta <=> $tb;
        return mb_strlen($a['article']['titre']) <=> mb_strlen($b['article']['titre']);
    });
    return array_slice($out, 0, max(1, $limit));
}

function aide_contact(): string
{
    return (string) (saas_config()['contact'] ?? '');
}

function aide_contact_mailto(string $subject = "Question depuis le centre d'aide"): string
{
    $contact = aide_contact();
    if ($contact === '') return '';
    return 'mailto:' . $contact . '?subject=' . rawurlencode($subject);
}

/**
 * Réponse documentaire à une question. Ne fait aucun appel réseau et ne stocke rien.
 * Retourne ok=true avec la meilleure fiche, ou ok=false avec le contact et le mailto.
 */
function aide_answer(string $question): array
{
    $question = trim($question);
    $contact = aide_contact();
    $mailto = aide_contact_mailto('Question sans réponse dans le centre d\'aide');
    $refusal = static function (string $message) use ($contact, $mailto): array {
        return ['ok' => false, 'message' => $message, 'contact' => $contact, 'mailto' => $mailto, 'article' => null, 'tutoriel' => null];
    };
    if ($question === '') return $refusal("Posez une question sur Brocs : plan, emplacement, paiement, Studio, registre…");
    if (AIDE_IA_ENABLED) {
        // Point d'extension : brancher ici un service génératif, en conservant le repli local ci-dessous.
    }
    $results = aide_search($question, null, 6);
    $best = $results[0] ?? null;
    if (!$best || $best['score'] < AIDE_CHAT_MIN_SCORE || $best['coverage'] < AIDE_CHAT_MIN_COVERAGE) {
        $message = $contact !== ''
            ? "Je ne trouve pas cette réponse dans le centre d'aide. Écrivez-nous à l'adresse de contact ci-dessous, ou utilisez le formulaire de contact d'une fiche produit quand la boutique l'a activé."
            : "Je ne trouve pas cette réponse dans le centre d'aide. Utilisez le formulaire de contact d'une fiche produit quand la boutique l'a activé, ou consultez les conditions d'utilisation pour joindre la plateforme.";
        return $refusal($message);
    }
    $article = $best['article'];
    $tutoriel = null;
    foreach ($results as $result) {
        if ($result['article']['type'] === 'tutoriel') { $tutoriel = $result['article']; break; }
    }
    $message = 'D’après le centre d’aide : « ' . $article['titre'] . ' ». ' . $article['resume'];
    return [
        'ok' => true,
        'message' => $message,
        'article' => $article,
        'tutoriel' => $tutoriel,
        'score' => $best['score'],
        'coverage' => $best['coverage'],
        'contact' => $contact,
        'mailto' => $mailto,
    ];
}

// ── Sérialisation pour le JavaScript ──────────────────────────────────────

function aide_json_flags(): int
{
    return JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
}

function aide_article_for_json(array $article): array
{
    return [
        'slug' => $article['slug'],
        'type' => $article['type'],
        'role' => $article['role'],
        'role_label' => $article['role_label'],
        'titre' => $article['titre'],
        'resume' => $article['resume'],
        'mots_cles' => $article['mots_cles'],
        'etapes' => array_map('aide_step_text', $article['etapes']),
        'erreurs' => $article['erreurs'],
        'prerequis' => $article['prerequis'],
        'section' => $article['section'],
        'url' => $article['url'],
        'url_cible' => $article['url_cible'],
        'ecart' => $article['ecart'],
        'liens' => $article['liens'],
        'tutoriel' => $article['tutoriel'],
    ];
}

function aide_corpus_json(): string
{
    $items = array_map('aide_article_for_json', aide_articles());
    return json_encode(['articles' => $items, 'contact' => aide_contact(), 'mailto' => aide_contact_mailto()], aide_json_flags()) ?: '{"articles":[]}';
}

function aide_index_json(): string
{
    $items = array_map('aide_article_for_json', aide_articles());
    return json_encode([
        'generated_at' => gmdate('c'),
        'count' => count($items),
        'articles' => $items,
    ], aide_json_flags()) ?: '{"articles":[]}';
}

// ── Helpers d'affichage ────────────────────────────────────────────────────

function aide_capture_exists(array $capture): bool
{
    return is_file(__DIR__ . '/assets/captures/' . $capture['fichier']);
}

function aide_capture_url(array $capture): string
{
    return '/aide/assets/captures/' . rawurlencode($capture['fichier']);
}

function aide_video_exists(array $tutorial): bool
{
    return !empty($tutorial['video']) && is_file(__DIR__ . '/assets/videos/' . $tutorial['video']['slug'] . '.mp4');
}

function aide_vtt_exists(array $tutorial): bool
{
    return !empty($tutorial['video']) && is_file(__DIR__ . '/assets/videos/' . $tutorial['video']['slug'] . '.vtt');
}

function aide_type_label(string $type): string
{
    return $type === 'tutoriel' ? 'Tutoriel' : ($type === 'glossaire' ? 'Glossaire' : 'Question');
}

function aide_related(array $article, int $limit = 3): array
{
    $tokens = aide_tokens($article['titre'] . ' ' . implode(' ', $article['mots_cles']));
    $out = [];
    foreach (aide_articles() as $other) {
        if ($other['slug'] === $article['slug']) continue;
        $fields = aide_article_fields($other);
        $score = 0;
        foreach ($tokens as $token) {
            if (aide_contains_token($fields['mots'], $token)) $score += 2;
            if (aide_contains_token($fields['titre'], $token)) $score += 1;
        }
        if ($score > 0) $out[] = ['article' => $other, 'score' => $score];
    }
    usort($out, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_slice(array_column($out, 'article'), 0, $limit);
}
