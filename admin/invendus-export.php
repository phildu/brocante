<?php
/** Export CSV des propositions d'une édition (compatible Excel français : UTF-8 avec BOM, séparateur « ; »). */
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/invendus.php';
require_admin();
require_once __DIR__ . '/../includes/shop-modules.php';
shop_module_require('invendus-dons');   // plugin activé + accès payé, en essai, offert ou inclus

$event = unsold_event_get((int) unsold_param($_GET, 'event'));
if (!$event) {
    http_response_code(404);
    exit('Édition introuvable.');
}

$offers = unsold_offers_list((int) $event['id']);
$tours = [];
foreach (unsold_tours_list((int) $event['id']) as $t) {
    $tours[(int) $t['id']] = $t['volunteer_name'];
}

$filename = 'invendus-' . slugify($event['name'], 40) . '-' . $event['event_date'] . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Réf.', 'Objet', 'Quantité', 'Catégorie', 'Volume', 'Statut', 'Invendu confirmé', 'Remise', 'Tournée',
    'Donateur', 'Emplacement', 'Téléphone', 'E-mail', 'Description', 'Note interne', 'Créée le', 'Dernier changement'], ';', '"', '');
foreach ($offers as $o) {
    fputcsv($out, array_map('unsold_csv_cell', [
        $o['id'], $o['title'], $o['quantity'], unsold_category_label($o['category']),
        unsold_sizes()[$o['size']]['label'] ?? $o['size'], unsold_statuses()[$o['status']] ?? $o['status'],
        $o['confirmed_at'] ? 'oui' : 'non', $o['handover'] === 'pickup' ? 'ramassage' : 'apporté',
        $o['tour_id'] ? ($tours[(int) $o['tour_id']] ?? '') : '',
        $o['donor_name'], $o['stand_ref'], $o['donor_phone'], $o['donor_email'],
        $o['description'], $o['admin_note'], $o['created_at'], $o['updated_at'],
    ]), ';', '"', '');
}
fclose($out);
