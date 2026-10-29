<?php
// QR Code Story : pages d'histoire, étiquettes et certificats.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__, 3) . '/includes/addons.php';
require_once dirname(__DIR__, 3) . '/includes/share.php';

const QS_MODULE = 'qr-story';

function qs_setting(string $k, string $d = ''): string
{
    $st = db()->prepare('SELECT value FROM qs_settings WHERE key = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $d : (string) $v;
}

function qs_setting_set(string $k, string $v): void
{
    db()->prepare('INSERT INTO qs_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$k, mb_substr(trim($v), 0, 300)]);
}

function qs_story(string $ref): ?array
{
    $st = db()->prepare('SELECT * FROM qs_stories WHERE product_ref = ?');
    $st->execute([$ref]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function qs_url(array $story): string
{
    return shop_abs_url('/api/story/' . $story['token']);
}

/** Enregistre l'histoire (créée avec son jeton au premier enregistrement). -> message d'erreur ou null. */
function qs_save(string $ref, array $in): ?string
{
    $p = db()->prepare('SELECT 1 FROM products WHERE ref = ?');
    $p->execute([$ref]);
    if (!$p->fetchColumn()) return 'Pièce introuvable.';
    db()->prepare('INSERT OR IGNORE INTO qs_stories (product_ref, token) VALUES (?, ?)')->execute([$ref, bin2hex(random_bytes(16))]);
    db()->prepare('UPDATE qs_stories SET epoch = ?, origin = ?, expertise = ?, expert_name = ?, published = ?, updated_at = CURRENT_TIMESTAMP WHERE product_ref = ?')->execute([
        mb_substr(trim((string) ($in['epoch'] ?? '')), 0, 120), mb_substr(trim((string) ($in['origin'] ?? '')), 0, 3000),
        mb_substr(trim((string) ($in['expertise'] ?? '')), 0, 3000), mb_substr(trim((string) ($in['expert_name'] ?? '')), 0, 120),
        !empty($in['published']) ? 1 : 0, $ref]);
    return null;
}

function qs_photo(array $p): string
{
    if (function_exists('product_gallery')) {
        foreach (product_gallery($p) as $g) {
            if (($g['type'] ?? 'photo') === 'video') continue;
            if (!empty($g['src'])) return function_exists('share_media_url') ? share_media_url($g['src']) : (string) $g['src'];
        }
    }
    return !empty($p['photo']) ? shop_abs_url('/' . ltrim($p['photo'], '/')) : '';
}

function qs_h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** Page publique de l'histoire (QR Code). */
function qs_public_html(array $story, array $p): string
{
    $site = (string) (get_content()['site_name'] ?? tenant('name'));
    $photo = qs_photo($p);
    $sold = (int) $p['stock'] <= 0;
    $nl = static fn (string $t) => nl2br(qs_h($t));
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . qs_h($p['name']) . ' — ' . qs_h($site) . '</title>'
        . '<style>body{font:17px/1.6 Georgia,serif;margin:0;color:#2a2622;background:#f7f3ec}main{max-width:640px;margin:0 auto;padding:20px 18px 48px}img{width:100%;border-radius:6px;display:block;margin-bottom:16px}h1{font-size:1.7rem;line-height:1.2;margin:.2em 0}.k{font:600 .72rem system-ui,sans-serif;letter-spacing:.08em;text-transform:uppercase;color:#8a6a3d;margin:22px 0 4px}.btn{display:inline-block;margin-top:24px;padding:12px 20px;background:#2a2622;color:#fff;text-decoration:none;border-radius:4px;font:600 .95rem system-ui,sans-serif}.sold{background:#eee;padding:10px 12px;border-radius:4px;font:.9rem system-ui,sans-serif}</style></head><body><main>'
        . ($photo ? '<img src="' . qs_h($photo) . '" alt="' . qs_h($p['name']) . '">' : '')
        . '<p class="k">' . qs_h($site) . '</p><h1>' . qs_h($p['name']) . '</h1>'
        . ($story['epoch'] !== '' ? '<p class="k">Époque</p><p>' . qs_h($story['epoch']) . '</p>' : '')
        . ($story['origin'] !== '' ? '<p class="k">Son histoire</p><p>' . $nl($story['origin']) . '</p>' : '')
        . ($story['expertise'] !== '' ? '<p class="k">Expertise</p><p>' . $nl($story['expertise']) . ($story['expert_name'] !== '' ? '<br><em>— ' . qs_h($story['expert_name']) . '</em>' : '') . '</p>' : '')
        . ($sold ? '<p class="sold">Cette pièce a trouvé preneur.</p>' : '<a class="btn" href="' . qs_h(shop_abs_url('/produit.php?ref=' . rawurlencode($p['ref']))) . '">Voir la pièce — ' . qs_h(effective_price($p)) . '</a>')
        . '</main></body></html>';
}

/** Feuille imprimable : étiquettes QR ou certificats d'authenticité. */
function qs_print_html(string $kind, array $refs): string
{
    $site = (string) (get_content()['site_name'] ?? tenant('name'));
    $items = [];
    foreach ($refs as $ref) {
        $s = qs_story($ref);
        $st = db()->prepare('SELECT * FROM products WHERE ref = ?');
        $st->execute([$ref]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if ($s && $p) $items[] = [$s, $p];
    }
    $css = '@page{size:A4;margin:10mm}body{font:13px/1.45 system-ui,sans-serif;margin:0;color:#111}.np{padding:10px}@media print{.np{display:none}}'
        . '.labels{display:grid;grid-template-columns:repeat(3,1fr);gap:4mm;padding:0 4mm}.lab{border:1px dashed #aaa;padding:3mm;text-align:center;break-inside:avoid;height:52mm;display:grid;align-content:center;gap:2mm}.lab .qr{width:30mm;height:30mm;margin:0 auto}.lab b{font-size:11px;line-height:1.2}.lab small{color:#555}'
        . '.cert{page-break-after:always;border:3px double #7a6130;margin:6mm;padding:14mm 12mm;min-height:250mm;display:grid;align-content:start;gap:8mm;text-align:center}.cert h1{font:700 26px Georgia,serif;letter-spacing:.06em;text-transform:uppercase;margin:0}.cert .t{font:italic 22px Georgia,serif}.cert .qr{width:36mm;height:36mm;margin:0 auto}.cert .txt{text-align:left;max-width:150mm;margin:0 auto}.cert .sig{display:flex;justify-content:space-between;max-width:150mm;margin:12mm auto 0;width:100%}.cert .sig div{border-top:1px solid #333;padding-top:2mm;width:60mm}';
    $body = '';
    foreach ($items as [$s, $p]) {
        $u = qs_url($s);
        if ($kind === 'certificate') {
            $signatory = qs_setting('signatory') ?: $site;
            $body .= '<section class="cert"><h1>Certificat d\'authenticité</h1><div>' . qs_h($site) . '</div><div class="t">' . qs_h($p['name']) . '</div>'
                . '<div class="txt"><p><b>Référence :</b> ' . qs_h($p['ref']) . '</p>'
                . ($s['epoch'] !== '' ? '<p><b>Époque :</b> ' . qs_h($s['epoch']) . '</p>' : '')
                . ($s['origin'] !== '' ? '<p><b>Histoire :</b> ' . nl2br(qs_h($s['origin'])) . '</p>' : '')
                . ($s['expertise'] !== '' ? '<p><b>Expertise :</b> ' . nl2br(qs_h($s['expertise'])) . ($s['expert_name'] !== '' ? ' — ' . qs_h($s['expert_name']) : '') . '</p>' : '')
                . '<p>Le vendeur atteste que les informations ci-dessus correspondent à celles dont il dispose sur cette pièce à la date d\'établissement du certificat.</p></div>'
                . '<div class="qr" data-url="' . qs_h($u) . '"></div><small>Scannez pour retrouver l\'histoire de la pièce</small>'
                . '<div class="sig"><div>Fait le ' . qs_h(date('d/m/Y')) . '</div><div>' . qs_h($signatory) . '</div></div></section>';
        } else {
            $body .= '<div class="lab"><div class="qr" data-url="' . qs_h($u) . '"></div><b>' . qs_h(mb_substr($p['name'], 0, 60)) . '</b><small>' . qs_h($p['ref']) . ' · ' . qs_h(effective_price($p)) . '</small></div>';
        }
    }
    if (!$items) $body = '<p class="np">Aucune pièce avec une histoire enregistrée dans la sélection.</p>';
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>' . ($kind === 'certificate' ? 'Certificats' : 'Étiquettes QR') . '</title><style>' . $css . '</style></head><body>'
        . '<p class="np"><button onclick="print()">Imprimer / enregistrer en PDF</button></p>' . ($kind === 'certificate' ? $body : '<div class="labels">' . $body . '</div>')
        . '<script src="/assets/vendor/qrcode-generator.js"></script><script>document.querySelectorAll(".qr").forEach(function(b){var q=qrcode(0,"M");q.addData(b.dataset.url);q.make();b.innerHTML=q.createSvgTag({cellSize:4,margin:0,scalable:true});var s=b.querySelector("svg");if(s){s.style.width="100%";s.style.height="100%"}});</script></body></html>';
}
