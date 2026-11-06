<?php
require_once __DIR__ . '/../../includes/notifications-layout.php';
nt_admin_init();
$self = '/admin/notifications/rubriques.php';

switch (nt_post_action()) {
    case 'save_topic': {
        $key = trim((string) ($_POST['key'] ?? ''));
        $isNew = !empty($_POST['is_new']);
        // La table des actions accepte « créer » OU « modifier » : on précise ici selon le cas.
        require_permission($isNew ? 'portail.notifications.creer' : 'portail.notifications.modifier');
        if ($isNew && isset(nt_topics()[$key])) nt_redirect($self . '#nouvelle-rubrique', 'Cette clé existe déjà.', 'error');
        if (!$isNew && !isset(nt_topics()[$key])) nt_redirect($self, 'Rubrique introuvable.', 'error');
        $err = nt_topic_save($key, (string) ($_POST['label'] ?? ''), (string) ($_POST['description'] ?? ''), !empty($_POST['is_marketing']));
        if ($err) nt_redirect($self . ($isNew ? '#nouvelle-rubrique' : ''), $err, 'error');
        nt_redirect($self, $isNew ? 'Rubrique créée.' : 'Rubrique enregistrée.');
    }

    case 'delete_topic': {
        $ok = nt_topic_delete((string) ($_POST['key'] ?? ''));
        nt_redirect($self, $ok ? 'Rubrique supprimée (les abonnements correspondants aussi).' : 'Cette rubrique ne peut pas être supprimée.', $ok ? 'ok' : 'error');
    }
}

$topics = nt_topics();
$subs = [];
foreach (db()->query('SELECT topic, COUNT(DISTINCT user_id) n FROM nt_preferences WHERE enabled = 1 GROUP BY topic') as $r) $subs[$r['topic']] = (int) $r['n'];
$optouts = [];
foreach (db()->query('SELECT topic, COUNT(DISTINCT user_id) n FROM nt_preferences WHERE enabled = 0 GROUP BY topic') as $r) $optouts[$r['topic']] = (int) $r['n'];

nt_admin_header('Rubriques', 'rubriques');
?>
<p class="nt-hint">Une rubrique classe vos messages (suivi de commande, rappel d'événement, newsletter…). Chaque utilisateur choisit, par canal, les rubriques qu'il souhaite recevoir.</p>
<p class="nt-callout"><strong>Marketing ou non ?</strong> Une rubrique <em>marketing</em> (promotions, nouveautés, newsletter) n'est envoyée qu'aux personnes qui s'y sont abonnées explicitement, et chaque message contient un lien de désinscription. Une rubrique <em>de suivi</em> (commande, planning bénévole…) est reçue par défaut sur les canaux actifs, mais chacun peut s'en retirer. Ce réglage est fixé à la création et ne peut plus être modifié ensuite : passer une rubrique en « suivi » reviendrait à abonner tout le monde sans consentement.</p>

<div class="nt-card">
  <div class="nt-table-wrap"><table class="nt-table">
    <thead><tr><th>Rubrique</th><th>Type</th><th class="nt-right">Abonnés (cases cochées)</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($topics as $k => $t): ?>
      <tr>
        <td><strong><?= h($t['label']) ?></strong><br><span class="nt-muted" style="font-size:.82rem;"><?= h($t['description']) ?></span><br><code class="nt-code"><?= h($k) ?></code></td>
        <td><?= $t['is_marketing'] ? nt_badge('Marketing · opt-in', 'warn') : nt_badge('Suivi') ?></td>
        <td class="nt-right nt-num"><?= $t['is_marketing'] ? (int) ($subs[$k] ?? 0) . ' abonné(s)' : (int) ($optouts[$k] ?? 0) . ' retrait(s)' ?></td>
        <td class="nt-right">
          <details class="nt-inline"><summary class="btn-small" style="display:inline-block;cursor:pointer;">Modifier</summary>
            <?= nt_form_open('save_topic', ['key' => $k]) ?>
              <div class="nt-form-grid" style="margin-top:10px;min-width:260px;text-align:left;">
                <div class="field"><label>Libellé</label><input class="nt-input" name="label" value="<?= h($t['label']) ?>" maxlength="80" required></div>
                <div class="field"><label>Description</label><input class="nt-input" name="description" value="<?= h($t['description']) ?>" maxlength="240"></div>
              </div>
              <div class="nt-actions"><button class="btn-small btn-primary" type="submit">Enregistrer</button></div>
            </form>
            <?php if (!$t['is_system']): ?>
              <?= nt_form_open('delete_topic', ['key' => $k], 'Supprimer la rubrique « ' . $t['label'] . ' » et tous les abonnements associés ?', 'nt-inline') ?><button class="btn-small nt-btn-danger" type="submit" style="margin-top:8px;">Supprimer</button></form>
            <?php endif; ?>
          </details>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="nt-card" id="nouvelle-rubrique">
  <h2 class="nt-h2">Nouvelle rubrique</h2>
  <?= nt_form_open('save_topic', ['is_new' => 1]) ?>
    <div class="nt-form-grid" style="margin-top:10px;">
      <div class="field"><label for="tk">Clé (identifiant court)</label><input class="nt-input" id="tk" name="key" pattern="[a-z][a-z0-9_]{1,31}" placeholder="atelier" required></div>
      <div class="field"><label for="tl">Libellé affiché</label><input class="nt-input" id="tl" name="label" maxlength="80" placeholder="Ateliers créatifs" required></div>
      <div class="field span-2"><label for="td">Description</label><input class="nt-input" id="td" name="description" maxlength="240"></div>
      <label class="nt-check"><input type="checkbox" name="is_marketing" value="1"> Rubrique marketing (abonnement explicite requis)</label>
    </div>
    <div class="nt-actions"><button class="btn btn-primary" type="submit">Créer la rubrique</button></div>
  </form>
</div>
<?php nt_admin_footer();
