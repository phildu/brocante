<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/discounts.php');
    exit;
}

$action = (string) ($_POST['action'] ?? '');

switch ($action) {

    case 'add': {
        $kind = ($_POST['kind'] ?? '') === 'qty' ? 'qty' : 'total';
        $type = ($_POST['type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $label = mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 60);
        $thresholdRaw = trim((string) ($_POST['threshold'] ?? ''));
        $valueRaw = trim((string) ($_POST['value'] ?? ''));

        // Seuil : un nombre d'articles (entier ≥ 1) ou un total en euros.
        $threshold = $kind === 'qty' ? (int) $thresholdRaw : (price_to_cents($thresholdRaw) ?? 0);
        if ($threshold < 1) {
            flash_set($kind === 'qty' ? "Le nombre d'articles doit être d'au moins 1." : 'Le total à atteindre doit être supérieur à 0 €.', 'error');
            break;
        }
        // Valeur : un pourcentage (1 à 100) ou un montant en euros.
        if ($type === 'percent') {
            $percent = (float) str_replace(',', '.', $valueRaw);
            if ($percent <= 0 || $percent > 100) {
                flash_set('Le pourcentage doit être compris entre 1 et 100.', 'error');
                break;
            }
            $value = (int) round($percent);
        } else {
            $value = price_to_cents($valueRaw) ?? 0;
            if ($value < 1) {
                flash_set('Le montant de la remise doit être supérieur à 0 €.', 'error');
                break;
            }
        }
        db()->prepare('INSERT INTO cart_discounts (kind, threshold, type, value, label) VALUES (?, ?, ?, ?, ?)')
            ->execute([$kind, $threshold, $type, $value, $label]);
        flash_set('Palier ajouté : ' . cart_discount_describe(['kind' => $kind, 'threshold' => $threshold, 'type' => $type, 'value' => $value]) . '.');
        break;
    }

    case 'toggle': {
        db()->prepare('UPDATE cart_discounts SET is_active = 1 - is_active WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        break;
    }

    case 'delete': {
        db()->prepare('DELETE FROM cart_discounts WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        flash_set('Palier supprimé.');
        break;
    }
}

header('Location: /admin/discounts.php');
exit;
