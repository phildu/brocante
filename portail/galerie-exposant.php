<?php
// Fiche d'un exposant : données légales (particulier, association, entreprise), copie de la pièce d'identité et vérification,
// correction par l'organisateur, décision sur l'emplacement et journal des actions.
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/gallery-booking.php';
require_once PORTAIL_ROOT . '/includes/gallery-waitlist.php';
require_once PORTAIL_ROOT . '/includes/client-types.php';

$actor = (string) (($_SESSION['gallery_admin']['email'] ?? '') ?: 'exploitant');
$slotId = (string) ($_GET['slot'] ?? $_POST['slot'] ?? '');
$self = '/portail/galerie-exposant.php' . ($limited ? '?' : '?g=' . urlencode($gaSlug) . '&') . 'slot=' . urlencode($slotId);
$list = '/portail/galerie-exposants.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$row = $slotId !== '' ? gb_row($gaGallery, $slotId) : null;
if (!$row) {
    portail_flash('Demande introuvable.', 'error');
    header('Location: ' . $list);
    exit;
}
gb_cni_purge($gaGallery['slug']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $err = null;
    if ($action === 'save_client') {
        $err = gb_client_update($gaGallery['slug'], $slotId, $_POST, $actor);
        portail_flash($err ?? 'Fiche exposant enregistrée.', $err ? 'error' : 'ok');
    } elseif (in_array($action, ['accept', 'refuse', 'release'], true)) {
        $err = gb_decide($gaGallery, $slotId, $action, $actor);
        if (!$err && in_array($action, ['refuse', 'release'], true)) gwl_on_release($gaGallery, $slotId, $actor);
        portail_flash($err ?? 'Décision enregistrée.', $err ? 'error' : 'ok');
        if (!$err) { header('Location: ' . $list); exit; }
    } elseif (in_array($action, ['cni_verified', 'cni_rejected', 'cni_pending'], true)) {
        $status = str_replace('cni_', '', $action);
        $err = gb_cni_set_status($gaGallery['slug'], $slotId, $status, $actor, (string) ($_POST['reason'] ?? ''));
        portail_flash($err ?? ($status === 'verified' ? 'Pièce vérifiée.' : ($status === 'rejected' ? 'Pièce refusée.' : 'Vérification remise en attente.')), $err ? 'error' : 'ok');
    } elseif ($action === 'cni_delete') {
        $err = gb_cni_delete($gaGallery['slug'], $slotId, $actor);
        portail_flash($err ?? 'Copie de la pièce supprimée ; les références du registre restent enregistrées.', $err ? 'error' : 'ok');
    } else {
        portail_flash('Action inconnue.', 'error');
    }
    header('Location: ' . $self);
    exit;
}

$row = gb_row($gaGallery, $slotId) ?? $row;
$slotItem = gb_slot($gaGallery, $slotId);
$slotLabel = $slotItem ? gb_slot_name($gaGallery, $slotItem) : $slotId;
$c = $row['client'];
$kind = $row['kind'];
$expired = $row['status'] === 'pending' && !gb_active($row, gb_hold_seconds($gaGallery));
$priceLabel = $row['price_cents'] === null ? 'tarif à définir' : 'tarif ' . gb_eur($row['price_cents']);
$cni = $row['cni'] ?? null;
$blocks = $c ? ct_display($c, $kind) : [];
$cniLabel = gb_cni_status_label($cni);
$cniRecto = $cni ? gb_cni_path($gaGallery['slug'], $row, 'recto') : null;
$cniVerso = $cni ? gb_cni_path($gaGallery['slug'], $row, 'verso') : null;
$audit = gb_audit_read($gaGallery['slug'], 40);
$audit = array_values(array_filter($audit, static fn ($a) => (string) ($a['slot'] ?? '') === $slotId));
$hid = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">' . ($limited ? '' : '<input type="hidden" name="g" value="' . e($gaSlug) . '">') . '<input type="hidden" name="slot" value="' . e($slotId) . '">';

ga_head('Fiche exposant');
?>
    <section class="gcard">
      <div class="row" style="justify-content:space-between;">
        <h2 style="margin:0;"><?= e($row['name']) ?> — <?= e(ct_label($kind)) ?></h2>
        <div class="row" style="gap:6px;">
          <a class="btn" href="/portail/galerie-messages.php<?= $limited ? '' : '?g=' . e(urlencode($gaSlug)) . '&' ?>new=slot:<?= e(urlencode($slotId)) ?>">Écrire un message</a>
          <a class="btn" href="<?= e($list) ?>">← Exposants</a>
        </div>
      </div>
      <p class="meta"><?= e($slotLabel) ?> · <?= e($row['email']) ?><?= $row['phone'] !== '' ? ' · ' . e($row['phone']) : '' ?> · <?= e($priceLabel) ?></p>
      <p>
        <?php if ($row['status'] === 'taken'): ?><span class="pill done">Emplacement attribué</span>
        <?php elseif ($expired): ?><span class="pill">Demande expirée</span>
        <?php else: ?><span class="pill sent">Demande en attente</span><?php endif; ?>
        <?php if (gb_register_ok($row)): ?><span class="pill done">Registre complet</span><?php else: ?><span class="pill">Registre : <?= count($row['register_missing']) ?> mention(s) manquante(s)</span><?php endif; ?>
        <span class="pill"><?= e($cniLabel) ?></span>
      </p>
      <?php if ($row['message'] !== ''): ?><p class="meta">Message de l'exposant : « <?= e($row['message']) ?> »</p><?php endif; ?>
      <?php if (!gb_register_ok($row)): ?><p class="flash" data-kind="error" style="margin:0;">À compléter pour le registre : <?= e(implode(', ', $row['register_missing'])) ?>.</p><?php endif; ?>
      <div class="row">
        <form method="post" style="display:inline;"><?= $hid ?>
          <?php if ($row['status'] === 'pending'): ?>
            <button class="btn btn-primary" name="action" value="accept">Accepter la demande</button>
            <button class="btn" name="action" value="refuse" onclick="return confirm('Refuser cette demande ? L\'exposant en est informé.');">Refuser</button>
          <?php else: ?>
            <button class="btn" name="action" value="release" onclick="return confirm('Libérer cet emplacement ? L\'exposant en est informé.');">Libérer l'emplacement</button>
          <?php endif; ?>
        </form>
      </div>
    </section>

    <?php if ($c): ?>
    <section class="gcard">
      <h2>Données du registre des vendeurs</h2>
      <?php foreach ($blocks as $title => $fields): ?>
        <h3><?= e($title) ?></h3>
        <table class="t"><tbody>
        <?php foreach ($fields as $label => $value): ?>
          <tr><th style="width:34%;"><?= e($label) ?></th><td><?= nl2br(e($value)) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endforeach; ?>
      <p class="meta">Les références de la pièce d'identité sont recopiées au registre, conservé cinq ans après sa clôture. La copie de la pièce, si elle existe, ne fait pas partie du registre.</p>
      <?php if (!empty($row['consent_at'])): ?><p class="meta">Accord de l'exposant pour l'enregistrement de ses coordonnées : donné le <?= e(date('d/m/Y H:i', (int) $row['consent_at'])) ?>.</p><?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="gcard">
      <h2>Copie de la pièce d'identité</h2>
      <p><strong><?= e($cniLabel) ?></strong>
        <?php if ($cni): ?>
          <?php if (!empty($cni['uploaded'])): ?> · déposée le <?= e(date('d/m/Y H:i', (int) $cni['uploaded'])) ?><?php endif; ?>
          <?php if (!empty($cni['purge_after'])): ?> · suppression automatique le <?= e(date('d/m/Y', (int) $cni['purge_after'])) ?><?php endif; ?>
          <?php if (!empty($cni['verified'])): ?> · vérifiée le <?= e(date('d/m/Y H:i', (int) $cni['verified'])) ?> par <?= e($cni['by']) ?><?php endif; ?>
          <?php if (!empty($cni['rejected'])): ?> · refusée le <?= e(date('d/m/Y H:i', (int) $cni['rejected'])) ?> par <?= e($cni['by']) ?><?= !empty($cni['reason']) ? ' (' . e($cni['reason']) . ')' : '' ?><?php endif; ?>
        <?php endif; ?>
      </p>
      <?php if ($cniRecto || $cniVerso): ?>
        <div class="row" style="align-items:flex-start;">
          <?php foreach (['recto' => $cniRecto, 'verso' => $cniVerso] as $side => $path): if (!$path) continue; ?>
            <figure style="margin:0;max-width:300px;">
              <a href="/portail/galerie-cni.php?<?= e(http_build_query(['g' => $gaSlug, 'slot' => $slotId, 'side' => $side])) ?>" target="_blank" rel="noopener"><img src="/portail/galerie-cni.php?<?= e(http_build_query(['g' => $gaSlug, 'slot' => $slotId, 'side' => $side, 'thumb' => 1])) ?>" alt="Pièce d'identité — <?= e(GB_CNI_SIDES[$side]) ?>" style="max-width:100%;border:1px solid var(--line);border-radius:8px;"></a>
              <figcaption class="meta" style="font-size:.78rem;"><?= e(GB_CNI_SIDES[$side]) ?> · <a href="/portail/galerie-cni.php?<?= e(http_build_query(['g' => $gaSlug, 'slot' => $slotId, 'side' => $side])) ?>" target="_blank" rel="noopener">ouvrir</a></figcaption>
            </figure>
          <?php endforeach; ?>
        </div>
        <form method="post" class="row" style="align-items:flex-end;"><?= $hid ?>
          <label class="f" style="min-width:220px;">Motif en cas de refus<input type="text" name="reason" maxlength="200" placeholder="Photo illisible, document expiré…"></label>
          <button class="btn btn-primary" name="action" value="cni_verified" onclick="return confirm('Confirmer la vérification de la pièce ?');">Pièce vérifiée</button>
          <button class="btn" name="action" value="cni_rejected">Refuser la copie</button>
          <button class="btn" name="action" value="cni_delete" onclick="return confirm('Supprimer immédiatement la copie ? Les références du registre restent enregistrées.');">Supprimer la copie</button>
        </form>
      <?php else: ?>
        <p class="meta">Aucune copie disponible : l'exposant n'en a pas déposé, ou elle a été supprimée / purgée. Les références de la pièce figurent ci-dessus au registre ; vous pouvez vérifier l'original en main propre.</p>
      <?php endif; ?>
      <?php if (!empty($c['cni_consent'])): ?>
        <p class="meta">Accord de l'exposant pour le dépôt et la consultation : donné<?= !empty($cni['consent']) ? ' le ' . e(date('d/m/Y H:i', (int) $cni['consent'])) : '' ?>.</p>
      <?php endif; ?>
      <p class="meta">La copie n'est pas exigée par le registre (la CNIL considère sa conservation comme excessive). Elle est privée, accessible seulement aux organisateurs connectés, et supprimée automatiquement au plus tard <?= (int) GB_CNI_RETENTION_DAYS ?> jours après le dépôt.</p>
    </section>

    <section class="gcard">
      <h2>Corriger la fiche</h2>
      <form method="post" style="display:grid;gap:10px;">
        <?= $hid ?><input type="hidden" name="action" value="save_client">
        <div class="row">
          <label class="f">Type<select name="kind"><?php foreach (CT_TYPES as $k => $label): ?><option value="<?= e($k) ?>"<?= $kind === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
          <label class="f">Nom affiché<input type="text" name="name" maxlength="80" value="<?= e($row['name']) ?>"></label>
          <label class="f">E-mail<input type="email" name="email" maxlength="160" required value="<?= e($row['email']) ?>"></label>
          <label class="f">Téléphone<input type="text" name="phone" maxlength="30" value="<?= e($row['phone']) ?>"></label>
        </div>
        <h3>Particulier</h3>
        <div class="row">
          <label class="f">Prénom<input type="text" name="firstname" maxlength="60" value="<?= e($c['firstname'] ?? '') ?>"></label>
          <label class="f">Nom<input type="text" name="lastname" maxlength="60" value="<?= e($c['lastname'] ?? '') ?>"></label>
          <label class="f">Date de naissance<input type="date" name="birth_date" value="<?= e($c['birth_date'] ?? '') ?>"></label>
          <label class="f">Lieu de naissance<input type="text" name="birth_place" maxlength="120" value="<?= e($c['birth_place'] ?? '') ?>"></label>
        </div>
        <div class="row">
          <label class="f">Adresse du domicile<input type="text" name="address" maxlength="160" value="<?= e($c['address'] ?? '') ?>"></label>
          <label class="f">Code postal<input type="text" name="postal_code" maxlength="12" value="<?= e($c['postal_code'] ?? '') ?>"></label>
          <label class="f">Ville<input type="text" name="city" maxlength="80" value="<?= e($c['city'] ?? '') ?>"></label>
        </div>
        <label class="f" style="display:flex;gap:8px;align-items:flex-start;"><input type="checkbox" name="attestation" value="1"<?= !empty($c['attestation']) ? ' checked' : '' ?>> <span>Attestation sur l'honneur (2 participations maximum dans l'année)</span></label>

        <h3>Association ou entreprise</h3>
        <div class="row">
          <label class="f">Dénomination<input type="text" name="org_name" maxlength="120" value="<?= e($c['org_name'] ?? '') ?>"></label>
          <label class="f">Forme juridique<select name="legal_form"><option value="">—</option><?php foreach (CT_LEGAL_FORMS as $lk => $lv): ?><option value="<?= e($lk) ?>"<?= ($c['legal_form'] ?? '') === $lk ? ' selected' : '' ?>><?= e($lv) ?></option><?php endforeach; ?></select></label>
          <label class="f">RNA<input type="text" name="rna" maxlength="20" value="<?= e($c['rna'] ?? '') ?>"></label>
          <label class="f">SIREN<input type="text" name="siren" maxlength="20" value="<?= e($c['siren'] ?? '') ?>"></label>
          <label class="f">SIRET<input type="text" name="siret" maxlength="20" value="<?= e($c['siret'] ?? '') ?>"></label>
          <label class="f">TVA<input type="text" name="vat" maxlength="20" value="<?= e($c['vat'] ?? '') ?>"></label>
        </div>
        <div class="row">
          <label class="f">Adresse du siège<input type="text" name="org_address" maxlength="160" value="<?= e($c['org_address'] ?? '') ?>"></label>
          <label class="f">Code postal<input type="text" name="org_postal_code" maxlength="12" value="<?= e($c['org_postal_code'] ?? '') ?>"></label>
          <label class="f">Ville<input type="text" name="org_city" maxlength="80" value="<?= e($c['org_city'] ?? '') ?>"></label>
        </div>
        <div class="row">
          <label class="f">Prénom du représentant<input type="text" name="rep_firstname" maxlength="60" value="<?= e($c['rep_firstname'] ?? '') ?>"></label>
          <label class="f">Nom du représentant<input type="text" name="rep_lastname" maxlength="60" value="<?= e($c['rep_lastname'] ?? '') ?>"></label>
          <label class="f">Fonction<input type="text" name="rep_function" maxlength="80" value="<?= e($c['rep_function'] ?? '') ?>"></label>
        </div>
        <label class="f" style="display:flex;gap:8px;align-items:flex-start;"><input type="checkbox" name="association_declaration" value="1"<?= !empty($c['association_declaration']) ? ' checked' : '' ?>> <span>Association : objets usagés issus de dons de particuliers</span></label>

        <h3>Pièce d'identité</h3>
        <div class="row">
          <label class="f">Nature<select name="id_doc_type"><option value="">—</option><?php foreach (CT_ID_DOC_TYPES as $dk => $dv): ?><option value="<?= e($dk) ?>"<?= ($c['id_doc_type'] ?? '') === $dk ? ' selected' : '' ?>><?= e($dv) ?></option><?php endforeach; ?></select></label>
          <label class="f">Numéro<input type="text" name="id_doc_number" maxlength="40" value="<?= e($c['id_doc_number'] ?? '') ?>"></label>
          <label class="f">Délivrée le<input type="date" name="id_doc_date" value="<?= e($c['id_doc_date'] ?? '') ?>"></label>
          <label class="f">Autorité<input type="text" name="id_doc_issuer" maxlength="100" value="<?= e($c['id_doc_issuer'] ?? '') ?>"></label>
        </div>
        <label class="f" style="display:flex;gap:8px;align-items:flex-start;"><input type="checkbox" name="cni_consent" value="1"<?= !empty($c['cni_consent']) ? ' checked' : '' ?>> <span>Accord de l'exposant pour le dépôt / la consultation d'une copie de sa pièce</span></label>
        <div><button class="btn btn-primary" type="submit">Enregistrer la fiche</button></div>
      </form>
    </section>

    <section class="gcard">
      <h2>Journal des actions</h2>
      <?php if (!$audit): ?><p class="meta">Aucune action enregistrée pour cet exposant.</p><?php else: ?>
        <table class="t"><thead><tr><th>Date</th><th>Action</th><th>Par</th><th></th></tr></thead><tbody>
        <?php foreach ($audit as $a): ?>
          <tr><td><?= e(date('d/m/Y H:i', strtotime((string) ($a['at'] ?? 'now')))) ?></td>
            <td><?= e(GB_AUDIT_LABELS[(string) ($a['action'] ?? '')] ?? (string) ($a['action'] ?? '')) ?></td>
            <td><?= e((string) ($a['by'] ?? ($a['email'] ?? 'exposant'))) ?></td>
            <td class="meta"><?= e((string) ($a['reason'] ?? '')) ?><?= !empty($a['cni']) ? 'Copie : ' . e((string) $a['cni']) : '' ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </section>
<?php ga_foot(); ?>
