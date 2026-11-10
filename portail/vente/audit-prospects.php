<?php
// Audit des sites et réseaux sociaux du carnet, sans export ni import : les
// fiches sont lues dans la base du carnet, ouvertes par lots (une requête courte
// par petit groupe), puis le rapport est enregistré sous data/rapports/ et
// consulté ici même. Aucun compte de réseau social n'est utilisé.
require __DIR__ . '/_bootstrap.php';
fv_require_auth();
require_once FV_ROOT . '/includes/prospect-audit.php';
require_once FV_ROOT . '/includes/prospect-audit-jobs.php';

$scope = fv_is_scoped() ? fv_scope()['type'] . '-' . fv_scope()['slug'] : 'portail';
$scopeLabel = fv_is_scoped() ? 'carnet de vente · ' . fv_brand() : 'carnet de vente du portail';
$pageUrl = fv_url('audit-prospects.php');

// Rapport demandé : fichier du dossier de l'audit, servi uniquement après connexion.
$reportId = trim((string) ($_GET['rapport'] ?? ''));
if ($reportId !== '') {
    $format = (string) ($_GET['format'] ?? 'html');
    $path = paj_file($scope, $reportId, $format);
    if ($path === null) {
        http_response_code(404);
        exit('Rapport introuvable.');
    }
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: private, no-store');
    if ($format === 'html') {
        $html = (string) file_get_contents($path);
        $back = '<a href="' . e($pageUrl) . '" style="position:fixed;right:16px;bottom:16px;z-index:2147483647;font:600 14px/1 system-ui,sans-serif;background:#14110f;color:#fff;padding:12px 16px;border-radius:999px;text-decoration:none;box-shadow:0 8px 24px rgba(0,0,0,.28)">← Retour à l’audit</a>';
        header('Content-Type: text/html; charset=utf-8');
        echo str_replace('</body>', $back . '</body>', $html);
    } else {
        $types = ['csv' => 'text/csv; charset=UTF-8', 'md' => 'text/markdown; charset=UTF-8', 'json' => 'application/json; charset=UTF-8'];
        header('Content-Type: ' . $types[$format]);
        header('Content-Disposition: attachment; filename="audit-prospects-' . date('Y-m-d') . '.' . $format . '"');
        readfile($path);
    }
    exit;
}

// Démarrage, lots et finalisation : réponses JSON pour la progression dans la page.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $json = static function (array $data, int $code = 200): never {
        http_response_code($code);
        echo json_encode($data + ['ok' => $code < 400], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    };
    if (!hash_equals(fv_csrf(), (string) ($_POST['csrf'] ?? ''))) {
        $json(['error' => 'Session expirée : rechargez la page et recommencez.'], 400);
    }
    try {
        switch ((string) ($_POST['action'] ?? '')) {
            case 'demarrer':
                $includeClients = !empty($_POST['clients']);
                $includeLost = !empty($_POST['perdu']);
                $prospects = [];
                foreach (fv_prospects_search([], 2000) as $row) {
                    $status = (string) ($row['status'] ?? '');
                    if ($status === 'boutique_creee' && !$includeClients) continue;
                    if ($status === 'perdu' && !$includeLost) continue;
                    $prospect = pa_prospect_from_row($row);
                    if (pa_display_name($prospect) === 'Prospect sans nom') continue;
                    $prospects[] = $prospect;
                }
                $limit = max(0, min(200, (int) ($_POST['limit'] ?? 0)));
                if ($limit > 0) $prospects = array_slice($prospects, 0, $limit);
                if (!$prospects) $json(['error' => 'Aucun prospect à auditer : ajoutez des fiches ou élargissez les filtres.'], 400);
                $created = paj_create($scope, $prospects, ['social' => !empty($_POST['social'])]);
                $json(['job' => $created['id'], 'total' => $created['total']]);

            case 'lot':
                $take = paj_take($scope, (string) ($_POST['job'] ?? ''), 2);
                if (!$take) $json(['error' => 'Audit introuvable ou déjà terminé.'], 404);
                session_write_close();      // les appels réseau peuvent durer : on ne bloque pas les autres onglets
                @set_time_limit(90);
                $results = [];
                foreach ($take['prospects'] as $prospect) {
                    $results[] = pa_audit_prospect($prospect, [
                        'timeout' => (int) ($take['options']['timeout'] ?? 10),
                        'social_timeout' => (int) ($take['options']['social_timeout'] ?? 6),
                        'no_social' => empty($take['options']['social']),
                    ]);
                }
                $progress = paj_append($scope, (string) ($_POST['job'] ?? ''), $results);
                $rows = [];
                foreach ($results as $result) {
                    $rows[] = [
                        'name' => $result['name'],
                        'web' => $result['scores']['web'],
                        'social' => $result['scores']['social'],
                        'global' => $result['scores']['global'],
                        'priority' => $result['scores']['priority'],
                        'tier' => $result['scores']['tier_label'],
                    ];
                }
                $json(['done' => $progress['done'], 'total' => $progress['total'], 'rows' => $rows]);

            case 'terminer':
                $finished = paj_finish($scope, (string) ($_POST['job'] ?? ''), $scopeLabel);
                $json(['url' => $pageUrl . '?rapport=' . rawurlencode($finished['job']['id']), 'count' => (int) ($finished['job']['count'] ?? 0)]);
        }
        $json(['error' => 'Action inconnue.'], 400);
    } catch (Throwable $e) {
        $json(['error' => $e->getMessage()], 500);
    }
}

$counts = ['total' => 0, 'actifs' => 0, 'clients' => 0, 'perdus' => 0];
foreach (fv_prospects_search([], 2000) as $row) {
    $counts['total']++;
    if (($row['status'] ?? '') === 'boutique_creee') $counts['clients']++;
    elseif (($row['status'] ?? '') === 'perdu') $counts['perdus']++;
    else $counts['actifs']++;
}
$jobs = paj_list($scope);

fv_layout_start('Audit des sites et réseaux', [
    'eyebrow' => 'Qualité web et présence sociale · ordre d’appel',
    'desc' => 'Ouvre une fois les sites et pages publiques des prospects, note-les sur 100 et classe les appels : le commerce le plus faible et le plus joignable passe en premier.',
    'wide' => true,
    'actions' => '<a class="fv-btn fv-btn-sm" href="prospects.php">' . fv_icon('users', 14) . ' Prospects</a>',
]);
?>

<div class="fv-toolbar">
  <span class="fv-sub"><?= (int) $counts['actifs'] ?> prospect(s) actif(s) · <?= (int) $counts['total'] ?> au total</span>
  <span class="fv-sub" style="margin-left:auto;">Pages publiques uniquement · aucun compte de réseau social utilisé</span>
</div>

<section class="fv-card">
  <div class="fv-card-head">
    <div>
      <h2>Lancer un audit</h2>
      <p>Comptez quelques secondes par prospect : chaque site et chaque page sociale est ouvert une seule fois.</p>
    </div>
  </div>
  <div class="fv-card-body">
    <form id="paj-form" autocomplete="off">
      <div class="fv-form-grid">
        <label class="fv-check full"><input type="checkbox" name="social" checked> Ouvrir les pages Facebook, Instagram, TikTok, YouTube et LinkedIn</label>
        <label class="fv-check"><input type="checkbox" name="clients"> Inclure les clients / inscrits (<?= (int) $counts['clients'] ?>)</label>
        <label class="fv-check"><input type="checkbox" name="perdu"> Inclure les prospects sans suite (<?= (int) $counts['perdus'] ?>)</label>
        <label class="fv-field">Nombre de prospects
          <select name="limit">
            <option value="0">tous</option>
            <option value="25">25</option>
            <option value="50">50</option>
            <option value="100">100</option>
          </select>
        </label>
      </div>
      <div class="fv-actions">
        <button class="fv-btn fv-btn-primary" id="paj-start" type="submit"><?= fv_icon('search', 15) ?> Lancer l’audit</button>
      </div>
    </form>

    <p class="fv-sub" id="paj-error" hidden></p>

    <div id="paj-progress" hidden>
      <div class="fv-progress"><span id="paj-bar" style="width:0%"></span></div>
      <p class="fv-sub" id="paj-log" aria-live="polite">0 prospect audité</p>
      <div class="fv-table-wrap">
        <table class="fv-table">
          <thead><tr><th>Prospect</th><th class="num">Web</th><th class="num">Réseaux</th><th class="num">Global</th><th class="num">Priorité</th><th>Palier</th></tr></thead>
          <tbody id="paj-rows"></tbody>
        </table>
      </div>
    </div>

    <p class="fv-sub">Les rapports contiennent les coordonnées des prospects : ils restent sur le serveur, dans <code>data/rapports/</code>, et ne sont consultables qu’après connexion. Les dix derniers audits du carnet sont conservés.</p>
  </div>
</section>

<section class="fv-card">
  <div class="fv-card-head">
    <div>
      <h2>Rapports enregistrés</h2>
      <p>Classeur d’appels interactif (HTML), tableau CSV, fiche Markdown et données JSON.</p>
    </div>
  </div>
  <div class="fv-card-body">
    <?php if (!$jobs): ?>
      <p class="fv-sub">Aucun audit pour l’instant : lancez le premier ci-dessus.</p>
    <?php else: ?>
      <div class="fv-table-wrap">
        <table class="fv-table">
          <thead><tr><th>Date</th><th class="num">Prospects</th><th>État</th><th class="right">Rapport</th></tr></thead>
          <tbody>
          <?php foreach ($jobs as $job): ?>
            <?php $ts = strtotime((string) $job['created_at']); ?>
            <tr>
              <td class="main"><?= e($ts ? date('d/m/Y H:i', $ts) : (string) $job['created_at']) ?></td>
              <td class="num"><?= (int) ($job['finished'] ? $job['count'] : $job['done']) ?> / <?= (int) $job['total'] ?></td>
              <td><?= $job['finished'] ? 'Terminé' : 'Interrompu' ?></td>
              <td class="right">
                <?php if ($job['finished']): ?>
                  <a class="fv-btn fv-btn-sm" href="<?= e($pageUrl . '?rapport=' . rawurlencode($job['id'])) ?>"><?= fv_icon('eye', 14) ?> Ouvrir</a>
                  <a class="fv-btn fv-btn-sm" href="<?= e($pageUrl . '?rapport=' . rawurlencode($job['id']) . '&format=csv') ?>">CSV</a>
                  <a class="fv-btn fv-btn-sm" href="<?= e($pageUrl . '?rapport=' . rawurlencode($job['id']) . '&format=md') ?>">Markdown</a>
                <?php else: ?>
                  <span class="fv-sub">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<script>
(function () {
  const form = document.getElementById('paj-form');
  const start = document.getElementById('paj-start');
  const box = document.getElementById('paj-progress');
  const bar = document.getElementById('paj-bar');
  const log = document.getElementById('paj-log');
  const error = document.getElementById('paj-error');
  const tbody = document.getElementById('paj-rows');
  const csrf = <?= json_encode(fv_csrf(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const endpoint = location.pathname + location.search;
  let job = '';
  let done = 0;
  let total = 0;

  const post = async (data) => {
    const body = new URLSearchParams({ csrf });
    Object.keys(data).forEach((key) => body.set(key, String(data[key])));
    const response = await fetch(endpoint, { method: 'POST', body, headers: { 'X-Requested-With': 'fetch' } });
    let payload = null;
    try { payload = await response.json(); } catch (e) { /* réponse illisible */ }
    if (!response.ok || !payload || payload.ok === false) {
      throw new Error((payload && payload.error) || 'Erreur réseau (' + response.status + ').');
    }
    return payload;
  };

  const cell = (text, className) => {
    const td = document.createElement('td');
    if (className) td.className = className;
    td.textContent = text === null || text === undefined ? '—' : String(text);
    return td;
  };

  const addRow = (row) => {
    const tr = document.createElement('tr');
    tr.appendChild(cell(row.name, 'main'));
    tr.appendChild(cell(row.web, 'num'));
    tr.appendChild(cell(row.social, 'num'));
    tr.appendChild(cell(row.global, 'num'));
    tr.appendChild(cell(row.priority, 'num'));
    tr.appendChild(cell(row.tier));
    tbody.appendChild(tr);
  };

  const progress = (value, count) => {
    done = value;
    total = count;
    bar.style.width = count ? Math.round(value / count * 100) + '%' : '0%';
    log.textContent = value + ' / ' + count + ' prospect(s) audité(s)';
  };

  async function run() {
    error.hidden = true;
    start.disabled = true;
    box.hidden = false;
    try {
      if (!job) {
        tbody.innerHTML = '';
        progress(0, 0);
        const started = await post({
          action: 'demarrer',
          social: form.elements.social.checked ? 1 : 0,
          clients: form.elements.clients.checked ? 1 : 0,
          perdu: form.elements.perdu.checked ? 1 : 0,
          limit: form.elements.limit.value,
        });
        job = started.job;
        progress(0, started.total);
      }
      let finished = false;
      while (!finished) {
        const lot = await post({ action: 'lot', job });
        (lot.rows || []).forEach(addRow);
        progress(lot.done, lot.total);
        finished = lot.done >= lot.total;
      }
      const end = await post({ action: 'terminer', job });
      log.textContent = 'Audit terminé · ouverture du rapport…';
      window.location.href = end.url;
    } catch (e) {
      error.hidden = false;
      error.textContent = e.message + (job ? ' Cliquez sur « Reprendre » pour continuer.' : '');
      start.disabled = false;
      start.textContent = job ? 'Reprendre' : 'Relancer';
    }
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    run();
  });
})();
</script>

<?php fv_layout_end(); ?>
