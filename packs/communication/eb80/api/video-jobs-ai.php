<?php
// Copie d'electroboy80 (voir packs/communication/tools/extract-eb80.php) : ne pas modifier ici, relancer l'extraction.
defined('PLUGIN_RUNTIME') || exit;
/**
 * ELECTROBOY 80 — Vidéo promo assistée par IA
 *
 * POST /api/video-jobs/ai   (admin, editor)
 *   { infos: { date, heure, lieu, ville, mentions[], texteInfos, lienInfos },
 *     consigne, duree (secondes), scenarioActuel: [types…], variantes: 1 ou 3 }
 *   → { variantes: [{ nom, props: { scenes[], mentions, texteInfos, palette, badge, musique },
 *                     legende, hashtags[], explication }], duree, modele }
 *     (la première variante est aussi recopiée à la racine : props, legende, hashtags, explication)
 *
 * Claude reçoit les infos du concert, la consigne, la durée visée et les vignettes des images
 * de la médiathèque ; il écrit un scénario scène par scène (types, durées, textes, photos),
 * différent du scénario actuel. La réponse suit un schéma JSON (sorties structurées).
 * Clé : réglage claude_api_key (ou anthropic_api_key), comme api/ai-generate.php.
 */

require_once __DIR__ . '/../compat.php';

const VIA_MODELE      = 'claude-opus-5-5';
const VIA_MAX_IMAGES  = 30;   // images les plus récentes envoyées à Claude
const VIA_VIGNETTE_PX = 320;  // côté max des vignettes
// Palettes de toute la vidéo, identiques à PALETTES de theme.ts (remotion-videos).
const VIA_PALETTES    = ['neon', 'chaud', 'froid', 'acide', 'miami', 'mono'];
const VIA_TYPES       = ['titre', 'photo', 'mots', 'compte', 'concert', 'fin'];
const VIA_DUREES      = [10, 15, 20, 30];
// Variantes d'animation par type de scène (la première : défaut) et effets d'entrée,
// identiques à scenario.ts du dépôt remotion-videos.
const VIA_ANIMATIONS  = [
    'titre'   => ['zoom', 'glitch', 'machine', 'chute'],
    'photo'   => ['dezoom', 'zoom', 'glisse', 'glitch', 'strobo'],
    'mots'    => ['claque', 'un-par-un', 'machine'],
    'compte'  => ['rebond', 'glitch', 'machine'],
    'concert' => [],
    'fin'     => [],
];
const VIA_TRANSITIONS = ['coupe', 'fondu', 'flash', 'glitch', 'balayage', 'zoom'];

if (getMethod() !== 'POST' || rtrim(getPath(), '/') !== '/video-jobs/ai') {
    errorResponse('Route non trouvée [' . getMethod() . ' ' . getPath() . ']', 404);
}
requireAuth(['admin', 'editor']);

$in       = getJsonInput();
$infos    = is_array($in['infos'] ?? null) ? $in['infos'] : [];
$consigne = mb_substr(trim((string)($in['consigne'] ?? '')), 0, 1000);
$duree    = in_array((int)($in['duree'] ?? 0), VIA_DUREES, true) ? (int)$in['duree'] : 15;
$nbVariantes = (int)($in['variantes'] ?? 1) === 3 ? 3 : 1;
$actuel   = array_values(array_filter((array)($in['scenarioActuel'] ?? []), fn($t) => in_array($t, VIA_TYPES, true)));

// Sujet alimenté depuis l'admin (article merch, partenaire) ; absent = concert, comme avant.
$sujet = is_array($in['sujet'] ?? null) && in_array($in['sujet']['profil'] ?? '', ['produit', 'organisation', 'generique'], true) ? $in['sujet'] : eb80_via_sujet_boutique();
$sujetPrio = $sujet ? array_values(array_filter(array_map(
    fn($u) => strtolower(preg_replace('/_web$/', '', pathinfo((string)parse_url((string)$u, PHP_URL_PATH), PATHINFO_FILENAME))),
    array_slice((array)($sujet['photos'] ?? []), 0, 8)))) : [];

$cle = eb80_via_cle(); // Gemini (quota de la boutique) ou Claude, selon API & Réseaux → IA ; quota vérifié

// Visuel du lieu (photo, ou image extraite d'une vidéo dans le navigateur) : JPEG en data URL.
$visuel = null;
if (preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', (string)($in['visuelLieu'] ?? ''), $mv)
    && strlen($mv[1]) < 2000000 && @getimagesizefromstring(base64_decode($mv[1]))) {
    $visuel = $mv[1];
}

// ── Mode « carte du lieu » : accorder la charte au visuel, sans écrire de scénario ──
if (($in['mode'] ?? '') === 'charte') {
    if (!$visuel) errorResponse('Choisissez d\'abord un visuel du lieu (photo ou vidéo).', 400);
    $champ = fn($k) => mb_substr(trim((string)($infos[$k] ?? '')), 0, 200);
    $systeme = "Tu es directeur artistique pour " . eb80_via_identite_courte() . ". On te montre un visuel "
        . "de la boutique (photo du lieu, de sa façade, de sa vitrine ou de sa communication). Tu proposes "
        . "l'habillage de la carte d'information aux couleurs de la boutique : une couleur d'accent prise dans le visuel "
        . "(#RRGGBB, assez saturée et foncée pour se lire sur un fond papier clair), une petite étiquette au-dessus "
        . "de la date (2 à 4 mots en français, ex. « EN CONCERT AU », « CE SOIR AU », « RENDEZ-VOUS AU »), et la "
        . "palette de la vidéo qui s'accorde le mieux au lieu parmi : neon, chaud, froid, acide, miami, mono. "
        . "explication : une phrase pour l'équipe sur l'ambiance retenue.";
    $contenu = [
        ['type' => 'text', 'text' => "Lieu : " . $champ('lieu') . " (" . $champ('ville') . "), concert le " . $champ('date') . ".\nVisuel du lieu :"],
        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $visuel]],
    ];
    $schemaCharte = [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['accent', 'label', 'palette', 'explication'],
        'properties' => [
            'accent' => ['type' => 'string'], 'label' => ['type' => 'string'],
            'palette' => ['type' => 'string', 'enum' => VIA_PALETTES], 'explication' => ['type' => 'string'],
        ],
    ];
    [$r, $brut] = viaClaude($cle, $systeme, $contenu, $schemaCharte, 2000, 90);
    jsonResponse([
        'accent'      => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($r['accent'] ?? '')) ? strtoupper($r['accent']) : '#F87A00',
        'label'       => mb_strtoupper(mb_substr(trim((string)($r['label'] ?? '')), 0, 30)),
        'palette'     => in_array($r['palette'] ?? '', VIA_PALETTES, true) ? $r['palette'] : 'neon',
        'explication' => mb_substr(trim((string)($r['explication'] ?? '')), 0, 400),
        'modele'      => $brut['model'] ?? VIA_MODELE,
    ]);
}

// ── Images de la médiathèque ─────────────────────────────────────────────────
$images = viaImages($sujetPrio);
$audios = viaAudios();
if (!$images) errorResponse('Aucune image dans la médiathèque', 400);

// ── Prompt ───────────────────────────────────────────────────────────────────
$champ = fn($k) => mb_substr(trim((string)($infos[$k] ?? '')), 0, 200);
$mentions = array_slice(array_map(fn($m) => mb_substr((string)$m, 0, 40), (array)($infos['mentions'] ?? [])), 0, 6);

$eb80Identite = eb80_via_identite();
$systeme = <<<TXT
{$eb80Identite}

Tu écris le scénario d'une vidéo promo verticale pour un concert : une suite de scènes prises dans
cette bibliothèque (champs inutilisés : chaîne vide ou liste vide) :
- titre   : grand titre glitch. texte = titre (« \n » pour aller à la ligne, 10 caractères max par ligne),
            texte2 = petit surtitre jaune dessous (peut être vide).
- photo   : une photo plein écran teintée. photo = identifiant IMG-n, texte = accroche ligne 1
            (22 caractères max), texte2 = accroche ligne 2 courte et percutante (14 max) ; textes facultatifs.
            cadrage = recadrage dans la vidéo verticale : x et y (0 à 100) = point de l'image à garder au
            centre, en % de la largeur et de la hauteur (le visage, les lunettes, le musicien) ; zoom de 1
            (image entière) à 2,5 (gros plan). Écarte les bords blancs, le vide, les autres personnes.
            Pour les autres types de scène : x 50, y 50, zoom 1.
- mots    : mots-chocs qui tombent un par un sur le temps. mots = 1 à 4 mots courts,
            bandeau = texte qui défile en haut et en bas (noms d'influences, slogan…) ou vide.
- compte  : compte à rebours plein écran. texte = « J-5 », « DEMAIN », « CE SOIR »…, texte2 = sous-texte.
- concert : carte du concert (date, heure, lieu, ville, étiquettes sont fournis). texte2 = petite
            étiquette au-dessus de la date (« PROCHAIN CONCERT », « RENDEZ-VOUS »…).
- fin     : écran final avec l'appel à l'action et le lien. texte = titre de fin (court).

Chaque scène a une animation (anim) et un effet d'entrée (transition) :
- anim, selon le type (chaîne vide pour concert et fin) :
  titre  : zoom (arrive en grand et se pose), glitch (clignote puis se fige), machine (lettre à lettre),
           chute (les lignes tombent l'une après l'autre) ;
  photo  : dezoom (recul lent), zoom (avancée lente), glisse (travelling latéral), glitch (sauts de
           couleur et de cadre), strobo (éclats lumineux sur chaque temps) ;
  mots   : claque (les mots s'empilent en claquant), un-par-un (un seul mot plein écran à la fois),
           machine (chaque mot tapé lettre à lettre) ;
  compte : rebond (entre en rebondissant), glitch, machine.
- transition, à l'entrée de la scène : coupe (cut sec), fondu (depuis le noir), flash (blanc),
  glitch (brouillage), balayage (volet latéral rose), zoom (arrive en zoomant).
  Le cut sec reste le plus nerveux : réserve les effets aux changements de partie, pas à chaque scène.

Règles du scénario :
- La somme des durées doit faire exactement {$duree} secondes.
- Un temps dure 0,353 s : cale les durées sur des multiples (0,7 s ; 1,06 s ; 1,41 s ; 2,12 s ; 2,82 s…).
- Exactement une scène concert (au moins 3 s, pour qu'on lise les infos) et une scène fin à la toute fin
  (au moins 3 s : c'est l'appel à l'action et le lien, il faut le temps de les lire).
- 2 à 6 scènes photo selon la durée, chacune entre 0,7 et 2,5 s ; jamais deux fois la même photo d'affilée.
- Accroche dans la première seconde : commence par une scène forte (titre, compte, mots ou photo avec texte).
- Varie la construction : ne recopie pas le schéma « titre, photos, mots, concert, fin » s'il t'est
  signalé comme scénario actuel ; ose l'inattendu (ouvrir sur une photo, alterner photo et mots…).

Règles de rédaction : français, pas d'emoji dans la vidéo, la vidéo met en capitales elle-même.
- photos : uniquement des photos nettes du sujet ou de la boutique ; jamais un logo, une
  affiche, un visuel de lieu, une texture ou une image contenant une date imprimée.
- mentions : 2 ou 3 étiquettes de style de 1 à 2 mots (garde celles fournies si elles conviennent).
- texteInfos : appel final au plus 22 caractères ; ne promets pas de billetterie si rien n'en parle.
- palette de couleurs de toute la vidéo (fonds, titres, accents, filtre des photos) :
  « neon » (rose, jaune, cyan sur noir),
  « chaud » (rouge, orange, ambre), « froid » (bleus, cyan, violet), « acide » (vert acide, magenta),
  « miami » (rose bonbon, turquoise, violet, façon Miami Vice), « mono » (noir et blanc, accent rouge,
  photos en noir et blanc).
- badge : pastille sur la carte du concert (« J-5 », « GRATUIT »…) ou chaîne vide.
- musique : nom exact d'un des fichiers audio listés, le plus adapté à une promo énergique
  ou chaîne vide s'il n'y en a aucun.
- legende : légende Instagram de 2 à 4 phrases avec date, lieu et appel à l'action (emoji permis).
- hashtags : 6 à 8 hashtags pertinents, sans le #.
- explication : une phrase qui présente l'idée du scénario, pour l'équipe.
- accentLieu : si un visuel du lieu est fourni, couleur d'accent #RRGGBB prise dans ce visuel pour la carte
  du lieu (lisible sur fond papier clair) ; sinon chaîne vide.
TXT;
if ($sujet) {
    $profil = $sujet['profil'];
    $quoi = ['produit' => 'un PRODUIT / ARTICLE à vendre', 'organisation' => 'un PARTENAIRE ou une ORGANISATION', 'generique' => 'une FICHE (contenu du site)'][$profil];
    $carteDate  = $profil === 'produit' ? 'le prix' : 'la catégorie du sujet';
    $carteVille = $profil === 'produit' ? 'un mot court (« BOUTIQUE »…) ou vide' : 'la ville du sujet';
    $etiquettes = ['produit' => '« NOUVEAUTÉ », « EN BOUTIQUE »', 'organisation' => '« PARTENAIRE », « MERCI À »', 'generique' => '« À DÉCOUVRIR », « NOUVEAU »'][$profil];
    $systeme .= "\n\nATTENTION : le sujet de cette vidéo n'est PAS un concert, c'est $quoi. Ignore tout ce qui concerne "
        . "un concert (date, salle, compte à rebours, « prochain concert »). Mets le sujet en valeur sans rien inventer : "
        . "uniquement les informations fournies ci-dessous.\n"
        . "- La scène « concert » devient la CARTE D'INFORMATION du sujet : date = $carteDate, heure = vide, lieu = le nom, "
        . "ville = $carteVille ; texte2 = étiquette courte ($etiquettes).\n"
        . "- Évite la scène « compte » (pas de J-n). Le badge reste vide sauf information fournie.\n"
        . "- Les photos marquées « photo du sujet » sont à privilégier pour les scènes photo.\n"
        . "- legende : cite le nom du sujet" . ($profil === 'produit' ? ', son prix et le lien fourni' : ' et le lien fourni s\'il existe') . ".";
}
$systeme .= $nbVariantes > 1
    ? "\n\nPropose exactement $nbVariantes variantes nettement différentes les unes des autres : construction,"
      . " rythme, animations, transitions, palette de couleurs et musique (par exemple une énergique et saccadée, une plus"
      . " cinématique, une rétro machine à écrire). Donne à chacune un nom court (1 à 2 mots)."
    : "\n\nPropose une seule variante (liste variantes d'un élément) ; nom = un mot qui la résume.";

$sujetTxt = $sujet ? "Sujet de la vidéo (" . ['produit' => 'produit', 'organisation' => 'partenaire / organisation', 'generique' => 'fiche'][$sujet['profil']] . ") :\n"
    . mb_substr(trim((string)($sujet['contexte'] ?? '')), 0, 1200) . "\n\nChamps de la carte d'information :\n" : "Concert :\n";
$texte = "Aujourd'hui : " . date('Y-m-d') . " (" . viaJour() . ").\n\n" . $sujetTxt
    . "- date : " . $champ('date') . "\n"
    . "- heure : " . $champ('heure') . "\n"
    . "- lieu : " . $champ('lieu') . "\n"
    . "- ville : " . $champ('ville') . "\n"
    . "- étiquettes actuelles : " . implode(', ', $mentions) . "\n"
    . "- appel final actuel : " . $champ('texteInfos') . " / lien : " . $champ('lienInfos') . "\n"
    . "\nDurée visée : $duree secondes.\n"
    . ($actuel ? "Scénario actuel (à ne pas reproduire) : " . implode(' → ', $actuel) . "\n" : '')
    . ($consigne !== '' ? "\nConsigne de l'équipe : $consigne\n" : '')
    . "\nFichiers audio de la médiathèque : " . ($audios ? implode(', ', array_keys($audios)) : 'aucun') . "\n"
    . "\nImages disponibles dans la médiathèque (identifiant puis nom de fichier) :";

$contenu = [['type' => 'text', 'text' => $texte]];
if ($visuel) {
    $contenu[] = ['type' => 'text', 'text' => "Visuel du lieu du concert (ne pas l'utiliser comme photo de scène) : "
        . "inspire-toi de ses couleurs et de son ambiance pour la palette, l'étiquette de la carte concert et accentLieu."];
    $contenu[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $visuel]];
    $contenu[] = ['type' => 'text', 'text' => "Médiathèque :"];
}
foreach ($images as $i => $img) {
    $estSujet = $sujetPrio && in_array(strtolower(preg_replace('/_web$/', '', pathinfo($img['name'], PATHINFO_FILENAME))), $sujetPrio, true);
    $contenu[] = ['type' => 'text', 'text' => "IMG-$i : {$img['name']}" . ($estSujet ? ' (photo du sujet)' : '')];
    $contenu[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $img['b64']]];
}

$schemaScene = [
    'type' => 'object',
    'additionalProperties' => false,
    'required' => ['type', 'duree', 'texte', 'texte2', 'photo', 'mots', 'bandeau', 'anim', 'transition', 'cadrage'],
    'properties' => [
        'type'    => ['type' => 'string', 'enum' => VIA_TYPES],
        'anim'    => ['type' => 'string', 'enum' => array_merge([''], array_values(array_unique(array_merge(...array_values(VIA_ANIMATIONS)))))],
        'transition' => ['type' => 'string', 'enum' => VIA_TRANSITIONS],
        'cadrage' => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['x', 'y', 'zoom'],
            'properties' => ['x' => ['type' => 'number'], 'y' => ['type' => 'number'], 'zoom' => ['type' => 'number']],
        ],
        'duree'   => ['type' => 'number'],
        'texte'   => ['type' => 'string'],
        'texte2'  => ['type' => 'string'],
        'photo'   => ['type' => 'string'],
        'mots'    => ['type' => 'array', 'items' => ['type' => 'string']],
        'bandeau' => ['type' => 'string'],
    ],
];
$schemaVariante = [
    'type' => 'object',
    'additionalProperties' => false,
    'required' => ['nom', 'scenes', 'mentions', 'texteInfos', 'palette', 'badge', 'musique', 'legende', 'hashtags', 'explication', 'accentLieu'],
    'properties' => [
        'nom'         => ['type' => 'string'],
        'scenes'      => ['type' => 'array', 'items' => $schemaScene],
        'mentions'    => ['type' => 'array', 'items' => ['type' => 'string']],
        'texteInfos'  => ['type' => 'string'],
        'palette'     => ['type' => 'string', 'enum' => VIA_PALETTES],
        'badge'       => ['type' => 'string'],
        'musique'     => ['type' => 'string'],
        'legende'     => ['type' => 'string'],
        'hashtags'    => ['type' => 'array', 'items' => ['type' => 'string']],
        'explication' => ['type' => 'string'],
        'accentLieu'  => ['type' => 'string'],
    ],
];
$schema = [
    'type' => 'object',
    'additionalProperties' => false,
    'required' => ['variantes'],
    'properties' => ['variantes' => ['type' => 'array', 'items' => $schemaVariante]],
];

// ── Appel Claude ─────────────────────────────────────────────────────────────
set_time_limit(260); // trois variantes : la réponse peut prendre une à deux minutes
[$json, $rep] = viaClaude($cle, $systeme, $contenu, $schema, 16000, 240);

// ── Contrôle et mise en forme ────────────────────────────────────────────────
$court = fn($v, $n) => mb_substr(trim((string)$v), 0, $n);

$variantes = [];
foreach (array_slice((array)($json['variantes'] ?? []), 0, $nbVariantes) as $i => $v) {
    if (!is_array($v)) continue;
    $scenes = viaScenes((array)($v['scenes'] ?? []), $images, $duree);
    if (!array_filter($scenes, fn($sc) => $sc['type'] === 'photo')) continue;
    $variantes[] = [
        'nom'   => $court($v['nom'] ?? '', 30) ?: 'Variante ' . ($i + 1),
        'props' => [
            'scenes'     => $scenes,
            'mentions'   => array_slice(array_map(fn($m) => $court($m, 30), (array)($v['mentions'] ?? [])), 0, 4),
            'texteInfos' => $court($v['texteInfos'] ?? '', 40),
            'palette'    => in_array($v['palette'] ?? '', VIA_PALETTES, true) ? $v['palette'] : 'neon',
            'badge'      => $court($v['badge'] ?? '', 14),
            'musique'    => isset($audios[trim((string)($v['musique'] ?? ''))])
                ? ['src' => $audios[trim((string)$v['musique'])], 'debut' => 0, 'volume' => 0.9]
                : null,
        ],
        'legende'     => $court($v['legende'] ?? '', 2000),
        'hashtags'    => array_slice(array_map(fn($h) => ltrim($court($h, 40), '#'), (array)($v['hashtags'] ?? [])), 0, 10),
        'explication' => $court($v['explication'] ?? '', 500),
        'accentLieu'  => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($v['accentLieu'] ?? '')) ? strtoupper($v['accentLieu']) : '',
    ];
}
if (!$variantes) {
    errorResponse("L'IA n'a retenu aucune photo exploitable ; ajoutez des photos des pièces à la médiathèque.", 502);
}

jsonResponse([
    'variantes' => $variantes,
    'duree'     => $duree,
    'modele'    => $rep['model'] ?? VIA_MODELE,
] + array_diff_key($variantes[0], ['nom' => 1]));

// ── Fonctions ────────────────────────────────────────────────────────────────

/** Images récentes de uploads/ avec une vignette JPEG en base64 (pour la vision de Claude). */
function viaImages(array $prio = []): array {
    if (!is_dir(UPLOAD_DIR)) return [];
    $fichiers = [];
    foreach (new DirectoryIterator(UPLOAD_DIR) as $f) {
        if (!$f->isFile()) continue;
        $nom = $f->getFilename();
        if (preg_match('/_web\.jpg$/i', $nom) || !preg_match('/\.(jpe?g|png|webp)$/i', $nom)) continue;
        $fichiers[$nom] = $f->getMTime();
    }
    arsort($fichiers);
    // Photos du sujet en tête de liste, même si elles sont anciennes.
    $noms = array_keys($fichiers);
    if ($prio) {
        $tete = array_values(array_filter($noms, fn($n) => in_array(strtolower(pathinfo($n, PATHINFO_FILENAME)), $prio, true)));
        $noms = array_merge($tete, array_diff($noms, $tete));
    }
    $images = [];
    foreach (array_slice($noms, 0, VIA_MAX_IMAGES) as $nom) {
        $base = pathinfo($nom, PATHINFO_FILENAME);
        $web  = $base . '_web.jpg';
        // La version _web (1200 px) est plus légère à lire ; c'est aussi elle qu'utilisera la vidéo.
        $source = is_file(UPLOAD_DIR . $web) ? $web : $nom;
        $b64 = viaVignette(UPLOAD_DIR . $source);
        if ($b64) $images[] = ['name' => $nom, 'url' => UPLOAD_URL . $source, 'b64' => $b64];
    }
    return $images;
}

/**
 * Contrôle le scénario de Claude : types connus, photos existantes, une scène concert,
 * une fin en dernier, puis durées ramenées exactement à la durée visée.
 */
function viaScenes(array $brutes, array $images, int $duree): array {
    $court = fn($v, $n) => mb_substr(trim((string)$v), 0, $n);
    $scenes = [];
    foreach (array_slice($brutes, 0, 16) as $b) {
        $type = $b['type'] ?? '';
        if (!in_array($type, VIA_TYPES, true)) continue;
        $sc = ['type' => $type, 'duree' => max(0.5, (float)($b['duree'] ?? 1))];
        if (in_array($b['anim'] ?? '', VIA_ANIMATIONS[$type], true)) $sc['anim'] = $b['anim'];
        if (in_array($b['transition'] ?? '', VIA_TRANSITIONS, true) && $b['transition'] !== 'coupe') {
            $sc['transition'] = $b['transition'];
        }
        foreach (['texte' => 40, 'texte2' => 40, 'bandeau' => 120] as $k => $n) {
            if (($v = $court($b[$k] ?? '', $n)) !== '') $sc[$k] = $v;
        }
        if ($type === 'photo') {
            if (!preg_match('/^IMG-(\d+)$/', trim((string)($b['photo'] ?? '')), $m) || !isset($images[(int)$m[1]])) continue;
            $sc['photo'] = $images[(int)$m[1]]['url'];
            if ($cadrage = viaCadrage($b['cadrage'] ?? null)) $sc['cadrage'] = $cadrage;
        }
        if ($type === 'mots') {
            $sc['mots'] = array_slice(array_values(array_filter(array_map(fn($w) => $court($w, 16), (array)($b['mots'] ?? [])), 'strlen')), 0, 4);
            if (!$sc['mots']) continue;
        }
        $scenes[] = $sc;
    }
    // Une seule carte concert, une fin en dernier.
    $concert = array_values(array_filter($scenes, fn($sc) => $sc['type'] === 'concert'))[0] ?? ['type' => 'concert', 'duree' => 3];
    $fin     = array_values(array_filter($scenes, fn($sc) => $sc['type'] === 'fin'))[0] ?? ['type' => 'fin', 'duree' => 1.5];
    $autres  = array_values(array_filter($scenes, fn($sc) => !in_array($sc['type'], ['concert', 'fin'], true)));
    $posConcert = null;
    foreach ($scenes as $i => $sc) if ($sc['type'] === 'concert') { $posConcert = $i; break; }
    $avant = $posConcert === null ? count($autres)
        : count(array_filter(array_slice($scenes, 0, $posConcert), fn($sc) => $sc['type'] !== 'fin'));
    $scenes = array_merge(array_slice($autres, 0, $avant), [$concert], array_slice($autres, $avant), [$fin]);

    // Durées ramenées à la durée visée. La carte concert et l'écran de fin gardent 3 s au moins
    // (le temps de lire la date, le lien) ; les autres scènes se partagent le reste, 0,6 s au moins.
    $mini = fn($sc) => in_array($sc['type'], ['concert', 'fin'], true) ? 3.0 : 0.6;
    $total = array_sum(array_column($scenes, 'duree'));
    foreach ($scenes as &$sc) {
        $sc['duree'] = max($mini($sc), round($sc['duree'] * $duree / $total, 2));
    }
    unset($sc);
    $autres = array_keys(array_filter($scenes, fn($sc) => !in_array($sc['type'], ['concert', 'fin'], true)));
    $ecart = round($duree - array_sum(array_column($scenes, 'duree')), 2);
    $souples = array_sum(array_map(fn($k) => $scenes[$k]['duree'] - 0.6, $autres));
    if ($ecart < 0 && $souples > 0) {
        // Trop long : on raccourcit les autres scènes au prorata de leur marge.
        $part = min(1, -$ecart / $souples);
        foreach ($autres as $k) $scenes[$k]['duree'] = round($scenes[$k]['duree'] - ($scenes[$k]['duree'] - 0.6) * $part, 2);
    }
    // Reliquat d'arrondi (ou excédent) sur la carte concert.
    $ecart = round($duree - array_sum(array_column($scenes, 'duree')), 2);
    $i = array_search('concert', array_column($scenes, 'type'), true);
    $scenes[$i]['duree'] = max(3.0, round($scenes[$i]['duree'] + $ecart, 2));
    return $scenes;
}

/**
 * Appel à Claude avec sortie JSON imposée par $schema. Renvoie [json décodé, réponse brute] ;
 * arrête la requête avec un message clair en cas d'échec.
 */
function _eb80_orig_viaClaude(string $cle, string $systeme, array $contenu, array $schema, int $maxTokens, int $delai): array {
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'         => VIA_MODELE,
            'max_tokens'    => $maxTokens,
            'system'        => $systeme,
            'messages'      => [['role' => 'user', 'content' => $contenu]],
            'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            // En cas de refus par les filtres de sécurité, l'API rejoue la demande sur un autre modèle.
            'fallbacks'     => 'default',
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $cle,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: server-side-fallback-2026-07-01',
        ],
        CURLOPT_TIMEOUT        => $delai,
    ]);
    $brut = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($err) errorResponse('Erreur réseau Claude : ' . $err, 502);
    $rep = json_decode((string)$brut, true);
    if ($code >= 400 || isset($rep['error'])) {
        errorResponse('Claude API : ' . ($rep['error']['message'] ?? "HTTP $code"), 502);
    }
    if (($rep['stop_reason'] ?? '') === 'refusal') errorResponse("Claude a décliné la demande ; reformulez la consigne.", 502);
    if (($rep['stop_reason'] ?? '') === 'max_tokens') errorResponse('Réponse de Claude tronquée, réessayez.', 502);

    $json = null;
    foreach ($rep['content'] ?? [] as $bloc) {
        if (($bloc['type'] ?? '') === 'text') { $json = json_decode($bloc['text'], true); break; }
    }
    if (!is_array($json)) errorResponse('Réponse de Claude illisible', 502);
    return [$json, $rep];
}

/** Recadrage proposé, borné ; null s'il ne change rien (centre, zoom 1). */
function viaCadrage($c): ?array {
    if (!is_array($c)) return null;
    $borne = fn($v, $a, $b, $d) => is_numeric($v) ? max($a, min($b, round((float)$v, 2))) : $d;
    $cad = ['x' => $borne($c['x'] ?? null, 0, 100, 50), 'y' => $borne($c['y'] ?? null, 0, 100, 50), 'zoom' => $borne($c['zoom'] ?? null, 1, 3, 1)];
    return $cad == ['x' => 50, 'y' => 50, 'zoom' => 1] ? null : $cad;
}

/** Fichiers audio de uploads/ (nom → URL), les plus récents d'abord. */
function viaAudios(): array {
    if (!is_dir(UPLOAD_DIR)) return [];
    $fichiers = [];
    foreach (new DirectoryIterator(UPLOAD_DIR) as $f) {
        if ($f->isFile() && preg_match('/\.(mp3|m4a|aac|wav|ogg|flac)$/i', $f->getFilename())) {
            $fichiers[$f->getFilename()] = $f->getMTime();
        }
    }
    arsort($fichiers);
    $audios = [];
    foreach (array_slice(array_keys($fichiers), 0, 40) as $nom) $audios[$nom] = UPLOAD_URL . $nom;
    return $audios;
}

function viaVignette(string $chemin): ?string {
    $ext = strtolower(pathinfo($chemin, PATHINFO_EXTENSION));
    $im = match ($ext) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($chemin),
        'png'         => @imagecreatefrompng($chemin),
        'webp'        => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($chemin) : false,
        default       => false,
    };
    if (!$im) return null;
    $w = imagesx($im);
    $h = imagesy($im);
    $r = min(1, VIA_VIGNETTE_PX / max($w, $h));
    $nw = max(1, (int)round($w * $r));
    $nh = max(1, (int)round($h * $r));
    $out = imagecreatetruecolor($nw, $nh);
    // Fond blanc pour les PNG transparents (logos), sinon ils deviennent noirs.
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start();
    imagejpeg($out, null, 75);
    $data = ob_get_clean();


    return base64_encode($data);
}

function viaJour(): string {
    return ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][(int)date('w')];
}
