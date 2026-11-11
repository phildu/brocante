<?php
// QR « Payer cette pièce » de la fiche produit : le client scanne le code avec
// son téléphone, règle sur payer.php avec les moyens de paiement de la boutique,
// et la pièce passe en « Vendue » dès que le paiement est confirmé. Le vendeur
// peut imprimer le code pour le coller sur l'objet ou sur son étiquette.

require_once __DIR__ . '/share.php';

/** La pièce peut-elle être réglée par QR ? (vente active, en stock, prix fixe, un moyen de paiement). */
function shop_qr_payable(array $product): bool
{
    if (!shop_sales_enabled() || !in_stock($product)) return false;
    $price = effective_price($product);
    if (!is_fixed_price($price)) return false;
    $cents = price_to_cents($price);
    if ($cents === null || $cents < 50) return false;   // Stripe refuse les paiements sous 0,50 €
    return shop_qr_payment_methods() !== [];
}

/** Adresse absolue de la page de paiement d'une pièce (celle que porte le QR). */
function shop_qr_pay_url(array $product): string
{
    return shop_abs_url('/payer.php?ref=' . rawurlencode((string) $product['ref']));
}

/** Carte « Payer avec son téléphone » de la fiche produit ('' si la pièce ne peut pas être réglée en ligne). */
function product_qr_html(array $product): string
{
    if (!shop_qr_payable($product)) return '';
    $url = shop_qr_pay_url($product);
    $name = (string) $product['name'];
    $ref = (string) $product['ref'];
    $price = effective_price($product);
    $shop = (string) ((get_content()['site_name'] ?? '') ?: tenant('name'));
    $isAdmin = function_exists('admin_session') && admin_session() !== null;
    $h = static fn ($v): string => h((string) $v);

    return '<div class="qr-pay" data-qr-url="' . $h($url) . '" data-qr-name="' . $h($name) . '" data-qr-price="' . $h($price) . '" data-qr-ref="' . $h($ref) . '" data-qr-shop="' . $h($shop) . '">'
        . '<div class="qr-pay-code" data-qr-box role="img" aria-label="Code QR pour payer ' . $h($name) . '"></div>'
        . '<div class="qr-pay-body">'
        . '<p class="qr-pay-kicker">Paiement en boutique</p>'
        . '<h3 class="qr-pay-title">Scannez pour régler cette pièce</h3>'
        . '<p class="qr-pay-text">Ouvrez l’appareil photo de votre téléphone, scannez le code et payez avec vos moyens de paiement : carte bancaire, Apple Pay, Google Pay, PayPal… Dès le paiement reçu, la pièce passe en <strong>« Vendue »</strong>.</p>'
        . '<div class="qr-pay-actions">'
        . '<a class="btn btn-ghost" href="' . $h('/payer.php?ref=' . rawurlencode($ref)) . '">Ouvrir la page de paiement</a>'
        . '<button type="button" class="btn btn-ghost" data-qr-print>Imprimer le code</button>'
        . ($isAdmin ? '<button type="button" class="btn btn-ghost" data-qr-fichero>Étiqueteuse Fichero</button>' : '')
        . ($isAdmin ? '<a class="qr-pay-admin" href="' . $h('/admin/catalog.php#produit-' . rawurlencode($ref)) . '">Marquer vendue (administration)</a>' : '')
        . '</div></div></div>'
        . '<script src="/assets/vendor/qrcode-generator.js"></script>'
        . '<script>'
        . '(function () {'
        . '  var root = document.querySelector(".qr-pay");'
        . '  if (!root) return;'
        . '  var box = root.querySelector("[data-qr-box]");'
        . '  var url = root.getAttribute("data-qr-url") || "";'
        . '  if (!url || typeof qrcode !== "function") { box.textContent = "QR indisponible : utilisez le bouton de paiement."; return; }'
        . '  var q;'
        . '  try {'
        . '    q = qrcode(0, "M"); q.addData(url); q.make();'
        . '    box.innerHTML = q.createSvgTag({ cellSize: 5, margin: 2, scalable: true, alt: "Code QR pour payer " + (root.getAttribute("data-qr-name") || "cette pièce") });'
        . '  } catch (e) { box.textContent = "QR indisponible : utilisez le bouton de paiement."; return; }'
        . '  function esc(s) { return String(s).replace(/[&<>"\']/g, function (c) { return {"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;","\'":"&#39;"}[c]; }); }'
        . '  function printQr() {'
        . '    var w = window.open("", "_blank", "width=620,height=800");'
        . '    if (!w) { alert("Autorisez les fenêtres pour imprimer le code."); return; }'
        . '    var svg = q.createSvgTag({ cellSize: 10, margin: 4 });'
        . '    var shop = esc(root.getAttribute("data-qr-shop") || "");'
        . '    var name = esc(root.getAttribute("data-qr-name") || "");'
        . '    var price = esc(root.getAttribute("data-qr-price") || "");'
        . '    var ref = esc(root.getAttribute("data-qr-ref") || "");'
        . '    w.document.write("<!doctype html><html lang=\\"fr\\"><head><meta charset=\\"utf-8\\"><title>Payer — " + name + "</title><style>"'
        . '      + "@page{size:A5;margin:10mm}body{font-family:system-ui,-apple-system,sans-serif;text-align:center;margin:0;color:#111}"'
        . '      + "h1{font-size:20px;margin:0 0 4px}h2{font-size:16px;font-weight:600;margin:0 0 6px}.shop{font-size:12px;color:#666;margin:0 0 14px}"'
        . '      + ".qr{width:78mm;margin:0 auto}.qr svg{width:100%;height:auto;display:block}.price{font-size:26px;font-weight:700;margin:12px 0 4px}"'
        . '      + ".ref{font-size:12px;color:#666}.note{font-size:13px;margin-top:12px;line-height:1.4}.url{font-size:10px;color:#777;word-break:break-all;margin-top:8px}"'
        . '      + "</style></head><body><h1>" + shop + "</h1><h2>" + name + "</h2><div class=\\"qr\\">" + svg + "</div>"'
        . '      + "<div class=\\"price\\">" + price + "</div><div class=\\"ref\\">Réf. " + ref + "</div>"'
        . '      + "<p class=\\"note\\">Scannez ce code avec l’appareil photo pour payer<br>carte bancaire · Apple Pay · Google Pay · PayPal</p>"'
        . '      + "<p class=\\"url\\">" + esc(url) + "</p>"'
        . '      + "<script>window.onload=function(){window.print()}<\\/script></body></html>");'
        . '    w.document.close();'
        . '  }'
        . '  var btn = root.querySelector("[data-qr-print]");'
        . '  if (btn) btn.addEventListener("click", printQr);'
        . '  var fichero = root.querySelector("[data-qr-fichero]");'
        . '  if (fichero) fichero.addEventListener("click", function () {'
        . '    function go() { window.FicheroPrint.printRefs([root.getAttribute("data-qr-ref")]).catch(function (e) { alert(e && e.message ? e.message : "Impression impossible."); }); }'
        . '    if (window.FicheroPrint) { go(); return; }'
        . '    var s = document.createElement("script");'
        . '    s.src = (window.APP_BASE || "") + "/assets/fichero-print.js";'
        . '    s.onload = go; s.onerror = function () { alert("Script d’impression introuvable."); };'
        . '    document.head.appendChild(s);'
        . '  });'
        . '})();'
        . '</script>';
}
