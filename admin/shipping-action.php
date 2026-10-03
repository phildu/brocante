<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/shipping.php');
    exit;
}

$action = (string) ($_POST['action'] ?? '');

switch ($action) {

    case 'add_carrier': {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            flash_set('Le nom du transporteur est obligatoire.', 'error');
            break;
        }
        add_shipping_carrier($name);
        flash_set('Transporteur « ' . $name . ' » ajouté.');
        break;
    }

    case 'toggle_carrier': {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('UPDATE shipping_carriers SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        break;
    }

    case 'delete_carrier': {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM shipping_carriers WHERE id = ?')->execute([$id]);
        flash_set('Transporteur supprimé (avec ses tarifs).');
        break;
    }

    case 'add_rate': {
        $carrierId = (int) ($_POST['carrier_id'] ?? 0);
        $serviceName = trim((string) ($_POST['service_name'] ?? ''));
        $weightMinKg = (float) str_replace(',', '.', (string) ($_POST['weight_min_kg'] ?? '0'));
        $weightMaxKg = (float) str_replace(',', '.', (string) ($_POST['weight_max_kg'] ?? '0'));
        $priceCents = price_to_cents((string) ($_POST['price'] ?? '')) ?? 0;
        $deliveryDelay = trim((string) ($_POST['delivery_delay'] ?? ''));
        $zone = (string) ($_POST['zone'] ?? 'metropole');
        if (!array_key_exists($zone, shipping_zones())) {
            $zone = 'metropole';
        }

        if (!$carrierId || $serviceName === '' || $weightMaxKg <= $weightMinKg || $priceCents <= 0) {
            flash_set('Palier invalide — vérifiez le service, les poids (max > min) et le tarif.', 'error');
            break;
        }

        add_shipping_rate(
            $carrierId,
            $serviceName,
            (int) round($weightMinKg * 1000),
            (int) round($weightMaxKg * 1000),
            $priceCents,
            $deliveryDelay,
            $zone
        );
        flash_set('Palier de tarif ajouté.');
        break;
    }

    case 'delete_rate': {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM shipping_rates WHERE id = ?')->execute([$id]);
        flash_set('Palier de tarif supprimé.');
        break;
    }

    case 'seed_chronopost': {
        $existing = db()->query("SELECT id FROM shipping_carriers WHERE name = 'Chronopost' LIMIT 1")->fetchColumn();
        if ($existing) {
            flash_set('Chronopost est déjà configuré — supprimez-le d\'abord pour recharger les tarifs exemple.', 'error');
            break;
        }
        $carrierId = add_shipping_carrier('Chronopost');

        // Tarifs Chrono 13 (étiquette en ligne, prépayée), grand public, 2026.
        $metropole = [
            ['Chrono 13 — 0 à 1 kg', 0, 1000, 2340, '13h le lendemain'],
            ['Chrono 13 — 1 à 3 kg', 1000, 3000, 2520, '13h le lendemain'],
            ['Chrono 13 — 3 à 10 kg', 3000, 10000, 2880, '13h le lendemain'],
            ['Chrono 13 — 10 à 20 kg', 10000, 20000, 4080, '13h le lendemain'],
            ['Chrono 13 — 20 à 30 kg', 20000, 30000, 5400, '13h le lendemain'],
        ];
        foreach ($metropole as [$service, $min, $max, $cents, $delay]) {
            add_shipping_rate($carrierId, $service, $min, $max, $cents, $delay, 'metropole');
        }

        // Chrono 13 vers la Corse : supplément d'environ 30,72 € constaté sur
        // le tarif standard (source tierce — à vérifier auprès de Chronopost).
        $corseSupplementCents = 3072;
        foreach ($metropole as [$service, $min, $max, $cents, $delay]) {
            add_shipping_rate($carrierId, str_replace('Chrono 13', 'Chrono 13 (Corse)', $service), $min, $max, $cents + $corseSupplementCents, $delay, 'corse');
        }

        // Chrono Express zone Z8 (Outre-mer général), grand public, 2026.
        $domTom = [
            ['Chrono Express Outre-mer — 0 à 1 kg', 0, 1000, 5800, '1 à 5 jours'],
            ['Chrono Express Outre-mer — 1 à 3 kg', 1000, 3000, 8800, '1 à 5 jours'],
            ['Chrono Express Outre-mer — 3 à 10 kg', 3000, 10000, 15500, '1 à 5 jours'],
            ['Chrono Express Outre-mer — 10 à 20 kg', 10000, 20000, 28000, '1 à 5 jours'],
            ['Chrono Express Outre-mer — 20 à 30 kg', 20000, 30000, 49600, '1 à 5 jours'],
        ];
        foreach ($domTom as [$service, $min, $max, $cents, $delay]) {
            add_shipping_rate($carrierId, $service, $min, $max, $cents, $delay, 'dom_tom');
        }

        flash_set('Tarifs exemple Chronopost chargés (métropole, Corse, outre-mer) — le supplément Corse est une estimation à vérifier auprès de Chronopost, ajustez selon vos vrais contrats.');
        break;
    }

    case 'seed_colissimo': {
        $existing = db()->query("SELECT id FROM shipping_carriers WHERE name = 'Colissimo' LIMIT 1")->fetchColumn();
        if ($existing) {
            flash_set('Colissimo est déjà configuré — supprimez-le d\'abord pour recharger les tarifs exemple.', 'error');
            break;
        }
        $carrierId = add_shipping_carrier('Colissimo');

        // Tarifs Colissimo Domicile France métropolitaine, grand public, 2026
        // (laposte.fr/tarif-colissimo). La Corse est incluse au même tarif
        // que la métropole chez Colissimo (pas de grille séparée publiée).
        $metropole = [
            ['Colissimo Domicile — jusqu\'à 250 g', 0, 250, 549, '2 jours'],
            ['Colissimo Domicile — 250 à 500 g', 250, 500, 759, '2 jours'],
            ['Colissimo Domicile — 500 à 750 g', 500, 750, 929, '2 jours'],
            ['Colissimo Domicile — 750 g à 1 kg', 750, 1000, 959, '2 jours'],
            ['Colissimo Domicile — 1 à 2 kg', 1000, 2000, 1119, '2 jours'],
            ['Colissimo Domicile — 2 à 5 kg', 2000, 5000, 1739, '2 jours'],
            ['Colissimo Domicile — 5 à 10 kg', 5000, 10000, 2529, '2 jours'],
            ['Colissimo Domicile — 10 à 15 kg', 10000, 15000, 3199, '2 jours'],
            ['Colissimo Domicile — 15 à 30 kg', 15000, 30000, 3959, '2 jours'],
        ];
        foreach ($metropole as [$service, $min, $max, $cents, $delay]) {
            add_shipping_rate($carrierId, $service, $min, $max, $cents, $delay, 'metropole');
            add_shipping_rate($carrierId, $service, $min, $max, $cents, $delay, 'corse');
        }

        // Tarifs Colissimo Outre-mer (zone OM1), grand public, 2026 — paliers
        // publiés plus larges qu'en métropole (500 g / 1 kg / 5 kg / 30 kg).
        $domTom = [
            ['Colissimo Outre-mer — jusqu\'à 500 g', 0, 500, 1202, '6 à 18 jours'],
            ['Colissimo Outre-mer — 500 g à 1 kg', 500, 1000, 1900, '6 à 18 jours'],
            ['Colissimo Outre-mer — 1 à 5 kg', 1000, 5000, 3890, '6 à 18 jours'],
            ['Colissimo Outre-mer — 5 à 30 kg', 5000, 30000, 14302, '6 à 18 jours'],
        ];
        foreach ($domTom as [$service, $min, $max, $cents, $delay]) {
            add_shipping_rate($carrierId, $service, $min, $max, $cents, $delay, 'dom_tom');
        }

        flash_set('Tarifs exemple Colissimo chargés (métropole, Corse, outre-mer) — à ajuster selon vos vrais contrats.');
        break;
    }

    case 'seed_colissimo_international': {
        $carrierId = db()->query("SELECT id FROM shipping_carriers WHERE name = 'Colissimo' LIMIT 1")->fetchColumn();
        if (!$carrierId) {
            flash_set('Ajoutez d\'abord Colissimo (tarifs France) avant de charger ses tarifs internationaux.', 'error');
            break;
        }
        $alreadyHasIntl = db()->prepare("SELECT COUNT(*) FROM shipping_rates WHERE carrier_id = ? AND zone LIKE 'intl_%'");
        $alreadyHasIntl->execute([$carrierId]);
        if ($alreadyHasIntl->fetchColumn() > 0) {
            flash_set('Les tarifs internationaux Colissimo sont déjà chargés.', 'error');
            break;
        }

        // Tarifs Colissimo International par zone, grand public, 2026
        // (laposte.fr/tarif-colissimo — zone A : UE/Suisse/Royaume-Uni,
        // zone B : Europe de l'Est, zone C : reste du monde).
        $intlByZone = [
            'intl_a' => [
                ['Colissimo International zone A — jusqu\'à 500 g', 0, 500, 1499],
                ['Colissimo International zone A — 500 g à 1 kg', 500, 1000, 1939],
                ['Colissimo International zone A — 1 à 2 kg', 1000, 2000, 2219],
                ['Colissimo International zone A — 2 à 5 kg', 2000, 5000, 2859],
                ['Colissimo International zone A — 5 à 10 kg', 5000, 10000, 4699],
                ['Colissimo International zone A — 10 à 15 kg', 10000, 15000, 6799],
                ['Colissimo International zone A — 15 à 20 kg', 15000, 20000, 8799],
            ],
            'intl_b' => [
                ['Colissimo International zone B — jusqu\'à 500 g', 0, 500, 2379],
                ['Colissimo International zone B — 500 g à 1 kg', 500, 1000, 2839],
                ['Colissimo International zone B — 1 à 2 kg', 1000, 2000, 3109],
                ['Colissimo International zone B — 2 à 5 kg', 2000, 5000, 3989],
                ['Colissimo International zone B — 5 à 10 kg', 5000, 10000, 6609],
                ['Colissimo International zone B — 10 à 15 kg', 10000, 15000, 8959],
                ['Colissimo International zone B — 15 à 20 kg', 15000, 20000, 10949],
            ],
            'intl_c' => [
                ['Colissimo International zone C — jusqu\'à 500 g', 0, 500, 3519],
                ['Colissimo International zone C — 500 g à 1 kg', 500, 1000, 3919],
                ['Colissimo International zone C — 1 à 2 kg', 1000, 2000, 5399],
                ['Colissimo International zone C — 2 à 5 kg', 2000, 5000, 7869],
                ['Colissimo International zone C — 5 à 10 kg', 5000, 10000, 14899],
                ['Colissimo International zone C — 10 à 15 kg', 10000, 15000, 21079],
                ['Colissimo International zone C — 15 à 20 kg', 15000, 20000, 25689],
            ],
        ];
        foreach ($intlByZone as $zone => $rates) {
            foreach ($rates as [$service, $min, $max, $cents]) {
                add_shipping_rate((int) $carrierId, $service, $min, $max, $cents, '3 à 8 jours', $zone);
            }
        }

        flash_set('Tarifs internationaux Colissimo chargés (zones A, B, C) — à ajuster selon vos vrais contrats.');
        break;
    }
}

header('Location: /admin/shipping.php');
exit;
