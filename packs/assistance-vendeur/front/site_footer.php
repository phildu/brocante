<?php
// Widget visiteur : disponibilité du vendeur, question rapide (e-mail ou appel WhatsApp) et nouvelles des pièces.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/av.php';

if (!av_active() || !av_bool('widget')) return;

$token = av_session_token(true);
$file = basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
$page = match ($file) {
    'index.php' => 'home',
    'boutique.php', 'catalogue.php' => 'shop',
    'produit.php' => 'product',
    'cart.php' => 'cart',
    'checkout.php' => 'checkout',
    default => 'other',
};
$ref = $page === 'product' ? (string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_GET['ref'] ?? '')) : '';
$cart = [];
if (function_exists('cart_lines') && $page !== 'home') {
    foreach (cart_lines() as $line) $cart[] = (string) ($line['product']['ref'] ?? '');
}
$state = av_state($token, $ref);
$base = function_exists('shop_abs_url') ? shop_abs_url('/api/presence') : '/api/presence';
$init = [
    'token' => $token,
    'page' => $page,
    'ref' => $ref,
    'cart' => array_values(array_filter($cart)),
    'state' => $state,
    'urls' => [
        'state' => $base . '/state',
        'ping' => $base . '/ping',
        'question' => $base . '/question',
    ],
];
$shopName = av_site_name();
?>
<link rel="stylesheet" href="<?= h(plugin_asset_url($m, 'presence.css')) ?>">
<div id="av-widget" class="av" data-token="<?= h($token) ?>" data-page="<?= h($page) ?>" data-ref="<?= h($ref) ?>">
  <script type="application/json" id="av-init"><?= json_encode($init, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <button type="button" class="av-bubble" id="av-bubble" aria-expanded="false" aria-controls="av-panel">
    <span class="av-bubble-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9.6 9.6 0 0 1-3.9-.8L3 21l1.9-4.7A8.3 8.3 0 0 1 3.6 11.5a8.4 8.4 0 0 1 8.4-8.4h.5a8.4 8.4 0 0 1 8.5 8.4z"/></svg>
    </span>
    <span class="av-bubble-text" id="av-bubble-text">Une question ?</span>
    <span class="av-dot" id="av-dot" aria-hidden="true"></span>
  </button>

  <section class="av-panel" id="av-panel" role="dialog" aria-modal="false" aria-labelledby="av-title" hidden>
    <header class="av-head">
      <div>
        <p class="av-eyebrow" id="av-shop"><?= h($shopName) ?></p>
        <h2 id="av-title">Assistance</h2>
      </div>
      <button type="button" class="av-close" id="av-close" aria-label="Fermer">×</button>
    </header>

    <p class="av-status" id="av-status" role="status">Chargement de la disponibilité…</p>
    <p class="av-welcome" id="av-welcome"></p>
    <p class="av-hold" id="av-hold" hidden></p>

    <a class="av-wa" id="av-wa" hidden target="_blank" rel="noopener">
      <span aria-hidden="true">✆</span> Discuter sur WhatsApp
    </a>

    <form class="av-form" id="av-form" novalidate>
      <div class="av-channels" role="radiogroup" aria-label="Comment vous répondre ?">
        <label><input type="radio" name="channel" value="site" checked> Par e-mail</label>
        <label><input type="radio" name="channel" value="whatsapp_call"> Par WhatsApp (appel)</label>
      </div>
      <label class="av-field">Votre nom <span class="av-opt">(facultatif)</span>
        <input type="text" name="name" maxlength="80" autocomplete="name">
      </label>
      <label class="av-field" id="av-email-field">Votre e-mail
        <input type="email" name="email" maxlength="160" autocomplete="email" inputmode="email">
      </label>
      <label class="av-field" id="av-phone-field" hidden>Votre numéro WhatsApp
        <input type="tel" name="phone" maxlength="24" autocomplete="tel" placeholder="06 12 34 56 78">
      </label>
      <label class="av-consent" id="av-consent-field" hidden>
        <input type="checkbox" name="consent" value="1"> J’accepte d’être appelé(e) sur WhatsApp au sujet de ma question.
      </label>
      <label class="av-field">Votre question
        <textarea name="body" rows="3" maxlength="1200" required placeholder="Par exemple : cette pièce est-elle toujours disponible ?"></textarea>
      </label>
      <input type="text" name="website" class="av-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
      <button type="submit" class="av-submit" id="av-submit">Envoyer ma question</button>
      <p class="av-msg" id="av-msg" role="status"></p>
    </form>

    <p class="av-foot">Réponse par e-mail ou sur WhatsApp. Vos coordonnées ne servent qu’à répondre à cette question.</p>
  </section>
</div>
<script src="<?= h(plugin_asset_url($m, 'presence.js')) ?>" defer></script>