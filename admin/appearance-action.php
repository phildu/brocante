<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/appearance.php');
    exit;
}

switch ($_POST['action'] ?? '') {
    case 'save':
        appearance_save(
            (array) ($_POST['colors'] ?? []),
            (string) ($_POST['font_display'] ?? ''),
            (string) ($_POST['font_body'] ?? ''),
            isset($_POST['theme']) ? (string) $_POST['theme'] : null,
            array_key_exists('hero', $_POST) ? (string) $_POST['hero'] : null
        );
        flash_set('Apparence enregistrée : elle s\'applique maintenant à tout le site.');
        break;

    case 'template':
        try {
            appearance_save_template((string) ($_POST['template'] ?? ''), !empty($_POST['with_style']));
            flash_set('Modèle « ' . SHOP_TEMPLATES[$_POST['template']]['label'] . ' » appliqué : il s\'affiche maintenant sur tout le site.');
        } catch (InvalidArgumentException $e) {
            flash_set($e->getMessage(), 'error');
        }
        header('Location: /admin/appearance.php#modele');
        exit;

    case 'reset':
        appearance_reset();
        flash_set("Apparence d'origine rétablie.");
        break;

    case 'logos':
        $logos = logos_saved();
        $changed = [];
        $siteName = trim((string) (get_content()['site_name'] ?? ''));
        $enlarge = (string) ($_POST['enlarge'] ?? '');
        if ($enlarge !== '') {
            try {
                $big = logo_enlarge($enlarge);
                $title = mb_strtolower(LOGO_VARIANTS[$enlarge][0] ?? $enlarge);
                media_library_register($big['path'], 'Logo ' . $title . ($siteName !== '' ? ' — ' . $siteName : '') . ' (agrandi)', 'Logo', 'logo, agrandi');
                flash_set('Logo ' . $title . ' agrandi : ' . $big['width'] . ' × ' . $big['height'] . ' px (avant : ' . $big['from_width'] . ' × ' . $big['from_height'] . ' px).');
            } catch (InvalidArgumentException | RuntimeException $e) {
                flash_set($e->getMessage(), 'error');
            }
            header('Location: /admin/appearance.php#logos');
            exit;
        }
        try {
            foreach (LOGO_VARIANTS as $variant => [$title]) {
                $file = $_FILES['logo_' . $variant] ?? null;
                $media = trim((string) ($_POST['media_' . $variant] ?? ''));
                if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $logos[$variant] = logo_store_upload($variant, $file);
                    media_library_register($logos[$variant], 'Logo ' . mb_strtolower($title) . ($siteName !== '' ? ' — ' . $siteName : ''), 'Logo', 'logo');
                    $changed[] = mb_strtolower($title);
                } elseif ($media !== '') {
                    $logos[$variant] = logo_store_media($variant, $media);
                    media_library_register($logos[$variant], 'Logo ' . mb_strtolower($title) . ($siteName !== '' ? ' — ' . $siteName : ''), 'Médiathèque', 'logo');
                    $changed[] = mb_strtolower($title) . ' (médiathèque)';
                } elseif (!empty($_POST['reset_' . $variant])) {
                    unset($logos[$variant]);
                    $changed[] = mb_strtolower($title) . " (logo d'origine)";
                }
            }
        } catch (InvalidArgumentException | RuntimeException $e) {
            flash_set($e->getMessage(), 'error');
            header('Location: /admin/appearance.php#logos');
            exit;
        }
        logos_save($logos);
        flash_set($changed ? 'Logos enregistrés : ' . implode(', ', $changed) . '.' : 'Aucun fichier choisi : rien de changé.');
        header('Location: /admin/appearance.php#logos');
        exit;
}

header('Location: /admin/appearance.php');
exit;
