<?php
// Page publique de la bourse d'échanges (/bourse.php, servie par le socle quand ce plugin est actif) : un particulier propose une
// pièce à la vente, et le commerçant publie ses recherches d'achat. Chaque demande part dans Administration → Bourse d'échanges.
// Les recherches publiées sont visibles ici ; une réponse à une recherche est enregistrée comme une proposition rattachée.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/bourse.php';

$replyTo = (int) ($_GET['repondre'] ?? 0);
$replyItem = $replyTo > 0 ? bourse_get($replyTo) : null;
if ($replyItem && ($replyItem['kind'] !== 'recherche' || $replyItem['status'] !== 'published')) $replyItem = null;
if ($replyTo > 0 && !$replyItem) $replyTo = 0;

function bourse_back_url(int $parent = 0): string
{
    return '/bourse.php' . ($parent > 0 ? '?repondre=' . $parent : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $parentPost = (int) ($_POST['parent_id'] ?? 0);
    $back = bourse_back_url($parentPost);
    if (trim((string) ($_POST['website'] ?? '')) !== '') {   // champ piège : un robot ne reçoit pas de message différent
        header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'envoye=1');
        exit;
    }
    if (!bourse_csrf_valid()) {
        flash_set('Votre session a expiré : renvoyez le formulaire.', 'error');
        header('Location: ' . $back);
        exit;
    }
    if (!bourse_rate_ok()) {
        flash_set('Vous avez envoyé plusieurs demandes à l’instant : réessayez dans un moment.', 'error');
        header('Location: ' . $back);
        exit;
    }
    $_POST['kind'] = 'offre';
    if ($parentPost > 0) $_POST['parent_id'] = $parentPost;
    [$id, $error] = bourse_submit($_POST, $_FILES);
    if ($error) {
        flash_set($error, 'error');
        header('Location: ' . $back);
        exit;
    }
    bourse_rate_note();
    flash_set('Merci ! Votre proposition est transmise : nous vous répondons par e-mail.', 'ok');
    header('Location: /bourse.php?envoye=1#proposer');
    exit;
}

$searches = bourse_public_searches(40);
$categories = BOURSE_CATEGORIES;
$conditions = bourse_condition_options();
$css = plugin_asset_url($m, 'bourse.css');
?>
<?php if ($css !== ''): ?><link rel="stylesheet" href="<?= h($css) ?>"><?php endif; ?>

<section class="tight bourse-page">
  <div class="wrap">
    <p class="eyebrow">Achat &amp; vente entre particuliers</p>
    <h1 class="bourse-title">Bourse d’échanges</h1>
    <p class="lede bourse-intro">Vous avez une pièce à vendre — un meuble, un service de table, un tableau ? Proposez-la-nous : nous
      répondons à chaque demande. En contrepartie, retrouvez ici les pièces que nous recherchons en ce moment.</p>
  </div>
</section>

<section class="tight bourse-page">
  <div class="wrap bourse-layout">
    <div class="bourse-form-col" id="proposer">
      <div class="bourse-card">
        <?php if ($replyItem): ?>
          <p class="eyebrow">Réponse à une recherche</p>
          <h2 class="bourse-card-title">Proposer une pièce pour « <?= h($replyItem['title']) ?> »</h2>
          <p class="bourse-muted">Décrivez la pièce que vous pouvez fournir : nous revenons vers vous avec une proposition.</p>
        <?php else: ?>
          <p class="eyebrow">Particulier → commerçant</p>
          <h2 class="bourse-card-title">Proposer une pièce à la vente</h2>
          <p class="bourse-muted">Quelques photos et une description suffisent. Aucun engagement : nous étudions chaque proposition.</p>
        <?php endif; ?>

        <form method="post" action="/bourse.php" enctype="multipart/form-data" class="bourse-form">
          <?= bourse_csrf_field() ?>
          <input type="hidden" name="kind" value="offre">
          <input type="hidden" name="parent_id" value="<?= (int) $replyTo ?>">
          <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" class="bourse-hp">

          <div class="field">
            <label for="bourse-title">La pièce</label>
            <input id="bourse-title" type="text" name="title" maxlength="140" required
              value="<?= h($replyItem ? 'Réponse à « ' . $replyItem['title'] . ' »' : '') ?>"
              placeholder="Ex. Commode en chêne, faïence de Gien…">
            <p class="bourse-hint">Un titre court et précis.</p>
          </div>

          <div class="bourse-row">
            <div class="field">
              <label for="bourse-category">Catégorie</label>
              <select id="bourse-category" name="category">
                <option value="">Choisir…</option>
                <?php foreach ($categories as $value => $label): ?>
                  <option value="<?= h($value) ?>"<?= ($replyItem['category'] ?? '') === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="bourse-condition">État</label>
              <select id="bourse-condition" name="condition">
                <option value="">Non précisé</option>
                <?php foreach ($conditions as $group => $options): ?>
                  <optgroup label="<?= h($group) ?>">
                    <?php foreach ($options as $value => $option): ?>
                      <option value="<?= h($value) ?>"><?= h($option[0] ?? $value) ?></option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="field">
            <label for="bourse-description">Description</label>
            <textarea id="bourse-description" name="description" rows="5" maxlength="1600" required
              placeholder="Dimensions, matière, époque, défauts éventuels, où se trouve la pièce…"></textarea>
          </div>

          <div class="bourse-row">
            <div class="field">
              <label for="bourse-price">Prix souhaité <span class="bourse-optional">(facultatif)</span></label>
              <input id="bourse-price" type="text" name="price" inputmode="decimal" placeholder="en euros">
            </div>
            <div class="field">
              <label for="bourse-location">Lieu <span class="bourse-optional">(facultatif)</span></label>
              <input id="bourse-location" type="text" name="location" maxlength="80" placeholder="Ville / département">
            </div>
          </div>

          <div class="field">
            <label for="bourse-photo">Photo <span class="bourse-optional">(facultatif, une seule image)</span></label>
            <input id="bourse-photo" type="file" name="photo" accept="image/*">
          </div>

          <div class="bourse-row">
            <div class="field">
              <label for="bourse-name">Votre nom</label>
              <input id="bourse-name" type="text" name="contact_name" maxlength="80" required autocomplete="name">
            </div>
            <div class="field">
              <label for="bourse-email">Votre e-mail</label>
              <input id="bourse-email" type="email" name="email" maxlength="120" required autocomplete="email">
            </div>
          </div>

          <div class="field">
            <label for="bourse-phone">Téléphone <span class="bourse-optional">(facultatif)</span></label>
            <input id="bourse-phone" type="tel" name="phone" maxlength="40" autocomplete="tel">
          </div>

          <label class="bourse-consent">
            <input type="checkbox" name="consent" value="1" required>
            <span>J’autorise la transmission de mes coordonnées à la boutique pour répondre à ma proposition.</span>
          </label>

          <button type="submit" class="btn btn-primary bourse-submit">Envoyer ma proposition</button>
        </form>
      </div>
    </div>

    <aside class="bourse-search-col" id="recherches">
      <div class="bourse-card bourse-card-soft">
        <p class="eyebrow">Commerçant → particuliers</p>
        <h2 class="bourse-card-title">Ce que nous recherchons</h2>
        <?php if ($searches): ?>
          <p class="bourse-muted">Une de ces pièces vous concerne ? Répondez à la recherche : votre proposition nous arrive directement.</p>
          <ul class="bourse-searches">
            <?php foreach ($searches as $s): ?>
              <li class="bourse-search">
                <div class="bourse-search-head">
                  <h3><?= h($s['title']) ?></h3>
                  <?php if ($s['category'] !== ''): ?><span class="bourse-tag"><?= h($s['category']) ?></span><?php endif; ?>
                </div>
                <p><?= nl2br(h($s['description'])) ?></p>
                <p class="bourse-search-meta">
                  <?php if (!empty($s['price_cents'])): ?><span>Budget jusqu’à <strong><?= h(bourse_money((int) $s['price_cents'])) ?></strong></span><?php endif; ?>
                  <?php if ($s['location'] !== ''): ?><span><?= h($s['location']) ?></span><?php endif; ?>
                  <span>Depuis le <?= h(bourse_date((string) $s['created_at'])) ?></span>
                </p>
                <a class="btn bourse-reply" href="/bourse.php?repondre=<?= (int) $s['id'] ?>#proposer">J’ai cette pièce →</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="bourse-muted">Aucune recherche publiée pour le moment. Vous pouvez tout de même nous proposer une pièce : elle sera
            étudiée et, si elle nous intéresse, rejoindra la boutique.</p>
          <a class="btn" href="#proposer">Proposer une pièce</a>
        <?php endif; ?>
      </div>
    </aside>
  </div>
</section>
