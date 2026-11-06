<?php
require_once __DIR__ . '/../../includes/notifications-layout.php';
nt_admin_init();
$self = '/admin/notifications/utilisateurs.php';

switch (nt_post_action()) {
    case 'create_user': {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') nt_redirect($self . '#nouvel-utilisateur', 'Le nom est obligatoire.', 'error');
        $id = nt_user_create($name, (string) ($_POST['notes'] ?? ''));
        $errors = [];
        foreach (nt_channels() as $ch => $def) {
            if (!empty($def['self_service'])) continue; // abonnement navigateur : la personne seule l'active
            $addr = trim((string) ($_POST['new_' . $ch] ?? ''));
            if ($addr === '') continue;
            if ($err = nt_user_set_channel($id, $ch, $addr, true, count(nt_user_get($id)['channels']))) $errors[] = $err;
        }
        $msg = 'Utilisateur « ' . $name . ' » créé.' . ($errors ? ' À corriger : ' . implode(' ', $errors) : '');
        nt_redirect($self . '?open=' . $id . '#u-' . $id, $msg, $errors ? 'error' : 'ok');
    }

    case 'save_user': {
        $id = (int) ($_POST['id'] ?? 0);
        $user = nt_user_get($id);
        if (!$user) nt_redirect($self, 'Utilisateur introuvable.', 'error');
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') nt_redirect($self . '?open=' . $id . '#u-' . $id, 'Le nom est obligatoire.', 'error');
        nt_user_update($id, $name, (string) ($_POST['notes'] ?? ''), !empty($_POST['is_active']));

        $errors = [];
        foreach (nt_channels() as $ch => $def) {
            $in = $_POST['ch'][$ch] ?? [];
            if (!empty($def['self_service'])) {
                // Abonnement navigateur : l'administration peut seulement le suspendre/réordonner, jamais le créer ni changer l'adresse.
                if (isset($user['channels'][$ch])) {
                    $order = max(1, min(99, (int) ($in['order'] ?? 1))) - 1;
                    nt_user_set_channel($id, $ch, $user['channels'][$ch]['address'], !empty($in['enabled']), $order);
                }
                continue;
            }
            $addr = trim((string) ($in['address'] ?? ''));
            if ($addr === '') {
                if (isset($user['channels'][$ch])) nt_user_remove_channel($id, $ch);
                continue;
            }
            $order = max(1, min(99, (int) ($in['order'] ?? 1))) - 1;
            if ($err = nt_user_set_channel($id, $ch, $addr, !empty($in['enabled']), $order)) $errors[] = $err;
        }

        // Préférences : seules les cases réellement affichées dans le formulaire sont appliquées
        // (un canal ajouté dans ce même envoi n'avait pas de case : il garde les valeurs par défaut).
        $fresh = nt_user_get($id);
        $topics = nt_topics();
        foreach ((array) ($_POST['shown'] ?? []) as $cell) {
            [$t, $c] = array_pad(explode('|', (string) $cell, 2), 2, '');
            if (!isset($topics[$t]) || !isset($fresh['channels'][$c])) continue;
            nt_set_pref($id, $t, $c, isset($_POST['pref'][$t][$c]), 'admin');
        }
        $msg = 'Utilisateur enregistré.' . ($errors ? ' À corriger : ' . implode(' ', $errors) : '');
        nt_redirect($self . '?open=' . $id . '#u-' . $id, $msg, $errors ? 'error' : 'ok');
    }

    case 'delete_user': {
        nt_user_delete((int) ($_POST['id'] ?? 0));
        nt_redirect($self, 'Utilisateur supprimé avec ses coordonnées, ses préférences et son historique d\'envois.');
    }

    case 'test_channel': {
        $id = (int) ($_POST['id'] ?? 0);
        $ch = (string) ($_POST['channel'] ?? '');
        $r = nt_send_test($id, $ch);
        $back = $self . '?open=' . $id . '#u-' . $id;
        if (!empty($r['error'])) nt_redirect($back, $r['error'], 'error');
        if ($r['recipients'] === 0) nt_redirect($back, 'Test impossible : ' . ($r['skipped'][0][3] ?? 'canal indisponible') . '.', 'error');
        $p = $r['processed'];
        if ($p['sent'] > 0) nt_redirect($back, 'Message de test envoyé par ' . nt_channel_label($ch) . '.');
        $why = db()->prepare('SELECT error FROM nt_deliveries WHERE message_id = ? ORDER BY id DESC LIMIT 1');
        $why->execute([$r['message_id']]);
        nt_redirect($back, 'Le test a échoué : ' . ($why->fetchColumn() ?: 'erreur inconnue'), 'error');
    }

    case 'import_volunteers': {
        $r = nt_import_volunteers();
        nt_redirect($self, $r['created'] . ' bénévole(s) importé(s), ' . $r['existing'] . ' déjà présent(s). Aucun abonnement marketing n\'est créé par l\'import.');
    }

    case 'import_customers': {
        $r = nt_import_customers();
        nt_redirect($self, $r['created'] . ' client(s) importé(s), ' . $r['existing'] . ' déjà présent(s). Ils ne reçoivent que les messages de suivi : aucun consentement à la newsletter n\'est supposé.');
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$open = (int) ($_GET['open'] ?? 0);
$users = nt_users_search($q, 200);
$total = (int) db()->query('SELECT COUNT(*) FROM nt_users')->fetchColumn();
$topics = nt_topics();
$channels = nt_channels();
$status = nt_channel_status();

nt_admin_header('Utilisateurs', 'utilisateurs');
?>
<p class="nt-hint">Un utilisateur est une personne que vous pouvez notifier : client, bénévole, adhérent. Pour chacun, renseignez les canaux où le joindre, leur ordre de préférence, et les rubriques qu'il souhaite recevoir. Les rubriques « marketing » (newsletter…) ne sont envoyées qu'à ceux qui s'y sont abonnés.</p>

<div class="nt-card">
  <h2 class="nt-h2">Ajouter des utilisateurs</h2>
  <div class="nt-actions" style="margin-top:6px;">
    <?= nt_form_open('import_volunteers', [], '', 'nt-inline') ?><button class="btn" type="submit">Importer les bénévoles de la buvette</button></form>
    <?= nt_form_open('import_customers', [], '', 'nt-inline') ?><button class="btn" type="submit">Importer les clients (commandes payées)</button></form>
    <a class="btn btn-ghost" href="#nouvel-utilisateur">Créer à la main</a>
  </div>
  <p class="nt-hint" style="margin:10px 0 0;">Les imports ne créent aucun abonnement marketing : avoir passé commande ou être bénévole ne vaut pas consentement à recevoir la newsletter.</p>
</div>

<form method="get" class="nt-actions" style="margin:0 0 16px;">
  <input class="nt-input" style="max-width:340px;" type="search" name="q" value="<?= h($q) ?>" placeholder="Rechercher un nom ou une adresse…" aria-label="Rechercher">
  <button class="btn-small" type="submit">Rechercher</button>
  <?php if ($q !== ''): ?><a href="<?= h($self) ?>">Effacer</a><?php endif; ?>
  <span class="nt-muted"><?= count($users) ?> affiché(s) sur <?= $total ?></span>
</form>

<?php if (!$users): ?>
  <p class="nt-callout"><?= $q !== '' ? 'Aucun résultat.' : 'Aucun utilisateur pour l\'instant — importez-en ou créez-en un ci-dessous.' ?></p>
<?php endif; ?>

<?php foreach ($users as $u): $uid = $u['id']; ?>
  <details class="nt-fold<?= $u['is_active'] ? '' : ' is-inactive' ?>" id="u-<?= $uid ?>"<?= $open === $uid ? ' open' : '' ?>>
    <summary>
      <span class="sum-main">
        <span class="sum-name"><?= h($u['name']) ?></span>
        <span class="sum-meta">
          <?php if (!$u['channels']): ?><?= nt_badge('Aucun canal', 'warn') ?><?php endif; ?>
          <?php foreach ($u['channels'] as $ch => $row): ?>
            <?= nt_badge($channels[$ch]['label'] ?? $ch, $row['enabled'] ? ($status[$ch]['ready'] ? 'ok' : '') : 'warn') ?>
          <?php endforeach; ?>
          <?php if (!$u['is_active']): ?><?= nt_badge('Désactivé') ?><?php endif; ?>
          <?php if ($u['source'] !== 'manuel'): ?><span class="nt-muted">(<?= h($u['source']) ?>)</span><?php endif; ?>
        </span>
      </span>
    </summary>
    <div class="nt-fold-body">
      <?= nt_form_open('save_user', ['id' => $uid]) ?>
        <div class="nt-form-grid" style="margin-top:12px;">
          <div class="field"><label for="n<?= $uid ?>">Nom</label><input class="nt-input" id="n<?= $uid ?>" name="name" value="<?= h($u['name']) ?>" maxlength="120" required></div>
          <div class="field span-2"><label for="no<?= $uid ?>">Notes (internes)</label><input class="nt-input" id="no<?= $uid ?>" name="notes" value="<?= h($u['notes']) ?>" maxlength="500"></div>
          <label class="nt-check"><input type="checkbox" name="is_active" value="1"<?= $u['is_active'] ? ' checked' : '' ?>> Actif (reçoit des notifications)</label>
        </div>

        <h3>Canaux</h3>
        <div class="nt-table-wrap"><table class="nt-table">
          <thead><tr><th>Canal</th><th>Adresse (laisser vide pour retirer)</th><th class="c">Actif</th><th class="c" title="1 = canal préféré ; sert à l'ordre et au repli">Ordre</th></tr></thead>
          <tbody>
          <?php foreach ($channels as $ch => $def): $row = $u['channels'][$ch] ?? null; ?>
            <?php if (!empty($def['self_service']) && !$row): ?>
            <tr><td><strong><?= h($def['label']) ?></strong></td><td colspan="3" class="nt-muted">Non activé : la personne l'active elle-même depuis sa page de préférences.</td></tr>
            <?php continue; endif; ?>
            <tr>
              <td><strong><?= h($def['label']) ?></strong><?php if (!$status[$ch]['ready'] && !($ch === 'discord' && $row && str_starts_with($row['address'], 'https://'))): ?><br><span class="nt-muted" style="font-size:.78rem;">non configuré</span><?php endif; ?></td>
              <td>
                <?php if (!empty($def['self_service'])): ?>
                  <span class="nt-muted">Abonnement du navigateur de la personne (non modifiable ici)</span>
                <?php else: ?>
                <input class="nt-input" name="ch[<?= h($ch) ?>][address]" value="<?= h($row['address'] ?? '') ?>" placeholder="<?= h($def['placeholder']) ?>" aria-label="<?= h($def['address_label']) ?>" maxlength="300" autocomplete="off">
                <?php endif; ?>
                <?php if ($row && $row['disabled_reason'] !== ''): ?><span class="nt-muted" style="font-size:.78rem;">Désactivé : <?= h($row['disabled_reason']) ?></span><?php endif; ?>
              </td>
              <td class="c"><input type="checkbox" name="ch[<?= h($ch) ?>][enabled]" value="1"<?= !$row || $row['enabled'] ? ' checked' : '' ?> aria-label="Actif"></td>
              <td class="c"><input class="nt-input narrow" type="number" min="1" max="99" name="ch[<?= h($ch) ?>][order]" value="<?= (int) ($row['priority'] ?? count($u['channels'])) + 1 ?>" aria-label="Ordre"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>

        <?php if ($u['channels']): ?>
        <h3>Rubriques souhaitées</h3>
        <div class="nt-table-wrap"><table class="nt-table nt-matrix">
          <thead><tr><th>Rubrique</th><?php foreach ($u['channels'] as $ch => $row): ?><th class="c"><?= h($channels[$ch]['label'] ?? $ch) ?></th><?php endforeach; ?></tr></thead>
          <tbody>
          <?php foreach ($topics as $tk => $t): ?>
            <tr>
              <td><?= h($t['label']) ?> <?= $t['is_marketing'] ? nt_badge('marketing · opt-in', 'warn') : '' ?></td>
              <?php foreach ($u['channels'] as $ch => $row): ?>
                <td class="c">
                  <input type="hidden" name="shown[]" value="<?= h($tk . '|' . $ch) ?>">
                  <input type="checkbox" name="pref[<?= h($tk) ?>][<?= h($ch) ?>]" value="1"<?= nt_pref_effective($u, $t, $ch) ? ' checked' : '' ?> aria-label="<?= h($t['label'] . ' par ' . ($channels[$ch]['label'] ?? $ch)) ?>">
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <p class="nt-hint" style="margin-top:8px;">Cocher une rubrique marketing enregistre un consentement (date et origine « admin ») : ne le faites que si la personne vous l'a demandé. Elle peut elle-même tout régler depuis le lien ci-dessous.</p>
        <?php endif; ?>

        <div class="nt-actions"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
      </form>

      <h3>Lien personnel de gestion des préférences</h3>
      <input class="nt-linkbox" readonly value="<?= h(nt_preferences_url($u)) ?>" onfocus="this.select()" aria-label="Lien de gestion des préférences">
      <p class="nt-hint" style="margin-top:6px;">À donner à la personne si elle veut choisir ses canaux et rubriques ou se désinscrire. Il est ajouté automatiquement aux messages marketing.</p>

      <div class="nt-actions">
        <?php $live = array_keys(array_filter($u['channels'], fn($r) => $r['enabled'])); ?>
        <?php if ($live): ?>
          <?= nt_form_open('test_channel', ['id' => $uid], '', 'nt-inline') ?>
            <select class="nt-input" name="channel" aria-label="Canal du test"><?php foreach ($live as $ch): ?><option value="<?= h($ch) ?>"><?= h($channels[$ch]['label'] ?? $ch) ?></option><?php endforeach; ?></select>
            <button class="btn-small" type="submit">Envoyer un message de test</button>
          </form>
        <?php endif; ?>
        <?= nt_form_open('delete_user', ['id' => $uid], 'Supprimer « ' . $u['name'] . ' » avec ses coordonnées, préférences et historique d\'envois ? C\'est définitif.', 'nt-inline') ?>
          <button class="btn-small nt-btn-danger" type="submit">Supprimer (effacement complet)</button>
        </form>
      </div>
    </div>
  </details>
<?php endforeach; ?>

<div class="nt-card" id="nouvel-utilisateur" style="margin-top:26px;">
  <h2 class="nt-h2">Nouvel utilisateur</h2>
  <?= nt_form_open('create_user') ?>
    <div class="nt-form-grid" style="margin-top:10px;">
      <div class="field"><label for="nn">Nom</label><input class="nt-input" id="nn" name="name" maxlength="120" required placeholder="Prénom Nom"></div>
      <div class="field span-2"><label for="nnotes">Notes (internes)</label><input class="nt-input" id="nnotes" name="notes" maxlength="500"></div>
      <?php foreach ($channels as $ch => $def): if (!empty($def['self_service'])) continue; ?>
        <div class="field"><label for="new_<?= h($ch) ?>"><?= h($def['address_label']) ?></label><input class="nt-input" id="new_<?= h($ch) ?>" name="new_<?= h($ch) ?>" placeholder="<?= h($def['placeholder']) ?>" maxlength="300" autocomplete="off"></div>
      <?php endforeach; ?>
    </div>
    <p class="nt-hint" style="margin-top:10px;">Les coordonnées sont facultatives (modifiables ensuite). Les rubriques de suivi sont actives par défaut ; la newsletter demande un abonnement explicite.</p>
    <div class="nt-actions"><button class="btn btn-primary" type="submit">Créer l'utilisateur</button></div>
  </form>
</div>
<?php nt_admin_footer();
