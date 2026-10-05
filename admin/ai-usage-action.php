<?php
// Consommation IA : crédit prépayé et tarifs utilisés pour les estimations.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/ai-usage.php');
    exit;
}
admin_csrf_check();

switch ((string) ($_POST['action'] ?? '')) {
    case 'budget':
        $amount = (float) str_replace([' ', ','], ['', '.'], (string) ($_POST['amount'] ?? ''));
        if ($amount <= 0 || $amount > 100000) {
            flash_set('Saisissez un montant en euros (ex. 10).', 'error');
            break;
        }
        ai_budget_save($amount);
        flash_set('Crédit enregistré : le solde repart de ' . ai_format_eur($amount) . ', les dépenses sont comptées à partir de maintenant.');
        break;
    case 'budget_clear':
        db()->prepare("DELETE FROM settings WHERE name = 'ai_budget'")->execute();
        flash_set('Crédit retiré : seules les dépenses cumulées restent affichées.');
        break;
    case 'pricing':
        ai_pricing_save($_POST);
        flash_set('Tarifs enregistrés : ils servent aux estimations à venir (les appels déjà consignés gardent leur coût).');
        break;
    case 'pricing_reset':
        db()->prepare("DELETE FROM settings WHERE name = 'ai_pricing'")->execute();
        flash_set('Tarifs par défaut rétablis.');
        break;
}
header('Location: /admin/ai-usage.php');
exit;
