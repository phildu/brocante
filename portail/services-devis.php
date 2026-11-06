<?php
// Prestations sur devis : fourchettes de prix affichées aux clients à l'étape 1 de l'inscription, puis formulaire de demande à la fin
// du parcours (inscription/identite.php). Tout est réglable ici ; le bouton IA propose des fourchettes de départ, à relire.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save') {
        $rows = [];
        foreach ((array) ($_POST['s'] ?? []) as $key => $row) {
            if (!is_array($row)) continue;
            foreach (['min_cents', 'max_cents'] as $field) {
                if (isset($row[$field . '_eur'])) {
                    $row[$field] = (int) round((float) str_replace([' ', ','], ['', '.'], (string) $row[$field . '_eur']) * 100);
                }
            }
            $rows[$key] = $row;
        }
        $saved = saas_service_quote_save($rows);
        if ($saved) unset($_SESSION['portail_devis_draft']);
        portail_flash($saved ? 'Prestations enregistrées : les fourchettes sont à jour sur le parcours d’inscription.' : "Impossible d'enregistrer les prestations (droits d'écriture sur data/saas/).", $saved ? 'ok' : 'error');
    } elseif ($action === 'discard') {
        unset($_SESSION['portail_devis_draft']);
        portail_flash('Propositions de l’IA écartées : les fourchettes enregistrées sont de nouveau affichées.');
    } elseif ($action === 'ai') {
        $services = saas_service_quote_catalog();
        $prompt = "Tu proposes des fourchettes de prix réalistes en France (euros HT) pour des prestations vendues à des commerçants et brocanteurs, "
            . "autour de la boutique en ligne. Chaque fourchette est indicative et sera confirmée par un devis. "
            . "Réponds UNIQUEMENT en JSON, sans markdown : {\"prestations\":{\"cle\":{\"min\":entier en euros,\"max\":entier en euros,\"note\":\"une phrase de 160 caractères maximum expliquant le prix\"}}}. "
            . "Garde les prix cohérents entre eux et n'invente pas de clé :\n";
        foreach ($services as $key => $service) $prompt .= "- $key : {$service['name']} — {$service['description']}\n";
        try {
            $data = portail_gemini_json($prompt);
            // Les lignes que l'IA n'a pas proposées gardent leurs réglages actuels (même nom, description et activation).
            $rows = $services;
            $found = 0;
            foreach ((array) ($data['prestations'] ?? []) as $key => $row) {
                if (!isset($services[$key]) || !is_array($row)) continue;
                $rows[$key] = [
                    'name' => $services[$key]['name'],
                    'description' => mb_substr(trim((string) ($row['note'] ?? '')), 0, 300) ?: $services[$key]['description'],
                    'min_cents' => (int) ($row['min'] ?? 0) * 100,
                    'max_cents' => (int) ($row['max'] ?? 0) * 100,
                    'unit' => $services[$key]['unit'],
                    'active' => !empty($services[$key]['active']),
                ];
                $found++;
            }
            // Proposition seulement : les fourchettes publiées ne changent qu'au clic sur « Enregistrer les fourchettes ».
            if ($found) {
                $_SESSION['portail_devis_draft'] = $rows;
            } else {
                portail_flash("L'IA n'a pas renvoyé de fourchettes exploitables : réessayez.", 'error');
            }
        } catch (Throwable $ex) {
            portail_flash($ex->getMessage() === 'no_key' ? "Aucune clé Gemini n'est configurée (.secrets/gemini.key)." : $ex->getMessage(), 'error');
        }
    }
    header('Location: /portail/services-devis.php');
    exit;
}

$flash = portail_flash();
$aiDraft = isset($_SESSION['portail_devis_draft']);
$services = $aiDraft ? $_SESSION['portail_devis_draft'] : saas_service_quote_catalog();
$requests = [];
foreach (saas_requests() as $request) {
    if (empty($request['service_quotes'])) continue;
    $requests[] = $request;
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Prestations sur devis — Portail des commerces</title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .gwrap { display: grid; gap: 16px; max-width: 1040px; margin: 0 auto; padding: 0 16px 48px; }
  .gcard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 12px; }
  .gcard h2 { margin: 0; font-size: 1.05rem; }
  .srow { display: grid; grid-template-columns: minmax(220px, 1.4fr) 110px 110px 150px 110px; gap: 10px; align-items: start; padding: 12px 0; border-bottom: 1px solid var(--line); }
  .srow:last-child { border-bottom: 0; }
  .f2 { display: grid; gap: 3px; font-size: .78rem; font-weight: 600; }
  .f2 input, .f2 select, .f2 textarea { padding: 7px 9px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; width: 100%; }
  .range { color: var(--ink-soft); font-size: .82rem; padding-top: 22px; }
  .range b { color: var(--ink); }
  table.t { width: 100%; border-collapse: collapse; font-size: .9rem; } table.t th, table.t td { text-align: left; padding: 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
  table.t th { font-size: .75rem; opacity: .75; font-weight: 600; }
  @media (max-width: 760px) { .srow { grid-template-columns: 1fr 1fr; } .srow .wide { grid-column: 1 / -1; } .range { padding-top: 0; } }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Prestations sur devis',
    'eyebrow' => 'Revenus',
    'desc' => "Fourchettes affichées sur le parcours d'inscription. Le client ne paie rien à cette étape : sa demande de devis arrive à la fin, une fois sa boutique créée.",
]); ?>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>
  <div class="gwrap">
    <section class="gcard">
      <h2>Proposer des fourchettes par IA</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="ai">
        <button class="btn ghost" type="submit">Préremplir les fourchettes par IA</button>
        <span class="meta">L'IA remplit le tableau ci-dessous sans rien publier : relisez, corrigez, puis enregistrez. Une fourchette n'est jamais un prix ferme.</span>
      </form>
    </section>

    <?php if ($aiDraft): ?>
      <div class="flash" role="status" style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap">
        <span>Propositions de l'IA, pas encore publiées : relisez-les puis cliquez sur « Enregistrer les fourchettes ».</span>
        <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="discard"><button class="btn ghost" type="submit">Écarter</button></form>
      </div>
    <?php endif; ?>

    <form class="gcard" method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save">
      <h2>Fourchettes de prix</h2>
      <?php foreach ($services as $key => $service): ?>
        <div class="srow">
          <label class="f2">Prestation et description
            <input type="text" name="s[<?= e($key) ?>][name]" value="<?= e($service['name']) ?>" maxlength="60">
            <textarea name="s[<?= e($key) ?>][description]" rows="2" maxlength="300"><?= e($service['description']) ?></textarea>
          </label>
          <label class="f2">Prix mini (€)<input type="text" inputmode="decimal" name="s[<?= e($key) ?>][min_cents_eur]" value="<?= e(number_format($service['min_cents'] / 100, 2, ',', '')) ?>"></label>
          <label class="f2">Prix maxi (€)<input type="text" inputmode="decimal" name="s[<?= e($key) ?>][max_cents_eur]" value="<?= e(number_format($service['max_cents'] / 100, 2, ',', '')) ?>"></label>
          <label class="f2">Unité
            <select name="s[<?= e($key) ?>][unit]"><?php foreach (SAAS_SERVICE_QUOTE_UNITS as $uk => $ul): ?><option value="<?= e($uk) ?>"<?= $service['unit'] === $uk ? ' selected' : '' ?>><?= e($ul) ?></option><?php endforeach; ?></select>
          </label>
          <label class="f2"><span><input type="checkbox" name="s[<?= e($key) ?>][active]" value="1"<?= $service['active'] ? ' checked' : '' ?>> Proposée</span>
            <span class="range">Affiché : <b><?= e(saas_service_quote_price($service)) ?></b></span></label>
        </div>
      <?php endforeach; ?>
      <p style="margin:0"><button class="btn btn-primary" type="submit">Enregistrer les fourchettes</button></p>
    </form>

    <section class="gcard">
      <h2>Demandes reçues en fin de parcours</h2>
      <?php if (!$requests): ?><p class="meta">Aucune demande de devis pour le moment.</p>
      <?php else: ?>
        <table class="t"><thead><tr><th>Boutique</th><th>Client</th><th>Prestations</th><th>Message</th><th>Reçue</th></tr></thead><tbody>
        <?php foreach (array_slice($requests, 0, 40) as $request): ?>
          <tr>
            <td><strong><?= e($request['shop_name'] ?? $request['id']) ?></strong><br><small class="meta"><?= e($request['status'] ?? '') ?></small></td>
            <td><?= e(trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? ''))) ?><br><small class="meta"><?= e($request['email'] ?? '') ?></small></td>
            <td><?= e(implode(', ', saas_service_quote_names((array) ($request['service_quotes'] ?? [])))) ?></td>
            <td><?= e($request['service_quotes_message'] ?? '') ?></td>
            <td><?= !empty($request['service_quotes_at']) ? e(date('d/m/Y H:i', (int) $request['service_quotes_at'])) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </section>
  </div>
</div>
</body>
</html>
