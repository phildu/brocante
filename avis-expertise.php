<?php
// Avis de l'expert : page où le gérant répond (prix et mot) à un vendeur qui lui a envoyé une pièce à estimer depuis la caisse (vente express).
// Accès par le lien secret reçu par e-mail (jeton de 32 caractères) : pas de connexion, rien d'indexé. Voir includes/pos-express.php.
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/pos.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$a = pos_appraisal_by_token($token);
$product = $a ? get_product((string) $a['ref']) : null;
$open = $a && $product && pos_express_product((string) $a['ref']) !== null;
$notice = '';
$error = '';

if ($open && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cents = (int) round(((float) str_replace(',', '.', (string) ($_POST['price'] ?? ''))) * 100);
    $error = pos_appraisal_answer($token, $cents, (string) ($_POST['comment'] ?? '')) ?? '';
    if ($error === '') {
        $notice = 'Merci, votre estimation est transmise au vendeur.';
        $a = pos_appraisal_by_token($token);
    }
}
$siteName = trim((string) (get_content()['site_name'] ?? '')) ?: (string) tenant('name');
$eur = static fn (int $c): string => number_format($c / 100, 2, ',', ' ') . ' €';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Avis de l'expert — <?= h($siteName) ?></title>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .avis { max-width: 560px; margin: 0 auto; padding: 24px 16px 48px; display: grid; gap: 14px; }
  .avis img { width: 100%; max-height: 360px; object-fit: cover; border-radius: 10px; }
  .avis label { display: grid; gap: 6px; font-weight: 600; }
  .avis input, .avis textarea { padding: 12px; border: 1px solid var(--line, #ccc); font: inherit; width: 100%; box-sizing: border-box; }
  .avis .ok { color: #1e6b3a; font-weight: 600; }
  .avis .bad { color: #a4361b; font-weight: 600; }
</style>
</head>
<body>
<main class="avis">
<?php if (!$a || !$product): ?>
  <h1>Lien inconnu</h1>
  <p>Cette demande d'avis n'existe pas ou n'existe plus.</p>
<?php else: ?>
  <p class="eyebrow"><?= h($siteName) ?></p>
  <h1>Avis demandé par <?= h($a['seller_name'] ?: 'un vendeur') ?></h1>
  <?php if (!empty($product['photo'])): ?><img src="/<?= h(ltrim((string) $product['photo'], '/')) ?>" alt="Photo de la pièce"><?php endif; ?>
  <h2><?= h($product['name']) ?></h2>
  <?php if (trim((string) $product['description']) !== '' && $product['description'] !== 'Description à compléter.'): ?><p><?= nl2br(h($product['description'])) ?></p><?php endif; ?>
  <?php if (trim((string) $a['ai_hint']) !== ''): ?><p><strong>Suggestion automatique</strong> (indicative) : <?= h($a['ai_hint']) ?></p><?php endif; ?>
  <?php if (trim((string) $a['note']) !== ''): ?><p><strong>Mot du vendeur</strong> : <?= nl2br(h($a['note'])) ?></p><?php endif; ?>
  <?php if ($notice): ?><p class="ok" role="status"><?= h($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="bad" role="alert"><?= h($error) ?></p><?php endif; ?>
  <?php if (!$open): ?>
    <p>Cette pièce n'est plus à vendre : la demande est close.<?php if ($a['status'] === 'answered'): ?> Votre estimation était de <?= h($eur((int) $a['answer_cents'])) ?>.<?php endif; ?></p>
  <?php else: ?>
    <?php if ($a['status'] === 'answered'): ?><p class="ok">Estimation envoyée : <?= h($eur((int) $a['answer_cents'])) ?>. Vous pouvez la corriger tant que la pièce n'est pas vendue.</p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="t" value="<?= h($token) ?>">
      <label>Prix conseillé (€)
        <input type="number" name="price" min="0" step="0.5" inputmode="decimal" required value="<?= $a['status'] === 'answered' ? h(number_format((int) $a['answer_cents'] / 100, 2, '.', '')) : '' ?>">
      </label>
      <label>Un mot pour le vendeur (facultatif)
        <textarea name="comment" rows="3" maxlength="500"><?= h((string) $a['answer_note']) ?></textarea>
      </label>
      <button class="btn btn-primary" type="submit"><?= $a['status'] === 'answered' ? 'Corriger mon estimation' : 'Envoyer mon estimation' ?></button>
    </form>
  <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
