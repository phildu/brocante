<?php
// Boîte locale : messages de test recopiés par les fonctions d'envoi dans
// data/mail-outbox.log et data/sms-outbox.log quand le site tourne en local.
// Sert à retrouver un lien de réinitialisation, un code SMS ou un mot de passe
// provisoire sans ouvrir les fichiers à la main. Réservé au local : en ligne,
// ces fichiers n'existent pas (les messages partent réellement).
require __DIR__ . '/_bootstrap.php';

if (!$portailLocal) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fr"><meta charset="utf-8"><title>Boîte locale</title>'
        . '<p style="font-family:system-ui;padding:24px">La boîte locale est réservée à la machine de développement (localhost ou domaine en .test).</p></html>';
    exit;
}

/** Minuscules sans accents, pour une recherche tolérante (« réinit » trouve « Réinit »). */
function jl_fold(string $s): string
{
    return strtr(mb_strtolower($s, 'UTF-8'), [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae', '’' => "'", '–' => '-', '—' => '-',
    ]);
}

/** Lit un fichier de boîte : blocs « [date] À : destinataire / Objet : … / corps » séparés par « ---- ». */
function jl_read(string $file, string $canal): array
{
    $raw = is_file($file) ? (string) @file_get_contents($file) : '';
    if ($raw === '') return [];
    $out = [];
    foreach (preg_split('/^----\s*$/m', $raw) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '' || !preg_match('/^\[([^\]]+)\]\s*À\s*:\s*(.+?)\n(.*)$/su', $chunk, $m)) continue;
        $body = trim($m[3]);
        $subject = '';
        if (preg_match('/^Objet\s*:\s*([^\n]+)\n(.*)$/su', $body, $s)) {
            $subject = trim($s[1]);
            $body = trim($s[2]);
        }
        $out[] = [
            'canal' => $canal,
            'ts' => strtotime(trim($m[1])) ?: (int) @filemtime($file),
            'to' => trim($m[2]),
            'subject' => $subject,
            'body' => $body,
        ];
    }
    return $out;
}

/** Corps du message : liens cliquables (liens de réinitialisation) et retours à la ligne conservés. */
function jl_body_html(string $text): string
{
    $html = '';
    foreach (preg_split('~(https?://\S+)~u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $part) {
        $html .= $i % 2 === 1
            ? '<a href="' . e($part) . '" target="_blank" rel="noopener">' . e($part) . '</a>'
            : nl2br(e($part));
    }
    return $html;
}

$files = [
    'mail' => ['path' => PORTAIL_ROOT . '/data/mail-outbox.log', 'label' => 'E-mails', 'icon' => 'inbox'],
    'sms' => ['path' => PORTAIL_ROOT . '/data/sms-outbox.log', 'label' => 'SMS', 'icon' => 'message'],
];

$canal = in_array($_GET['canal'] ?? '', ['mail', 'sms'], true) ? (string) $_GET['canal'] : '';
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
$needle = jl_fold($q);

$all = [];
$counts = ['mail' => 0, 'sms' => 0];
foreach ($files as $key => $meta) {
    $rows = jl_read($meta['path'], $key);
    $counts[$key] = count($rows);
    foreach ($rows as $row) {
        if ($canal !== '' && $key !== $canal) continue;
        if ($needle !== '' && !str_contains(jl_fold($row['to'] . ' ' . $row['subject'] . ' ' . $row['body']), $needle)) continue;
        $all[] = $row;
    }
}
usort($all, static fn (array $a, array $b): int => $b['ts'] <=> $a['ts']);

$perPage = 20;
$pages = max(1, (int) ceil(count($all) / $perPage));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);
$slice = array_slice($all, ($page - 1) * $perPage, $perPage);

$url = static function (array $over = []) use ($canal, $q): string {
    $params = array_merge(['canal' => $canal, 'q' => $q], $over);
    $params = array_filter($params, static fn ($v) => $v !== '' && $v !== null);
    return '/portail/journal-local.php' . ($params ? '?' . http_build_query($params) : '');
};
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Boîte locale — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .owrap { display: grid; gap: 16px; max-width: 980px; margin: 0 auto; padding: 0 16px 56px; }
  .ocard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 12px; min-width: 0; }
  .ocard h2 { margin: 0; font-size: 1.02rem; }
  .ocard h2 small { font-weight: 400; color: var(--muted); margin-left: 6px; }
  .searchbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .searchbar input[type=search] { flex: 1; min-width: 220px; padding: 9px 12px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; }
  .filters { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .filters a.chip { padding: 4px 12px; border: 1px solid var(--line); border-radius: 999px; text-decoration: none; font-size: .84rem; color: var(--ink); }
  .filters a.chip[aria-current="true"] { background: var(--ink); color: var(--panel); border-color: var(--ink); }
  .jfile { display: grid; gap: 2px; font-size: .82rem; color: var(--muted); }
  .jfile code { font-family: var(--font-mono); font-size: .78rem; color: var(--ink); }
  .msg { border: 1px solid var(--line); border-radius: 10px; background: var(--bg); display: grid; gap: 8px; padding: 14px 16px; min-width: 0; }
  .msg-head { display: flex; gap: 10px; align-items: baseline; flex-wrap: wrap; }
  .msg-head .who { font-weight: 700; overflow-wrap: anywhere; }
  .msg-head time { color: var(--muted); font-size: .82rem; }
  .msg-head .copy { margin-left: auto; }
  .msg-subject { font-weight: 600; }
  .msg-body { margin: 0; overflow-wrap: anywhere; font-size: .9rem; line-height: 1.5; }
  .msg-body a { overflow-wrap: anywhere; }
  .tag { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: .74rem; font-weight: 700; white-space: nowrap; background: var(--panel-2); border: 1px solid var(--line); }
  .tag.mail { background: var(--accent-soft); color: var(--accent); border-color: transparent; }
  .muted { color: var(--muted); }
  .pager { display: flex; gap: 10px; align-items: center; justify-content: center; font-size: .88rem; }
  .empty { display: grid; gap: 6px; color: var(--muted); font-size: .9rem; }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Boîte locale',
    'eyebrow' => 'Plateforme',
    'desc' => 'E-mails et SMS de test : liens de réinitialisation, codes SMS, mots de passe provisoires. Rien ne part réellement en local.',
    'actions' => '<a class="btn" href="' . e($url(['q' => '', 'canal' => '', 'page' => ''])) . '">Tout afficher</a>',
]); ?>
  <div class="owrap">
    <section class="ocard">
      <form class="searchbar" method="get" action="/portail/journal-local.php">
        <?php if ($canal !== ''): ?><input type="hidden" name="canal" value="<?= e($canal) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Rechercher un destinataire, un objet, une boutique, un lien…" aria-label="Rechercher dans les messages" autofocus>
        <button class="btn btn-primary" type="submit">Rechercher</button>
        <?php if ($q !== ''): ?><a class="btn" href="<?= e($url(['q' => '', 'page' => ''])) ?>">Effacer</a><?php endif; ?>
      </form>
      <div class="filters" role="group" aria-label="Type de message">
        <a class="chip" href="<?= e($url(['canal' => '', 'page' => ''])) ?>"<?= $canal === '' ? ' aria-current="true"' : '' ?>>Tout (<?= $counts['mail'] + $counts['sms'] ?>)</a>
        <?php foreach ($files as $key => $meta): ?>
          <a class="chip" href="<?= e($url(['canal' => $key, 'page' => ''])) ?>"<?= $canal === $key ? ' aria-current="true"' : '' ?>><?= e($meta['label']) ?> (<?= $counts[$key] ?>)</a>
        <?php endforeach; ?>
        <span class="muted" style="margin-left:auto"><?= count($all) ?> message<?= count($all) > 1 ? 's' : '' ?><?= $q !== '' ? ' pour « ' . e($q) . ' »' : '' ?></span>
      </div>
      <div class="jfile">
        <?php foreach ($files as $key => $meta):
            $exists = is_file($meta['path']); ?>
          <span><?= e($meta['label']) ?> : <code>data/<?= $key === 'mail' ? 'mail' : 'sms' ?>-outbox.log</code>
            <?= $exists ? '· ' . number_format((int) filesize($meta['path']) / 1024, 1, ',', ' ') . ' Ko · modifié le ' . e(date('d/m/Y H:i', (int) filemtime($meta['path']))) : '· pas encore créé' ?></span>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="ocard">
      <h2>Messages <small><?= count($all) ?></small></h2>
      <?php if (!$slice): ?>
        <p class="empty">
          <span><?= $q !== '' ? 'Aucun message ne correspond à cette recherche.' : 'Aucun message pour l\'instant.' ?></span>
          <span>Les e-mails et SMS de test apparaissent ici dès qu'un envoi a lieu : création ou réinitialisation d'un compte administrateur de galerie, code par SMS, inscription, retour testeur…</span>
        </p>
      <?php else: ?>
        <?php foreach ($slice as $row): $meta = $files[$row['canal']]; ?>
          <article class="msg">
            <div class="msg-head">
              <span class="tag <?= e($row['canal']) ?>"><?= e($meta['label']) ?></span>
              <span class="who"><?= e($row['to']) ?></span>
              <time datetime="<?= e(date('c', $row['ts'])) ?>"><?= e(date('d/m/Y H:i:s', $row['ts'])) ?></time>
              <button type="button" class="btn small copy" data-copy="<?= e($row['subject'] !== '' ? $row['subject'] . "\n\n" . $row['body'] : $row['body']) ?>">Copier</button>
            </div>
            <?php if ($row['subject'] !== ''): ?><div class="msg-subject"><?= e($row['subject']) ?></div><?php endif; ?>
            <p class="msg-body"><?= jl_body_html($row['body']) ?></p>
          </article>
        <?php endforeach; ?>
        <?php if ($pages > 1): ?>
          <div class="pager">
            <?php if ($page > 1): ?><a href="<?= e($url(['page' => $page - 1])) ?>">← Plus récents</a><?php endif; ?>
            <span>Page <?= $page ?> / <?= $pages ?></span>
            <?php if ($page < $pages): ?><a href="<?= e($url(['page' => $page + 1])) ?>">Plus anciens →</a><?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </div>
</div>
<script>
document.addEventListener('click', function (event) {
  var btn = event.target.closest('[data-copy]');
  if (!btn) return;
  navigator.clipboard.writeText(btn.getAttribute('data-copy')).then(function () {
    var old = btn.textContent;
    btn.textContent = 'Copié';
    setTimeout(function () { btn.textContent = old; }, 1500);
  });
});
</script>
</body>
</html>
