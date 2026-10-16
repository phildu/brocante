<?php

// Gestion du prix de vente d'un article, au-delà du « prix affiché » (le champ Prix : celui que voit et paie le client) :
//   - prix de vente MINIMUM (price_floor) : interne, jamais montré, sert à la marge et à juger les offres ;
//   - fourchette de prix estimée (est_min / est_max), affichable ou non sur la fiche (est_show) ;
//   - prix négociable (negotiable) : la fiche l'indique et propose « Faire une offre » (table price_offers, offre.php, admin/offers.php) ;
//   - prix du neuf et lien (new_price / new_url), trouvés par la recherche « Marché », affichables ou non (new_show).

function price_offers_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS price_offers (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      ref TEXT NOT NULL,
      name TEXT NOT NULL DEFAULT '',
      email TEXT NOT NULL,
      amount_cents INTEGER NOT NULL,
      message TEXT NOT NULL DEFAULT '',
      status TEXT NOT NULL DEFAULT 'new',
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_price_offers_ref ON price_offers(ref)');
}

/** Adresse http(s) valide (≤ 500 caractères), sinon chaîne vide : jamais de javascript: ni de données dans un lien affiché. */
function pricing_safe_url(?string $url): string
{
    $url = trim((string) $url);
    return mb_strlen($url) <= 500 && preg_match('#^https?://[^\s"<>]+$#i', $url) && filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

/** Hôte lisible d'un lien (« amazon.fr »), pour le libeller sans afficher toute l'adresse. */
function pricing_url_host(string $url): string
{
    return preg_replace('/^www\./i', '', (string) parse_url($url, PHP_URL_HOST));
}

/** Montant en euros d'un champ (« 12,5 », '' → null). */
function pricing_euros($v): ?float
{
    $s = str_replace([' ', "\u{00a0}", ',', '€'], ['', '', '.', ''], trim((string) $v));
    return $s !== '' && is_numeric($s) && (float) $s >= 0 && (float) $s < 10000000 ? round((float) $s, 2) : null;
}

function pricing_fmt(float $eur): string
{
    return price_research_format($eur);
}

/** La fiche publique a-t-elle quelque chose à montrer en plus du prix ? */
function product_price_extras_visible(array $p): bool
{
    return !empty($p['negotiable']) || (!empty($p['est_show']) && ($p['est_min'] ?? null) !== null) || (!empty($p['new_show']) && pricing_safe_url($p['new_url'] ?? '') !== '');
}

/**
 * Sous le prix de la fiche publique : « Prix négociable » + bouton « Faire une offre » (fenêtre de saisie), fourchette estimée et lien vers le prix du neuf,
 * chacun seulement si le vendeur l'a coché. Chaîne vide s'il n'y a rien à montrer.
 */
function product_price_extras_html(array $p): string
{
    if (!product_price_extras_visible($p) || !in_stock($p)) return '';
    $h = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $out = '<div class="price-extras">';
    if (!empty($p['negotiable'])) {
        $out .= '<p class="pe-nego"><span class="pe-tag">Prix négociable</span> <button type="button" class="pe-offer-btn" data-offer-open>Faire une offre</button></p>';
    }
    if (!empty($p['est_show']) && ($p['est_min'] ?? null) !== null) {
        $min = (float) $p['est_min'];
        $max = ($p['est_max'] ?? null) !== null ? (float) $p['est_max'] : $min;
        $out .= '<p class="pe-line"><span>Estimation du marché (occasion)</span> <strong>' . $h($max > $min ? pricing_fmt($min) . ' à ' . pricing_fmt($max) : pricing_fmt($min)) . '</strong></p>';
    }
    $url = !empty($p['new_show']) ? pricing_safe_url($p['new_url'] ?? '') : '';
    if ($url !== '') {
        $np = trim((string) ($p['new_price'] ?? ''));
        $out .= '<p class="pe-line"><span>Prix du neuf' . ($np !== '' ? ' constaté' : '') . '</span> '
            . ($np !== '' ? '<strong>' . $h($np) . '</strong> ' : '')
            . '<a href="' . $h($url) . '" target="_blank" rel="noopener noreferrer nofollow">Voir le prix du neuf (' . $h(pricing_url_host($url)) . ') ↗</a></p>';
    }
    $out .= '</div>';
    if (!empty($p['negotiable'])) {
        $out .= '<dialog class="pe-dialog" data-offer-dialog aria-label="Faire une offre"><form method="post" action="' . $h(app_prefix('/offre.php')) . '" data-offer-form>'
            . '<div class="pe-head"><b>Faire une offre</b><button type="button" class="pe-x" data-offer-close aria-label="Fermer">×</button></div>'
            . '<p class="pe-note">Pour « ' . $h($p['name']) . ' », affiché ' . $h($p['price']) . '. Le vendeur vous répond par e-mail ; rien n\'est débité.</p>'
            . '<input type="hidden" name="ref" value="' . $h($p['ref']) . '"><input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px">'
            . '<label>Votre offre (€)<input type="number" name="amount" min="1" step="0.01" inputmode="decimal" required></label>'
            . '<label>Votre nom<input type="text" name="name" maxlength="80" autocomplete="name"></label>'
            . '<label>Votre e-mail<input type="email" name="email" maxlength="120" autocomplete="email" required></label>'
            . '<label>Message (facultatif)<textarea name="message" rows="3" maxlength="600"></textarea></label>'
            . '<p class="pe-status" role="status" data-offer-status></p>'
            . '<div class="pe-actions"><button type="submit" class="btn btn-primary">Envoyer mon offre</button><button type="button" class="btn btn-ghost" data-offer-close>Annuler</button></div>'
            . '</form></dialog>';
    }
    return $out
        . '<link rel="stylesheet" href="/assets/price-extras.css?v=' . (int) @filemtime(dirname(__DIR__) . '/assets/price-extras.css') . '">'
        . '<script src="/assets/price-extras.js?v=' . (int) @filemtime(dirname(__DIR__) . '/assets/price-extras.js') . '" defer></script>';
}

/** Adresses e-mail des administrateurs actifs de la boutique (avertis d'une offre). */
function pricing_admin_emails(): array
{
    $out = [];
    try {
        foreach (db()->query("SELECT email FROM accounts WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN) as $e) {
            if (filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = (string) $e;
        }
    } catch (Throwable $e) {
    }
    return $out;
}
