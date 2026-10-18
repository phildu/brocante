<?php
// Portage fidèle d'electroboy80 dans le pack Communication : recopie l'interface d'administration d'electroboy80 et les fichiers
// serveur de sa médiathèque, avec des retouches listées ici et rien d'autre. À relancer quand electroboy80 évolue :
//
//   php packs/communication/tools/extract-eb80.php [--source=/chemin/vers/electroboy80]
//   php packs/communication/tools/extract-eb80.php --status   (copie à jour ? réponse JSON : fait toute la recopie en mémoire,
//                                                            sans rien écrire, et compare son empreinte à celle de SOURCE.txt)
//
// Le portail local (portail → Plugins, entrée « sync » du manifeste) lance ce même script.
//
// Produit (et écrase) :
//   packs/communication/assets/eb80/admin.html  l'admin d'electroboy80, servie dans le cadre de la page Médiathèque ;
//                                               eb80-shim.js (au début) et eb80-after.js (à la fin) l'adaptent à la boutique
//   packs/communication/assets/eb80/js/…, plugin/…  ses scripts et styles : tous ceux que la page charge (<script src="/…">,
//                                               <link href="/…">), recopiés au même chemin, et le lecteur de la vidéo IA
//   packs/communication/eb80/api/*.php          config.php, media.php, media-links.php, generate-web-images.php, settings.php,
//                                               ai-generate.php (script vidéo par l'IA), rs-v2.php, rs-schedule.php,
//                                               rs-tiktok-callback.php, social-feed.php (publication sur les réseaux),
//                                               video-jobs.php, video-jobs-ai.php (Vidéo IA : file de rendu, scénario),
//                                               palettes.php (palettes des visuels générées par l'IA)
//                                               (et eb80/reglages-publics.php, le filtre des réglages publics) ;
//                                               packs/communication/eb80/compat.php remplace les fonctions renommées ici
//   packs/communication/eb80/SOURCE.txt         d'où vient la copie (dossier, commit, date)
// Une retouche dont le motif n'est plus trouvé arrête tout, avant toute écriture : la copie reste entière, telle qu'avant ;
// electroboy80 a changé à cet endroit, il faut revoir la retouche.
PHP_SAPI === 'cli' || exit;
date_default_timezone_set('Europe/Paris'); // dates de SOURCE.txt à l'heure française, quel que soit le PHP

$pack = dirname(__DIR__);
$opts = getopt('', ['source:', 'status']);
$source = $opts['source'] ?? '';
if ($source === '') {
    // Dossier frère du dépôt (…/web local/electroboy80), y compris depuis un worktree rangé dans .claude/worktrees/.
    foreach ([dirname($pack, 3) . '/electroboy80', dirname($pack, 6) . '/electroboy80'] as $guess) {
        if (is_file($guess . '/admin.html')) { $source = $guess; break; }
    }
}
$source = rtrim((string) realpath($source), '/');
if ($source === '' || !is_file($source . '/admin.html') || !is_file($source . '/api/media.php')) {
    fwrite(STDERR, "Dossier electroboy80 introuvable : --source=/chemin/vers/electroboy80\n");
    exit(1);
}

/** Dernier commit d'electroboy80 (« hash date sujet ») et modifications pas encore commitées. */
function source_state(string $source): array
{
    $git = 'git -C ' . escapeshellarg($source);
    $commit = trim((string) @shell_exec($git . ' log -1 --format="%h %cd %s" --date=iso 2>/dev/null'));
    $dirty = trim((string) @shell_exec($git . ' status --porcelain --untracked-files=no 2>/dev/null')) !== '';
    return [$commit, $dirty];
}
// Fichiers d'electroboy80 lus pour la copie : leur empreinte (avec celle de ce script, donc des retouches) dit si la copie est à
// jour, modifications non commitées comprises.
$sourceHashes = ['(retouches) tools/extract-eb80.php' => md5_file(__FILE__)];
function src_read(string $path): string
{
    global $sourceHashes, $source;
    $content = (string) file_get_contents($path);
    $sourceHashes[substr($path, strlen($source) + 1)] = md5($content);
    return $content;
}

$log = [];
/** Remplace $search par $replace exactement $expected fois au moins (0 = au moins une fois), sinon arrête tout. */
function patch(string $what, string $text, string $search, string $replace, int $min = 1): string
{
    global $log;
    $n = substr_count($text, $search);
    if ($n < $min) {
        fwrite(STDERR, "Retouche impossible ($what) : motif « $search » trouvé $n fois.\n");
        exit(1);
    }
    $log[] = "$what : $n";
    return str_replace($search, $replace, $text);
}
/** Variante par expression régulière. */
function patch_re(string $what, string $text, string $pattern, string|callable $replace, int $min = 1): string
{
    global $log;
    $n = 0;
    $out = is_callable($replace) ? preg_replace_callback($pattern, $replace, $text, -1, $n) : preg_replace($pattern, $replace, $text, -1, $n);
    if ($out === null || $n < $min) {
        fwrite(STDERR, "Retouche impossible ($what) : motif $pattern trouvé $n fois.\n");
        exit(1);
    }
    $log[] = "$what : $n";
    return $out;
}
/** Appels sans effet depuis PHP 8 et obsolètes en PHP 8.5 (avertissements qui casseraient les réponses JSON) : retirés. */
function php85(string $php, string $what): string
{
    $php = patch_re("$what : curl_close retiré", $php, '/[ \t]*curl_close\(\$\w+\);[ \t]*\n?/', '', 0);
    return patch_re("$what : imagedestroy retiré", $php, '/[ \t]*imagedestroy\([^;]*\);[ \t]*/', '', 0);
}
/** Fichiers à écrire : gardés en mémoire, écrits seulement quand toutes les retouches ont réussi (voir la fin). */
$pending = [];
function write_file(string $path, string $content): void
{
    global $pending;
    $pending[$path] = $content;
}

// ── Navigateur : l'admin et ses scripts ─────────────────────────────────────
// Adresses des fichiers envoyés : la médiathèque d'une boutique est dans uploads/<boutique>/ (window.EB80_UPLOADS).
function patch_uploads(string $what, string $js, int $min = 0): string
{
    $js = patch($what . ' : /uploads/ + nom', $js, "'/uploads/' +", "(window.EB80_UPLOADS || '/uploads/') +", $min);
    return patch($what . ' : `/uploads/${…}`', $js, '`/uploads/${', '`${window.EB80_UPLOADS || \'/uploads/\'}${', 0);
}
// Couleurs écrites en dur (fonds, bordures, textes) : ramenées aux variables, que eb80-theme.css règle aux couleurs de la boutique.
function patch_colors(string $what, string $s): string
{
    $s = patch_re($what . ' : fonds sombres', $s, '/(background(?:-color)?\s*:\s*)(#000000|#000|#050505|#080808|#0a0a0a|#0a0a14|#0c0c0c|#0d0d0d|#0f0f0f|#111111|#111|#111122|#141414|#161616|#181818|#1a1a1a|#222222|#222|#2a2a2a|#333333|#333|#3a3a3a)\b/i', '$1var(--mid)', 0);
    // Fonds noirs semi-transparents (menus, barres, voiles) : fond clair de la boutique, presque opaque.
    $s = patch_re($what . ' : fonds noirs translucides', $s, '/(background(?:-color)?\s*:\s*)rgba\(\s*(?:0|10)\s*,\s*(?:0|10)\s*,\s*(?:0|10)\s*,\s*0?\.(?:[6-9]\d*)\s*\)/i', '$1color-mix(in srgb, var(--black) 96%, transparent)', 0);
    $s = patch_re($what . ' : bordures sombres', $s, '/(border(?:-(?:top|bottom|left|right))?\s*:\s*\d+px\s+(?:solid|dashed|dotted)\s+)(#1a1a1a|#222222|#222|#2a2a2a|#333333|#333|#444)\b/i', '$1var(--border)', 0);
    $s = patch_re($what . ' : textes clairs', $s, '/((?<![-\w])color\s*:\s*)(#ffffff|#fff|#f0f0f0|#eeeeee|#eee|#dddddd|#ddd)\b/i', '$1var(--white)', 0);
    return patch_re($what . ' : textes atténués', $s, '/((?<![-\w])color\s*:\s*)(#aaaaaa|#aaa|#999999|#999|#888888|#888|#777777|#777|#666666|#666|#555555|#555)\b/i', '$1var(--muted)', 0);
}

$html = src_read($source . '/admin.html');
// Fichiers d'adaptation chargés avec la version de la page (?v= de l'adresse du cadre, posée par lib/eb80-frame.php) : une
// mise à jour est prise en compte aussitôt, sans ancienne copie en cache. document.write garde le chargement synchrone.
$eb80Tag = static fn (string $tag): string => "<script>document.write(" . json_encode($tag) . ".replace('{v}', encodeURIComponent(new URLSearchParams(location.search).get('v') || '')))</script>";
$html = patch_re('admin.html : adaptation au début', $html, '/<head(?=[\s>])[^>]*>/i', static fn ($m) => $m[0] . "\n" . $eb80Tag('<script src="eb80-shim.js?v={v}"></scr' . 'ipt>'), 1);
if (substr_count($html, 'eb80-shim.js') !== 1) { fwrite(STDERR, "admin.html : plusieurs balises <head>\n"); exit(1); }
$html = patch('admin.html : couleurs de la boutique', $html, '</head>', $eb80Tag('<link rel="stylesheet" href="eb80-theme.css?v={v}">') . "\n</head>");
$pos = strripos($html, '</body>');
if ($pos === false) { fwrite(STDERR, "admin.html : </body> introuvable\n"); exit(1); }
$html = substr($html, 0, $pos) . $eb80Tag('<script src="eb80-after.js?v={v}"></scr' . 'ipt>') . "\n" . substr($html, $pos);
$log[] = 'admin.html : adaptation à la fin : 1';
// Scripts et styles du site chargés par la page (<script src="/…"> ou "js/…", <link href="…">) : chacun est recopié dans assets/eb80/
// (même chemin) et appelé en relatif, avec l'empreinte de son contenu. Un nouveau fichier d'electroboy80 suit donc tout seul ; s'il manque, tout s'arrête.
$assetFiles = [];
$html = patch_re('admin.html : scripts et styles en chemin relatif', $html,
    '#(<(?:script|link)\b[^>]*?\b(?:src|href)=")/?(?![/.]|[a-z][a-z0-9+.-]*:)([^"?\#]+)((?:\?[^"]*)?")#i',
    static function (array $m) use ($source, &$assetFiles): string {
        $rel = $m[2];
        if (!preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9._-]+)+\.(?:js|css)$#', $rel) || str_starts_with($rel, 'api/') || str_contains($rel, '..')) {
            fwrite(STDERR, "admin.html charge « /$rel » : chemin non pris en charge, à revoir dans extract-eb80.php.\n");
            exit(1);
        }
        if (!is_file($source . '/' . $rel)) {
            fwrite(STDERR, "admin.html charge « /$rel », absent d'electroboy80.\n");
            exit(1);
        }
        $assetFiles[$rel] = true;
        // Empreinte du contenu dans l'adresse : une modification est servie aussitôt, même si electroboy80 n'a pas changé son ?v=.
        $query = rtrim($m[3], '"');
        return $m[1] . $rel . ($query === '' ? '?' : $query . '&') . 'h=' . substr(md5_file($source . '/' . $rel), 0, 10) . '"';
    }, 1);
$html = patch_uploads('admin.html', $html, 1);
// Boutons ✨ IA (SEO, message des réseaux) : la clé est côté serveur (Gemini ou Claude de la boutique), plus de clé exigée ici.
$html = patch('admin.html : clé IA non exigée dans le navigateur', $html, 'if (!apiKey) {', 'if (false /* boutique : clé côté serveur */ && !apiKey) {', 3);
// SEO des images : le bouton ✨ IA passe par Gemini et le quota de la boutique (compat.php), pas par une clé Claude à saisir.
$html = patch_re('admin.html : texte du bouton IA du SEO', $html, '/utilise Claude Vision pour générer des suggestions automatiques — clé API à renseigner dans l\'onglet <strong[^>]*>Clés IA<\/strong>\./u',
    'propose le texte, la légende et le nom de fichier avec l\'IA de la boutique (Gemini, dans son quota d\'IA).', 1);
$html = patch_colors('admin.html', $html);
// Retour OAuth TikTok : la route eb80 du plugin (window.EB80_TIKTOK_CALLBACK, posée par eb80-shim.js) au lieu de /api/….
$html = patch('admin.html : retour TikTok', $html, "window.location.origin + '/api/rs/tiktok-callback'", "window.location.origin + (window.EB80_TIKTOK_CALLBACK || '/api/rs/tiktok-callback')", 1);
write_file($pack . '/assets/eb80/admin.html', $html);

// Fichiers chargés par la page (ci-dessus), plus ceux que les scripts chargent eux-mêmes (lecteur de la vidéo IA).
foreach (['js/eb80-effects.js', 'js/video-promo/eb80-video-player.js', 'js/video-promo/eb80-video-player.css'] as $f) $assetFiles[$f] = true;
foreach (array_keys($assetFiles) as $f) {
    $js = src_read($source . '/' . $f);
    if (str_ends_with($f, '.js')) $js = patch_uploads($f, $js);
    // Vidéo IA : son lecteur se charge à la première ouverture depuis le dossier des scripts recopiés, à côté de la page.
    if ($f === 'js/video-promo/video-ia.js') $js = patch($f . ' : lecteur en chemin relatif', $js, "const BASE = '/js/video-promo/';", "const BASE = 'js/video-promo/';", 1);
    if ($f === 'js/video-promo/video-ia.js') {
        // Textes d'electroboy80 (concert, new wave, son nom, son site) : ceux de la boutique, posés par eb80-shim.js (EB80_VIA_TEXTES).
        foreach ([
            ["textes de la boutique", <<<'EOT'
(() => {
  const BASE = 'js/video-promo/';
EOT, <<<'EOT'
(() => {
  const T = window.EB80_VIA_TEXTES || {}; // textes de la boutique (eb80-shim.js), sinon ceux d'electroboy80
  const BASE = 'js/video-promo/';
EOT],
            ["titre de la fenêtre", <<<'EOT'
<h2>VIDÉO <span>IA</span> · promo concert</h2>
EOT, <<<'EOT'
<h2>VIDÉO <span>IA</span> · ${T.sousTitre || 'promo concert'}</h2>
EOT],
            ["exemple de consigne", <<<'EOT'
placeholder="Ex. ton festif, mettre en avant les lunettes fluo, entrée gratuite…"
EOT, <<<'EOT'
placeholder="${T.consigneEx || 'Ex. ton festif, mettre en avant les lunettes fluo, entrée gratuite…'}"
EOT],
            ["nom de l'IA", <<<'EOT'
Claude regarde les photos de la médiathèque
EOT, <<<'EOT'
${T.iaNom || 'Claude'} regarde les photos de la médiathèque
EOT],
            ["exemple de date", <<<'EOT'
placeholder="VENDREDI 16 OCTOBRE"
EOT, <<<'EOT'
placeholder="${T.dateEx || 'VENDREDI 16 OCTOBRE'}"
EOT],
            ["exemple d'étiquettes", <<<'EOT'
placeholder="ROCK'N'ROLL, NEW WAVE"
EOT, <<<'EOT'
placeholder="${T.mentionsEx || "ROCK'N'ROLL, NEW WAVE"}"
EOT],
            ["appel final", <<<'EOT'
value="Plus d'infos ici"
EOT, <<<'EOT'
value="${T.texteInfos || "Plus d'infos ici"}"
EOT],
            ["lien affiché", <<<'EOT'
value="electroboy80.com"
EOT, <<<'EOT'
value="${T.lien || 'electroboy80.com'}"
EOT],
            ["nom de la carte finale (liste)", <<<'EOT'
<option value="concert">Carte concert</option>
EOT, <<<'EOT'
<option value="concert">${T.carteNom || 'Carte concert'}</option>
EOT],
            ["charte (case)", <<<'EOT'
Habiller la carte du concert aux couleurs du lieu
EOT, <<<'EOT'
${T.charte || 'Habiller la carte du concert aux couleurs du lieu'}
EOT],
            ["charte (aide)", <<<'EOT'
La charte habille la carte du concert et ajoute le logo du lieu à l'écran de fin.
EOT, <<<'EOT'
${T.charteAide || 'La charte habille la carte du concert et ajoute le logo du lieu à l\'écran de fin.'}
EOT],
            ["charte sans logo", <<<'EOT'
sinon la carte Electroboy est utilisée.
EOT, <<<'EOT'
sinon la carte par défaut est utilisée.
EOT],
            ["nom de la carte finale", <<<'EOT'
concert: 'Carte concert', fin: 'Fin' };
EOT, <<<'EOT'
concert: T.carteNom || 'Carte concert', fin: 'Fin' };
EOT],
            ["scène titre", <<<'EOT'
if (type === 'titre') { sc.texte = 'ELECTRO\nBOY 80'; sc.texte2 = 'EN CONCERT'; }
EOT, <<<'EOT'
if (type === 'titre') { sc.texte = T.titre || 'ELECTRO\nBOY 80'; sc.texte2 = T.titre2 || 'EN CONCERT'; }
EOT],
            ["scène mots-chocs", <<<'EOT'
if (type === 'mots') sc.mots = ['CHEAP.', 'PRIMITIF.', 'SAUVAGE.'];
EOT, <<<'EOT'
if (type === 'mots') sc.mots = T.mots ? T.mots.slice() : ['CHEAP.', 'PRIMITIF.', 'SAUVAGE.'];
    if (type === 'concert' && T.carteEtiquette) sc.texte2 = T.carteEtiquette;
    if (type === 'fin' && T.fin) sc.texte = T.fin;
EOT],
            ["scènes photo", <<<'EOT'
texte: "LA NEW WAVE DES 80'S", texte2: 'À 170 BPM' }
EOT, <<<'EOT'
texte: T.photo || "LA NEW WAVE DES 80'S", texte2: T.photo2 || 'À 170 BPM' }
EOT],
            ["carte finale (exemple)", <<<'EOT'
'placeholder="PROCHAIN CONCERT"'
EOT, <<<'EOT'
'placeholder="' + (T.carteEtiquette || 'PROCHAIN CONCERT') + '"'
EOT],
            ["carte finale (renvoi)", <<<'EOT'
Date, lieu, étiquettes : section « Le concert ».
EOT, <<<'EOT'
Date, lieu, étiquettes : section « ${T.sujet || 'Le concert'} ».
EOT],
            ["fin (exemple)", <<<'EOT'
'placeholder="ELECTROBOY 80"'
EOT, <<<'EOT'
'placeholder="' + (T.fin || 'ELECTROBOY 80') + '"'
EOT],
            ["fin (renvoi)", <<<'EOT'
Appel final et lien : section « Le concert ».
EOT, <<<'EOT'
Appel final et lien : section « ${T.sujet || 'Le concert'} ».
EOT],
            ["nom de l'IA (visuel)", <<<'EOT'
Claude s'en inspire pour la couleur
EOT, <<<'EOT'
${T.iaNom || 'Claude'} s'en inspire pour la couleur
EOT],
            ["nom de l'IA (sujet)", <<<'EOT'
; Claude écrit les textes à partir de ces infos.`;
EOT, <<<'EOT'
; ${T.iaNom || 'Claude'} écrit les textes à partir de ces infos.`;
EOT],
            ["nom de l'IA (charte)", <<<'EOT'
msg.textContent = '⏳ Claude regarde le visuel du lieu…';
EOT, <<<'EOT'
msg.textContent = '⏳ ' + (T.iaNom || 'Claude') + ' regarde le visuel du lieu…';
EOT],
            ["nom de l'IA (variantes)", <<<'EOT'
`⏳ Claude regarde les photos et écrit ${nombre} variantes
EOT, <<<'EOT'
`⏳ ${T.iaNom || 'Claude'} regarde les photos et écrit ${nombre} variantes
EOT],
            ["nom de l'IA (scénario)", <<<'EOT'
`⏳ Claude regarde les photos et écrit un scénario
EOT, <<<'EOT'
`⏳ ${T.iaNom || 'Claude'} regarde les photos et écrit un scénario
EOT],
            ["bandeau défilant : libellé", <<<'EOT'
'Bandeau défilant (vide = influences)'
EOT, <<<'EOT'
T.bandeauLib || 'Bandeau défilant (vide = influences)'
EOT],
            ["bandeau défilant : vide = aucun", <<<'EOT'
scenes: etat.scenes.filter((sc) => sc.type !== 'photo' || sc.photo).map((sc) => ({ ...sc, duree: Number(sc.duree) || 1 })),
EOT, <<<'EOT'
scenes: etat.scenes.filter((sc) => sc.type !== 'photo' || sc.photo).map((sc) => ({ ...sc, duree: Number(sc.duree) || 1,
        // Mots-chocs sans bandeau saisi : texte de la boutique (vide = aucun bandeau), jamais celui de la composition d'origine.
        ...(sc.type === 'mots' && sc.bandeau == null && T.bandeau != null ? { bandeau: T.bandeau } : {}) })),
EOT],
        ] as [$what, $from, $to]) $js = patch($f . ' : ' . $what, $js, $from, $to, 1);
        // Couleurs de la boutique pour la fenêtre (bloc CSS de l'interface seulement ; les palettes de la vidéo ne changent pas).
        $js = patch_re($f . ' : couleurs de la fenêtre', $js, '/(const CSS = `)(.*?)(`;)/s', static fn ($m) => $m[1] . patch_colors('video-ia.js (fenêtre)', $m[2]) . $m[3], 1);
    }
    // Modules d'interface (plugin/…) : couleurs de la boutique, comme admin.html. Pas les scripts de js/ (effets, vidéo), qui
    // dessinent l'affiche elle-même : ses couleurs ne doivent pas changer.
    if (str_starts_with($f, 'plugin/')) $js = patch_colors($f, $js);
    write_file($pack . '/assets/eb80/' . $f, $js);
}
$log[] = 'scripts et styles recopiés : ' . implode(', ', array_keys($assetFiles));

// ── Serveur : config.php et les contrôleurs de la médiathèque ───────────────
$guard = "<?php\n// Copie d'electroboy80 (voir packs/communication/tools/extract-eb80.php) : ne pas modifier ici, relancer l'extraction.\ndefined('PLUGIN_RUNTIME') || exit;\n";
$cfg = src_read($source . '/api/config.php');
$cfg = patch_re('config.php : en-tête PHP', $cfg, '/^<\?php\s*/', $guard, 1);
$cfg = php85($cfg, 'config.php');
// En-têtes CORS ouverts, réponse aux pré-requêtes, type de contenu : l'API du plugin s'en charge (même origine, pas de CORS).
$cfg = patch_re('config.php : en-têtes CORS', $cfg, '/^header\(\'Access-Control-[^\n]*\n/m', '', 4);
$cfg = patch_re('config.php : pré-requêtes OPTIONS', $cfg, '/^if \(\$_SERVER\[\'REQUEST_METHOD\'\] === \'OPTIONS\'\) \{.*?^\}\n/ms', '', 1);
$cfg = patch_re('config.php : type de contenu', $cfg, '/^header\(\'Content-Type: application\/json[^\n]*\n/m', '', 1);
// Base d'electroboy80 : constante propre (DB_PATH est déjà la base de la boutique dans la plateforme : ne jamais la réutiliser).
$cfg = patch_re('config.php : base propre au portage', $cfg, '/\bDB_PATH\b/', 'EB80_DB_PATH', 2);
// Constantes (base, dossier d'envoi, jeton…) : fixées avant par compat.php pour la boutique courante.
$cfg = patch_re('config.php : constantes', $cfg, '/^define\(\'([A-Z0-9_]+)\'/m', "defined('\$1') || define('\$1'", 5);
// Fonctions remplacées par compat.php (connexion de la boutique, envoi contrôlé, réponses capturables).
foreach (['requireAuth', 'requirePerm', 'getCurrentUser', 'uploadFile', 'jsonResponse', 'getJsonInput'] as $fn) {
    $cfg = patch_re("config.php : $fn remplacée", $cfg, '/^function ' . $fn . '\(/m', 'function _eb80_orig_' . $fn . '(', 1);
}
write_file($pack . '/eb80/api/config.php', $cfg);

$controllers = ['media.php', 'media-links.php', 'generate-web-images.php', 'settings.php', 'ai-generate.php', 'rs-v2.php', 'rs-schedule.php', 'rs-tiktok-callback.php', 'social-feed.php', 'video-jobs.php', 'video-jobs-ai.php', 'palettes.php'];
foreach ($controllers as $f) {
    $php = src_read($source . '/api/' . $f);
    $php = patch_re("$f : en-tête PHP", $php, '/^<\?php\s*/', $guard, 1);
    $php = php85($php, $f);
    $php = patch_re("$f : config", $php, '/require_once\s+(?:__DIR__\s*\.\s*)?[\'"]\/?config\.php[\'"];/', "require_once __DIR__ . '/../compat.php';", 1);
    $php = patch("$f : adresses des fichiers", $php, "'/uploads/' . ", 'UPLOAD_URL . ', 0);
    if (preg_match('/\bDB_PATH\b/', $php)) { fwrite(STDERR, "$f utilise DB_PATH : à adapter (base de la boutique).\n"); exit(1); }
    $php = patch_re("$f : constantes", $php, '/^define\(\'([A-Z0-9_]+)\'/m', "defined('\$1') || define('\$1'", 0);
    if ($f === 'media.php') {
        // Envoi par morceaux, import par adresse, suggestion SEO, outils vidéo serveur : versions sûres dans compat.php.
        foreach (['handleGetMedia', 'handleUploadChunk', 'handleImportUrl', 'handleSeoSuggest', 'handleDownloadVideo', 'handleMergeAV', 'handleExtractAudio'] as $fn) {
            $php = patch_re("media.php : $fn remplacée", $php, '/^function ' . $fn . '\(/m', 'function _eb80_orig_' . $fn . '(', 1);
        }
    }
    if ($f === 'ai-generate.php') {
        // Connexion de la boutique au lieu du jeton electroboy80, puis quota d'IA de la boutique.
        $php = patch_re('ai-generate.php : connexion', $php, '/\/\/ Auth\s*\n\$headers = getallheaders\(\);.*?if \(!\$payload\) \{[^\n]*\}\n/s',
            "requireAuth(['admin']);\nif ((\$eb80Gate = ai_gate()) !== null) errorResponse(\$eb80Gate, 402);\n", 1);
        // La boutique n'a que Gemini : clé de la boutique (compat.php) et consommation comptée.
        $php = patch_re('ai-generate.php : fournisseur', $php, '/^\$provider\s*=.*$/m', "\$provider        = 'gemini'; // la boutique n'utilise que Gemini", 1);
        $php = patch_re('ai-generate.php : clé', $php, '/^function getAiKey\(/m', 'function _eb80_orig_getAiKey(', 1);
        $php = patch('ai-generate.php : consommation comptée', $php, "    \$text = \$resp['candidates'][0]['content']['parts'][0]['text'] ?? '';",
            "    \$text = \$resp['candidates'][0]['content']['parts'][0]['text'] ?? '';\n    if (\$text) ai_usage_log_text(\$resp['usageMetadata'] ?? []);", 1);
    }
    if ($f === 'rs-v2.php') {
        // Appels aux réseaux (simulateur local possible), renouvellement TikTok, message rédigé par l'IA : remplacés dans compat.php.
        foreach (['apiCurl', 'refreshTikTokToken', 'handleGenerateMessage'] as $fn) {
            $php = patch_re("rs-v2.php : $fn remplacée", $php, '/^function ' . $fn . '\(/m', 'function _eb80_orig_' . $fn . '(', 1);
        }
    }
    if ($f === 'rs-schedule.php') {
        $php = patch('rs-schedule.php : bibliothèque RS', $php, "require_once 'rs-v2.php';", "require_once __DIR__ . '/rs-v2.php';", 1);
    }
    if ($f === 'rs-tiktok-callback.php') {
        // Adresse de retour : la route eb80 du plugin, sous le préfixe de la boutique (identique à celle envoyée par le navigateur).
        $php = patch_re('rs-tiktok-callback.php : adresse de retour', $php, '/^\$redirectUri\s*=.*$/m',
            "\$redirectUri = appBaseUrl() . app_prefix(plugin_api_url('communication', 'eb80') . '&path=' . rawurlencode('/rs/tiktok-callback'));", 1);
    }
    if ($f === 'palettes.php') {
        // Palettes des visuels : la boutique au lieu d'Electroboy 80, et l'IA de la boutique (Gemini ou Claude, quota, simulateur).
        $php = patch_re('palettes.php : identité', $php, '/^function palettesIdentite\(/m', 'function _eb80_orig_palettesIdentite(', 1);
        $php = patch_re('palettes.php : appel de l\'IA', $php, '/^function palettesAppelIA\(/m', 'function _eb80_orig_palettesAppelIA(', 1);
    }
    if ($f === 'video-jobs.php') {
        // File de rendu : morceaux hors de uploads/, pas d'écrasement, fichier rendu contrôlé (compat.php : EB80_TMP_DIR, EB80_VIDEO_MAX_OCTETS).
        foreach ([
            ["dossier temporaire hors de uploads/", <<<'EOT'
    $tmpDir = UPLOAD_DIR . 'tmp/video-job-' . $id . '/';
EOT, <<<'EOT'
    $tmpDir = EB80_TMP_DIR . 'video-job-' . $id . '/'; // hors de uploads/ : jamais servi
EOT],
            ["nom de fichier sans écrasement", <<<'EOT'
    $filename = $base . '.mp4';
EOT, <<<'EOT'
    $filename = $base . '-' . $id . '.mp4'; // jamais d'écrasement d'un fichier de la médiathèque
EOT],
            ["contrôle du fichier rendu", <<<'EOT'
    fclose($out);
    @rmdir($tmpDir);

EOT, <<<'EOT'
    fclose($out);
    @rmdir($tmpDir);
    // Fichier rendu : un vrai MP4 (ou MOV) d'une taille raisonnable, sinon refusé et supprimé.
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($final);
    if (!in_array($mime, ['video/mp4', 'video/quicktime'], true) || filesize($final) > EB80_VIDEO_MAX_OCTETS) {
        @unlink($final);
        dbExec("UPDATE video_jobs SET status = 'error', error = ?, done_at = ? WHERE id = ?", ['Fichier rendu refusé (MP4 attendu, 500 Mo au plus).', vjNow(), $id]);
        errorResponse('Fichier rendu refusé : MP4 attendu, 500 Mo au plus.', 400);
    }

EOT],
        ] as [$what, $from, $to]) $php = patch($f . ' : ' . $what, $php, $from, $to, 1);
    }
    if ($f === 'video-jobs-ai.php') {
        // Scénario par l'IA : la boutique au lieu d'Electroboy 80 ; clé et appel (Gemini ou Claude) dans compat.php.
        foreach ([
            ["sujet par défaut : la boutique", <<<'EOT'
$sujet = is_array($in['sujet'] ?? null) && in_array($in['sujet']['profil'] ?? '', ['produit', 'organisation', 'generique'], true) ? $in['sujet'] : null;
EOT, <<<'EOT'
$sujet = is_array($in['sujet'] ?? null) && in_array($in['sujet']['profil'] ?? '', ['produit', 'organisation', 'generique'], true) ? $in['sujet'] : eb80_via_sujet_boutique();
EOT],
            ["clé de l'IA", <<<'EOT'
$cle = getSetting('claude_api_key') ?: getSetting('anthropic_api_key');
if (!$cle) errorResponse('Clé Claude non configurée : API & Réseaux → Clés IA → « Enregistrer les clés IA »', 400);
EOT, <<<'EOT'
$cle = eb80_via_cle(); // Gemini (quota de la boutique) ou Claude, selon API & Réseaux → IA ; quota vérifié
EOT],
            ["charte : la boutique", <<<'EOT'
$systeme = "Tu es directeur artistique pour Electroboy 80 (new wave des années 80, néon). On te montre un visuel "
        . "d'une salle de concert (photo du lieu, de sa façade, de son affiche ou de sa communication). Tu proposes "
        . "l'habillage de la carte du concert aux couleurs du lieu
EOT, <<<'EOT'
$systeme = "Tu es directeur artistique pour " . eb80_via_identite_courte() . ". On te montre un visuel "
        . "de la boutique (photo du lieu, de sa façade, de sa vitrine ou de sa communication). Tu proposes "
        . "l'habillage de la carte d'information aux couleurs de la boutique
EOT],
            ["identité de la boutique", <<<'EOT'
$systeme = <<<TXT
Tu es réalisateur de clips promo pour Electroboy 80, projet musical qui rejoue la new wave des années 80
à 170 BPM (The Cure, Kraftwerk, Soft Cell, Taxi Girl…), slogan « Cheap. Primitif. Sauvage. »,
univers néon rose/cyan/jaune, lunettes fluo, combinaisons argentées.
EOT, <<<'EOT'
$eb80Identite = eb80_via_identite();
$systeme = <<<TXT
{$eb80Identite}
EOT],
            ["photos du sujet", <<<'EOT'
- photos : uniquement des photos où l'on voit le musicien ou la scène, nettes ; jamais un logo, une
EOT, <<<'EOT'
- photos : uniquement des photos nettes du sujet ou de la boutique ; jamais un logo, une
EOT],
            ["palette sans charte Electroboy", <<<'EOT'
« neon » (rose, jaune, cyan sur noir : la charte Electroboy, à privilégier par défaut),
EOT, <<<'EOT'
« neon » (rose, jaune, cyan sur noir),
EOT],
            ["musique sans Electroboy", <<<'EOT'
  (privilégie les morceaux d'Electroboy 80), ou chaîne vide s'il n'y en a aucun.
EOT, <<<'EOT'
  ou chaîne vide s'il n'y en a aucun.
EOT],
            ["message sans photo", <<<'EOT'
errorResponse("Claude n'a retenu aucune photo exploitable ; ajoutez des photos du groupe à la médiathèque.", 502);
EOT, <<<'EOT'
errorResponse("L'IA n'a retenu aucune photo exploitable ; ajoutez des photos des pièces à la médiathèque.", 502);
EOT],
            ["appel de l'IA remplacé", <<<'EOT'
function viaClaude(string $cle,
EOT, <<<'EOT'
function _eb80_orig_viaClaude(string $cle,
EOT],
        ] as [$what, $from, $to]) $php = patch($f . ' : ' . $what, $php, $from, $to, 1);
    }
    write_file($pack . '/eb80/api/' . $f, $php);
}

// Filtre des réglages publics (lu par settings.php : __DIR__ . '/../reglages-publics.php').
$pub = src_read($source . '/reglages-publics.php');
$pub = patch_re('reglages-publics.php : en-tête PHP', $pub, '/^<\?php\s*/', $guard, 1);
write_file($pack . '/eb80/reglages-publics.php', $pub);

[$commit, $dirty] = source_state($source);
ksort($sourceHashes);
$fingerprint = md5(json_encode($sourceHashes));
if (isset($opts['status'])) {
    // État seulement : rien n'est écrit. À jour = même empreinte que la dernière copie (commit, modifications et retouches).
    $record = (string) @file_get_contents($pack . '/eb80/SOURCE.txt');
    $copied = preg_match('/^Commit : (.*)$/m', $record, $m) ? trim($m[1]) : '';
    $copiedAt = preg_match('/faite le (.*)$/m', $record, $m) ? trim($m[1]) : '';
    $recorded = preg_match('/^Empreinte : ([0-9a-f]{32})$/m', $record, $m) ? $m[1] : '';
    echo json_encode([
        'source' => $source, 'source_commit' => $commit, 'source_dirty' => $dirty,
        'copied_commit' => $copied, 'copied_at' => $copiedAt, 'up_to_date' => $recorded === $fingerprint,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}
if ($commit !== '' && $dirty) $commit .= ' (+ modifications non commitées)';
write_file($pack . '/eb80/SOURCE.txt', "Copie d'electroboy80 faite le " . date('Y-m-d H:i') . "\nDossier : $source\nCommit : " . ($commit ?: 'inconnu') . "\nEmpreinte : $fingerprint\n\nRetouches :\n" . implode("\n", $log) . "\n");
// Syntaxe des scripts retouchés (si node est installé sur ce poste) : une retouche qui casserait le JavaScript arrête tout, avant
// toute écriture — sinon l'administration portée s'afficherait vide ou sur une autre page.
/** Scripts en ligne (<script> sans attribut) d'une page, sans expression régulière (la page fait plus d'un million de caractères). */
function inline_scripts(string $html): array
{
    $codes = [];
    $pos = 0;
    while (($start = strpos($html, '<script>', $pos)) !== false) {
        $end = strpos($html, '</script>', $start + 8);
        if ($end === false) break;
        $codes[] = substr($html, $start + 8, $end - $start - 8);
        $pos = $end + 9;
    }
    return $codes;
}
$node = trim((string) @shell_exec('command -v node 2>/dev/null'));
if ($node !== '') {
    $tmp = sys_get_temp_dir() . '/eb80-syntaxe-' . getmypid() . '.js';
    foreach ($pending as $path => $content) {
        $codes = str_ends_with($path, '.js') ? [$content] : (str_ends_with($path, '.html') ? inline_scripts($content) : []);
        foreach ($codes as $i => $code) {
            if (strlen($code) < 200) continue; // petits scripts d'amorçage (document.write)
            $checked = ($checked ?? 0) + 1;
            file_put_contents($tmp, $code);
            exec(escapeshellarg($node) . ' --check ' . escapeshellarg($tmp) . ' 2>&1', $outNode, $rc);
            if ($rc !== 0) {
                @unlink($tmp);
                fwrite(STDERR, 'JavaScript invalide après retouche : ' . basename($path) . ($codes !== [$content] ? " (script $i)" : '') . "\n" . implode("\n", array_slice($outNode, 0, 6)) . "\n");
                exit(1);
            }
            $outNode = [];
        }
    }
    @unlink($tmp);
    $log[] = 'syntaxe JavaScript vérifiée (node) : ' . $checked . ' script(s)';
}
// Toutes les retouches ont réussi : écriture des fichiers (chacun remplacé d'un bloc, par renommage).
foreach ($pending as $path => $content) {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    $tmp = $path . '.tmp-extract';
    if (file_put_contents($tmp, $content) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        fwrite(STDERR, "Écriture impossible : $path\n");
        exit(1);
    }
}
echo "Extraction terminée depuis $source (" . ($commit ?: 'commit inconnu') . ")\n" . implode("\n", $log) . "\n";
