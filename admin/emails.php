<?php
// Ouvertures d'e-mails : messages envoyés par la boutique et la plateforme,
// avec le nombre de fois où leur pixel invisible a été demandé (voir
// includes/mail-tracking.php et mail-open.php). Mesure indicative : les
// messageries qui bloquent les images ou les préchargent faussent le chiffre.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mail-tracking.php';
require_admin();

$content = get_content();
$days = in_array((int) ($_GET['jours'] ?? 30), [7, 30, 90, 365], true) ? (int) ($_GET['jours'] ?? 30) : 30;
$source = (string) ($_GET['source'] ?? '');
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 120);
$onlyOpened = ($_GET['ouvertes'] ?? '') === '1';
$since = time() - $days * 86400;

try {
    mail_open_ensure_schema();
    $knownSources = array_keys(MAIL_OPEN_SOURCES);
    foreach (db()->query('SELECT DISTINCT source FROM mail_opens WHERE source != ""')->fetchAll(PDO::FETCH_COLUMN) as $extra) {
        if (!in_array($extra, $knownSources, true)) $knownSources[] = (string) $extra;
    }
    if (!in_array($source, $knownSources, true)) $source = '';

    $where = ['sent_at >= ?'];
    $params = [$since];
    if ($source !== '') {
        $where[] = 'source = ?';
        $params[] = $source;
    }
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $where[] = "(email LIKE ? ESCAPE '\\' OR subject LIKE ? ESCAPE '\\' OR context LIKE ? ESCAPE '\\')";
        array_push($params, $like, $like, $like);
    }
    if ($onlyOpened) $where[] = 'open_count > 0';
    $sqlWhere = implode(' AND ', $where);

    $summarySt = db()->prepare("SELECT COUNT(*) AS sent, COUNT(DISTINCT email) AS recipients,
        COALESCE(SUM(CASE WHEN open_count > 0 THEN 1 ELSE 0 END), 0) AS opened,
        COALESCE(SUM(open_count), 0) AS opens, COALESCE(SUM(bot_count), 0) AS bots
        FROM mail_opens WHERE $sqlWhere");
    $summarySt->execute($params);
    $summary = $summarySt->fetch(PDO::FETCH_ASSOC) ?: ['sent' => 0, 'recipients' => 0, 'opened' => 0, 'opens' => 0, 'bots' => 0];

    $bySource = db()->prepare("SELECT source, COUNT(*) AS sent, COALESCE(SUM(CASE WHEN open_count > 0 THEN 1 ELSE 0 END), 0) AS opened,
        COALESCE(SUM(open_count), 0) AS opens FROM mail_opens WHERE $sqlWhere GROUP BY source ORDER BY sent DESC");
    $bySource->execute($params);
    $bySource = $bySource->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $summary = ['sent' => 0, 'recipients' => 0, 'opened' => 0, 'opens' => 0, 'bots' => 0];
    $bySource = [];
    $knownSources = array_keys(MAIL_OPEN_SOURCES);
    $sqlWhere = '';
    $params = [];
    $problem = 'La base de suivi n’est pas encore disponible : aucun e-mail suivi n’a été envoyé depuis cette boutique.';
}

if (($_GET['export'] ?? '') === 'csv' && $sqlWhere !== '') {
    $rows = db()->prepare("SELECT * FROM mail_opens WHERE $sqlWhere ORDER BY sent_at DESC");
    $rows->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ouvertures-emails-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Envoyé le', 'Destinataire', 'Objet', 'Source', 'Contexte', 'Ouvertures', 'Première ouverture', 'Dernière ouverture', 'Logiciel', 'Adresse IP'], ';', '"', '\\');
    $safe = static fn ($v): string => preg_match('/^[=+\-@]/', (string) $v) ? "'" . (string) $v : (string) $v;
    foreach ($rows as $row) {
        fputcsv($out, [
            date('d/m/Y H:i', (int) $row['sent_at']),
            $safe($row['email']),
            $safe($row['subject']),
            mail_open_source_label((string) $row['source']),
            $safe($row['context']),
            (int) $row['open_count'],
            $row['first_open_at'] ? date('d/m/Y H:i', (int) $row['first_open_at']) : '',
            $row['last_open_at'] ? date('d/m/Y H:i', (int) $row['last_open_at']) : '',
            mail_open_client_label((string) $row['user_agent']),
            (string) $row['ip'],
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

try {
    $perPage = 40;
    $total = 0;
    if ($sqlWhere !== '') {
        $countSt = db()->prepare("SELECT COUNT(*) FROM mail_opens WHERE $sqlWhere");
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();
    }
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);
    $rows = [];
    if ($sqlWhere !== '') {
        $listSt = db()->prepare("SELECT * FROM mail_opens WHERE $sqlWhere ORDER BY sent_at DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
        $listSt->execute($params);
        $rows = $listSt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $rows = [];
    $total = 0;
    $pages = 1;
    $page = 1;
}

$rate = (int) $summary['sent'] > 0 ? (int) round((int) $summary['opened'] * 100 / (int) $summary['sent']) : 0;
$url = static function (array $over = []) use ($days, $source, $q, $onlyOpened): string {
    $params = array_filter(array_merge(['jours' => $days, 'source' => $source, 'q' => $q, 'ouvertes' => $onlyOpened ? '1' : ''], $over),
        static fn ($v): bool => $v !== '' && $v !== null && $v !== false);
    return '/admin/emails.php' . ($params ? '?' . http_build_query($params) : '');
};
$stamp = static fn ($ts): string => $ts ? date('d/m/Y H:i', (int) $ts) : '—';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Ouvertures d’e-mails — <?= h($content['site_name']) ?></title>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .em-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin: 18px 0; }
  .em-card { background: var(--surface); border: 1px solid var(--line); padding: 14px 16px; }
  .em-card .k { font-family: var(--font-mono); font-size: .68rem; text-transform: uppercase; letter-spacing: .05em; color: var(--ink-soft); }
  .em-card .v { font-family: var(--font-mono); font-size: 1.5rem; margin-top: 6px; font-variant-numeric: tabular-nums; }
  .em-filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; margin: 0 0 16px; }
  .em-filters label { display: flex; flex-direction: column; gap: 4px; font-size: .8rem; color: var(--ink-soft); }
  .em-filters input, .em-filters select { background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 8px 10px; font: inherit; min-width: 150px; }
  .em-table { width: 100%; border-collapse: collapse; font-size: .86rem; }
  .em-table th, .em-table td { padding: 7px 9px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
  .em-table th { font-family: var(--font-mono); font-size: .68rem; text-transform: uppercase; letter-spacing: .04em; color: var(--ink-soft); font-weight: 400; }
  .em-table td.num { text-align: right; font-family: var(--font-mono); font-variant-numeric: tabular-nums; white-space: nowrap; }
  .em-open { color: #1b6b3a; font-weight: 600; }
  .em-none { color: var(--ink-soft); }
  .em-src { display: inline-block; border: 1px solid var(--line); padding: 1px 6px; font-size: .74rem; white-space: nowrap; }
  .em-pager { display: flex; gap: 8px; align-items: center; margin-top: 14px; }
  .em-note { font-size: .82rem; color: var(--ink-soft); max-width: 82ch; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'emails'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Espace boutique</p>
    <h1 style="font-size:1.8rem;margin:12px 0;">Ouvertures d’e-mails</h1>
    <p class="hint" style="max-width:82ch;">Chaque message envoyé par la boutique contient une image invisible de 1 × 1 pixel. Quand le destinataire affiche le message avec les images, cette demande est comptée ici. C’est un <strong>indicateur de tendance</strong>, pas une preuve de lecture : Outlook bloque les images par défaut, et la protection de la vie privée d’Apple Mail les précharge pour tous les messages.</p>

    <?php if (isset($problem)): ?><p class="publish-status" data-kind="info" style="margin:16px 0;"><?= h($problem) ?></p><?php endif; ?>

    <div class="em-cards">
      <div class="em-card"><div class="k">E-mails envoyés</div><div class="v"><?= (int) $summary['sent'] ?></div></div>
      <div class="em-card"><div class="k">Destinataires</div><div class="v"><?= (int) $summary['recipients'] ?></div></div>
      <div class="em-card"><div class="k">Ouverts au moins une fois</div><div class="v"><?= (int) $summary['opened'] ?></div></div>
      <div class="em-card"><div class="k">Taux d’ouverture</div><div class="v"><?= $rate ?> %</div></div>
      <div class="em-card"><div class="k">Ouvertures cumulées</div><div class="v"><?= (int) $summary['opens'] ?></div></div>
    </div>

    <form method="get" action="/admin/emails.php" class="em-filters">
      <label>Période
        <select name="jours">
          <?php foreach ([7 => '7 jours', 30 => '30 jours', 90 => '90 jours', 365 => '1 an'] as $d => $label): ?>
            <option value="<?= $d ?>"<?= $days === $d ? ' selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Source
        <select name="source">
          <option value="">Toutes</option>
          <?php foreach ($knownSources as $key): ?>
            <option value="<?= h($key) ?>"<?= $source === $key ? ' selected' : '' ?>><?= h(mail_open_source_label($key)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Recherche
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="adresse, objet ou contexte">
      </label>
      <label style="flex-direction:row;align-items:center;gap:6px;padding-bottom:9px;">
        <input type="checkbox" name="ouvertes" value="1"<?= $onlyOpened ? ' checked' : '' ?>> Seulement les ouverts
      </label>
      <button type="submit" class="btn-small">Filtrer</button>
      <a href="<?= h($url(['export' => 'csv', 'page' => null])) ?>" class="btn-small">Télécharger le CSV</a>
    </form>

    <?php if ($bySource): ?>
      <div class="admin-block" style="margin-bottom:18px;">
        <h2 style="font-size:1.05rem;">Par source</h2>
        <div style="overflow-x:auto;"><table class="em-table">
          <thead><tr><th>Source</th><th class="num">Envoyés</th><th class="num">Ouverts</th><th class="num">Ouvertures</th></tr></thead>
          <tbody>
            <?php foreach ($bySource as $row): ?>
              <tr>
                <td><a href="<?= h($url(['source' => (string) $row['source'], 'page' => null])) ?>"><?= h(mail_open_source_label((string) $row['source'])) ?></a></td>
                <td class="num"><?= (int) $row['sent'] ?></td>
                <td class="num"><?= (int) $row['opened'] ?></td>
                <td class="num"><?= (int) $row['opens'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
    <?php endif; ?>

    <div class="admin-block">
      <h2 style="font-size:1.05rem;">Derniers envois <small class="hint" style="font-weight:400;">(<?= (int) $total ?>)</small></h2>
      <?php if (!$rows): ?>
        <p class="hint">Aucun e-mail suivi sur cette période. Les envois effectués depuis cette mise à jour apparaîtront ici.</p>
      <?php else: ?>
        <div style="overflow-x:auto;"><table class="em-table">
          <thead><tr><th>Envoyé</th><th>Destinataire</th><th>Objet</th><th>Source</th><th class="num">Ouvertures</th><th>Première ouverture</th><th>Logiciel</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= h($stamp((int) $row['sent_at'])) ?></td>
                <td><?= h($row['email']) ?></td>
                <td><?= h($row['subject']) ?><?php if ((string) $row['context'] !== ''): ?><div class="hint"><?= h($row['context']) ?></div><?php endif; ?></td>
                <td><span class="em-src"><?= h(mail_open_source_label((string) $row['source'])) ?></span></td>
                <td class="num"><?php if ((int) $row['open_count'] > 0): ?><span class="em-open"><?= (int) $row['open_count'] ?></span><?php else: ?><span class="em-none">—</span><?php endif; ?></td>
                <td><?= h($stamp((int) ($row['first_open_at'] ?? 0))) ?></td>
                <td title="<?= h($row['user_agent']) ?>"><?= h(mail_open_client_label((string) $row['user_agent'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php if ($pages > 1): ?>
          <div class="em-pager">
            <?php if ($page > 1): ?><a class="btn-small" href="<?= h($url(['page' => $page - 1])) ?>">← Précédent</a><?php endif; ?>
            <span class="hint">Page <?= $page ?> / <?= $pages ?></span>
            <?php if ($page < $pages): ?><a class="btn-small" href="<?= h($url(['page' => $page + 1])) ?>">Suivant →</a><?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
      <p class="em-note" style="margin-top:12px;">Les demandes des robots et des aperçus de liens ne sont pas comptées. Une même personne qui rouvre le message plusieurs fois augmente le nombre d’ouvertures sans changer le nombre de messages ouverts.</p>
    </div>
  </div>
</section>
</main>
</body>
</html>
