<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/catalog.php');
    exit;
}

$refs = array_values(array_filter(array_map('strval', (array) ($_POST['refs'] ?? []))));
$action = (string) ($_POST['action'] ?? '');

// Le formulaire fournit sa propre page de retour (catalogue en vue liste ou
// administration complète) — on n'accepte qu'un chemin /admin/ local, jamais
// une redirection externe fournie par le client.
$redirect = app_unprefix((string) ($_POST['redirect'] ?? '/admin/catalog.php'));
if (!str_starts_with($redirect, '/admin/')) $redirect = '/admin/catalog.php';

if (!$refs) {
    flash_set('Aucune pièce sélectionnée.', 'error');
    header('Location: ' . $redirect);
    exit;
}

$placeholders = implode(',', array_fill(0, count($refs), '?'));
$n = count($refs);
$noun = $n > 1 ? "$n pièces" : '1 pièce';

switch ($action) {

    case 'delete': {
        foreach ($refs as $ref) {
            move_product_photos_to_media($ref);
        }
        $stmt = db()->prepare("DELETE FROM products WHERE ref IN ($placeholders)");
        $stmt->execute($refs);
        $count = $stmt->rowCount();
        flash_set(($count > 1 ? "$count pièces supprimées" : '1 pièce supprimée') . ' — leurs photos ont été conservées dans la médiathèque.');
        break;
    }

    case 'hide': {
        db()->prepare("UPDATE products SET is_hidden = 1 WHERE ref IN ($placeholders)")->execute($refs);
        flash_set("$noun masquée(s) — hors boutique.");
        break;
    }

    case 'unhide': {
        db()->prepare("UPDATE products SET is_hidden = 0 WHERE ref IN ($placeholders)")->execute($refs);
        flash_set("$noun réaffichée(s).");
        break;
    }

    case 'promo': {
        db()->prepare("UPDATE products SET badge = 'Promo' WHERE ref IN ($placeholders)")->execute($refs);
        flash_set("$noun mise(s) en promo.");
        break;
    }

    case 'unpromo': {
        db()->prepare("UPDATE products SET badge = '', promo_price = NULL WHERE ref IN ($placeholders) AND badge = 'Promo'")->execute($refs);
        flash_set('Promo retirée.');
        break;
    }

    default:
        flash_set('Action inconnue.', 'error');
}

header('Location: ' . $redirect);
exit;
