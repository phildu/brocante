<?php
// Portage fidèle d'electroboy80 dans le pack Communication : recopie l'interface d'administration d'electroboy80 et les fichiers
// serveur de sa médiathèque, avec des retouches listées ici et rien d'autre. À relancer quand electroboy80 évolue :
//
//   php packs/communication/tools/extract-eb80.php [--source=/chemin/vers/electroboy80]
//
// Produit (et écrase) :
//   packs/communication/assets/eb80/admin.html  l'admin d'electroboy80, servie dans le cadre de la page Médiathèque ;
//                                               eb80-shim.js (au début) et eb80-after.js (à la fin) l'adaptent à la boutique
//   packs/communication/assets/eb80/js/…        ses scripts annexes (effets, outils du sélecteur, validation, vidéo IA)
//   packs/communication/eb80/api/*.php          config.php, media.php, media-links.php, generate-web-images.php, settings.php,
//                                               ai-generate.php (script vidéo par l'IA)
//                                               (et eb80/reglages-publics.php, le filtre des réglages publics) ;
//                                               packs/communication/eb80/compat.php remplace les fonctions renommées ici
//   packs/communication/eb80/SOURCE.txt         d'où vient la copie (dossier, commit, date)
// Une retouche dont le motif n'est plus trouvé arrête tout : electroboy80 a changé, il faut revoir la retouche.
PHP_SAPI === 'cli' || exit;

$pack = dirname(__DIR__);
$opts = getopt('', ['source:']);
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
function write_file(string $path, string $content): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    file_put_contents($path, $content);
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
    $s = patch_re($what . ' : fonds sombres', $s, '/(background(?:-color)?\s*:\s*)(#000000|#000|#050505|#080808|#0a0a0a|#0c0c0c|#0d0d0d|#0f0f0f|#111111|#111|#111122|#141414|#161616|#181818|#1a1a1a|#222222|#222|#2a2a2a|#333333|#333|#3a3a3a)\b/i', '$1var(--mid)', 0);
    // Fonds noirs semi-transparents (menus, barres, voiles) : fond clair de la boutique, presque opaque.
    $s = patch_re($what . ' : fonds noirs translucides', $s, '/(background(?:-color)?\s*:\s*)rgba\(\s*(?:0|10)\s*,\s*(?:0|10)\s*,\s*(?:0|10)\s*,\s*0?\.(?:[6-9]\d*)\s*\)/i', '$1color-mix(in srgb, var(--black) 96%, transparent)', 0);
    $s = patch_re($what . ' : bordures sombres', $s, '/(border(?:-(?:top|bottom|left|right))?\s*:\s*\d+px\s+(?:solid|dashed|dotted)\s+)(#1a1a1a|#222222|#222|#2a2a2a|#333333|#333|#444)\b/i', '$1var(--border)', 0);
    $s = patch_re($what . ' : textes clairs', $s, '/((?<![-\w])color\s*:\s*)(#ffffff|#fff|#f0f0f0|#eeeeee|#eee|#dddddd|#ddd)\b/i', '$1var(--white)', 0);
    return patch_re($what . ' : textes atténués', $s, '/((?<![-\w])color\s*:\s*)(#aaaaaa|#aaa|#999999|#999|#888888|#888|#777777|#777|#666666|#666|#555555|#555)\b/i', '$1var(--muted)', 0);
}

$html = (string) file_get_contents($source . '/admin.html');
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
$html = patch('admin.html : scripts annexes en chemin relatif', $html, 'src="/js/', 'src="js/', 1);
$html = patch_uploads('admin.html', $html, 1);
$html = patch_colors('admin.html', $html);
write_file($pack . '/assets/eb80/admin.html', $html);

$jsFiles = ['eb80-effects.js', 'media-picker-outils.js', 'media-validation.js', 'video-promo/video-ia.js', 'video-promo/eb80-video-player.js', 'video-promo/eb80-video-player.css'];
foreach ($jsFiles as $f) {
    $js = (string) file_get_contents($source . '/js/' . $f);
    if (str_ends_with($f, '.js')) $js = patch_uploads('js/' . $f, $js);
    write_file($pack . '/assets/eb80/js/' . $f, $js);
}

// ── Serveur : config.php et les contrôleurs de la médiathèque ───────────────
$guard = "<?php\n// Copie d'electroboy80 (voir packs/communication/tools/extract-eb80.php) : ne pas modifier ici, relancer l'extraction.\ndefined('PLUGIN_RUNTIME') || exit;\n";
$cfg = (string) file_get_contents($source . '/api/config.php');
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
foreach (['requireAuth', 'requirePerm', 'getCurrentUser', 'uploadFile', 'jsonResponse'] as $fn) {
    $cfg = patch_re("config.php : $fn remplacée", $cfg, '/^function ' . $fn . '\(/m', 'function _eb80_orig_' . $fn . '(', 1);
}
write_file($pack . '/eb80/api/config.php', $cfg);

$controllers = ['media.php', 'media-links.php', 'generate-web-images.php', 'settings.php', 'ai-generate.php'];
foreach ($controllers as $f) {
    $php = (string) file_get_contents($source . '/api/' . $f);
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
    write_file($pack . '/eb80/api/' . $f, $php);
}

// Filtre des réglages publics (lu par settings.php : __DIR__ . '/../reglages-publics.php').
$pub = (string) file_get_contents($source . '/reglages-publics.php');
$pub = patch_re('reglages-publics.php : en-tête PHP', $pub, '/^<\?php\s*/', $guard, 1);
write_file($pack . '/eb80/reglages-publics.php', $pub);

$commit = trim((string) @shell_exec('git -C ' . escapeshellarg($source) . ' log -1 --format="%h %cd %s" --date=iso 2>/dev/null'));
write_file($pack . '/eb80/SOURCE.txt', "Copie d'electroboy80 faite le " . date('Y-m-d H:i') . "\nDossier : $source\nCommit : " . ($commit ?: 'inconnu') . "\n\nRetouches :\n" . implode("\n", $log) . "\n");
echo "Extraction terminée depuis $source (" . ($commit ?: 'commit inconnu') . ")\n" . implode("\n", $log) . "\n";
