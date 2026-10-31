<?php
// Bas de toutes les pages : étiquettes en surimpression sur les photos des pièces concernées, appel à l'action dans
// le hero de l'accueil et en tête du catalogue, et annonce dans l'app pour la personne reconnue sur le site.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/aco.php';
if (!aco_enabled()) return;

$op = aco_front_operation();
$announce = $op && !empty($op['show_banner']) ? [
    'label' => trim((string) $op['label']) ?: aco_kind_label((string) $op['kind']),
    'discount' => aco_discount_text($op),
    'until' => date('d/m/Y à H:i', (int) $op['ends_at']),
    'refs' => aco_front_refs($op),
] : null;

$inapp = [];
$email = aco_current_email();
if ($email !== '') $inapp = aco_inapp_unseen($email);

if ($announce === null && !$inapp) return;

$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$path = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
$segments = array_values(array_filter(explode('/', $path), static fn ($s): bool => $s !== ''));
$last = $segments ? (string) end($segments) : '';
$cfg = [
    'home' => $last === '' || $last === 'index.php' || (count($segments) <= 1 && !str_contains($last, '.')),
    'catalogue' => str_ends_with($path, '/boutique.php'),
    'label' => $announce['label'] ?? '',
    'discount' => $announce['discount'] ?? '',
    'until' => $announce['until'] ?? '',
    'shop' => shop_abs_url('/boutique.php'),
    'refs' => $announce['refs'] ?? [],
];
$pinText = addcslashes(($announce['label'] ?? '') . ' ' . ($announce['discount'] ?? ''), "\\\"\r\n");
?>
<style>
.aco-pin{position:relative;display:block;overflow:hidden}
.aco-pin::before{content:"<?= $pinText ?>";position:absolute;top:6px;left:6px;z-index:6;max-width:calc(100% - 12px);overflow:hidden;white-space:nowrap;text-overflow:ellipsis;background:#b3261e;color:#fff;border-radius:2px;padding:7px 10px;font:700 11px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;letter-spacing:.07em;text-transform:uppercase;box-shadow:0 4px 14px rgba(0,0,0,.38);pointer-events:none}
.aco-cta{display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;background:linear-gradient(100deg,#b3261e,#8a1c15);color:#fff;text-decoration:none;padding:14px 18px;margin:0 0 20px;border-radius:3px;box-shadow:0 10px 26px rgba(0,0,0,.22)}
.aco-cta:hover,.aco-cta:focus{color:#fff;text-decoration:none}
.aco-cta-kicker{font:700 10px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;text-transform:uppercase;letter-spacing:.13em;background:rgba(255,255,255,.2);padding:6px 9px;border-radius:2px}
.aco-cta-main{flex:1;min-width:200px;font-weight:600;font-size:.97rem;line-height:1.35}
.aco-cta-btn{background:#fff;color:#8a1c15;font-weight:700;font-size:.9rem;padding:9px 14px;border-radius:2px;white-space:nowrap}
.aco-cta-wide{margin:0;border-radius:0}
.aco-inapp{position:fixed;right:18px;bottom:18px;z-index:90;max-width:340px;background:#17130f;color:#fff;padding:16px 46px 16px 18px;border-radius:4px;box-shadow:0 18px 50px rgba(0,0,0,.42);opacity:0;transform:translateY(16px);transition:opacity .25s ease,transform .25s ease;font-size:.92rem;line-height:1.4}
.aco-inapp.is-on{opacity:1;transform:none}
.aco-inapp p{margin:0 0 7px}
.aco-inapp-kicker{font:700 10px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;letter-spacing:.12em;text-transform:uppercase;opacity:.72}
.aco-inapp a{color:#fff;font-weight:700;text-decoration:underline}
.aco-inapp-x{position:absolute;top:2px;right:2px;background:none;border:0;color:#fff;font-size:1.4rem;line-height:1;cursor:pointer;padding:8px 12px;opacity:.65}
.aco-inapp-x:hover{opacity:1}
@media (prefers-reduced-motion: reduce){.aco-inapp{transition:none}}
</style>
<?php if ($announce !== null): ?>
<script type="application/json" id="aco-front"><?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(function () {
  var node = document.getElementById('aco-front');
  if (!node) return;
  var cfg;
  try { cfg = JSON.parse(node.textContent); } catch (e) { return; }
  var refs = {};
  (cfg.refs || []).forEach(function (r) { refs[String(r)] = 1; });

  // Étiquette sur les photos des pièces concernées.
  document.querySelectorAll('a[href*="/produit.php?ref="]').forEach(function (a) {
    var m = (a.getAttribute('href') || '').match(/[?&]ref=([^&]+)/);
    if (!m || !refs[decodeURIComponent(m[1])] || !a.querySelector('img')) return;
    a.classList.add('aco-pin');
  });

  function cta(extra) {
    var a = document.createElement('a');
    a.className = 'aco-cta' + (extra ? ' ' + extra : '');
    a.href = cfg.shop;
    var k = document.createElement('span');
    k.className = 'aco-cta-kicker';
    k.textContent = cfg.label;
    var t = document.createElement('span');
    t.className = 'aco-cta-main';
    t.textContent = cfg.discount + ' sur une sélection · jusqu\'au ' + cfg.until;
    var b = document.createElement('span');
    b.className = 'aco-cta-btn';
    b.textContent = 'Voir la sélection →';
    a.append(k, t, b);
    return a;
  }

  if (cfg.catalogue) {
    var main = document.querySelector('main');
    if (main) main.insertBefore(cta('aco-cta-wide'), main.firstChild);
  } else if (cfg.home) {
    var h1 = document.querySelector('main h1');
    if (!h1) return;
    var row = null, scope = h1.parentElement;
    for (var i = 0; i < 4 && scope; i++) {
      var links = scope.querySelectorAll('a[href*="boutique.php"]');
      if (links.length) { row = links[links.length - 1].parentElement; break; }
      scope = scope.parentElement;
    }
    var link = cta();
    if (row && row.appendChild) row.appendChild(link);
    else h1.insertAdjacentElement('afterend', link);
  }
})();
</script>
<?php endif; ?>
<?php foreach (array_slice($inapp, 0, 1) as $n): $opId = (int) $n['id']; ?>
<aside class="aco-inapp" data-key="aco-seen-<?= $opId ?>" role="status">
  <p class="aco-inapp-kicker"><?= $h(trim((string) $n['label']) ?: aco_kind_label((string) $n['kind'])) ?></p>
  <p><strong><?= $h(aco_discount_text($n)) ?></strong> sur une sélection, jusqu'au <?= $h(date('d/m/Y à H:i', (int) $n['ends_at'])) ?>.</p>
  <p><a href="<?= $h(shop_abs_url('/boutique.php')) ?>">Voir la boutique</a></p>
  <button type="button" class="aco-inapp-x" aria-label="Fermer l'annonce">×</button>
</aside>
<?php endforeach; ?>
<script>
(function () {
  var card = document.querySelector('.aco-inapp');
  if (!card) return;
  var key = card.getAttribute('data-key');
  var seen = false;
  try { seen = localStorage.getItem(key) === '1'; } catch (e) {}
  if (seen) { card.remove(); return; }
  window.requestAnimationFrame(function () { card.classList.add('is-on'); });
  var x = card.querySelector('.aco-inapp-x');
  if (x) x.addEventListener('click', function () {
    card.classList.remove('is-on');
    try { localStorage.setItem(key, '1'); } catch (e) {}
    window.setTimeout(function () { card.remove(); }, 260);
  });
})();
</script>
