<?php
// Sondage public d'une galerie commerciale : /galerie/sondage.php?g=<identifiant>
// L'organisateur active le sondage dans l'assistant (portail). Les réponses sont enregistrées dans
// data/galeries/<identifiant>.sondage.json ; aucune réponse n'est publiée ni transmise à un tiers.
require_once __DIR__ . '/../includes/gallery-assistant.php';

$e = static fn ($v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
$slug = strtolower((string) ($_GET['g'] ?? $_POST['g'] ?? ''));
$gallery = gallery_slug_valid($slug) ? gallery_load($slug) : null;

$sent = false;
$error = null;
$data = null;
$survey = null;
if ($gallery) {
    $data = gass_read($slug);
    $survey = $data['survey'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $error = gass_survey_response_add($slug, $_POST);
        $sent = $error === null;
    }
}

$questions = [];
$open = false;
if ($gallery && $survey) {
    $open = !empty($survey['enabled']) && !empty($survey['open']);
    $questions = $survey['questions'] ?: gass_survey_questions($data);
}

$theme = $gallery ? gallery_theme($gallery) : ['css' => '', 'fonts' => ''];
$accent = $gallery ? (string) $gallery['accent'] : '#b5502e';
$pageTitle = $survey && $gallery ? (string) $survey['title'] : 'Sondage';

if ($gallery) {
    header('X-Robots-Tag: noindex');
    http_response_code(200);
} else {
    http_response_code(404);
}

$old = static function (string $key, string $value) use ($e): string {
    $v = $_POST['q'][$key] ?? null;
    if (is_array($v)) return in_array($value, array_map('strval', $v), true) ? ' checked' : '';
    return (string) $v === $value ? ' checked' : '';
};
$oldText = static function (string $key): string {
    return htmlspecialchars((string) ($_POST['q'][$key] ?? ''), ENT_QUOTES, 'UTF-8');
};
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($pageTitle) ?><?= $gallery ? ' — ' . $e($gallery['name']) : '' ?></title>
<?php if ($theme['fonts'] !== ''): ?><link href="<?= $e($theme['fonts']) ?>" rel="stylesheet"><?php endif; ?>
<?= $theme['css'] ?>
<style>
  :root { --accent: <?= $e($accent) ?>; --accent-ink: #fff; --bg: #f7f3ec; --surface: #fff; --ink: #221f1a; --ink-soft: #5c574f; --line: #ddd6ca; --radius: 14px; }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--ink); font: 16px/1.55 var(--body, system-ui, -apple-system, "Segoe UI", sans-serif); }
  .wrap { max-width: 700px; margin: 0 auto; padding: 36px 18px 64px; }
  .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; font: 600 .82rem/1.3 "IBM Plex Mono", ui-monospace, monospace; letter-spacing: .08em; text-transform: uppercase; color: var(--ink-soft); }
  .brand span { width: 10px; height: 10px; border-radius: 50%; background: var(--accent); }
  .card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 28px; box-shadow: 0 12px 40px -32px rgba(0,0,0,.4); }
  h1 { margin: 0 0 8px; font: 700 clamp(1.5rem, 3vw, 2rem)/1.2 var(--display, system-ui, sans-serif); letter-spacing: -.01em; }
  .intro { margin: 0 0 22px; color: var(--ink-soft); }
  .q { padding: 18px 0; border-top: 1px solid var(--line); }
  .q:first-of-type { border-top: 0; padding-top: 0; }
  .q > strong { display: block; margin-bottom: 8px; font-size: .98rem; }
  .q .hint { display: block; margin: -4px 0 8px; font-size: .8rem; color: var(--ink-soft); }
  .opt { display: flex; gap: 8px; align-items: flex-start; padding: 7px 10px; border: 1px solid var(--line); border-radius: 9px; margin: 0 0 6px; cursor: pointer; background: var(--bg); font-size: .92rem; }
  .opt:has(input:checked) { border-color: var(--accent); box-shadow: inset 0 0 0 1px var(--accent); }
  .opt input { margin-top: 3px; accent-color: var(--accent); }
  .opt-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 6px; }
  textarea, input[type=email] { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 9px; background: var(--bg); color: inherit; font: inherit; }
  textarea { min-height: 96px; resize: vertical; }
  .consent { display: flex; gap: 8px; align-items: flex-start; font-size: .86rem; color: var(--ink-soft); margin-top: 10px; }
  .consent input { margin-top: 4px; accent-color: var(--accent); }
  .hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
  .btn { display: inline-flex; align-items: center; justify-content: center; padding: 12px 22px; border-radius: 10px; border: 1px solid var(--accent); background: var(--accent); color: var(--accent-ink); font: 600 .95rem/1 var(--display, system-ui, sans-serif); cursor: pointer; text-decoration: none; }
  .btn:hover { filter: brightness(.95); }
  .btn.ghost { background: transparent; color: var(--ink); border-color: var(--line); }
  .actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 22px; }
  .flash { border: 1px solid #e3b4b4; background: #fdeeee; color: #8f1d1d; padding: 12px 14px; border-radius: 10px; margin: 0 0 16px; font-size: .9rem; }
  .done { text-align: center; padding: 18px 4px; }
  .done .tick { display: inline-grid; place-items: center; width: 54px; height: 54px; border-radius: 50%; background: var(--accent); color: var(--accent-ink); font-size: 1.5rem; margin-bottom: 14px; }
  .note { margin: 22px 0 0; font-size: .78rem; color: var(--ink-soft); }
  .note a { color: inherit; }
  .closed { color: var(--ink-soft); }
  @media (max-width: 520px) { .card { padding: 20px; } }
</style>
</head>
<body>
<div class="wrap">
  <?php if ($gallery): ?>
    <div class="brand"><span></span><?= $e($gallery['name']) ?> · sondage</div>
  <?php endif; ?>

  <div class="card">
    <?php if (!$gallery): ?>
      <h1>Sondage introuvable</h1>
      <p class="closed">Ce lien ne correspond à aucune galerie. Vérifiez l’adresse reçue ou contactez l’organisateur.</p>

    <?php elseif ($sent): ?>
      <div class="done">
        <div class="tick" aria-hidden="true">✓</div>
        <h1>Merci pour votre réponse</h1>
        <p class="closed">Votre avis a bien été enregistré. Il servira à préparer la prochaine édition<?= $gallery['name'] !== '' ? ' de ' . $e($gallery['name']) : '' ?>.</p>
        <div class="actions" style="justify-content:center;">
          <a class="btn" href="/galerie/<?= $e($gallery['slug']) ?>/">Voir la galerie</a>
        </div>
      </div>

    <?php elseif (!$open): ?>
      <h1><?= $e($pageTitle) ?></h1>
      <p class="closed">Ce sondage n’est pas ouvert actuellement. Vous pouvez consulter la page de la galerie ou contacter l’organisateur.</p>
      <div class="actions"><a class="btn ghost" href="/galerie/<?= $e($gallery['slug']) ?>/">Voir la galerie</a></div>

    <?php else: ?>
      <h1><?= $e($survey['title']) ?></h1>
      <?php if (trim((string) $survey['intro']) !== ''): ?><p class="intro"><?= nl2br($e($survey['intro'])) ?></p><?php endif; ?>
      <?php if ($error !== null): ?><p class="flash" role="alert"><?= $e($error) ?></p><?php endif; ?>

      <form method="post" action="<?= $e('/galerie/sondage.php?g=' . rawurlencode($gallery['slug'])) ?>" autocomplete="off">
        <input type="hidden" name="g" value="<?= $e($gallery['slug']) ?>">
        <div class="hp" aria-hidden="true"><label>Ne pas remplir<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

        <?php foreach ($questions as $q): ?>
          <div class="q">
            <strong><?= $e($q['label']) ?></strong>
            <?php if ($q['type'] === 'textarea'): ?>
              <textarea name="q[<?= $e($q['key']) ?>]" maxlength="1000" placeholder="Votre réponse…"><?= $oldText((string) $q['key']) ?></textarea>
            <?php else: ?>
              <div class="<?= $q['type'] === 'checkbox' ? 'opt-grid' : '' ?>">
                <?php foreach ($q['options'] as $ok => $ol): ?>
                  <label class="opt">
                    <input type="<?= $q['type'] === 'checkbox' ? 'checkbox' : 'radio' ?>" name="q[<?= $e($q['key']) ?>]<?= $q['type'] === 'checkbox' ? '[]' : '' ?>" value="<?= $e($ok) ?>"<?= $old((string) $q['key'], (string) $ok) ?><?= $q['type'] === 'radio' && $q['key'] === 'presence' ? ' required' : '' ?>>
                    <span><?= $e($ol) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <div class="q">
          <strong>Votre e-mail (facultatif)</strong>
          <span class="hint">Pour être prévenu de l’ouverture des inscriptions. Laissez vide pour rester anonyme.</span>
          <input type="email" name="email" maxlength="160" value="<?= $e($_POST['email'] ?? '') ?>" placeholder="vous@exemple.fr">
          <label class="consent"><input type="checkbox" name="consent" value="1"<?= !empty($_POST['consent']) ? ' checked' : '' ?>> J’accepte d’être recontacté au sujet de cet événement. Mes coordonnées ne seront ni vendues ni transmises à des tiers.</label>
        </div>

        <div class="actions">
          <button class="btn" type="submit">Envoyer mes réponses</button>
          <span class="hint" style="font-size:.8rem;color:var(--ink-soft);">Deux minutes suffisent.</span>
        </div>
      </form>
      <p class="note">Sondage organisé par <?= $e($gallery['name']) ?>. Les réponses servent uniquement à préparer la prochaine édition. Pour toute question, <a href="/galerie/<?= $e($gallery['slug']) ?>/">consultez la page de la galerie</a>.</p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
