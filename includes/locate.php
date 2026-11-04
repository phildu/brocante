<?php

// « Nous trouver » : adresse, carte IGN Géoplateforme, calcul d'itinéraire IGN et export au carnet d'adresses (vCard).
// Le fond de carte et l'itinéraire sont chargés dans le navigateur (assets/shop-locate.js) : aucune clé d'API, aucun traceur publicitaire.
// Pas de tuiles OpenStreetMap (compte banni) : plan et orthophoto viennent de data.geopf.fr, comme le plan des galeries.

require_once __DIR__ . '/legal.php';

const SHOP_LOCATE_TILE_PLAN = 'https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2&STYLE=normal&TILEMATRIXSET=PM&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}&FORMAT=image/png';
const SHOP_LOCATE_TILE_SAT = 'https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=ORTHOIMAGERY.ORTHOPHOTOS&STYLE=normal&TILEMATRIXSET=PM&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}&FORMAT=image/jpeg';
const SHOP_LOCATE_GEOCODE_URL = 'https://data.geopf.fr/geocodage/search';
const SHOP_LOCATE_ROUTE_URL = 'https://data.geopf.fr/navigation/itineraire';

/** Une adresse de remplissage (« à compléter ») n'est pas une adresse publique. */
function shop_locate_address_is_placeholder(string $address): bool
{
    return (bool) preg_match('/^(?:à compléter|a completer|adresse|\[|—|-)/iu', trim($address));
}

/**
 * Coordonnées publiques de la boutique : identité légale (Pages légales → Identité), sinon adresse du contenu.
 * -> name, company, address, phone, email, hours, delivery, tagline, page_url, maps_google, maps_apple, directions_google.
 */
function shop_locate_info(): array
{
    $content = function_exists('get_content') ? get_content() : [];
    $identity = function_exists('legal_identity') ? legal_identity() : [];

    $name = trim((string) ($content['site_name'] ?? '')) ?: (string) tenant('name', '');
    $company = trim((string) ($identity['company'] ?? '')) ?: $name;
    $address = trim((string) ($identity['address'] ?? ''));
    if (shop_locate_address_is_placeholder($address)) $address = '';
    if ($address === '' && function_exists('shop_public_address')) $address = shop_public_address($content);
    if ($address === '') $address = trim((string) ($content['contact_address'] ?? ''));
    if (shop_locate_address_is_placeholder($address)) $address = '';

    // Précision dans un site qui accueille plusieurs commerces : bâtiment/local/stand, étage, consigne d'accès.
    $addressExtra = trim((string) ($identity['address_extra'] ?? ''));
    $floor = trim((string) ($identity['floor'] ?? ''));
    $access = trim((string) ($identity['access'] ?? ''));
    $addressFull = trim($address . ($addressExtra !== '' ? ', ' . $addressExtra : '') . ($floor !== '' ? ', ' . $floor : ''), ' ,');

    // Point exact choisi sur la carte dans l'administration (facultatif) : il remplace le géocodage de l'adresse.
    $point = function_exists('legal_point') ? legal_point() : ['lat' => null, 'lng' => null];

    $phone = trim((string) ($identity['phone'] ?? ''));
    $email = trim((string) ($identity['email'] ?? ''));
    if ($email === '') $email = trim((string) tenant('admin_email', ''));

    $pageUrl = function_exists('shop_abs_url')
        ? shop_abs_url('/nous-trouver.php')
        : rtrim((string) tenant_site_url(), '/') . app_prefix('/nous-trouver.php');

    $place = trim($name . ',' . $addressFull, ', ');
    if ($point['lat'] !== null && $point['lng'] !== null) {
        $coord = number_format($point['lat'], 6, '.', '') . ',' . number_format($point['lng'], 6, '.', '');
        $mapsGoogle = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($place !== '' ? $place : $name) . '&center=' . $coord;
        $directions = 'https://www.google.com/maps/dir/?api=1&destination=' . $coord;
    } else {
        $mapsGoogle = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($place !== '' ? $place : $name);
        $directions = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($address !== '' ? $address : $name);
    }
    $appleParams = ['q' => $name];
    if ($addressFull !== '') $appleParams['address'] = $addressFull;
    if ($point['lat'] !== null && $point['lng'] !== null) {
        $appleParams['ll'] = number_format($point['lat'], 6, '.', '') . ',' . number_format($point['lng'], 6, '.', '');
        $appleParams['z'] = '18';
    }
    $mapsApple = 'https://maps.apple.com/?' . http_build_query($appleParams, '', '&', PHP_QUERY_RFC3986);

    return [
        'name' => $name,
        'company' => $company,
        'address' => $address,
        'address_extra' => $addressExtra,
        'floor' => $floor,
        'access' => $access,
        'address_full' => $addressFull,
        'point' => $point,
        'phone' => $phone,
        'email' => $email,
        'hours' => trim((string) ($content['contact_hours'] ?? '')),
        'delivery' => trim((string) ($content['contact_delivery'] ?? '')),
        'tagline' => trim((string) (($content['site_tagline'] ?? '') ?: tenant('tagline', ''))),
        'page_url' => $pageUrl,
        'maps_google' => $mapsGoogle,
        'maps_apple' => $mapsApple,
        'directions_google' => $directions,
        'vcard_url' => '/carte-visite.php',
    ];
}

/** Boutons compacts pour les sections « contact » des modèles : page, Google Maps, carnet d'adresses. */
function shop_locate_actions_html(array $options = []): string
{
    $i = shop_locate_info();
    $class = trim('shop-locate-actions ' . (string) ($options['class'] ?? ''));
    $out = '<div class="' . h($class) . '">';
    $out .= '<a class="shop-locate-btn" href="/nous-trouver.php">Carte et itinéraire</a>';
    if ($i['address'] !== '') {
        $out .= '<a class="shop-locate-btn shop-locate-btn-alt" href="' . h($i['maps_google']) . '" target="_blank" rel="noopener">Enregistrer dans Google Maps</a>';
    }
    if ($i['address'] !== '' || $i['phone'] !== '' || $i['email'] !== '') {
        $out .= '<a class="shop-locate-btn shop-locate-btn-alt" href="' . h($i['vcard_url']) . '" download data-locate-vcard>Ajouter au carnet d\'adresses</a>';
    }
    return $out . '</div>';
}

/**
 * Bloc « Nous trouver » complet : adresse, carte IGN, itinéraire et export.
 * $options : wrap (bool, true), id (string), kicker (string), class (string).
 */
function shop_locate_section_html(array $options = []): string
{
    $i = shop_locate_info();
    $wrap = $options['wrap'] ?? true;
    $id = (string) ($options['id'] ?? 'nous-trouver');
    $kicker = (string) ($options['kicker'] ?? 'Nous trouver');
    $class = trim('shop-locate' . ($wrap ? ' shop-locate-page' : '') . ' ' . (string) ($options['class'] ?? ''));
    $uid = 'sl' . substr(md5($i['name'] . $i['address_full']), 0, 6);
    $hasAddress = $i['address'] !== '';
    $hasContact = $i['phone'] !== '' || $i['email'] !== '';
    $hasPoint = $i['point']['lat'] !== null && $i['point']['lng'] !== null;

    $h = static fn ($v): string => h($v);
    $out = '<section class="' . $h($class) . '" id="' . $h($id) . '" data-shop-locate'
        . ' data-address="' . $h($i['address']) . '" data-name="' . $h($i['name']) . '"'
        . ($hasPoint ? ' data-lat="' . $h(number_format($i['point']['lat'], 6, '.', '')) . '" data-lng="' . $h(number_format($i['point']['lng'], 6, '.', '')) . '"' : '')
        . ' data-tiles-plan="' . $h(SHOP_LOCATE_TILE_PLAN) . '" data-tiles-sat="' . $h(SHOP_LOCATE_TILE_SAT) . '"'
        . ' data-geocode-url="' . $h(SHOP_LOCATE_GEOCODE_URL) . '" data-route-url="' . $h(SHOP_LOCATE_ROUTE_URL) . '"'
        . ' data-maps-google="' . $h($i['maps_google']) . '" data-maps-apple="' . $h($i['maps_apple']) . '"'
        . ' data-directions-google="' . $h($i['directions_google']) . '" data-vcard="' . $h($i['vcard_url']) . '">';

    if ($wrap) $out .= '<div class="wrap">';

    $out .= '<header class="shop-locate-head">'
        . '<p class="shop-locate-kicker">' . $h($kicker) . '</p>'
        . '<h1>Nous trouver</h1>';
    if ($hasAddress) {
        $out .= '<p class="shop-locate-lead"><address>' . nl2br($h($i['address_full'])) . '</address></p>';
    } else {
        $out .= '<p class="shop-locate-lead">L\'adresse n\'est pas encore renseignée. '
            . ($hasContact ? 'Écrivez-nous ou appelez-nous pour préparer votre visite.' : 'Contactez la boutique pour connaître le lieu de retrait.') . '</p>';
    }
    $meta = [];
    if ($i['access'] !== '') $meta[] = '<li class="shop-locate-access"><b>Accès</b><span>' . nl2br($h($i['access'])) . '</span></li>';
    if ($i['hours'] !== '') $meta[] = '<li><b>Horaires</b><span>' . nl2br($h($i['hours'])) . '</span></li>';
    if ($i['delivery'] !== '') $meta[] = '<li><b>Livraison</b><span>' . $h($i['delivery']) . '</span></li>';
    if ($i['phone'] !== '') $meta[] = '<li><b>Téléphone</b><a href="tel:' . $h(preg_replace('/[^\d+]/', '', $i['phone'])) . '">' . $h($i['phone']) . '</a></li>';
    if ($i['email'] !== '') $meta[] = '<li><b>E-mail</b><a href="mailto:' . $h($i['email']) . '">' . $h($i['email']) . '</a></li>';
    if ($meta) $out .= '<ul class="shop-locate-meta">' . implode('', $meta) . '</ul>';
    $out .= '</header>';

    $out .= '<div class="shop-locate-grid">';
    $out .= '<div class="shop-locate-mapwrap"' . ($hasAddress ? '' : ' hidden') . '>'
        . '<div class="shop-locate-map" data-locate-map role="region" aria-label="Carte IGN — ' . $h($i['name']) . '"></div>'
        . '<div class="shop-locate-layers" data-locate-layers hidden role="group" aria-label="Fond de carte">'
        . '<button type="button" data-locate-layer="plan" aria-pressed="true">Plan IGN</button>'
        . '<button type="button" data-locate-layer="sat" aria-pressed="false">Photo aérienne</button></div>'
        . '<p class="shop-locate-note">Fond de carte IGN Géoplateforme, sans traceur publicitaire. '
        . 'Prenez la carte en main pour la déplacer et zoomer.</p></div>';

    $out .= '<div class="shop-locate-side">';
    if ($hasAddress) {
        $out .= '<div class="shop-locate-actions shop-locate-actions-main">'
            . '<button type="button" class="shop-locate-btn" data-locate-toggle aria-expanded="false" aria-controls="' . $h($uid) . '-route">Calculer l\'itinéraire</button>'
            . '<a class="shop-locate-btn shop-locate-btn-alt" href="' . $h($i['maps_google']) . '" target="_blank" rel="noopener">Enregistrer dans Google Maps</a>'
            . '<a class="shop-locate-btn shop-locate-btn-alt" href="' . $h($i['maps_apple']) . '" target="_blank" rel="noopener" data-locate-apple hidden>Plans (Apple)</a>'
            . '<a class="shop-locate-btn shop-locate-btn-alt" href="' . $h($i['vcard_url']) . '" download data-locate-vcard>Ajouter au carnet d\'adresses</a>'
            . '</div>'
            . '<p class="shop-locate-hint">Le bouton Google Maps ouvre la fiche du lieu : touchez ensuite <strong>Enregistrer</strong> pour l\'ajouter à vos favoris ou à une liste. Le fichier <strong>.vcf</strong> s\'importe dans iPhone, Android, Outlook et Google Contacts.</p>';

        $out .= '<form class="shop-locate-route" id="' . $h($uid) . '-route" data-locate-routeform hidden>'
            . '<p class="shop-locate-route-title">Calculer l\'itinéraire</p>'
            . '<label class="shop-locate-field" for="' . $h($uid) . '-origin"><span>Point de départ</span>'
            . '<span class="shop-locate-origin-row">'
            . '<input id="' . $h($uid) . '-origin" type="search" role="combobox" data-locate-origin placeholder="Adresse, ville ou code postal" autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-controls="' . $h($uid) . '-suggestions">'
            . '<button type="button" data-locate-geoloc>Ma position</button></span></label>'
            . '<ul class="shop-locate-suggestions" id="' . $h($uid) . '-suggestions" data-locate-suggestions role="listbox" hidden></ul>'
            . '<div class="shop-locate-profiles" role="group" aria-label="Mode de déplacement">'
            . '<button type="button" data-locate-profile="car" aria-pressed="true">Voiture</button>'
            . '<button type="button" data-locate-profile="pedestrian" aria-pressed="false">À pied</button></div>'
            . '<button type="submit" class="shop-locate-btn shop-locate-submit">Calculer</button>'
            . '<p class="shop-locate-status" data-locate-status role="status"></p>'
            . '<div class="shop-locate-result" data-locate-result hidden>'
            . '<p class="shop-locate-result-line"><strong data-locate-distance></strong><span data-locate-duration></span></p>'
            . '<p><a data-locate-gmaps target="_blank" rel="noopener">Voir l\'itinéraire détaillé dans Google Maps →</a></p></div>'
            . '<p class="shop-locate-fallback"><a href="' . $h($i['directions_google']) . '" target="_blank" rel="noopener">Ouvrir directement dans Google Maps</a></p>'
            . '</form>';
    } else {
        $out .= '<div class="shop-locate-actions shop-locate-actions-main">';
        if ($hasContact) {
            if ($i['email'] !== '') {
                $out .= '<a class="shop-locate-btn" href="mailto:' . $h($i['email']) . '">Nous écrire</a>';
            } else {
                $out .= '<a class="shop-locate-btn" href="tel:' . $h(preg_replace('/[^\d+]/', '', $i['phone'])) . '">Nous appeler</a>';
            }
            $out .= '<a class="shop-locate-btn shop-locate-btn-alt" href="' . $h($i['vcard_url']) . '" download data-locate-vcard>Fiche de contact (.vcf)</a>';
        } else {
            $out .= '<a class="shop-locate-btn" href="/index.php#contact">Nous contacter</a>';
        }
        $out .= '</div><p class="shop-locate-hint">Dès que l\'adresse sera renseignée dans l\'administration, la carte et le calcul d\'itinéraire apparaîtront ici.</p>';
    }
    $out .= '</div></div>';

    $out .= '<noscript><p class="shop-locate-noscript">La carte interactive et le calcul d\'itinéraire demandent JavaScript. '
        . ($hasAddress ? 'L\'adresse reste disponible ci-dessus et le lien Google Maps fonctionne sans script.' : '') . '</p></noscript>';

    if ($wrap) $out .= '</div>';
    return $out . '</section>';
}

/** Découpe approximative d'une adresse postale française en composants vCard. */
function shop_locate_address_parts(string $address): array
{
    $address = trim(preg_replace('/\s+/u', ' ', $address) ?? '');
    $parts = ['street' => $address, 'postcode' => '', 'city' => '', 'country' => ''];
    if ($address === '') return $parts;
    if (preg_match('/^(.*?)[,\s]+(\d{5})\s+([^,]+?)(?:,\s*(.+))?$/u', $address, $m)) {
        $parts['street'] = trim($m[1], " ,");
        $parts['postcode'] = $m[2];
        $parts['city'] = trim($m[3]);
        $parts['country'] = trim((string) ($m[4] ?? ''));
        if ($parts['country'] === '') $parts['country'] = 'France';
    }
    return $parts;
}

/** Échappement d'une valeur vCard (3.0). */
function shop_locate_vcard_escape(string $value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $value);
}

/** Pliage des lignes vCard à 75 octets, sans couper un caractère UTF-8. */
function shop_locate_vcard_fold(string $line): string
{
    if (strlen($line) <= 75) return $line;
    $chunks = [];
    $first = true;
    while ($line !== '') {
        $max = $first ? 75 : 74;
        $chunk = function_exists('mb_strcut') ? mb_strcut($line, 0, $max, 'UTF-8') : substr($line, 0, $max);
        if ($chunk === '') break;
        $chunks[] = ($first ? '' : ' ') . $chunk;
        $line = substr($line, strlen($chunk));
        $first = false;
    }
    return implode("\r\n", $chunks);
}

/** Carte de visite vCard 3.0 de la boutique (nom, adresse, téléphone, e-mail, site, coordonnées si fournies). */
function shop_locate_vcard(array $info, ?float $lat = null, ?float $lng = null): string
{
    $esc = static fn (string $v): string => shop_locate_vcard_escape($v);
    $parts = shop_locate_address_parts((string) $info['address']);
    // Complément (bâtiment, local, stand) et étage : composant « extended address » de la vCard.
    $extended = trim(trim((string) ($info['address_extra'] ?? '')) . (($info['floor'] ?? '') !== '' ? ', ' . (string) $info['floor'] : ''), ' ,');
    $adr = implode(';', ['', $esc($extended), $esc($parts['street']), $esc($parts['city']), '', $esc($parts['postcode']), $esc($parts['country'])]);
    $lines = [
        'BEGIN:VCARD',
        'VERSION:3.0',
        'N:;' . $esc((string) $info['name']) . ';;;',
        'FN:' . $esc((string) $info['name']),
        'ORG:' . $esc((string) $info['company']),
    ];
    if ($info['address'] !== '') $lines[] = 'ADR;TYPE=WORK;CHARSET=UTF-8:' . $adr;
    if ($info['phone'] !== '') $lines[] = 'TEL;TYPE=WORK,VOICE:' . $esc((string) $info['phone']);
    if ($info['email'] !== '') $lines[] = 'EMAIL;TYPE=WORK:' . $esc((string) $info['email']);
    if ($info['page_url'] !== '') $lines[] = 'URL:' . $esc((string) $info['page_url']);
    // Consigne d'accès (interphone, entrée, parking) : utile quand le site accueille plusieurs commerces.
    $noteParts = array_filter([
        (string) $info['tagline'],
        trim((string) ($info['access'] ?? '')),
        'Nous trouver : ' . (string) $info['page_url'],
    ]);
    $note = implode(' — ', $noteParts);
    if ($note !== '') $lines[] = 'NOTE;CHARSET=UTF-8:' . $esc($note);
    // Point exact enregistré dans l'administration si l'appel n'a pas fourni de coordonnées.
    $point = $info['point'] ?? null;
    if ($lat === null && $lng === null && is_array($point) && isset($point['lat'], $point['lng']) && $point['lat'] !== null && $point['lng'] !== null) {
        $lat = (float) $point['lat'];
        $lng = (float) $point['lng'];
    }
    if ($lat !== null && $lng !== null && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
        $lines[] = 'GEO:' . number_format($lat, 6, '.', '') . ';' . number_format($lng, 6, '.', '');
    }
    $lines[] = 'REV:' . gmdate('Ymd\THis\Z');
    $lines[] = 'END:VCARD';
    return implode("\r\n", array_map('shop_locate_vcard_fold', $lines)) . "\r\n";
}
