<?php
session_start();
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
            (string) ($_POST['font_body'] ?? '')
        );
        flash_set('Apparence enregistrée : elle s\'applique maintenant à tout le site.');
        break;

    case 'reset':
        appearance_reset();
        flash_set("Apparence d'origine rétablie.");
        break;

    case 'logos':
        $logos = logos_saved();
        $changed = [];
        try {
            foreach (LOGO_VARIANTS as $variant => [$title]) {
                $file = $_FILES['logo_' . $variant] ?? null;
                if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $logos[$variant] = logo_store_upload($variant, $file);
                    $changed[] = mb_strtolower($title);
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
