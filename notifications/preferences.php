<?php
// Page publique : l'utilisateur règle lui-même ses canaux et rubriques, ou se
// désinscrit, depuis son lien personnel (jeton secret de 128 bits dans l'URL).
// Elle ne permet PAS de changer les adresses de contact : un lien transféré par
// erreur ne doit pas permettre de rediriger les messages de quelqu'un d'autre.
require_once __DIR__ . '/../includes/notifications-layout.php';
if (session_status() === PHP_SESSION_NONE) session_start();
nt_boot();

// Le jeton est dans l'URL : ne pas le laisser fuiter vers les polices Google (Referer) ni le cache.
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$user = nt_user_by_token($token);

// Désinscription « en un clic » demandée par les messageries (RFC 8058) : POST sans formulaire.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!$user) {
        http_response_code(404);
        exit('Lien inconnu.');
    }
    nt_user_optout_marketing($user['id'], 'utilisateur');
    exit('Désinscription enregistrée.');
}

if (!$user) {
    http_response_code(404);
    nt_public_header('Lien invalide');
    echo '<p class="ntp-lead">Ce lien est invalide ou n\'est plus valable. Si vous souhaitez ne plus recevoir de messages, répondez simplement à l\'un d\'eux ou contactez-nous.</p>';
    nt_public_footer();
    exit;
}

$self = '/notifications/preferences.php?t=' . $user['token'];
$channels = nt_channels();
$topics = nt_topics();
$live = array_filter($user['channels'], fn($c) => $c['enabled']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    switch ((string) ($_POST['action'] ?? '')) {
        case 'save':
            // Seules les cases correspondant à une rubrique existante et à un canal actif de cet utilisateur sont appliquées.
            foreach ((array) ($_POST['shown'] ?? []) as $cell) {
                [$t, $c] = array_pad(explode('|', (string) $cell, 2), 2, '');
                if (!isset($topics[$t]) || !isset($live[$c])) continue;
                nt_set_pref($user['id'], $t, $c, isset($_POST['pref'][$t][$c]), 'utilisateur');
            }
            flash_set('Vos préférences sont enregistrées. Merci !');
            break;
        case 'optout_marketing':
            nt_user_optout_marketing($user['id'], 'utilisateur');
            flash_set('Vous êtes désinscrit(e) de toutes les actualités et offres.');
            break;
        case 'stop_all':
            nt_user_disable_all($user['id'], 'Désinscription par l\'utilisateur');
            flash_set('Vous ne recevrez plus aucun message de notre part.');
            break;
    }
    header('Location: ' . $self);
    exit;
}

nt_public_header('Vos notifications');
$first = nt_user_first_name($user);
?>
<p class="ntp-lead"><?= $first !== '' ? 'Bonjour ' . h($first) . ', c' : 'C' ?>hoisissez ici comment et sur quels sujets <?= h(nt_site_name()) ?> peut vous écrire.</p>

<?php if (!$live): ?>
  <p class="nt-callout">Aucun canal de contact n'est actif pour vous : vous ne recevez aucun message.</p>
<?php else: ?>
<form method="post">
  <?= csrf_input() ?>
  <input type="hidden" name="t" value="<?= h($user['token']) ?>">
  <input type="hidden" name="action" value="save">
  <?php foreach ($topics as $tk => $t): ?>
    <section class="ntp-topic">
      <h2><?= h($t['label']) ?></h2>
      <?php if ($t['description'] !== ''): ?><p><?= h($t['description']) ?></p><?php endif; ?>
      <div class="ntp-chans">
        <?php foreach ($live as $ch => $row): ?>
          <label class="nt-check">
            <input type="hidden" name="shown[]" value="<?= h($tk . '|' . $ch) ?>">
            <input type="checkbox" name="pref[<?= h($tk) ?>][<?= h($ch) ?>]" value="1"<?= nt_pref_effective($user, $t, $ch) ? ' checked' : '' ?>>
            <?= h($channels[$ch]['label'] ?? $ch) ?> <span class="nt-muted">(<?= h(nt_mask_address($ch, $row['address'])) ?>)</span>
          </label>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
  <div class="nt-actions"><button class="btn btn-primary" type="submit">Enregistrer mes choix</button></div>
</form>
<?php endif; ?>

<div class="ntp-danger">
  <h2 style="font-size:1.05rem;">Me désinscrire</h2>
  <div class="nt-actions" style="margin-top:8px;">
    <?= nt_form_open('optout_marketing', ['t' => $user['token']], 'Ne plus recevoir les actualités et offres ?', 'nt-inline') ?><button class="btn" type="submit">Plus d'actualités ni d'offres</button></form>
    <?= nt_form_open('stop_all', ['t' => $user['token']], 'Ne plus recevoir aucun message, y compris le suivi de vos commandes ?', 'nt-inline') ?><button class="btn nt-btn-danger" type="submit">Ne plus rien recevoir</button></form>
  </div>
  <p class="nt-hint" style="margin-top:10px;">« Ne plus rien recevoir » coupe aussi les messages de suivi (commande, planning…). Pour changer une adresse ou un numéro, contactez-nous.</p>
</div>
<script>
document.addEventListener('submit', function (e) {
  var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) e.preventDefault();
});
</script>
<?php nt_public_footer();
