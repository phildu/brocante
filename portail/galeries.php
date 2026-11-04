<?php
// Galeries commerciales (voir includes/galleries.php) : création, choix des commerces membres, commerces distants, publication.
// Page publique : /galerie/<identifiant>/ ; cette page-ci est réservée à l'exploitant (connexion au portail en ligne).
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas-events.php';
require_once PORTAIL_ROOT . '/includes/galleries.php';
require_once PORTAIL_ROOT . '/includes/gallery-admin.php';
require_once PORTAIL_ROOT . '/includes/gallery-offers.php';
require_once PORTAIL_ROOT . '/includes/banner-ai.php';

// Mode restreint : administrateur d'une galerie (voir _bootstrap.php) — sa galerie seulement, ni création ni suppression, pas de gestion des comptes.
$limited = defined('GA_LIMITED') ? GA_LIMITED : null;
const GA_LIMITED_ACTIONS = ['save', 'places', 'style', 'style_reset', 'add_remote', 'remove_remote', 'refresh', 'pause', 'export'];

$shops = tenant_list();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $slug = strtolower((string) ($_POST['slug'] ?? ''));
    if ($limited) {
        $slug = $limited;
        if (!in_array($action, GA_LIMITED_ACTIONS, true)) { portail_flash("Cette action est réservée à l'exploitant.", 'error'); header('Location: /portail/galeries.php'); exit; }
    }
    $back = '/portail/galeries.php' . ($slug !== '' && $action !== 'delete' && $action !== 'create' ? '?g=' . urlencode($slug) : '');
    $gallery = $slug !== '' ? gallery_load($slug) : null;

    switch ($action) {
        case 'export':
            if (!$gallery) { portail_flash('Galerie introuvable.', 'error'); break; }
            try {
                $payload = gallery_export_payload($slug);
                if (!$payload) { portail_flash('Galerie introuvable.', 'error'); break; }
                $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="galerie-' . $slug . '.json"');
                header('Content-Length: ' . strlen($json));
                echo $json;
                exit;
            } catch (RuntimeException | JsonException $e) {
                error_log('gallery export ' . $slug . ': ' . $e->getMessage());
                portail_flash('Export impossible : ' . $e->getMessage(), 'error');
            }
            break;

        case 'import':
            if ($limited) { portail_flash("L'import est réservé à l'exploitant.", 'error'); break; }
            $upload = $_FILES['gallery_file'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                portail_flash('Choisissez un fichier JSON de galerie valide (taille maximale : 40 Mo).', 'error');
                break;
            }
            if (($upload['size'] ?? 0) > 40 * 1024 * 1024 || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
                portail_flash('Fichier importé trop volumineux ou invalide (limite : 40 Mo).', 'error');
                break;
            }
            $json = file_get_contents((string) $upload['tmp_name']);
            if ($json === false) { portail_flash('Impossible de lire le fichier importé.', 'error'); break; }
            try {
                $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload) || !is_array($payload['gallery'] ?? null)) {
                    throw new InvalidArgumentException('Le fichier JSON ne contient pas les données attendues.');
                }
                $modeInput = $_POST['import_mode'] ?? 'new';
                $targetInput = $_POST['target_slug'] ?? '';
                if (!is_string($modeInput) || !is_string($targetInput)) {
                    throw new InvalidArgumentException('Paramètres d’import invalides.');
                }
                $mode = $modeInput;
                $targetSlug = strtolower(trim($targetInput));
                if ($mode === 'replace') {
                    if (!gallery_slug_valid($targetSlug) || !($existing = gallery_load($targetSlug))) {
                        throw new InvalidArgumentException('Pour remplacer une galerie, indiquez un identifiant existant.');
                    }
                } elseif ($mode === 'new') {
                    $targetSlug = $targetSlug !== '' ? $targetSlug : gallery_slugify((string) ($payload['gallery']['name'] ?? 'galerie'));
                    if (!gallery_slug_valid($targetSlug) || in_array($targetSlug, ['index', 'admin', 'assets', 'portail', 'contact', 'clic', 'creer'], true)) {
                        throw new InvalidArgumentException('Identifiant invalide : 2 à 40 caractères (lettres minuscules, chiffres, tirets).');
                    }
                    if (gallery_load($targetSlug)) throw new InvalidArgumentException('Cet identifiant existe déjà : choisissez « Remplacer » ou un nouvel identifiant.');
                    $existing = null;
                } else {
                    throw new InvalidArgumentException('Choisissez de créer une galerie ou d’en remplacer une.');
                }
                $imported = gallery_import_payload($payload, $targetSlug, $existing);
                $saasNote = 'Galerie « ' . $imported['name'] . ' » importée (' . ($existing ? 'remplacée' : 'créée') . ')';
                portail_flash($saasNote . ($existing ? ' ; administrateurs et offre conservés.' : ' ; galerie en test créée, sans comptes administrateurs ni offre importée.'), 'ok');
                $back = '/portail/galeries.php?g=' . urlencode($targetSlug);
            } catch (JsonException | InvalidArgumentException | RuntimeException $e) {
                error_log('gallery import: ' . $e->getMessage());
                portail_flash('Import impossible : ' . $e->getMessage(), 'error');
            }
            break;

        case 'create':
            $name = trim((string) ($_POST['name'] ?? ''));
            $slug = strtolower(trim((string) ($_POST['slug'] ?? ''))) ?: gallery_slugify($name);
            if ($name === '') { portail_flash('Donnez un nom à la galerie.', 'error'); break; }
            if (!gallery_slug_valid($slug)) { portail_flash('Identifiant non valide : 2 à 40 caractères (lettres minuscules, chiffres, tirets).', 'error'); break; }
            if (gallery_load($slug) || in_array($slug, ['index', 'admin', 'assets', 'portail', 'contact', 'clic'], true)) { portail_flash('Une galerie « ' . $slug . ' » existe déjà : choisissez un autre identifiant.', 'error'); break; }
            $ok = gallery_save(['slug' => $slug, 'name' => $name, 'tagline' => (string) ($_POST['tagline'] ?? ''), 'accent' => (string) ($_POST['accent'] ?? ''),
                'presence_mode' => business_presence_mode($_POST['presence_mode'] ?? 'online'), 'published' => false,
                'type' => (gt_types()[(string) ($_POST['type'] ?? '')] ?? gt_type(GT_DEFAULT))['key'], 'created' => time(), 'members' => []]);
            portail_flash($ok ? 'Galerie créée : ajoutez maintenant ses commerces.' : "Impossible d'écrire dans data/galeries (droits du dossier).", $ok ? 'ok' : 'error');
            $back = $ok ? '/portail/galeries.php?g=' . urlencode($slug) : '/portail/galeries.php';
            break;

        case 'save':
            if (!$gallery) { portail_flash('Galerie introuvable.', 'error'); break; }
            // Commerces de ce serveur cochés, dans l'ordre de la liste ; « à la une » = case étoile.
            $checked = (array) ($_POST['member'] ?? []);
            $featured = (array) ($_POST['featured'] ?? []);
            $local = [];
            foreach (array_keys($shops) as $shopSlug) {
                if (in_array($shopSlug, $checked, true)) $local[] = ['type' => 'local', 'slug' => $shopSlug, 'featured' => in_array($shopSlug, $featured, true)];
            }
            // Les commerces d'ailleurs restent ; leur case « à la une » est repérée par leur position dans la galerie.
            $remoteFeatured = (array) ($_POST['remote_featured'] ?? []);
            $members = [];
            foreach ($gallery['members'] as $k => $m) {
                if ($m['type'] !== 'remote') continue;
                $m['featured'] = in_array((string) $k, $remoteFeatured, true);
                $members[] = $m;
            }
            $limit = $limited ? go_member_limit($gallery) : null;     // plafond de l'offre : appliqué à l'administrateur, pas à l'exploitant
            if ($limit !== null && count($local) + count($members) > $limit) {
                portail_flash('Votre offre permet ' . $limit . ' commerce' . ($limit > 1 ? 's' : '') . ' au plus (vous en avez coché ' . (count($local) + count($members)) . ') : décochez-en ou passez à une offre supérieure.', 'error');
                break;
            }
            $domainRaw = trim((string) ($_POST['domain'] ?? ''));
            $domain = gallery_domain_normalize($domainRaw);
            if ($domainRaw !== '' && $domain === '') { portail_flash('Nom de domaine non valide : indiquez un domaine public, par exemple galerie-exemple.fr (sans http ni chemin).', 'error'); break; }
            if ($domain !== '') {
                $owner = gallery_domain_owner($domain, (string) $gallery['slug']);
                if ($owner !== null) {
                    $ownerName = (string) (gallery_load($owner)['name'] ?? $owner);
                    portail_flash('Ce nom de domaine est déjà attribué à la galerie « ' . $ownerName . ' ».', 'error');
                    break;
                }
            }
            $gallery = array_merge($gallery, [
                'name' => trim((string) ($_POST['name'] ?? '')) ?: $gallery['name'],
                'tagline' => (string) ($_POST['tagline'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'presence_mode' => business_presence_mode($_POST['presence_mode'] ?? $gallery['presence_mode'], $gallery['presence_mode']),
                'published' => !empty($_POST['published']),
                'domain' => $domain,
                'members' => array_merge($local, $members),
                'apps' => gallery_apps_normalize([
                    'admin' => (string) ($_POST['app_name_admin'] ?? ''),
                    'visitor' => (string) ($_POST['app_name_visitor'] ?? ''),
                ]),
            ]);
            $ok = gallery_save($gallery);
            portail_flash($ok ? 'Galerie enregistrée.' : "Impossible d'écrire la galerie (droits du dossier data/galeries).", $ok ? 'ok' : 'error');
            break;

        case 'places':
            if (!$gallery) { portail_flash('Galerie introuvable.', 'error'); break; }
            // Localisation de la marchandise de chaque commerce membre : elle alimente le champ « adresse du bien » des plateformes.
            // Le plan peut avoir été enregistré entre-temps : on repart de sa valeur courante et on ne remplace que « places ».
            $places = [];
            foreach ((array) ($_POST['place'] ?? []) as $key => $pl) {
                if (!is_array($pl)) continue;
                $entry = [];
                foreach (['street' => 120, 'postcode' => 10, 'city' => 60, 'stand' => 12, 'note' => 200] as $field => $max) {
                    $entry[$field] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($pl[$field] ?? ''))), 0, $max);
                }
                if (array_filter($entry)) $places[(string) $key] = $entry;
            }
            $gallery['map'] = gallery_map_normalize(['places' => $places] + (array) ($gallery['map'] ?? []), array_map('gallery_member_key', $gallery['members']), gt_locations($gallery));
            $ok = gallery_save($gallery);
            portail_flash($ok ? 'Localisations de la marchandise enregistrées.' : "Impossible d'enregistrer les localisations (droits du dossier data/galeries).", $ok ? 'ok' : 'error');
            break;

        case 'style':
            if (!$gallery) { portail_flash('Galerie introuvable.', 'error'); break; }
            $old = $gallery['style'];
            $new = $old;
            foreach (['bg', 'ink', 'hero_color', 'banner_overlay', 'banner_pos', 'hero_height', 'hero_align', 'font_display', 'font_body', 'radius', 'card_ratio', 'density', 'per_page',
                'default_sort', 'dark_mode', 'cta_label', 'shops_title', 'shops_lede', 'pieces_title', 'footer_text'] as $k) {
                if (isset($_POST[$k])) $new[$k] = $_POST[$k];
            }
            $new['banner_slideshow'] = !empty($_POST['banner_slideshow']);
            // Les couleurs de fond, de texte et d'en-tête ne sont enregistrées que si leur case « personnaliser » est cochée (sinon : teintes d'origine).
            foreach (['bg' => 'use_bg', 'ink' => 'use_ink', 'hero_color' => 'use_hero'] as $k => $flag) if (empty($_POST[$flag])) $new[$k] = '';
            $new['show_stats'] = !empty($_POST['show_stats']);
            $new['show_cta'] = !empty($_POST['show_cta']);
            $errors = $added = [];
            // Bannières : image principale, suppression par position, choix dans la médiathèque, puis fichiers envoyés.
            $banners = gallery_banner_images($old);
            $mainPath = $banners[max(0, (int) ($_POST['banner_main'] ?? 0))] ?? '';
            $remove = array_map('intval', (array) ($_POST['remove_banners'] ?? []));
            if (!empty($_POST['remove_banner']) && $banners) { gallery_image_delete(array_shift($banners)); }
            foreach ($banners as $i => $path) {
                if (!in_array($i, $remove, true)) continue;
                gallery_image_delete($path);
                unset($banners[$i]);
            }
            $banners = array_values($banners);
            if ($mainPath !== '' && in_array($mainPath, $banners, true)) {
                $banners = array_values(array_diff($banners, [$mainPath]));
                array_unshift($banners, $mainPath);
            }
            $libraryPick = trim((string) ($_POST['library_banner'] ?? ''));
            if ($libraryPick !== '') {
                $item = banner_library_find($libraryPick);
                if ($item === null || !empty($item['exclusive'])) {
                    $errors[] = 'Bandeau de la médiathèque introuvable ou réservé à un commerce.';
                } elseif (count($banners) >= 6) {
                    $errors[] = 'Six images au maximum : retirez-en une avant d’ajouter un bandeau de la médiathèque.';
                } else {
                    try {
                        $path = banner_ai_adopt_library($libraryPick, $slug);
                        if (in_array($path, $banners, true)) {
                            $errors[] = 'Ce bandeau de la médiathèque est déjà utilisé par la galerie.';
                        } else {
                            array_unshift($banners, $path);
                            $added[] = $path;
                        }
                    } catch (Throwable $ex) {
                        $errors[] = 'Bandeau de la médiathèque : ' . $ex->getMessage();
                    }
                }
            }
            $uploaded = static function (array $files, int $i): ?array {
                if ((int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
                return ['error' => $files['error'][$i], 'tmp_name' => $files['tmp_name'][$i], 'size' => $files['size'][$i]];
            };
            $files = $_FILES['banners'] ?? null;
            if (is_array($files) && is_array($files['name'] ?? null)) {
                for ($i = 0; $i < count($files['name']) && count($banners) < 6; $i++) {
                    $file = $uploaded($files, $i);
                    if (!$file) continue;
                    try {
                        $path = gallery_image_store($file, $slug, 'banner', 2000);
                        $banners[] = $path;
                        $added[] = $path;
                    } catch (InvalidArgumentException $ex) {
                        $errors[] = 'Bannière : ' . $ex->getMessage();
                    }
                }
            }
            if (isset($_FILES['banner']) && ($_FILES['banner']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && count($banners) < 6) {
                try {
                    $path = gallery_image_store($_FILES['banner'], $slug, 'banner', 2000);
                    $banners[] = $path;
                    $added[] = $path;
                } catch (InvalidArgumentException $ex) {
                    $errors[] = 'Bannière : ' . $ex->getMessage();
                }
            }
            $new['banners'] = array_slice($banners, 0, 6);
            $new['banner'] = $new['banners'][0] ?? '';
            if (!empty($_POST['remove_logo'])) { gallery_image_delete((string) $old['logo']); $new['logo'] = ''; }
            $file = $_FILES['logo'] ?? null;
            if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $path = gallery_image_store($file, $slug, 'logo', 500);
                    if ($old['logo'] !== '') gallery_image_delete((string) $old['logo']);
                    $new['logo'] = $path;
                    $added[] = $path;
                } catch (InvalidArgumentException $ex) {
                    $errors[] = 'Logo : ' . $ex->getMessage();
                }
            }
            $gallery['accent'] = (string) ($_POST['accent'] ?? $gallery['accent']);
            $gallery['style'] = $new;
            $ok = gallery_save($gallery);
            if (!$ok) foreach ($added as $path) gallery_image_delete($path);
            portail_flash($errors ? implode(' ', $errors) . ($ok ? ' Le reste est enregistré.' : '') : ($ok ? "Apparence enregistrée : elle s'applique à la page publique." : "Impossible d'écrire la galerie (droits du dossier data/galeries)."), $errors || !$ok ? 'error' : 'ok');
            break;

        case 'banner_ai':
            if (!$gallery) { portail_flash('Galerie introuvable.', 'error'); break; }
            $banners = gallery_banner_images($gallery['style']);
            $room = 6 - count($banners);
            if ($room <= 0) { portail_flash('Six images au maximum : retirez-en une pour en générer une autre.', 'error'); break; }
            $count = min($room, max(1, min(3, (int) ($_POST['count'] ?? 1))));
            $hint = trim((string) ($_POST['hint'] ?? ''));
            $subject = 'la galerie « ' . $gallery['name'] . ' »'
                . ($gallery['tagline'] !== '' ? '. ' . $gallery['tagline'] : '')
                . ($gallery['description'] !== '' ? '. ' . mb_substr($gallery['description'], 0, 180) : '')
                . '. ' . gt_of($gallery)['name'] . ' convivial en France';
            $prompts = [];
            for ($i = 0; $i < $count; $i++) $prompts[] = banner_ai_prompt($subject . ($i > 0 ? ' (variation ' . ($i + 1) . ', composition différente)' : ''), $hint);
            try {
                $images = banner_ai_generate_many($prompts);
                banner_ai_usage_log('galerie', $slug, count($images));
                $paths = [];
                foreach ($images as $bytes) {
                    $path = banner_ai_save($bytes, 'uploads/galeries', $slug . '-banner', 2000);
                    $paths[] = $path;
                    banner_library_store(dirname(__DIR__) . '/' . $path, $hint !== '' ? $hint : $subject, 'galerie', 'galeries');
                }
                $gallery['style']['banners'] = array_slice(array_merge($banners, $paths), 0, 6);
                $gallery['style']['banner'] = $gallery['style']['banners'][0];
                if (!gallery_save($gallery)) {
                    foreach ($paths as $path) gallery_image_delete($path);
                    throw new RuntimeException("Impossible d'écrire la galerie (droits du dossier data/galeries).");
                }
                portail_flash(count($paths) . ' image' . (count($paths) > 1 ? 's' : '') . " générée" . (count($paths) > 1 ? 's' : '') . " par l'IA : elles rejoignent aussi la médiathèque commune, réutilisable par les autres galeries et boutiques. Choisissez l'image principale dans l'apparence.");
            } catch (Throwable $ex) {
                portail_flash($ex->getMessage(), 'error');
            }
            break;

        case 'style_reset':
            if (!$gallery) { portail_flash('Galerie introuvable.', 'error'); break; }
            foreach (array_merge(gallery_banner_images($gallery['style']), [(string) ($gallery['style']['logo'] ?? '')]) as $f) gallery_image_delete($f);
            $gallery['style'] = gallery_style_normalize([]);
            $gallery['accent'] = '#b5502e';
            gallery_save($gallery);
            portail_flash("Apparence d'origine rétablie.");
            break;

        case 'add_remote':
            if (!$gallery) { portail_flash('Galerie introuvable.', 'error'); break; }
            $limit = $limited ? go_member_limit($gallery) : null;
            if ($limit !== null && count($gallery['members']) >= $limit) { portail_flash('Votre offre permet ' . $limit . ' commerce' . ($limit > 1 ? 's' : '') . ' au plus : passez à une offre supérieure pour en ajouter.', 'error'); break; }
            $url = rtrim(trim((string) ($_POST['url'] ?? '')), '/');
            $name = trim((string) ($_POST['name'] ?? ''));
            if (!gallery_remote_url_valid($url)) { portail_flash("Adresse non valide : une adresse http(s) publique, par exemple https://brocante.arrimage.com", 'error'); break; }
            foreach ($gallery['members'] as $m) if ($m['type'] === 'remote' && $m['url'] === $url) { portail_flash('Ce commerce est déjà dans la galerie.', 'error'); break 2; }
            $feed = catalogue_remote($url);
            if (!$feed) { portail_flash("Cette adresse ne répond pas avec un catalogue (" . $url . "/catalogue.php) : le commerce doit être à jour de la plateforme.", 'error'); break; }
            $gallery['members'][] = ['type' => 'remote', 'name' => $name !== '' ? $name : (string) ($feed['shop']['name'] ?? $url), 'url' => $url, 'featured' => false];
            gallery_save($gallery);
            portail_flash('« ' . ($feed['shop']['name'] ?? $url) . ' » ajouté : ' . count($feed['products']) . ' pièce(s) en ligne.');
            break;

        case 'remove_remote':
            if (!$gallery) { portail_flash('Galerie introuvable.', 'error'); break; }
            $i = (int) ($_POST['index'] ?? -1);
            $gallery['members'] = array_values(array_filter($gallery['members'], static fn ($m, $k) => !($m['type'] === 'remote' && $k === $i), ARRAY_FILTER_USE_BOTH));
            gallery_save($gallery);
            portail_flash('Commerce retiré de la galerie.');
            break;

        case 'admin_add':
        case 'admin_reset':
            if (!$gallery) { portail_flash('Galerie introuvable.', 'error'); break; }
            $adminEmail = (string) ($_POST['email'] ?? '');
            [$err, $tempPassword] = ga_admin_add($slug, $adminEmail, (string) ($_POST['name'] ?? ''));
            if ($err) { portail_flash($err, 'error'); break; }
            $mailed = ga_mail(mb_strtolower(trim($adminEmail)), "Votre accès d'administrateur — " . $gallery['name'],
                "Bonjour,\n\nVous pouvez administrer la galerie « {$gallery['name']} » (présentation, commerces, tableau de bord, prospects).\n\nConnexion : $origin/galerie/admin.php\nIdentifiant : " . mb_strtolower(trim($adminEmail)) . "\nMot de passe provisoire : $tempPassword\n\nVous devrez le remplacer à votre première connexion.\n");
            $_SESSION['ga_new_password'] = ['email' => mb_strtolower(trim($adminEmail)), 'password' => $tempPassword, 'mailed' => $mailed, 'local' => $portailLocal];
            portail_flash($action === 'admin_add' ? 'Administrateur ajouté.' : 'Nouveau mot de passe généré.');
            break;

        case 'admin_remove':
            if (!$gallery) { portail_flash('Galerie introuvable.', 'error'); break; }
            ga_admin_remove($slug, (string) ($_POST['email'] ?? ''));
            portail_flash('Administrateur retiré.');
            break;

        case 'refresh':
            if ($gallery) { gallery_cache_clear($slug); portail_flash('Catalogue actualisé : la galerie relit les commerces à la prochaine visite.'); }
            break;

        case 'pause':
            if (!$gallery) { portail_flash('Galerie introuvable.', 'error'); break; }
            $gallery['paused'] = empty($gallery['paused']);
            $ok = gallery_save($gallery);
            portail_flash($ok
                ? ($gallery['paused'] ? 'Galerie mise en pause : elle n’est plus visible publiquement. Son offre n’est pas résiliée.' : 'Galerie réactivée.')
                : 'Impossible d’enregistrer le statut de la galerie.', $ok ? 'ok' : 'error');
            break;

        case 'status':
            if (!$gallery) { portail_flash('Galerie introuvable.', 'error'); break; }
            $state = (string) ($_POST['state'] ?? '');
            if (!in_array($state, ['published', 'test', 'paused'], true)) { portail_flash('Statut inconnu.', 'error'); break; }
            $gallery['published'] = $state !== 'test';
            $gallery['paused'] = $state === 'paused';
            $ok = gallery_save($gallery);
            portail_flash($ok
                ? ($state === 'published' ? 'Galerie publiée : elle est visible à son adresse publique.'
                    : ($state === 'test' ? 'Galerie en test : elle n’est plus visible que par son lien d’aperçu.'
                        : 'Galerie en pause : elle n’est plus visible publiquement, son offre n’est pas résiliée.'))
                : 'Impossible d’enregistrer le statut de la galerie.', $ok ? 'ok' : 'error');
            break;

        case 'gesture':
            if (!$gallery) { portail_flash('Galerie introuvable.', 'error'); break; }
            $kind = (string) ($_POST['gesture_kind'] ?? 'none');
            $note = mb_substr(trim((string) ($_POST['gesture_note'] ?? '')), 0, 300);
            $until = 0;
            $dateRaw = trim((string) ($_POST['gesture_until'] ?? ''));
            if ($dateRaw !== '') {
                $until = strtotime($dateRaw . ' 23:59:59') ?: 0;
                if (!$until || $until <= time()) { portail_flash('Indiquez une date de fin dans le futur, ou laissez la date vide.', 'error'); break; }
            }
            $gesture = null;
            if ($kind === 'forever') {
                $gesture = ['kind' => 'forever', 'until' => 0, 'note' => $note, 'created' => time()];
            } elseif ($kind === 'until') {
                if ($until <= 0) { portail_flash('Indiquez une date de fin de gratuité dans le futur.', 'error'); break; }
                $gesture = ['kind' => 'until', 'until' => $until, 'note' => $note, 'created' => time()];
            } elseif ($kind === 'credit') {
                $cents = (int) round(((float) str_replace(',', '.', (string) ($_POST['gesture_amount'] ?? ''))) * 100);
                if ($cents <= 0 || $cents > 1000000) { portail_flash('Indiquez un montant d’avoir entre 0,01 € et 10 000 €.', 'error'); break; }
                $gesture = ['kind' => 'credit', 'amount_cents' => $cents, 'until' => $until, 'note' => $note, 'created' => time()];
            } elseif ($kind === 'discount') {
                $percent = (int) ($_POST['gesture_percent'] ?? 0);
                if ($percent < 1 || $percent > 100) { portail_flash('Indiquez une remise entre 1 % et 100 %.', 'error'); break; }
                $gesture = ['kind' => 'discount', 'percent' => $percent, 'until' => $until, 'note' => $note, 'created' => time()];
            } elseif ($kind !== 'none') {
                portail_flash('Geste commercial inconnu.', 'error');
                break;
            }
            $gallery['gesture'] = $gesture;
            $ok = gallery_save($gallery);
            $billing = in_array($kind, ['credit', 'discount'], true);
            portail_flash($ok
                ? ($gesture
                    ? ($billing
                        ? 'Geste de facturation enregistré. Il ne modifie pas automatiquement Stripe : appliquez l’avoir ou la remise dans Stripe pour la même période.'
                        : 'Geste commercial enregistré : la galerie reste ouverte sans facturation pendant la période.')
                    : 'Geste commercial retiré.')
                : 'Impossible d’enregistrer le geste commercial.', $ok ? 'ok' : 'error');
            break;

        case 'delete':
            if ($gallery && trim((string) ($_POST['confirm'] ?? '')) === $slug) {
                $deleted = gallery_delete($slug);
                portail_flash($deleted
                    ? 'Galerie « ' . $gallery['name'] . ' » supprimée (les commerces ne sont pas touchés).'
                    : 'Suppression incomplète : impossible de retirer le fichier de la galerie ou une de ses images.', $deleted ? 'ok' : 'error');
            }
            else portail_flash("Pour confirmer, tapez l'identifiant de la galerie.", 'error');
            break;
    }
    saas_audit_flash('Galeries');
    header('Location: ' . $back);
    exit;
}

$flash = portail_flash();
$galleries = gallery_list();
$edit = $limited ? gallery_load($limited) : (isset($_GET['g']) ? gallery_load(strtolower((string) $_GET['g'])) : null);
$confirmDelete = !$limited && $edit && ($_GET['supprimer'] ?? '') === '1';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Galeries commerciales — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .member { display: flex; align-items: center; gap: 12px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; }
  .member .name { flex: 1; } .member small { opacity: .7; }
  .place-row { border: 1px solid var(--line); border-radius: 8px; padding: 10px 12px; margin: 0 0 10px; display: grid; gap: 8px; min-width: 0; }
  .place-row legend { font-weight: 700; padding: 0 6px; }
  .place-row .row { gap: 8px; }
  .swatch-in { width: 44px; height: 34px; padding: 0; border: 1px solid var(--line); border-radius: 6px; background: none; }
  .gl { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; padding: 10px 0; border-top: 1px solid var(--line); flex-wrap: wrap; }
  .gl:first-child { border-top: 0; }
  .gl > div { min-width: 0; }
  .gl .pill { white-space: nowrap; }
  @media (max-width: 560px) { .gl > .row { width: 100%; } }
  .style-layout { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 18px; align-items: start; } @media (max-width: 900px) { .style-layout { grid-template-columns: 1fr; } }
  .style-fields fieldset { border: 1px solid var(--line); border-radius: 8px; padding: 12px 14px; margin: 0 0 12px; display: grid; gap: 10px; min-width: 0; } .style-fields legend { font-weight: 700; padding: 0 6px; }
  .style-fields select, .style-fields input[type=file] { padding: 7px 8px; border: 1px solid var(--line); border-radius: 8px; background: var(--panel); color: inherit; font: inherit; width: 100%; max-width: 100%; }
  .style-fields label.sw { flex: none; min-width: 0; } .presets { display: flex; flex-wrap: wrap; gap: 6px; }
  .preset { display: inline-flex; align-items: center; gap: 4px; border: 1px solid var(--line); background: var(--panel-2); border-radius: 8px; padding: 4px 8px 4px 5px; cursor: pointer; font: inherit; font-size: .78rem; color: inherit; }
  .preset i { width: 14px; height: 14px; border-radius: 50%; border: 1px solid rgba(0,0,0,.15); display: inline-block; } .preset:hover { border-color: var(--accent); }
  .thumb { width: 84px; height: 48px; border-radius: 6px; border: 1px solid var(--line); background-size: cover; background-position: center; display: inline-block; } .thumb.logo { background-size: contain; background-repeat: no-repeat; background-color: #fff; }
  .shots { display: flex; flex-wrap: wrap; gap: 10px; } .shots label { display: grid; gap: 3px; font-size: .76rem; }
  .shots .thumb { width: 132px; height: 74px; } .shots .first .thumb { outline: 2px solid var(--accent); outline-offset: 1px; }
  .shots.library { display: grid; grid-template-columns: repeat(auto-fill, minmax(132px, 1fr)); }
  .shots.library .thumb { width: 100%; height: auto; aspect-ratio: 16 / 9; }
  .shots label.pick { padding: 4px; border: 1px solid var(--line); border-radius: 8px; background: var(--panel-2); }
  .shots label.pick:has(input:checked) { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); }
  .style-preview { position: sticky; top: 12px; } .pv { border: 1px solid var(--line); border-radius: 10px; overflow: hidden; background: var(--bg); color: var(--ink); font-family: var(--f-body, inherit); }
  .pv-hero { position: relative; background: var(--hero-bg); color: var(--hero-ink); padding: 22px 16px; overflow: hidden; } .pv-hero.h-compact { padding: 12px 16px; } .pv-hero.h-tall { padding: 44px 16px 24px; }
  .pv-media, .pv-shade { position: absolute; inset: 0; background-size: cover; } .pv-in { position: relative; } .pv-hero.a-center { text-align: center; } .pv-hero.a-center .pv-in img { margin-inline: auto; }
  .pv-in img { max-height: 36px; max-width: 120px; margin-bottom: 8px; display: block; } .pv-eyebrow { margin: 0 0 4px; font-size: .62rem; letter-spacing: .14em; text-transform: uppercase; opacity: .8; font-weight: 700; }
  .pv-in h3 { margin: 0; font-family: var(--f-display, inherit); font-size: 1.4rem; line-height: 1.05; color: inherit; } .pv-tag { margin: 6px 0 10px; font-size: .78rem; opacity: .92; }
  .pv-btn { display: inline-block; background: var(--hero-ink); color: var(--hero-bg); font-weight: 700; font-size: .72rem; padding: 6px 10px; border-radius: var(--r); } .pv-btn[hidden] { display: none; }
  .pv-body { padding: 12px 14px 14px; } .pv-h { margin: 0 0 8px; font-family: var(--f-display, inherit); font-weight: 700; } .pv-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; } .pv-cards i { aspect-ratio: 4 / 5; background: var(--surface); border: 1px solid var(--line); border-radius: var(--r); }
  code.url { font-family: 'IBM Plex Mono', monospace; font-size: .82rem; overflow-wrap: anywhere; }
</style>
</head>
<body>
<div class="app">
<?php
$gaNavHtml = '';
if ($limited || $edit) {
    ob_start();
    include __DIR__ . '/_galerie-nav.php';
    $gaNavHtml = ob_get_clean();
}
$gaActions = '';
if (!$limited) {
    if ($edit) {
        $gaActions .= '<a class="btn" href="/portail/galerie-tableau.php?g=' . e($edit['slug']) . '">Tableau de bord</a>';
        $gaActions .= '<a class="btn" href="/portail/galerie-prospects.php?g=' . e($edit['slug']) . '">Prospects</a>';
    }
    $gaActions .= '<a class="btn" href="/portail/offres-galerie.php">Types de galerie</a>';
}
portail_page_start([
    'title' => 'Galeries commerciales',
    'eyebrow' => 'Boutiques',
    'desc' => "Regrouper plusieurs commerces sur une page commune : une rue, un village, un groupe d'amis…",
    'nav_html' => $gaNavHtml,
    'actions' => $gaActions,
    'section' => $limited ? 'Galerie' : 'Boutiques',
]);
?>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="gwrap ga-page">
  <?php if (!$limited): $platformStripe = saas_stripe_modes_status(); ?>
    <section class="gcard" id="stripe">
      <h2>Paiement des galeries (Stripe plateforme)
        <span class="pill <?= $platformStripe['modes'][$platformStripe['mode']]['configured'] ? 'done' : 'sent' ?>">Mode <?= e(STRIPE_MODES[$platformStripe['mode']]) ?></span>
      </h2>
      <p class="meta">Les abonnements des galeries passent par le compte Stripe de la plateforme : <strong>mode test</strong> pour les essais, <strong>mode réel</strong> pour encaisser les clients. Les clés des deux modes sont conservées, la bascule ne demande pas de les recoller.</p>
      <p class="meta">Test : <?= $platformStripe['modes']['test']['configured'] ? 'clé ' . e($platformStripe['modes']['test']['secret_mask']) : 'sans clé' ?>
        · Réel : <?= $platformStripe['modes']['live']['configured'] ? 'clé ' . e($platformStripe['modes']['live']['secret_mask']) : 'sans clé' ?></p>
      <a class="btn btn-primary" href="/portail/stripe.php#plateforme">Basculer le mode / gérer les clés</a>
    </section>
  <?php endif; ?>
  <?php if (!$edit): ?>
    <section class="gcard">
      <h2>Mes galeries</h2>
      <?php if (!$galleries): ?><p class="meta">Aucune galerie pour le moment : créez la première ci-dessous.</p><?php endif; ?>
      <?php foreach ($galleries as $g): $url = gallery_public_url($g, $origin); $live = go_is_live($g); $paused = !empty($g['paused']); $inTest = !$paused && empty($g['published']); ?>
        <div class="gl">
          <div>
            <strong><?= e($g['name']) ?></strong>
            <span class="pill <?= $paused || $inTest ? 'sent' : 'done' ?>"><?= $paused ? 'En pause' : ($inTest ? 'En test' : 'Publiée') ?></span>
            <?php if (!empty($g['gesture'])): ?><span class="pill <?= go_gesture_current($g) ? 'done' : 'sent' ?>" title="<?= e((string) ($g['gesture']['note'] ?? '')) ?>"><?= e(go_gesture_label($g)) ?></span><?php endif; ?>
            <span class="pill"><?= e(gt_of($g)['emoji'] . ' ' . gt_of($g)['name']) ?></span>
            <span class="pill"><?= e(business_presence_label($g['presence_mode'])) ?></span><br>
            <small class="meta"><?= count($g['members']) ?> <?= e(count($g['members']) > 1 ? gt_word($g, 'members') : gt_word($g, 'member')) ?> · <code class="url"><?= e($url) ?></code></small>
          </div>
          <div class="row"><a class="btn small btn-primary" href="/portail/galeries.php?g=<?= e($g['slug']) ?>">Gérer</a>
            <?php if (gt_feature($g, 'plan')): ?><a class="btn small" href="/portail/galerie-plan.php?g=<?= e($g['slug']) ?>">Plan</a><?php endif; ?>
            <?php if (!$limited): ?><a class="btn small" href="/portail/galeries.php?g=<?= e($g['slug']) ?>#admins">Comptes admin</a><?php endif; ?>
            <?php if (!$limited): ?>
              <form method="post" class="row" style="gap:6px;"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>"><input type="hidden" name="action" value="status">
                <?php if (!$live): ?><button class="btn small" type="submit" name="state" value="published">Publier</button><?php endif; ?>
                <?php if ($live): ?><button class="btn small" type="submit" name="state" value="test">Mettre en test</button>
                  <button class="btn small" type="submit" name="state" value="paused">Mettre en pause</button><?php elseif ($paused): ?><button class="btn small" type="submit" name="state" value="test">Mettre en test</button><?php endif; ?>
              </form>
              <a class="btn small" href="/portail/galeries.php?g=<?= e($g['slug']) ?>#geste">Geste commercial</a>
            <?php endif; ?>
            <form method="post" class="row"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>"><button class="btn small" type="submit" name="action" value="export">Exporter JSON</button></form>
            <a class="btn small" href="<?= e($url . ($live ? '' : '?apercu=' . gallery_preview_token($g))) ?>" target="_blank" rel="noopener"><?= $live ? 'Voir' : 'Aperçu' ?></a>
            <?php if (!$limited): ?><a class="btn small danger" href="/portail/galeries.php?g=<?= e($g['slug']) ?>&amp;supprimer=1#supprimer">Supprimer</a><?php endif; ?></div>
        </div>
      <?php endforeach; ?>
    </section>

    <?php if (!$limited): ?>
    <form class="gcard" method="post" enctype="multipart/form-data">
      <h2>Importer une galerie</h2>
      <p class="meta">Importez un export JSON Brocante (40 Mo maximum). Les images sont incluses ; les boutiques membres restent inchangées. Les comptes administrateurs et l’offre Stripe ne sont jamais importés. Une nouvelle galerie est créée en test.</p>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="import">
      <div class="row">
        <label class="f">Fichier JSON<input type="file" name="gallery_file" accept="application/json,.json" required></label>
        <label class="f">Action
          <select name="import_mode" id="import-mode"><option value="new">Créer sous un nouvel identifiant</option><option value="replace">Remplacer une galerie existante</option></select>
        </label>
        <label class="f">Identifiant cible (facultatif pour une nouvelle galerie)<input type="text" name="target_slug" maxlength="40" pattern="[a-z0-9][a-z0-9\-]{1,39}" placeholder="par défaut : identifiant de l’export"></label>
      </div>
      <p><button class="btn btn-primary" type="submit" onclick="return confirm(document.getElementById('import-mode').value === 'replace' ? 'Remplacer la configuration de la galerie sélectionnée ? Ses administrateurs et son offre seront conservés.' : 'Créer cette galerie en test ?')">Importer la galerie</button></p>
    </form>
    <?php endif; ?>

    <form class="gcard" method="post" autocomplete="off">
      <h2>Nouvelle galerie</h2>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create">
      <div class="row">
        <label class="f">Nom<input type="text" name="name" required maxlength="80" placeholder="La Rue des Arts"></label>
        <label class="f">Identifiant (adresse) <input type="text" name="slug" maxlength="40" pattern="[a-z0-9][a-z0-9\-]{1,39}" placeholder="rue-des-arts (laisser vide : automatique)"></label>
      </div>
      <div class="row">
        <label class="f">Slogan<input type="text" name="tagline" maxlength="160" placeholder="Les commerçants de la rue, réunis en ligne"></label>
        <label class="f" style="flex:none;min-width:0;">Couleur<input class="swatch-in" type="color" name="accent" value="#b5502e"></label>
        <label class="f">Type<select name="type"><?php foreach (gt_types() as $tk => $tt): ?><option value="<?= e($tk) ?>"><?= e($tt['emoji'] . ' ' . $tt['name']) ?></option><?php endforeach; ?></select></label>
        <label class="f">Présence<select name="presence_mode"><?php foreach (BUSINESS_PRESENCE_MODES as $key => $label): ?><option value="<?= e($key) ?>"<?= $key === 'online' ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      </div>
      <p><button class="btn btn-primary" type="submit">Créer la galerie</button></p>
    </form>

  <?php else: $g = $edit; $url = gallery_public_url($g, $origin); $remoteIndex = []; foreach ($g['members'] as $i => $m) if ($m['type'] === 'remote') $remoteIndex[$i] = $m; ?>
    <?php if ($limited && ($st = go_status($g)) !== 'manual'): $lim = go_member_limit($g); ?>
      <p class="flash" data-kind="<?= in_array($st, ['expired', 'canceled'], true) ? 'error' : 'ok' ?>" role="status">Offre « <?= e(go_offer($g['plan']['offer'], gt_of($g)['key'])['name'] ?? $g['plan']['offer']) ?> » — <?= e(GO_PLAN_STATUS[$st] ?? $st) ?><?= $st === 'trial' ? ' jusqu\'au ' . e(date('d/m/Y', $g['plan']['trial_until'])) : '' ?> · <?= count($g['members']) ?>/<?= (int) $lim ?> commerces.
        <?php if (in_array($st, ['expired', 'canceled'], true)): ?>Votre galerie n'est plus visible du public. <?php endif; ?><a href="/portail/galerie-offre.php">Voir mon offre</a></p>
    <?php endif; ?>
    <section class="gcard" id="statut">
      <h2><?= e($g['name']) ?> <span class="pill <?= !empty($g['paused']) || !$g['published'] ? 'sent' : 'done' ?>"><?= !empty($g['paused']) ? 'En pause' : ($g['published'] ? 'Publiée' : 'En test') ?></span>
        <?php if (!empty($g['gesture'])): ?><span class="pill <?= go_gesture_current($g) ? 'done' : 'sent' ?>"><?= e(go_gesture_label($g)) ?></span><?php endif; ?>
        <span class="pill"><?= e(business_presence_label($g['presence_mode'])) ?></span></h2>
      <p class="meta">Adresse publique : <a href="<?= e($url) ?><?= go_is_live($g) ? '' : '?apercu=' . e(gallery_preview_token($g)) ?>" target="_blank" rel="noopener"><code class="url"><?= e($url) ?></code></a>
        <?= !empty($g['paused']) ? ' — galerie en pause : seul ce lien d\'aperçu l\'ouvre.' : ($g['published'] ? '' : ' — en test : seul ce lien d\'aperçu l\'ouvre.') ?></p>
      <?php if (!$limited): ?>
      <form method="post" class="row" style="align-items:center;gap:6px;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>"><input type="hidden" name="action" value="status">
        <span class="meta" style="margin:0;">Statut de la galerie :</span>
        <button class="btn small<?= go_is_live($g) ? ' btn-primary' : '' ?>" type="submit" name="state" value="published"<?= go_is_live($g) ? ' disabled' : '' ?>>Publiée — visible de tous</button>
        <button class="btn small<?= !$g['published'] ? ' btn-primary' : '' ?>" type="submit" name="state" value="test"<?= !$g['published'] ? ' disabled' : '' ?>>En test — lien d'aperçu seulement</button>
        <button class="btn small<?= !empty($g['paused']) ? ' btn-primary' : '' ?>" type="submit" name="state" value="paused"<?= !empty($g['paused']) ? ' disabled' : '' ?>>En pause — fermée au public</button>
      </form>
      <?php else: ?>
      <form method="post" class="row"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>">
        <button class="btn small" type="submit" name="action" value="pause"><?= !empty($g['paused']) ? 'Réactiver la galerie' : 'Mettre en pause' ?></button>
      </form>
      <?php endif; ?>
      <p><a class="btn small" href="/portail/addons.php?scope=gallery&amp;gallery=<?= e(rawurlencode($g['slug'])) ?>">Gérer les plugins et tarifs des boutiques membres</a></p>
      <form method="post" class="row"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>">
        <button class="btn small" type="submit" name="action" value="refresh">Actualiser le catalogue maintenant</button>
        <button class="btn small" type="submit" name="action" value="export">Exporter JSON</button>
        <?php if (!$limited): ?><a class="btn small danger" href="/portail/galeries.php?g=<?= e($g['slug']) ?>&amp;supprimer=1#supprimer">Supprimer la galerie</a><?php endif; ?>
        <span class="meta">Le catalogue est relu toutes les 10 minutes.</span></form>
      <?php if ($confirmDelete): ?>
        <form class="confirm danger-zone" method="post" autocomplete="off" id="supprimer">
          <span>Supprimer la galerie <strong><?= e($g['name']) ?></strong> ? Les commerces et leurs pièces ne sont pas touchés.</span>
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>">
          <label class="field">Pour confirmer, tapez l'identifiant <code><?= e($g['slug']) ?></code><input name="confirm" required autocapitalize="none" autocomplete="off" spellcheck="false" placeholder="<?= e($g['slug']) ?>"></label>
          <span class="actions" style="margin:0;"><button class="btn small danger-solid" type="submit">Supprimer définitivement</button> <a class="btn small" href="/portail/galeries.php?g=<?= e($g['slug']) ?>">Annuler</a></span>
        </form>
      <?php endif; ?>
    </section>

    <?php if (!$limited): $ges = $g['gesture'] ?? null; ?>
    <section class="gcard" id="geste">
      <h2>Geste commercial</h2>
      <p class="meta">Offrir un avantage à votre guise : <strong>gratuité</strong> (totale ou jusqu'à une date), <strong>avoir</strong> en euros ou <strong>remise</strong> en pourcentage. La gratuité garde la galerie ouverte et visible pendant le geste, même si l'essai est terminé ou l'abonnement résilié ; l'avoir et la remise sont seulement des gestes de facturation, mémorisés ici pour le suivi. <a href="/accueil/conditions.php#geste" target="_blank" rel="noopener">Voir la clause de geste commercial</a>.</p>
      <?php if ($ges): ?>
        <p class="flash" data-kind="<?= go_gesture_current($g) ? 'ok' : 'error' ?>" role="status"><strong><?= e(go_gesture_label($g)) ?></strong><?php if (($ges['note'] ?? '') !== ''): ?> — <?= e($ges['note']) ?><?php endif; ?>
          <?php if (!empty($ges['until']) && (int) $ges['until'] <= time()): ?> (terminé le <?= e(date('d/m/Y', (int) $ges['until'])) ?>)<?php endif; ?></p>
      <?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>"><input type="hidden" name="action" value="gesture">
        <div class="row">
          <label class="f" style="flex:none;min-width:0;"><span><input type="radio" name="gesture_kind" value="none"<?= !$ges ? ' checked' : '' ?>> Aucun geste</span></label>
          <label class="f" style="flex:none;min-width:0;"><span><input type="radio" name="gesture_kind" value="forever"<?= ($ges['kind'] ?? '') === 'forever' ? ' checked' : '' ?>> Gratuité totale</span></label>
          <label class="f" style="flex:none;min-width:0;"><span><input type="radio" name="gesture_kind" value="until"<?= ($ges['kind'] ?? '') === 'until' ? ' checked' : '' ?>> Gratuit jusqu'à une date</span></label>
          <label class="f" style="flex:none;min-width:0;"><span><input type="radio" name="gesture_kind" value="credit"<?= ($ges['kind'] ?? '') === 'credit' ? ' checked' : '' ?>> Avoir en euros</span></label>
          <label class="f" style="flex:none;min-width:0;"><span><input type="radio" name="gesture_kind" value="discount"<?= ($ges['kind'] ?? '') === 'discount' ? ' checked' : '' ?>> Remise en %</span></label>
        </div>
        <div class="row">
          <label class="f">Montant de l'avoir (€)<input type="text" name="gesture_amount" inputmode="decimal" value="<?= ($ges['kind'] ?? '') === 'credit' && !empty($ges['amount_cents']) ? e(number_format((int) $ges['amount_cents'] / 100, 2, ',', '')) : '' ?>" placeholder="ex. 29,00"></label>
          <label class="f">Pourcentage de remise (%)<input type="number" name="gesture_percent" min="1" max="100" step="1" value="<?= ($ges['kind'] ?? '') === 'discount' && !empty($ges['percent']) ? (int) $ges['percent'] : '' ?>" placeholder="ex. 20"></label>
          <label class="f">Fin de validité (facultative, incluse)<input type="date" name="gesture_until" value="<?= !empty($ges['until']) ? e(date('Y-m-d', (int) $ges['until'])) : '' ?>"></label>
        </div>
        <label class="f">Note interne (facultative)<input type="text" name="gesture_note" maxlength="300" value="<?= e((string) ($ges['note'] ?? '')) ?>" placeholder="ex. dédommagement incident du 2 novembre, offert au comité des fêtes pour l'inauguration"></label>
        <p><button class="btn btn-primary" type="submit">Enregistrer le geste commercial</button></p>
        <p class="meta">L'avoir et la remise ne modifient pas automatiquement Stripe : appliquez le coupon ou l'avoir dans Stripe pour la même période. La gratuité non plus ne suspend pas un abonnement : pensez à le mettre en pause dans Stripe.</p>
        <?php if (!empty($g['plan']['stripe_subscription_id'])): ?>
          <p class="meta">Abonnement Stripe de cette galerie : <code class="url"><?= e((string) $g['plan']['stripe_subscription_id']) ?></code>.</p>
        <?php endif; ?>
      </form>
    </section>
    <?php endif; ?>

    <?php if (!$limited): $newPw = $_SESSION['ga_new_password'] ?? null; unset($_SESSION['ga_new_password']); ?>
    <section class="gcard" id="admins">
      <h2>Administrateurs de la galerie</h2>
      <p class="meta">Ils se connectent sur <code class="url"><?= e($origin) ?>/galerie/admin.php</code> et gèrent cette galerie seulement : présentation, commerces membres, publication, tableau de bord et prospects.</p>
      <?php if ($portailLocal): ?><p class="meta">En local, aucun e-mail ne part réellement : les messages sont recopiés dans <code>data/mail-outbox.log</code> — <a href="/portail/journal-local.php?canal=mail">ouvrir la boîte locale</a> (recherche par adresse, objet ou lien).</p><?php endif; ?>
      <?php if ($newPw): ?>
        <p class="flash" data-kind="ok" role="status">Mot de passe provisoire de <strong><?= e($newPw['email']) ?></strong> : <code><?= e($newPw['password']) ?></code> — <?= !empty($newPw['local']) ? "recopié dans <code>data/mail-outbox.log</code> ; il ne sera plus affiché." : ($newPw['mailed'] ? "envoyé par e-mail ; il ne sera plus affiché." : "l'e-mail n'a pas pu partir : transmettez-le-lui vous-même, il ne sera plus affiché.") ?></p>
      <?php endif; ?>
      <?php foreach ($g['admins'] as $a): ?>
        <div class="member">
          <span class="name"><strong><?= e($a['name'] !== '' ? $a['name'] : $a['email']) ?></strong> <small><?= e($a['email']) ?> · <?= $a['last_login'] ? 'connecté le ' . e(date('d/m/Y H:i', $a['last_login'])) : 'jamais connecté' ?><?= $a['must_change'] ? ' · mot de passe provisoire' : '' ?></small></span>
          <form method="post" class="row" style="gap:6px;"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>"><input type="hidden" name="email" value="<?= e($a['email']) ?>"><input type="hidden" name="name" value="<?= e($a['name']) ?>">
            <button class="btn small" type="submit" name="action" value="admin_reset" onclick="return confirm('Générer un nouveau mot de passe provisoire et l\'envoyer par e-mail ?')">Nouveau mot de passe</button>
            <button class="btn small danger" type="submit" name="action" value="admin_remove" onclick="return confirm('Retirer cet administrateur ?')">Retirer</button></form>
        </div>
      <?php endforeach; ?>
      <?php if (!$g['admins']): ?><p class="meta">Aucun administrateur pour le moment.</p><?php endif; ?>
      <form method="post" class="row" autocomplete="off"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="admin_add"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>">
        <label class="f">Adresse e-mail<input type="email" name="email" required maxlength="160" placeholder="responsable@exemple.fr"></label>
        <label class="f">Nom (facultatif)<input type="text" name="name" maxlength="80"></label>
        <button class="btn btn-primary" type="submit">Ajouter et envoyer l'accès</button>
      </form>
    </section>
    <?php else: ?>
    <p class="meta">Les comptes administrateurs de la galerie se gèrent depuis le compte exploitant du portail : un administrateur de galerie ne peut pas modifier ses pairs.</p>
    <?php endif; ?>

    <form class="gcard" method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>">
      <h2>Présentation</h2>
      <div class="row">
        <label class="f">Nom<input type="text" name="name" required maxlength="80" value="<?= e($g['name']) ?>"></label>
        <label class="f">Présence<select name="presence_mode"><?php foreach (BUSINESS_PRESENCE_MODES as $key => $label): ?><option value="<?= e($key) ?>"<?= $g['presence_mode'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
      </div>
      <label class="f">Slogan<input type="text" name="tagline" maxlength="160" value="<?= e($g['tagline']) ?>"></label>
      <label class="f">Présentation (facultative)<textarea name="description" maxlength="1200"><?= e($g['description']) ?></textarea></label>

      <h2 style="margin-top:8px;">Noms des applications</h2>
      <p class="meta">Le nom affiché sous l'icône quand la galerie est installée sur un téléphone. Un champ vide garde le nom par défaut ; renommer ne casse pas l'installation.</p>
      <div class="row">
        <label class="f">Administration — organisateur et exposants
          <input type="text" name="app_name_admin" maxlength="40" value="<?= e($g['apps']['admin'] ?? '') ?>" placeholder="<?= e('Admin ' . $g['name']) ?>">
        </label>
        <label class="f">Espace client — visiteurs
          <input type="text" name="app_name_visitor" maxlength="40" value="<?= e($g['apps']['visitor'] ?? '') ?>" placeholder="<?= e($g['name']) ?>">
        </label>
      </div>

      <h2 style="margin-top:8px;"><?= e(ucfirst(gt_word($g, 'members'))) ?> de ce serveur</h2>
      <p class="meta">Cochez ceux qui font partie de la galerie ; ★ les met en avant dans l'annuaire. Seules les pièces visibles et en stock sont présentées.</p>
      <?php $inGallery = []; foreach ($g['members'] as $m) if ($m['type'] === 'local') $inGallery[$m['slug']] = $m; ?>
      <?php foreach ($shops as $slug => $shop): $on = isset($inGallery[$slug]); ?>
        <div class="member">
          <input type="checkbox" id="m-<?= e($slug) ?>" name="member[]" value="<?= e($slug) ?>"<?= $on ? ' checked' : '' ?>>
          <label class="name" for="m-<?= e($slug) ?>"><strong><?= e($shop['name']) ?></strong> <small>— <?= e($slug) ?><?= $shop['tagline'] !== '' ? ' · ' . e($shop['tagline']) : '' ?></small></label>
          <label><input type="checkbox" name="featured[]" value="<?= e($slug) ?>"<?= $on && $inGallery[$slug]['featured'] ? ' checked' : '' ?>> ★ à la une</label>
        </div>
      <?php endforeach; ?>

      <?php if ($remoteIndex): ?>
        <h2 style="margin-top:8px;"><?= e(ucfirst(gt_word($g, 'members'))) ?> d'ailleurs</h2>
        <?php foreach ($remoteIndex as $i => $m): ?>
          <div class="member"><span class="name"><strong><?= e($m['name'] ?: $m['url']) ?></strong> <small><code class="url"><?= e($m['url']) ?></code></small></span>
            <label><input type="checkbox" name="remote_featured[]" value="<?= (int) $i ?>"<?= $m['featured'] ? ' checked' : '' ?>> ★ à la une</label>
            <button class="btn small danger" type="submit" form="rm-<?= (int) $i ?>">Retirer</button></div>
        <?php endforeach; ?>
      <?php endif; ?>

      <h2 style="margin-top:8px;">Publication</h2>
      <label class="member"><input type="checkbox" name="published" value="1"<?= $g['published'] ? ' checked' : '' ?>> <span class="name"><strong>Publier la galerie</strong> <small>— visible de tous à l'adresse publique et dans la liste des galeries ; décochée, elle reste en test (lien d'aperçu seulement)</small></span></label>
      <label class="f" style="margin-top:8px;">Nom de domaine propre (facultatif)
        <input type="text" name="domain" maxlength="253" value="<?= e($g['domain'] ?? '') ?>" placeholder="galerie-exemple.fr">
        <span class="meta" style="font-weight:400;">Le domaine doit pointer vers ce serveur (enregistrement DNS) et disposer d'un certificat HTTPS. Laissez vide pour garder l'adresse <code class="url"><?= e($origin) ?>/galerie/<?= e($g['slug']) ?>/</code>.</span>
      </label>
      <p><button class="btn btn-primary" type="submit">Enregistrer</button></p>
    </form>
    <?php foreach ($remoteIndex as $i => $m): ?>
      <form id="rm-<?= (int) $i ?>" method="post" hidden><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remove_remote"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>"><input type="hidden" name="index" value="<?= (int) $i ?>"></form>
    <?php endforeach; ?>

    <?php
      // Localisation de la marchandise : une adresse par commerce membre, reprise par le champ « adresse du bien » des plateformes.
      // Les coordonnées éventuelles viennent du plan (repère ou centre de l'emplacement du commerce) pour retrouver l'adresse d'un clic.
      $placeRows = [];
      foreach ($g['members'] as $m) {
          $key = gallery_member_key($m);
          $placeRows[$key] = ['key' => $key, 'remote' => $m['type'] === 'remote',
            'name' => $m['type'] === 'local' ? (string) ($shops[$m['slug']]['name'] ?? $m['slug']) : ($m['name'] !== '' ? $m['name'] : $m['url'])];
      }
      $placeSpots = [];
      foreach (($g['map']['items'] ?? []) as $it) {
          $k = (string) ($it['shop'] ?? '');
          if ($k === '' || !isset($placeRows[$k]) || in_array($it['role'] ?? '', ['zone', 'territoire'], true)) continue;
          [$plat, $plng] = gallery_map_centroid($it['pts']);
          if (!isset($placeSpots[$k]) || $it['kind'] === 'marker') $placeSpots[$k] = [round($plat, 7), round($plng, 7)];
      }
      $placeValues = (array) ($g['map']['places'] ?? []);
    ?>
    <section class="gcard" id="placesCard">
      <h2>Localisation de la marchandise</h2>
      <p class="meta">Où se trouve la marchandise de chaque commerce ? Cette adresse alimente le champ « adresse du bien » des plateformes de vente (Leboncoin, Vinted…). Chaque boutique peut aussi la renseigner depuis son espace « Mes galeries ».</p>
      <?php if (!$placeRows): ?>
        <p class="meta">Ajoutez d'abord des commerces membres dans « Présentation ».</p>
      <?php else: ?>
      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="places"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>">
        <?php foreach ($placeRows as $r): $pl = $placeValues[$r['key']] ?? []; $id = 'pl-' . $r['key']; ?>
          <fieldset class="place-row">
            <legend><?= e($r['name']) ?><?= $r['remote'] ? ' — commerce d\'ailleurs' : '' ?></legend>
            <div class="row">
              <label class="f">Rue et numéro<input type="text" id="<?= e($id) ?>-street" name="place[<?= e($r['key']) ?>][street]" maxlength="120" value="<?= e($pl['street'] ?? '') ?>" placeholder="12 rue des Fleurs"></label>
              <label class="f">Code postal<input type="text" id="<?= e($id) ?>-postcode" name="place[<?= e($r['key']) ?>][postcode]" maxlength="10" inputmode="numeric" value="<?= e($pl['postcode'] ?? '') ?>" placeholder="75011"></label>
              <label class="f">Ville<input type="text" id="<?= e($id) ?>-city" name="place[<?= e($r['key']) ?>][city]" maxlength="60" value="<?= e($pl['city'] ?? '') ?>" placeholder="Paris"></label>
              <label class="f">Stand (facultatif)<input type="text" name="place[<?= e($r['key']) ?>][stand]" maxlength="12" value="<?= e($pl['stand'] ?? '') ?>" placeholder="A12"></label>
            </div>
            <label class="f">Précision (facultatif)<input type="text" name="place[<?= e($r['key']) ?>][note]" maxlength="200" value="<?= e($pl['note'] ?? '') ?>" placeholder="Derrière la halle, entrée par la cour…"></label>
            <?php if (!empty($placeSpots[$r['key']])): ?>
              <p class="meta" style="margin:0;"><button class="btn small" type="button" data-place-from="<?= e($id) ?>" data-lat="<?= e((string) $placeSpots[$r['key']][0]) ?>" data-lng="<?= e((string) $placeSpots[$r['key']][1]) ?>">Reprendre l'adresse du repère sur le plan</button></p>
            <?php endif; ?>
          </fieldset>
        <?php endforeach; ?>
        <p><button class="btn btn-primary" type="submit">Enregistrer les localisations</button></p>
      </form>
      <?php endif; ?>
    </section>
    <script>
    (function () {
      // Adresse à partir des coordonnées du plan : IGN (France), puis Photon en secours hors de France.
      function fill(id, p) {
        var num = p.housenumber ? String(p.housenumber) + ' ' : '';
        var street = p.street || p.name || p.locality || '';
        var s = document.getElementById(id + '-street'), pc = document.getElementById(id + '-postcode'), c = document.getElementById(id + '-city');
        if (s) s.value = (num + street).trim();
        if (pc) pc.value = p.postcode || '';
        if (c) c.value = p.city || p.town || p.village || p.municipality || '';
        return !!(street || pc.value || c.value);
      }
      document.querySelectorAll('[data-place-from]').forEach(function (b) {
        var label = b.textContent;
        function done(text) {
          b.textContent = text;
          b.disabled = false;
          if (text !== label) setTimeout(function () { b.textContent = label; }, 2500);
        }
        b.addEventListener('click', function () {
          var lat = encodeURIComponent(b.dataset.lat), lng = encodeURIComponent(b.dataset.lng), id = b.dataset.placeFrom;
          b.disabled = true; b.textContent = 'Recherche de l\'adresse…';
          fetch('https://data.geopf.fr/geocodage/reverse?index=address&limit=1&lat=' + lat + '&lon=' + lng)
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
              var f = j && j.features && j.features[0];
              if (f && f.properties && fill(id, f.properties)) { done('Adresse reprise du repère'); return; }
              return fetch('https://photon.komoot.io/reverse?lang=fr&limit=1&lat=' + lat + '&lon=' + lng)
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (q) {
                  var g = q && q.features && q.features[0];
                  done(g && g.properties && fill(id, g.properties) ? 'Adresse reprise du repère' : 'Adresse introuvable : saisissez-la');
                });
            })
            .catch(function () { done('Recherche indisponible : saisissez l\'adresse'); });
        });
      });
    })();
    </script>

    <?php
      $st = $g['style'];
      $fontUrls = [];
      foreach (APPEARANCE_FONTS as $role => $list) foreach ($list as $name => [$param]) $fontUrls[$name] = 'https://fonts.googleapis.com/css2?family=' . $param . '&display=swap';
      $sel = static fn (string $key, string $value): string => implode('', array_map(static fn ($k, $l) => '<option value="' . e((string) $k) . '"' . ((string) $k === $value ? ' selected' : '') . '>' . e($l) . '</option>', array_keys(GALLERY_STYLE_CHOICES[$key]), GALLERY_STYLE_CHOICES[$key]));
      $fontSel = static fn (string $role, string $value): string => '<option value="">Par défaut</option>' . implode('', array_map(static fn ($n, $f) => '<option value="' . e($n) . '"' . ($n === $value ? ' selected' : '') . '>' . e($n) . ' — ' . e($f[2]) . '</option>', array_keys(APPEARANCE_FONTS[$role]), APPEARANCE_FONTS[$role]));
      // Médiathèque commune : bandeaux partagés non réservés et pas déjà présents dans cette galerie.
      $usedHashes = [];
      foreach (gallery_banner_images($st) as $usedPath) { $usedAbs = dirname(__DIR__) . '/' . $usedPath; if (is_file($usedAbs)) $usedHashes[md5_file($usedAbs)] = true; }
      $libraryBanners = [];
      foreach (array_slice(banner_library_list(), 0, 12) as $item) {
          if (!empty($item['exclusive'])) continue;
          $itemAbs = banner_library_dir() . '/' . (string) $item['file'];
          if (!is_file($itemAbs) || isset($usedHashes[md5_file($itemAbs)])) continue;
          $libraryBanners[] = $item;
      }
    ?>
    <form class="gcard" method="post" enctype="multipart/form-data" autocomplete="off" id="style-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="style"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>">
      <h2>Apparence de la page publique</h2>
      <p class="meta">Palette, bannière, logo, polices, cartes et textes de <a href="<?= e($url . (go_is_live($g) ? '' : '?apercu=' . gallery_preview_token($g))) ?>" target="_blank" rel="noopener">la page de la galerie</a>. L'aperçu ci-contre se met à jour pendant la saisie ; rien ne change en ligne avant « Enregistrer l'apparence ».</p>
      <div class="style-layout">
        <div class="style-fields">
          <fieldset><legend>Palette</legend>
            <div class="presets" role="group" aria-label="Palettes toutes prêtes">
              <?php foreach (APPEARANCE_PALETTES as $key => [$label, $pbg, $pink, $pacc, $pacc2]): ?>
                <button type="button" class="preset" data-bg="<?= e($pbg) ?>" data-ink="<?= e($pink) ?>" data-accent="<?= e($pacc) ?>" title="<?= e($label) ?>"><i style="background:<?= e($pbg) ?>"></i><i style="background:<?= e($pink) ?>"></i><i style="background:<?= e($pacc) ?>"></i><span><?= e($label) ?></span></button>
              <?php endforeach; ?>
            </div>
            <div class="row"><label class="f sw">Couleur principale<input class="swatch-in" type="color" name="accent" value="<?= e($g['accent']) ?>"></label>
              <label class="f sw"><span><input type="checkbox" name="use_bg" value="1"<?= $st['bg'] !== '' ? ' checked' : '' ?>> Fond</span><input class="swatch-in" type="color" name="bg" value="<?= e($st['bg'] ?: '#f7f3ec') ?>"></label>
              <label class="f sw"><span><input type="checkbox" name="use_ink" value="1"<?= $st['ink'] !== '' ? ' checked' : '' ?>> Texte</span><input class="swatch-in" type="color" name="ink" value="<?= e($st['ink'] ?: '#221f1a') ?>"></label>
              <label class="f sw"><span><input type="checkbox" name="use_hero" value="1"<?= $st['hero_color'] !== '' ? ' checked' : '' ?>> En-tête</span><input class="swatch-in" type="color" name="hero_color" value="<?= e($st['hero_color'] ?: $g['accent']) ?>"></label>
              <label class="f">Mode sombre<select name="dark_mode"><?= $sel('dark_mode', $st['dark_mode']) ?></select></label></div>
            <p class="meta">Cases décochées : les teintes d'origine (fond crème, texte brun) sont gardées ; l'en-tête reprend la couleur principale. Les nuances intermédiaires et le thème sombre sont calculés.</p>
          </fieldset>

          <fieldset><legend>Bannières et logo</legend>
            <p class="meta">Jusqu'à six images, affichées en fondu enchaîné. La première sert d'image de partage sur les réseaux sociaux.</p>
            <?php $banners = gallery_banner_images($st); ?>
            <?php if ($banners): ?>
              <div class="shots">
                <?php foreach ($banners as $i => $path): ?>
                  <label class="<?= $i === 0 ? 'first' : '' ?>" data-src="/<?= e($path) ?>"><span class="thumb" style="background-image:url('/<?= e($path) ?>')"></span>
                    <span><input type="radio" name="banner_main" value="<?= (int) $i ?>"<?= $i === 0 ? ' checked' : '' ?>> Image principale</span>
                    <span><input type="checkbox" name="remove_banners[]" value="<?= (int) $i ?>"> Retirer<?= $i === 0 ? ' (1re)' : '' ?></span></label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <div class="row"><label class="f">Ajouter des images <small>(JPEG, PNG ou WebP, 12 Mo max chacune, large de préférence)</small><input type="file" name="banners[]" accept="image/jpeg,image/png,image/webp" multiple></label></div>
            <div class="row">
              <label class="f">Assombrissement <output id="ov-out"><?= (int) $st['banner_overlay'] ?> %</output><input type="range" name="banner_overlay" min="0" max="80" step="5" value="<?= (int) $st['banner_overlay'] ?>"></label>
              <label class="f">Cadrage<select name="banner_pos"><?= $sel('banner_pos', $st['banner_pos']) ?></select></label>
              <label class="f">Hauteur<select name="hero_height"><?= $sel('hero_height', $st['hero_height']) ?></select></label>
              <label class="f">Alignement<select name="hero_align"><?= $sel('hero_align', $st['hero_align']) ?></select></label>
              <label class="row" style="gap:6px;align-self:end;"><input type="checkbox" name="banner_slideshow" value="1"<?= $st['banner_slideshow'] ? ' checked' : '' ?>> Diaporama automatique</label></div>
            <div class="row"><label class="f">Logo <small>(PNG transparent conseillé)</small><input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
              <?php if ($st['logo'] !== ''): ?><span class="thumb logo" style="background-image:url('/<?= e($st['logo']) ?>')"></span><label><input type="checkbox" name="remove_logo" value="1"> Retirer</label><?php endif; ?></div>
          </fieldset>

          <?php if ($libraryBanners): ?>
          <fieldset><legend>Médiathèque de bandeaux</legend>
            <p class="meta">Images déjà générées par l'IA pour la plateforme, réutilisables sans nouveau crédit. En choisir une l'ajoute à cette galerie (une copie propre) et en fait l'image principale ; elle reste proposée aux autres galeries et boutiques.</p>
            <div class="shots library">
              <?php foreach ($libraryBanners as $item): ?>
                <label class="pick"><span class="thumb" style="background-image:url('/uploads/banner-library/<?= e((string) $item['file']) ?>')"></span>
                  <span><input type="radio" name="library_banner" value="<?= e((string) $item['file']) ?>"> Utiliser</span></label>
              <?php endforeach; ?>
            </div>
            <p class="meta"><label><input type="radio" name="library_banner" value="" checked> Garder les bannières actuelles</label></p>
          </fieldset>
          <?php endif; ?>

          <fieldset><legend>Polices</legend>
            <div class="row"><label class="f">Titres<select name="font_display"><?= $fontSel('display', $st['font_display']) ?></select></label>
              <label class="f">Textes<select name="font_body"><?= $fontSel('body', $st['font_body']) ?></select></label></div>
          </fieldset>

          <fieldset><legend>Cartes et grille</legend>
            <div class="row"><label class="f">Coins<select name="radius"><?= $sel('radius', $st['radius']) ?></select></label>
              <label class="f">Format des photos<select name="card_ratio"><?= $sel('card_ratio', $st['card_ratio']) ?></select></label>
              <label class="f">Grille<select name="density"><?= $sel('density', $st['density']) ?></select></label></div>
            <div class="row"><label class="f">Pièces par page<select name="per_page"><?= $sel('per_page', (string) $st['per_page']) ?></select></label>
              <label class="f">Tri par défaut<select name="default_sort"><?= $sel('default_sort', $st['default_sort']) ?></select></label></div>
          </fieldset>

          <fieldset><legend>Textes et blocs</legend>
            <div class="row"><label><input type="checkbox" name="show_stats" value="1"<?= $st['show_stats'] ? ' checked' : '' ?>> Chiffres sous le titre (commerces, pièces, univers)</label>
              <label><input type="checkbox" name="show_cta" value="1"<?= $st['show_cta'] ? ' checked' : '' ?>> Appel « Créer ma boutique » (bouton, tuile, bandeau)</label></div>
            <label class="f">Texte du bouton<input type="text" name="cta_label" maxlength="50" value="<?= e($st['cta_label']) ?>" placeholder="Créer ma boutique dans cette galerie"></label>
            <div class="row"><label class="f">Titre des commerces<input type="text" name="shops_title" maxlength="60" value="<?= e($st['shops_title']) ?>" placeholder="Les commerces"></label>
              <label class="f">Titre des pièces<input type="text" name="pieces_title" maxlength="60" value="<?= e($st['pieces_title']) ?>" placeholder="Toutes les pièces"></label></div>
            <label class="f">Phrase sous « Les commerces »<input type="text" name="shops_lede" maxlength="200" value="<?= e($st['shops_lede']) ?>" placeholder="Chaque commerce a sa boutique, son panier et son paiement…"></label>
            <label class="f">Texte de bas de page<input type="text" name="footer_text" maxlength="300" value="<?= e($st['footer_text']) ?>" placeholder="Les pièces sont vendues et expédiées par les commerces eux-mêmes."></label>
          </fieldset>
          <p class="row"><button class="btn btn-primary" type="submit">Enregistrer l'apparence</button>
            <button class="btn small danger" type="submit" name="action" value="style_reset" formnovalidate onclick="return confirm('Rétablir l\'apparence d\'origine (palette, bannière, logo, textes) ?');">Rétablir l'apparence d'origine</button></p>
        </div>

        <aside class="style-preview" aria-label="Aperçu">
          <div class="pv" id="pv">
            <div class="pv-hero" id="pv-hero"><div class="pv-media" id="pv-media"></div><div class="pv-shade" id="pv-shade"></div>
              <div class="pv-in"><img id="pv-logo" alt="" hidden><p class="pv-eyebrow">Galerie commerciale</p><h3 id="pv-title"><?= e($g['name']) ?></h3><p class="pv-tag"><?= e($g['tagline'] ?: 'Votre slogan') ?></p>
                <span class="pv-btn">Créer ma boutique</span></div></div>
            <div class="pv-body"><p class="pv-h" id="pv-h">Les commerces</p>
              <div class="pv-cards"><i></i><i></i><i></i></div></div>
          </div>
          <p class="meta">Aperçu simplifié : la page réelle s'ouvre avec le lien ci-dessus.</p>
        </aside>
      </div>
    </form>
    <script>
    (function () {
      var f = document.getElementById('style-form'), pv = document.getElementById('pv');
      var fonts = <?= json_encode($fontUrls, JSON_UNESCAPED_SLASHES) ?>, stacks = <?= json_encode(['display' => array_map(static fn ($f) => $f[1], APPEARANCE_FONTS['display']), 'body' => array_map(static fn ($f) => $f[1], APPEARANCE_FONTS['body'])], JSON_UNESCAPED_SLASHES) ?>;
      var current = { banner: <?= json_encode($st['banner'] !== '' ? '/' . $st['banner'] : '') ?>, logo: <?= json_encode($st['logo'] !== '' ? '/' . $st['logo'] : '') ?> };
      function hex(v) { return [1, 3, 5].map(function (i) { return parseInt(v.substr(i, 2), 16); }); }
      function lum(c) { var a = hex(c).map(function (v) { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }); return .2126 * a[0] + .7152 * a[1] + .0722 * a[2]; }
      function on(c) { return lum(c) > .4 ? '#1d1a16' : '#fffaf2'; }
      function mix(a, b, t) { var x = hex(a), y = hex(b); return '#' + x.map(function (v, i) { return ('0' + Math.round(v + (y[i] - v) * t).toString(16)).slice(-2); }).join(''); }
      function val(n) { return f.elements[n]; }
      function render() {
        var accent = val('accent').value, useBg = val('use_bg').checked, useInk = val('use_ink').checked, useHero = val('use_hero').checked;
        var bg = useBg ? val('bg').value : '#f7f3ec', ink = useInk ? val('ink').value : '#221f1a', hero = useHero ? val('hero_color').value : accent;
        var banner = val('banners') && val('banners').files[0] ? URL.createObjectURL(val('banners').files[0]) : current.banner;
        var mainRadio = f.querySelector('input[name=banner_main]:checked');
        var mainShots = f.querySelectorAll('.shots:not(.library) > label[data-src]');
        if (!(val('banners') && val('banners').files[0]) && mainRadio && mainShots[+mainRadio.value]) banner = mainShots[+mainRadio.value].dataset.src;
        var libPick = f.querySelector('input[name=library_banner]:checked');
        if (!(val('banners') && val('banners').files[0]) && libPick && libPick.value) banner = '/uploads/banner-library/' + libPick.value;
        var logo = val('remove_logo') && val('remove_logo').checked ? '' : (val('logo').files[0] ? URL.createObjectURL(val('logo').files[0]) : current.logo);
        var s = pv.style;
        s.setProperty('--bg', bg); s.setProperty('--ink', ink); s.setProperty('--accent', accent); s.setProperty('--accent-ink', on(accent));
        s.setProperty('--surface', useBg || useInk ? mix(bg, ink, .06) : '#ffffff'); s.setProperty('--line', mix(bg, ink, .25));
        s.setProperty('--hero-bg', hero); s.setProperty('--hero-ink', banner ? '#ffffff' : on(hero));
        s.setProperty('--r', { square: '3px', soft: '10px', round: '18px' }[val('radius').value]);
        var media = document.getElementById('pv-media'), shade = document.getElementById('pv-shade'), el = document.getElementById('pv-hero');
        media.style.backgroundImage = banner ? 'url(' + banner + ')' : 'none'; media.style.backgroundPosition = 'center ' + val('banner_pos').value;
        shade.style.background = banner ? 'rgba(0,0,0,' + (val('banner_overlay').value / 100) + ')' : 'none';
        el.className = 'pv-hero h-' + val('hero_height').value + ' a-' + val('hero_align').value;
        var img = document.getElementById('pv-logo'); img.hidden = !logo; if (logo) img.src = logo;
        document.getElementById('ov-out').textContent = val('banner_overlay').value + ' %';
        ['display', 'body'].forEach(function (role) {
          var name = val('font_' + role).value;
          if (name && !document.getElementById('pvf-' + name)) { var l = document.createElement('link'); l.rel = 'stylesheet'; l.id = 'pvf-' + name; l.href = fonts[name]; document.head.appendChild(l); }
          s.setProperty('--f-' + role, name ? '"' + name + '", ' + stacks[role][name] : 'inherit');
        });
        document.getElementById('pv-h').textContent = val('shops_title').value || 'Les commerces';
        var btn = pv.querySelector('.pv-btn'); btn.textContent = val('cta_label').value || 'Créer ma boutique'; btn.hidden = !val('show_cta').checked;
      }
      f.addEventListener('input', render); f.addEventListener('change', render);
      f.querySelectorAll('.preset').forEach(function (b) { b.addEventListener('click', function () {
        val('accent').value = b.dataset.accent; val('bg').value = b.dataset.bg; val('ink').value = b.dataset.ink; val('use_bg').checked = true; val('use_ink').checked = true; render();
      }); });
      render();
    })();
    </script>

    <?php if (!$limited): $bannerAiOk = banner_ai_available(); ?>
    <section class="gcard">
      <h2>Bannière par l'IA</h2>
      <p class="meta">L'IA ajoute une image panoramique 16:9 à la fin des bannières (six au maximum). Chaque image générée rejoint aussi la médiathèque commune : elle pourra être réutilisée par les autres galeries et boutiques sans nouveau crédit. Choisissez ensuite l'image principale dans l'apparence. Coût estimé ≈ <?= e(ai_format_eur(ai_eur(AI_PRICING_DEFAULTS['image']))) ?> par image, sur la clé Gemini de la plateforme.</p>
      <?php if (!$bannerAiOk): ?><p class="flash" data-kind="error" role="status">Génération indisponible : activez le fournisseur d'images Gemini et vérifiez sa clé dans <a href="/portail/ia-fournisseurs.php">Fournisseurs IA</a>.</p><?php endif; ?>
      <?= banner_ai_usage_html() ?>
      <form method="post" class="row" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="banner_ai"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>">
        <label class="f">Ambiance souhaitée (facultatif)<input type="text" name="hint" maxlength="200" placeholder="ex. allée d'étals colorés au soleil"></label>
        <label class="f" style="flex:none;min-width:130px;">Images<select name="count"><?php foreach ([1, 2, 3] as $n): ?><option value="<?= $n ?>"><?= $n ?> image<?= $n > 1 ? 's' : '' ?></option><?php endforeach; ?></select></label>
        <button class="btn btn-primary" type="submit"<?= $bannerAiOk ? '' : ' disabled' ?>>Générer avec l'IA</button>
      </form>
    </section>
    <?php endif; ?>

    <form class="gcard" method="post" autocomplete="off">
      <h2>Ajouter un <?= e(gt_word($g, 'member')) ?> d'ailleurs</h2>
      <p class="meta">Un commerce hébergé sur un autre serveur (par exemple le Petit Chalet) : indiquez l'adresse de sa boutique. Elle doit servir son catalogue (<code>/catalogue.php</code>, fourni par la plateforme).</p>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_remote"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>">
      <div class="row">
        <label class="f">Adresse de la boutique<input type="url" name="url" required placeholder="https://brocante.arrimage.com"></label>
        <label class="f">Nom (facultatif)<input type="text" name="name" maxlength="80" placeholder="repris du catalogue si vide"></label>
      </div>
      <p><button class="btn" type="submit">Tester et ajouter</button></p>
    </form>
  <?php endif; ?>
  </div>
</div>
</body>
</html>
