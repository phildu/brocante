<?php
// Actions du pack « Vente en ligne » (POST + jeton CSRF, vérifiés par admin/plugin-api.php).
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/vel.php';

/** Succès : message affiché après le rechargement de la page (ou dans le formulaire resté ouvert). */
function vel_done(string $message, bool $flash = true): never
{
    if ($flash) flash_set($message);
    plugin_json(['ok' => true, 'message' => $message]);
}

function vel_fail(string $message, int $status = 400): never
{
    plugin_json(['error' => $message], $status);
}

$in = plugin_input();
$action = (string) ($in['action'] ?? $_GET['action'] ?? '');

switch ($action) {

    // ── Commandes et suivi ────────────────────────────────────────────────

    case 'order_fulfillment': {
        $order = vel_order((int) ($in['id'] ?? 0));
        if (!$order) vel_fail('Commande introuvable.');
        $status = (string) ($in['fulfillment_status'] ?? '');
        if (!in_array($status, ['a_preparer', 'expediee', 'livree'], true)) vel_fail('Statut de suivi invalide.');
        $tracking = trim((string) ($in['tracking_number'] ?? ''));
        $stmt = db()->prepare('UPDATE orders SET fulfillment_status = ?, tracking_number = ? WHERE id = ?');
        $stmt->execute([$status, $tracking !== '' ? $tracking : null, (int) $order['id']]);
        if ((string) $order['fulfillment_status'] !== $status) {
            vel_events_add((int) $order['id'], 'statut', 'Suivi : ' . fulfillment_label((string) $order['fulfillment_status']) . ' → ' . fulfillment_label($status));
        }
        if ($tracking !== '' && $tracking !== (string) ($order['tracking_number'] ?? '')) {
            vel_events_add((int) $order['id'], 'suivi', 'N° de suivi : ' . $tracking);
        }
        vel_done('Suivi de commande mis à jour.');
    }

    case 'order_refresh_stripe': {
        $order = vel_order((int) ($in['id'] ?? 0));
        if (!$order) vel_fail('Commande introuvable.');
        try {
            $session = stripe_request('GET', '/checkout/sessions/' . urlencode((string) $order['stripe_session_id']), [], ($order['payment_via'] ?? '') === 'connect');
        } catch (Throwable $e) {
            vel_fail('Impossible de vérifier auprès de Stripe : ' . $e->getMessage(), 502);
        }
        if (($session['payment_status'] ?? '') !== 'paid') {
            vel_fail('Stripe indique que cette commande n\'est toujours pas payée (statut : ' . (string) ($session['payment_status'] ?? 'inconnu') . ').');
        }
        if ($order['status'] === 'paid') vel_done('Cette commande est déjà à jour (payée).');
        if (!order_mark_paid($order, $session)) vel_done('Cette commande est déjà à jour (payée).');
        vel_done('Paiement confirmé auprès de Stripe — commande mise à jour.');
    }

    case 'order_note': {
        $order = vel_order((int) ($in['id'] ?? 0));
        if (!$order) vel_fail('Commande introuvable.');
        $note = trim((string) ($in['message'] ?? ''));
        if ($note === '') vel_fail('Écrivez la note avant de l\'enregistrer.');
        vel_events_add((int) $order['id'], 'note', $note);
        vel_done('Note ajoutée au suivi.', false);
    }

    // ── Offres de prix ────────────────────────────────────────────────────

    case 'offer_status': {
        $status = (string) ($in['status'] ?? '');
        if (!in_array($status, ['new', 'done'], true)) vel_fail('Statut d\'offre invalide.');
        db()->prepare('UPDATE price_offers SET status = ? WHERE id = ?')->execute([$status, (int) ($in['id'] ?? 0)]);
        vel_done($status === 'done' ? 'Offre marquée traitée.' : 'Offre rouverte.');
    }

    case 'offer_delete': {
        db()->prepare('DELETE FROM price_offers WHERE id = ?')->execute([(int) ($in['id'] ?? 0)]);
        vel_done('Offre supprimée.');
    }

    // ── Remises du panier ─────────────────────────────────────────────────

    case 'discount_add': {
        $kind = ($in['kind'] ?? '') === 'qty' ? 'qty' : 'total';
        $type = ($in['type'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $label = mb_substr(trim((string) ($in['label'] ?? '')), 0, 60);
        $thresholdRaw = trim((string) ($in['threshold'] ?? ''));
        $valueRaw = trim((string) ($in['value'] ?? ''));

        $threshold = $kind === 'qty' ? (int) $thresholdRaw : (price_to_cents($thresholdRaw) ?? 0);
        if ($threshold < 1) {
            vel_fail($kind === 'qty' ? "Le nombre d'articles doit être d'au moins 1." : 'Le total à atteindre doit être supérieur à 0 €.');
        }
        if ($type === 'percent') {
            $percent = (float) str_replace(',', '.', $valueRaw);
            if ($percent <= 0 || $percent > 100) vel_fail('Le pourcentage doit être compris entre 1 et 100.');
            $value = (int) round($percent);
        } else {
            $value = price_to_cents($valueRaw) ?? 0;
            if ($value < 1) vel_fail('Le montant de la remise doit être supérieur à 0 €.');
        }
        db()->prepare('INSERT INTO cart_discounts (kind, threshold, type, value, label) VALUES (?, ?, ?, ?, ?)')
            ->execute([$kind, $threshold, $type, $value, $label]);
        vel_done('Palier ajouté : ' . cart_discount_describe(['kind' => $kind, 'threshold' => $threshold, 'type' => $type, 'value' => $value]) . '.');
    }

    case 'discount_toggle': {
        db()->prepare('UPDATE cart_discounts SET is_active = 1 - is_active WHERE id = ?')->execute([(int) ($in['id'] ?? 0)]);
        vel_done('Palier mis à jour.');
    }

    case 'discount_delete': {
        db()->prepare('DELETE FROM cart_discounts WHERE id = ?')->execute([(int) ($in['id'] ?? 0)]);
        vel_done('Palier supprimé.');
    }

    // ── Transporteurs et tarifs ───────────────────────────────────────────

    case 'carrier_add': {
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80);
        if ($name === '') vel_fail('Le nom du transporteur est obligatoire.');
        add_shipping_carrier($name);
        vel_done('Transporteur « ' . $name . ' » ajouté.');
    }

    case 'carrier_toggle': {
        db()->prepare('UPDATE shipping_carriers SET is_active = 1 - is_active WHERE id = ?')->execute([(int) ($in['id'] ?? 0)]);
        vel_done('Transporteur mis à jour.');
    }

    case 'carrier_delete': {
        db()->prepare('DELETE FROM shipping_carriers WHERE id = ?')->execute([(int) ($in['id'] ?? 0)]);
        vel_done('Transporteur supprimé (avec ses tarifs).');
    }

    case 'rate_add': {
        $carrierId = (int) ($in['carrier_id'] ?? 0);
        $serviceName = trim((string) ($in['service_name'] ?? ''));
        $weightMinKg = (float) str_replace(',', '.', (string) ($in['weight_min_kg'] ?? '0'));
        $weightMaxKg = (float) str_replace(',', '.', (string) ($in['weight_max_kg'] ?? '0'));
        $priceCents = price_to_cents((string) ($in['price'] ?? '')) ?? 0;
        $deliveryDelay = trim((string) ($in['delivery_delay'] ?? ''));
        $zone = (string) ($in['zone'] ?? 'metropole');
        if (!array_key_exists($zone, shipping_zones())) $zone = 'metropole';

        if (!$carrierId || $serviceName === '' || $weightMaxKg <= $weightMinKg || $priceCents <= 0) {
            vel_fail('Palier invalide — vérifiez le service, les poids (max > min) et le tarif.');
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
        vel_done('Palier de tarif ajouté.');
    }

    case 'rate_delete': {
        db()->prepare('DELETE FROM shipping_rates WHERE id = ?')->execute([(int) ($in['id'] ?? 0)]);
        vel_done('Palier de tarif supprimé.');
    }

    case 'seed': {
        $key = (string) ($in['carrier'] ?? '');
        $plan = vel_seed_plan($key);
        if ($plan === null) vel_fail('Modèle de tarifs inconnu.');

        $name = $key === 'colissimo_intl' ? 'Colissimo' : $plan['name'];
        $stmt = db()->prepare('SELECT id FROM shipping_carriers WHERE name = ? LIMIT 1');
        $stmt->execute([$name]);
        $carrierId = (int) $stmt->fetchColumn();

        if ($key === 'colissimo_intl') {
            if (!$carrierId) vel_fail('Ajoutez d\'abord Colissimo (tarifs France) avant de charger ses tarifs internationaux.');
            $count = db()->prepare("SELECT COUNT(*) FROM shipping_rates WHERE carrier_id = ? AND zone LIKE 'intl_%'");
            $count->execute([$carrierId]);
            if ((int) $count->fetchColumn() > 0) vel_fail('Les tarifs internationaux Colissimo sont déjà chargés.');
        } else {
            if ($carrierId) vel_fail($name . ' est déjà configuré — supprimez-le d\'abord pour recharger les tarifs exemple.');
            $carrierId = add_shipping_carrier($name);
        }

        foreach ($plan['rates'] as [$service, $min, $max, $cents, $delay, $zone]) {
            add_shipping_rate($carrierId, $service, $min, $max, $cents, $delay, $zone);
        }
        vel_done($key === 'colissimo_intl'
            ? 'Tarifs internationaux Colissimo chargés (zones A, B, C) — à ajuster selon vos vrais contrats.'
            : 'Tarifs exemple « ' . $name . ' » chargés (' . count($plan['rates']) . ' paliers) — indicatifs, à ajuster selon vos vrais contrats.');
    }

    // ── Clients (CRM) ─────────────────────────────────────────────────────

    case 'client_note': {
        $email = vel_email((string) ($in['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) vel_fail('Adresse e-mail invalide.');
        $note = trim((string) ($in['note'] ?? ''));
        if (mb_strlen($note) > 2000) vel_fail('La note est limitée à 2 000 caractères.');
        vel_note_save($email, $note);
        plugin_json(['ok' => true, 'message' => $note === '' ? 'Note effacée.' : 'Note enregistrée.']);
    }

    case 'sync_clients': {
        [$created, $upgraded] = accounts_sync_clients_from_orders();
        vel_done($created + $upgraded === 0
            ? 'Tous les clients ayant commandé sont déjà dans la liste.'
            : "$created client(s) ajouté(s) depuis les commandes" . ($upgraded ? ", $upgraded prospect(s) passé(s) en client" : '') . '.');
    }

    // ── Paiement ──────────────────────────────────────────────────────────

    case 'save_stripe_keys': {
        $secret = trim((string) ($in['stripe_secret'] ?? ''));
        $publishable = trim((string) ($in['stripe_publishable'] ?? ''));
        $swapped = false;
        if (str_starts_with($secret, 'pk_') && str_starts_with($publishable, 'sk_')) {
            [$secret, $publishable] = [$publishable, $secret];
            $swapped = true;
        }
        if ($secret === '' && $publishable === '') vel_fail('Aucune clé fournie — rien n\'a été changé.');

        // Mode choisi dans le formulaire, sinon déduit du préfixe de la clé
        // collée (une clé sk_test_ collée pendant le mode réel bascule la
        // boutique en test, et inversement).
        $mode = (string) ($in['stripe_mode'] ?? '');
        if (!isset(STRIPE_MODES[$mode])) {
            $mode = stripe_key_mode($secret !== '' ? $secret : $publishable);
        }
        if (!isset(STRIPE_MODES[$mode])) $mode = stripe_mode_current(SECRETS_DIR);

        [$ok, $message] = stripe_mode_save($mode, ['secret' => $secret, 'publishable' => $publishable], SECRETS_DIR);
        if (!$ok) vel_fail($message);
        // Le mode du formulaire devient le mode actif : le choix est explicite.
        [$activated, $activationMessage] = stripe_mode_activate($mode, SECRETS_DIR);
        if (!$activated) vel_fail($activationMessage);
        $message = $activationMessage;
        if ($swapped) $message .= ' Vos deux clés étaient inversées (secrète/publiable) : corrigé automatiquement.';
        vel_done($message);
    }

    case 'save_content': {
        $fee = trim((string) ($in['shipping_fee'] ?? ''));
        $pickupLabel = trim((string) ($in['pickup_label'] ?? ''));
        $shippingLabel = trim((string) ($in['shipping_label'] ?? ''));
        $thresholdRaw = str_replace(',', '.', trim((string) ($in['pickup_only_below'] ?? '')));
        $threshold = (is_numeric($thresholdRaw) && (float) $thresholdRaw >= 0 && (float) $thresholdRaw < 100000)
            ? rtrim(rtrim(number_format((float) $thresholdRaw, 2, '.', ''), '0'), '.')
            : '10';
        if ($threshold === '') $threshold = '0';
        $message = trim((string) ($in['pickup_only_message'] ?? ''));
        if ($message === '') $message = "Retrait à l'adresse du vendeur.";

        db()->prepare('UPDATE content SET shipping_fee = ?, pickup_label = ?, shipping_label = ?, pickup_only_below = ?, pickup_only_message = ? WHERE id = 1')
            ->execute([$fee, $pickupLabel, $shippingLabel, $threshold, $message]);
        vel_done('Réglages de livraison enregistrés.');
    }

    default:
        vel_fail('Action inconnue.');
}
