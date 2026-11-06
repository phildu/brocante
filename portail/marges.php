<?php
// Marges moyennes par type de galerie (réservé à l'exploitant). Le taux sert aux pages de vente : il permet d'afficher, sur chaque
// formule, le chiffre d'affaires approximatif à réaliser pour la couvrir (prix de la formule ÷ taux de marge). Le bouton IA propose
// des taux de départ, toujours relus et modifiables à la main.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/marges.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save') {
        $rows = [];
        foreach ((array) ($_POST['t'] ?? []) as $key => $row) {
            if (!is_array($row)) continue;
            $rows[$key] = ['pct' => $row['pct'] ?? 55, 'note' => $row['note'] ?? '', 'source' => $row['source'] ?? 'manuel'];
        }
        $saved = marges_save($rows);
        if ($saved) unset($_SESSION['portail_marges_draft']);
        portail_flash($saved ? 'Marges enregistrées : les pages de vente affichent le nouveau calcul.' : "Impossible d'enregistrer les marges (droits d'écriture sur data/saas/).", $saved ? 'ok' : 'error');
    } elseif ($action === 'discard') {
        unset($_SESSION['portail_marges_draft']);
        portail_flash('Propositions de l’IA écartées : la table affiche de nouveau les taux enregistrés.');
    } elseif ($action === 'ai') {
        $types = gt_types();
        $prompt = "Tu estimes, pour la France, le taux de marge moyen de commerces d'occasion et de marchés selon leur type. "
            . "Le taux est la part du prix de vente qui reste après l'achat des pièces et les frais directs (100 = objets déjà possédés). "
            . "Réponds UNIQUEMENT en JSON, sans markdown : {\"marges\":{\"cle\":{\"pct\":entier de 5 à 100,\"note\":\"justification en une phrase, 140 caractères maximum\"}}}. "
            . "Une entrée par clé, sans en inventer :\n";
        foreach ($types as $key => $type) $prompt .= "- $key : {$type['name']} — {$type['tagline']}\n";
        try {
            $data = portail_gemini_json($prompt);
            $rows = marges_load();
            $found = 0;
            foreach ((array) ($data['marges'] ?? []) as $key => $row) {
                if (!isset($rows[$key]) || !is_array($row)) continue;
                $rows[$key] = [
                    'pct' => max(5, min(100, (int) ($row['pct'] ?? $rows[$key]['pct']))),
                    'note' => mb_substr(trim((string) ($row['note'] ?? '')), 0, 200),
                    'source' => 'ia',
                    'updated' => time(),
                ];
                $found++;
            }
            // Proposition seulement : la table « publiée » n'est écrite qu'au clic sur « Enregistrer les marges ».
            if ($found) {
                $_SESSION['portail_marges_draft'] = $rows;
            } else {
                portail_flash("L'IA n'a pas renvoyé de taux exploitable : réessayez.", 'error');
            }
        } catch (Throwable $ex) {
            portail_flash($ex->getMessage() === 'no_key' ? "Aucune clé Gemini n'est configurée (.secrets/gemini.key)." : $ex->getMessage(), 'error');
        }
    }
    header('Location: /portail/marges.php');
    exit;
}

$flash = portail_flash();
$aiDraft = isset($_SESSION['portail_marges_draft']);
$rows = $aiDraft ? $_SESSION['portail_marges_draft'] : marges_load();
$types = gt_types();
$origin = saas_origin();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Marge &amp; rentabilité — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .gwrap { display: grid; gap: 16px; max-width: 1040px; margin: 0 auto; padding: 0 16px 48px; }
  .gcard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 12px; }
  .gcard h2 { margin: 0; font-size: 1.05rem; }
  table.t { width: 100%; border-collapse: collapse; font-size: .9rem; } table.t th, table.t td { text-align: left; padding: 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
  table.t th { font-size: .75rem; opacity: .75; font-weight: 600; }
  .f2 { display: grid; gap: 3px; font-size: .78rem; font-weight: 600; }
  .f2 input { padding: 7px 9px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; width: 100%; }
  .pct { width: 84px; }
  .be { color: var(--ink-soft); font-size: .82rem; line-height: 1.5; }
  .be b { color: var(--ink); }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: .72rem; font-weight: 700; background: var(--bg); border: 1px solid var(--line); }
  .badge.ia { border-color: var(--accent); color: var(--accent); }
  .formula { background: var(--bg); border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; }
  .formula code { font-family: "IBM Plex Mono", monospace; }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Marge & rentabilité',
    'eyebrow' => 'Revenus',
    'desc' => "Taux de marge moyen par type de commerce. Les pages de vente s'en servent pour afficher le chiffre d'affaires à réaliser afin de couvrir une formule.",
    'actions' => '<a class="btn" href="' . e($origin) . '/galerie-marchande/" target="_blank" rel="noopener">Voir une page de vente</a>',
]); ?>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>
  <div class="gwrap">
    <?php if ($aiDraft): ?>
      <div class="flash" role="status" style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap">
        <span>Propositions de l'IA, pas encore publiées : relisez-les puis cliquez sur « Enregistrer les marges ».</span>
        <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="discard"><button class="btn ghost" type="submit">Écarter</button></form>
      </div>
    <?php endif; ?>
    <section class="gcard">
      <h2>Comment le calcul est présenté</h2>
      <div class="formula">
        <p style="margin:0 0 6px"><strong>Chiffre d'affaires de rentabilité</strong> = prix de la formule ÷ taux de marge.</p>
        <p class="meta" style="margin:0">Exemple : une offre à <b>39 € / mois</b> avec une marge de <b>60 %</b> demande <b>65 € de ventes par mois</b> pour être couverte (39 ÷ 0,60). La marge est la part du prix de vente qui reste après l'achat des pièces et les frais directs : une pièce achetée 20 € et revendue 50 € laisse 60 % de marge.</p>
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="ai">
        <button class="btn ghost" type="submit">Préremplir les taux par IA</button>
        <span class="meta">L'IA propose un taux et une justification par type ; rien n'est publié avant votre enregistrement.</span>
      </form>
    </section>

    <form class="gcard" method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save">
      <h2>Table des marges moyennes</h2>
      <table class="t">
        <thead><tr><th>Type de commerce</th><th>Taux de marge</th><th>Justification</th><th>Rentabilité des formules</th></tr></thead>
        <tbody>
        <?php foreach ($types as $key => $type): $row = $rows[$key]; ?>
          <tr>
            <td><strong><?= e($type['emoji'] . ' ' . $type['name']) ?></strong><br><small class="meta"><?= e($key) ?></small><br>
              <span class="badge<?= $row['source'] === 'ia' ? ' ia' : '' ?>"><?= $row['source'] === 'ia' ? 'IA' : 'manuel' ?></span>
              <?php if ($row['updated']): ?><br><small class="meta"><?= e(date('d/m/Y', $row['updated'])) ?></small><?php endif; ?></td>
            <td><label class="f2">% des ventes<input class="pct" type="number" name="t[<?= e($key) ?>][pct]" min="1" max="100" value="<?= (int) $row['pct'] ?>" required></label>
              <input type="hidden" name="t[<?= e($key) ?>][source]" value="<?= e($row['source']) ?>"></td>
            <td><label class="f2">Note affichée en interne<input type="text" name="t[<?= e($key) ?>][note]" maxlength="200" value="<?= e($row['note']) ?>"></label></td>
            <td class="be">
              <?php foreach ($type['offers'] as $offer): $monthly = marge_monthly_cents($offer); $be = marge_break_even_cents($monthly, (int) $row['pct']); if (!$be) continue; ?>
                <?= e($offer['name']) ?> (<?= e(saas_money((int) $monthly)) ?> / mois) → <b><?= e(saas_money($be)) ?></b> de ventes<br>
              <?php endforeach; ?>
              <?php if ((int) $row['pct'] <= 0): ?><span class="meta">Taux invalide.</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p style="margin:0"><button class="btn btn-primary" type="submit">Enregistrer les marges</button></p>
    </form>
  </div>
</div>
</body>
</html>
