<?php
// Conversation ouverte par l'organisateur d'une galerie avec une personne extérieure (lien signé reçu par e-mail).
//   GET  ?g=<galerie>&i=<fil>&t=<jeton>   lire la conversation
//   POST action=reply                     répondre (jeton CSRF propre au fil)
// Les conversations d'exposants restent dans l'espace exposant (/galerie/exposant.php) : leurs jetons y sont déjà.
require_once __DIR__ . '/../includes/gallery-admin.php';
require_once __DIR__ . '/../includes/gallery-messages.php';

$slug = strtolower((string) ($_GET['g'] ?? $_POST['g'] ?? ''));
$id = (string) ($_GET['i'] ?? $_POST['i'] ?? '');
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$gallery = gallery_slug_valid($slug) ? gallery_load($slug) : null;
$accent = $gallery && preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($gallery['accent'] ?? '')) ? $gallery['accent'] : '#2f6b4f';
$ink = (0.299 * hexdec(substr($accent, 1, 2)) + 0.587 * hexdec(substr($accent, 3, 2)) + 0.114 * hexdec(substr($accent, 5, 2))) > 160 ? '#1f1f1f' : '#ffffff';
$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$galleryUrl = '/galerie/' . rawurlencode($slug) . '/';

/** Page autonome (fin de requête) : même charte que l'espace exposant. */
function gmsg_page(string $title, string $html, string $accent, string $ink): never
{
    $h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    header('Referrer-Policy: no-referrer');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . $h($title) . '</title><style>'
        . ':root{--a:' . $h($accent) . ';--ai:' . $h($ink) . ';--line:#ddd;--soft:#666}*{box-sizing:border-box}'
        . 'body{margin:0;font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;color:#1f1f1f;background:#f6f4ee}'
        . 'header{background:var(--a);color:var(--ai);padding:22px 16px 18px}header div,main{max-width:640px;margin:0 auto}'
        . 'header p{margin:0;opacity:.85;font-size:.85rem;letter-spacing:.08em;text-transform:uppercase}header h1{margin:4px 0 0;font-size:1.5rem}'
        . 'main{padding:16px;display:grid;gap:14px}h1{font-size:1.4rem;margin:0}'
        . '.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px;display:grid;gap:10px}'
        . '.card h2{margin:0;font-size:1.05rem}.soft{color:var(--soft);font-size:.88rem;margin:0}'
        . '.list{display:grid;gap:10px}.msg{border:1px solid var(--line);border-radius:10px;padding:10px 12px;max-width:92%;background:#fff}'
        . '.msg.admin{margin-right:auto;background:#f4f7f4}.msg.guest{margin-left:auto;background:#eef3f8}'
        . '.msg header{background:none;color:inherit;padding:0;display:flex;justify-content:space-between;gap:8px;font-size:.78rem;color:var(--soft)}'
        . '.msg header strong{color:#1f1f1f}.msg p{margin:6px 0 0;white-space:pre-line;overflow-wrap:anywhere}'
        . 'label{display:grid;gap:6px;font-weight:600;font-size:.9rem}textarea{font:inherit;padding:10px;border:1px solid var(--line);border-radius:10px;min-height:120px;width:100%}'
        . 'button,.btn{font:inherit;font-weight:600;padding:10px 16px;border-radius:10px;border:1px solid var(--a);background:#fff;color:var(--a);cursor:pointer;text-decoration:none;display:inline-block}'
        . 'button.primary{background:var(--a);color:var(--ai)}.notice{background:#e6f4ea;color:#1c6b3c;border:1px solid #b7dcc2;border-radius:10px;padding:10px 12px;margin:0}'
        . '.error{background:#fde8e8;color:#a51b1b;border:1px solid #f3c0c0;border-radius:10px;padding:10px 12px;margin:0}'
        . '</style></head><body><header><div><p>Conversation privée</p><h1>' . $h($title) . '</h1></div></header><main>' . $html . '</main></body></html>';
    exit;
}

if ($gallery && !gt_feature($gallery, 'messages')) {
    gmsg_page('Messagerie fermée',
        '<div class="card"><p>La messagerie de cette galerie n\'est pas ouverte.</p>'
        . '<p><a class="btn" href="' . $h($galleryUrl) . '">Voir la galerie</a></p></div>', $accent, $ink);
}

if (!$gallery || !gmsg_id_valid($id) || !gmsg_access_ok($slug, $id, $token) || !($thread = gmsg_thread($slug, $id)) || $thread['kind'] !== 'contact') {
    gmsg_page('Lien invalide',
        '<div class="card"><p>Ce lien n\'est plus valide : la conversation a peut-être été supprimée, ou l\'adresse est incomplète.</p>'
        . ($gallery ? '<p><a class="btn" href="' . $h($galleryUrl) . '">Voir la galerie</a></p>' : '<p><a class="btn" href="/">Retour à l\'accueil</a></p>') . '</div>',
        $accent, $ink);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') !== 'reply') {
        $error = 'Action inconnue.';
    } elseif (!gmsg_csrf_ok($slug, $id, (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Le formulaire a expiré : rechargez la page avant de répondre.';
    } else {
        $error = (string) (gmsg_post($slug, $id, 'guest', (string) ($_POST['body'] ?? ''), ['guest_quota' => true, 'by' => $thread['name']]) ?? '');
        if ($error === '') {
            if ($fresh = gmsg_thread($slug, $id)) gmsg_notify_admins($gallery, $fresh, (string) ($_POST['body'] ?? ''));
            header('Location: /galerie/messages.php?' . http_build_query(['g' => $slug, 'i' => $id, 't' => $token, 'envoye' => 1]) . '#repondre');
            exit;
        }
    }
}

gmsg_mark_read($slug, $id, 'guest');
$thread = gmsg_thread($slug, $id) ?? $thread;
$csrf = gmsg_csrf($slug, $id);
$sent = isset($_GET['envoye']);

$messages = '';
foreach ($thread['messages'] as $m) {
    $messages .= '<div class="msg ' . ($m['from'] === 'admin' ? 'admin' : 'guest') . '"><header><strong>'
        . $h($m['from'] === 'admin' ? ($m['by'] !== '' ? $m['by'] : 'Organisateur') : $thread['name'])
        . '</strong><span>' . $h(date('d/m/Y H:i', $m['at'])) . '</span></header><p>' . nl2br($h($m['body'])) . '</p></div>';
}
if ($messages === '') $messages = '<p class="soft">Aucun message pour le moment.</p>';

$html = '<h1>' . $h($thread['subject']) . '</h1>'
    . '<p class="soft">Conversation avec l\'organisateur de « ' . $h($gallery['name']) . ' ».</p>'
    . ($sent ? '<p class="notice">Votre message a bien été envoyé.</p>' : '')
    . ($error !== '' ? '<p class="error">' . $h($error) . '</p>' : '')
    . '<section class="card"><div class="list">' . $messages . '</div></section>'
    . '<section class="card" id="repondre"><h2>Répondre</h2>'
    . '<form method="post" style="display:grid;gap:10px;">'
    . '<input type="hidden" name="g" value="' . $h($slug) . '"><input type="hidden" name="i" value="' . $h($id) . '">'
    . '<input type="hidden" name="t" value="' . $h($token) . '"><input type="hidden" name="csrf" value="' . $h($csrf) . '">'
    . '<input type="hidden" name="action" value="reply">'
    . '<label>Votre message<textarea name="body" maxlength="' . GMSG_MAX_BODY . '" required placeholder="Bonjour, …"></textarea></label>'
    . '<div><button type="submit" class="primary">Envoyer</button></div></form>'
    . '<p class="soft">Ce lien est personnel : ne le transférez pas. Il permet de répondre sans créer de compte.</p></section>'
    . '<p class="soft"><a href="' . $h($galleryUrl) . '">← Retour à la galerie</a></p>';

gmsg_page($thread['subject'] !== '' ? $thread['subject'] : 'Message', $html, $accent, $ink);
