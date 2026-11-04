<?php
require __DIR__ . '/_bootstrap.php';
fv_require_auth();

$ctx = fv_current();
$member = $ctx['member'];
$pdo = fv_db();
$members = $ctx['manager'] ? fv_members_list(false) : [];

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'status' => (string) ($_GET['statut'] ?? ''),
    'source' => (string) ($_GET['source'] ?? ''),
    'zone' => trim((string) ($_GET['zone'] ?? '')),
    'city' => trim((string) ($_GET['ville'] ?? '')),
    'owner_id' => (int) ($_GET['owner'] ?? 0),
    'active_only' => isset($_GET['actifs']) || !isset($_GET['tous']),
    'has_email' => true,
];
if (!isset($_GET['statut']) && !isset($_GET['source']) && !isset($_GET['zone']) && !isset($_GET['ville'])) {
    // Premier affichage : on vise les fiches réellement en cours.
    $filters['active_only'] = true;
}

$templates = [
    'premier' => [
        'label' => 'Premier contact',
        'subject' => '{commerce} : votre boutique en ligne, sans commission',
        'body' => <<<'TXT'
Bonjour {contact},

Je suis {commercial}, je m’occupe des commerces de {ville} pour {marque}.

{offre}

Concrètement, votre boutique en ligne complète votre vitrine sans changer vos horaires, et {marque} ne prend pas de commission sur vos ventes. {prix}

Je peux vous montrer un exemple en quinze minutes, sur place ou en visio. Dites-moi simplement un créneau qui vous arrange.

Bien à vous,
{commercial}
{telephone}
{lien}
TXT,
    ],
    'demo' => [
        'label' => 'Invitation démo',
        'subject' => 'Votre démonstration {marque} — {ville}',
        'body' => <<<'TXT'
Bonjour {contact},

Comme convenu, je vous propose de découvrir {commerce} en ligne lors d’une démonstration de quinze minutes.

Au programme : votre page d’accueil, vos premiers articles, la gestion des commandes et le retrait en boutique. Vous repartez avec une boutique ouverte, prête à être complétée.

{prix}

Dites-moi quel créneau vous convient, je m’adapte à vos horaires.

À très vite,
{commercial}
{telephone}
{lien}
TXT,
    ],
    'relance' => [
        'label' => 'Relance J+7',
        'subject' => 'Une question sur votre boutique, {contact} ?',
        'body' => <<<'TXT'
Bonjour {contact},

Je reviens vers vous au sujet de {commerce}. Avez-vous eu le temps de regarder la démonstration ?

Si quelque chose bloque — le temps, le prix, la prise en main — dites-le-moi simplement. On peut avancer étape par étape, et je peux remplir les premières fiches avec vous.

Je vous rappelle volontiers cette semaine, ou nous fixons un rendez-vous de quinze minutes.

Bien à vous,
{commercial}
{telephone}
{lien}
TXT,
    ],
    'ambassadeur' => [
        'label' => 'Commerce référent',
        'subject' => '{commerce} : devenez commerce référent à {ville}',
        'body' => <<<'TXT'
Bonjour {contact},

Votre commerce est connu à {ville}, et c’est exactement ce que nous cherchons pour {marque}.

Je vous propose un rôle de commerce référent : vous recommandez la plateforme aux commerces de votre réseau, et chaque boutique créée avec votre code {code} vous rapporte une récompense et une remise pour vos propres clients ({remise} de remise parrainage).

Vous gardez votre activité, nous fournissons les supports, les démonstrations et le suivi. Le dispositif est détaillé ici : {lien}

Je vous explique tout en dix minutes si le principe vous intéresse.

Bien à vous,
{commercial}
{telephone}
TXT,
    ],
    'reprise' => [
        'label' => 'Réactivation',
        'subject' => '{commerce} — on reprend là où on s’était arrêtés ?',
        'body' => <<<'TXT'
Bonjour {contact},

Nous avions échangé au sujet de {commerce}, et le projet était resté en suspens. Je ne voulais pas vous relancer sans nouvelles : est-ce toujours d’actualité ?

Si le moment n’est pas le bon, dites-le-moi et je clos la fiche. Si vous voulez avancer, je peux vous montrer la version actuelle de {marque} : elle a changé depuis notre dernier échange.

{offre}

Bien à vous,
{commercial}
{telephone}
{lien}
TXT,
    ],
];

$templateKey = (string) ($_GET['modele'] ?? 'premier');
if (!isset($templates[$templateKey])) $templateKey = 'premier';

$recipients = fv_prospects_search($filters, 500);

if (isset($_GET['export'])) {
    $rows = [['Commerce', 'Contact', 'E-mail', 'Ville', 'Zone', 'Statut', 'Sujet', 'Message']];
    foreach ($recipients as $p) {
        $subject = fv_mail_merge($templates[$templateKey]['subject'], $p, $member);
        $body = fv_mail_merge($templates[$templateKey]['body'], $p, $member);
        $rows[] = [$p['business'], $p['name'], $p['email'], $p['city'], $p['zone'], fv_status_label($p['status']), $subject, $body];
    }
    fv_csv_download('mailing-' . preg_replace('/[^a-z0-9]+/', '-', $templateKey) . '-' . date('Y-m-d') . '.csv', $rows);
}

$subject = (string) ($_POST['subject'] ?? $templates[$templateKey]['subject']);
$bodyText = (string) ($_POST['body'] ?? $templates[$templateKey]['body']);
$subject = fv_text($subject, 180);
$bodyText = mb_substr(trim(str_replace("\r\n", "\n", $bodyText)), 0, 8000);

$bits = [];
if ($filters['status'] !== '') $bits[] = fv_status_label($filters['status']);
if ($filters['source'] !== '') $bits[] = fv_source_label($filters['source']);
if ($filters['zone'] !== '') $bits[] = 'zone ' . $filters['zone'];
if ($filters['city'] !== '') $bits[] = $filters['city'];
if ($filters['owner_id'] > 0) {
    foreach ($members as $m) {
        if ((int) $m['id'] === $filters['owner_id']) $bits[] = $m['name'];
    }
}
if (!$bits) $bits[] = $filters['active_only'] ? 'Fiches en cours' : 'Toutes les fiches';
$segmentLabel = implode(' · ', $bits);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'journaliser') {
    fv_csrf_check();
    if ($subject === '' || $bodyText === '') {
        fv_flash('Renseignez un sujet et un message avant de journaliser la campagne.', 'error');
    } else {
        $st = $pdo->prepare('INSERT INTO mailings (member_id, subject, body, segment, recipients) VALUES (?,?,?,?,?)');
        $st->execute([fv_effective_member_id(), $subject, $bodyText, $segmentLabel, count($recipients)]);
        fv_flash('Campagne enregistrée dans l’historique : « ' . $subject . ' » · ' . count($recipients) . ' destinataire' . (count($recipients) > 1 ? 's' : '') . '.');
        fv_redirect('mailing.php?statut=' . rawurlencode($filters['status']) . '&modele=' . rawurlencode($templateKey) . '&historique=1');
    }
}

$sample = $recipients[0] ?? null;
$sampleSubject = $sample ? fv_mail_merge($subject, $sample, $member) : fv_mail_merge($subject, [], $member);
$sampleBody = $sample ? fv_mail_merge($bodyText, $sample, $member) : fv_mail_merge($bodyText, [], $member);
$bcc = implode(', ', array_column($recipients, 'email'));
$bulkBody = fv_mail_merge($bodyText, [], $member);
$mailto = 'mailto:?bcc=' . rawurlencode($bcc) . '&subject=' . rawurlencode($sampleSubject) . '&body=' . rawurlencode(mb_substr($bulkBody, 0, 1800));
$history = $pdo->query('SELECT m.*, u.name AS member_name FROM mailings m LEFT JOIN members u ON u.id = m.member_id ORDER BY m.created_at DESC, m.id DESC LIMIT 12')->fetchAll();
$zones = fv_zones();

fv_layout_start('Mailing', [
    'eyebrow' => 'Communication écrite',
    'desc' => 'Un segment, un modèle, un message personnalisé. L’envoi part de votre messagerie en Cci : rien n’est expédié à votre place.',
    'wide' => true,
    'actions' => '<a class="fv-btn fv-btn-sm" href="prospects.php?nouveau=1">' . fv_icon('plus', 14) . ' Ajouter un contact</a>'
        . '<a class="fv-btn fv-btn-sm" href="kits.php">' . fv_icon('image', 14) . ' Kits</a>',
]);
?>

<div class="fv-grid fv-grid-2-1">
  <div class="fv-grid" style="gap:14px;">
    <section class="fv-card">
      <div class="fv-card-head">
        <div><h2>Composer la campagne</h2><p>Les champs entre accolades sont remplacés pour chaque destinataire.</p></div>
        <span class="fv-badge"><?= count($recipients) ?> destinataire<?= count($recipients) > 1 ? 's' : '' ?></span>
      </div>
      <div class="fv-card-pad" style="padding-top:5px;">
        <div class="fv-actions" style="margin-bottom:12px;">
          <?php foreach ($templates as $key => $tpl): ?>
            <a class="fv-btn fv-btn-sm<?= $key === $templateKey ? ' fv-btn-primary' : '' ?>" href="mailing.php?<?= e(http_build_query(array_filter(['statut' => $filters['status'], 'zone' => $filters['zone'] ?: null, 'ville' => $filters['city'] ?: null, 'source' => $filters['source'] ?: null, 'owner' => $filters['owner_id'] ?: null, 'tous' => $filters['active_only'] ? null : 1, 'modele' => $key]))) ?>"><?= e($tpl['label']) ?></a>
          <?php endforeach; ?>
        </div>

        <form class="fv-form" method="post" action="mailing.php?<?= e(http_build_query(array_filter(['statut' => $filters['status'], 'zone' => $filters['zone'] ?: null, 'ville' => $filters['city'] ?: null, 'source' => $filters['source'] ?: null, 'owner' => $filters['owner_id'] ?: null, 'tous' => $filters['active_only'] ? null : 1, 'modele' => $templateKey]))) ?>">
          <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
          <input type="hidden" name="action" value="journaliser">
          <label class="fv-field">Sujet
            <input name="subject" maxlength="180" required value="<?= e($subject) ?>">
          </label>
          <label class="fv-field">Message
            <textarea name="body" rows="14" required><?= e($bodyText) ?></textarea>
            <span class="fv-hint">{contact} {commerce} {ville} {marque} {commercial} {telephone} {email} {lien} {offre} {prix} {code} {remise}</span>
          </label>
          <div class="fv-actions">
            <button class="fv-btn fv-btn-primary" type="submit"><?= fv_icon('check', 14) ?> Journaliser l’envoi</button>
            <?php if ($bcc !== ''): ?>
              <a class="fv-btn" href="<?= e($mailto) ?>"><?= fv_icon('mail', 14) ?> Ouvrir ma messagerie (Cci)</a>
              <button class="fv-btn" type="button" data-copy="<?= e($bcc) ?>">Copier les adresses</button>
            <?php endif; ?>
            <a class="fv-btn fv-btn-ghost" href="mailing.php?<?= e(http_build_query(array_filter(['export' => 1, 'statut' => $filters['status'], 'zone' => $filters['zone'] ?: null, 'ville' => $filters['city'] ?: null, 'source' => $filters['source'] ?: null, 'owner' => $filters['owner_id'] ?: null, 'tous' => $filters['active_only'] ? null : 1, 'modele' => $templateKey]))) ?>"><?= fv_icon('download', 14) ?> CSV pour publipostage</a>
          </div>
          <p class="fv-hint">Envoi conseillé en Cci, par lots de 30 à 50. Les adresses sont professionnelles ; ajoutez une phrase de désinscription si le volume augmente.</p>
        </form>
      </div>
    </section>

    <section class="fv-card">
      <div class="fv-card-head">
        <div><h2>Destinataires <small class="fv-sub"><?= count($recipients) ?></small></h2><p>Les fiches sans adresse e-mail sont exclues. Cliquez sur « Écrire » pour un envoi personnalisé.</p></div>
        <a class="fv-btn fv-btn-sm" href="mailing.php?<?= e(http_build_query(array_filter(['statut' => $filters['status'], 'zone' => $filters['zone'] ?: null, 'ville' => $filters['city'] ?: null, 'source' => $filters['source'] ?: null, 'owner' => $filters['owner_id'] ?: null, 'tous' => $filters['active_only'] ? null : 1]))) ?>#segment">Segment</a>
      </div>
      <div class="fv-table-wrap">
        <?php if (!$recipients): ?>
          <div class="fv-empty"><b>Aucune adresse dans ce segment</b>Élargissez les filtres ou complétez les e-mails dans les fiches.</div>
        <?php else: ?>
          <table class="fv-table">
            <thead><tr><th>Commerce</th><th>Contact</th><th>E-mail</th><th>Statut</th><th class="right">Message</th></tr></thead>
            <tbody>
              <?php foreach (array_slice($recipients, 0, 80) as $p): ?>
                <?php
                $oneSubject = fv_mail_merge($subject, $p, $member);
                $oneBody = fv_mail_merge($bodyText, $p, $member);
                $oneMailto = 'mailto:' . rawurlencode($p['email']) . '?subject=' . rawurlencode($oneSubject) . '&body=' . rawurlencode(mb_substr($oneBody, 0, 1800));
                $msgId = 'msg-' . (int) $p['id'];
                ?>
                <tr>
                  <td><a class="fv-rowlink main" href="prospects.php?id=<?= (int) $p['id'] ?>"><?= e($p['business'] !== '' ? $p['business'] : $p['name']) ?></a><span class="sub"><?= e($p['city']) ?></span></td>
                  <td><?= e($p['name'] !== '' ? $p['name'] : '—') ?></td>
                  <td><a href="mailto:<?= e($p['email']) ?>"><?= e($p['email']) ?></a></td>
                  <td><span class="fv-badge" data-s="<?= e($p['status']) ?>"><?= e(fv_status_label($p['status'])) ?></span></td>
                  <td>
                    <div class="actions">
                      <a class="fv-btn fv-btn-sm" href="<?= e($oneMailto) ?>">Écrire</a>
                      <button class="fv-btn fv-btn-sm" type="button" data-copy-target="#<?= $msgId ?>">Copier</button>
                    </div>
                    <textarea id="<?= $msgId ?>" hidden><?= e($oneSubject . "\n\n" . $oneBody) ?></textarea>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php if (count($recipients) > 80): ?><p class="fv-hint" style="padding:12px 18px;">Les 80 premiers contacts sont affichés. Exportez le CSV pour la liste complète.</p><?php endif; ?>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <aside class="fv-grid" style="gap:14px;">
    <section class="fv-card" id="segment">
      <div class="fv-card-head"><div><h2>Segment</h2><p><?= e($segmentLabel) ?></p></div></div>
      <div class="fv-card-pad" style="padding-top:8px;">
        <form class="fv-form" method="get">
          <input type="hidden" name="modele" value="<?= e($templateKey) ?>">
          <div class="fv-form-grid">
            <label class="fv-field">Statut
              <select name="statut">
                <option value="">Tous</option>
                <?php foreach (fv_statuses() as $key => $label): ?><option value="<?= e($key) ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
              </select>
            </label>
            <label class="fv-field">Source
              <select name="source">
                <option value="">Toutes</option>
                <?php foreach (fv_sources() as $key => $label): ?><option value="<?= e($key) ?>"<?= $filters['source'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
              </select>
            </label>
            <label class="fv-field">Zone
              <select name="zone">
                <option value="">Toutes</option>
                <?php foreach ($zones as $z): ?><option value="<?= e($z) ?>"<?= $filters['zone'] === $z ? ' selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?>
              </select>
            </label>
            <label class="fv-field">Ville
              <input name="ville" value="<?= e($filters['city']) ?>" placeholder="Paris">
            </label>
            <?php if ($ctx['manager']): ?>
              <label class="fv-field">Propriétaire
                <select name="owner">
                  <option value="0">Toute l’équipe</option>
                  <?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>"<?= $filters['owner_id'] === (int) $m['id'] ? ' selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?>
                </select>
              </label>
            <?php endif; ?>
            <label class="fv-check full"><input type="checkbox" name="tous" value="1"<?= $filters['active_only'] ? '' : ' checked' ?>> Inclure les fiches closes</label>
          </div>
          <div class="fv-actions">
            <button class="fv-btn fv-btn-primary" type="submit">Appliquer</button>
            <a class="fv-btn fv-btn-ghost" href="mailing.php?modele=<?= e($templateKey) ?>">Réinitialiser</a>
          </div>
        </form>
      </div>
    </section>

    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Aperçu</h2><p>Rendu pour <?= $sample ? e($sample['business'] !== '' ? $sample['business'] : $sample['name']) : 'un contact type' ?>.</p></div></div>
      <div class="fv-card-body">
        <p class="fv-eyebrow">Objet</p>
        <p style="font-weight:600;"><?= e($sampleSubject) ?></p>
        <div class="fv-note" style="max-height:290px;overflow:auto;"><?= nl2br(e($sampleBody)) ?></div>
      </div>
    </section>

    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Journal</h2><p>Les campagnes enregistrées.</p></div></div>
      <div class="fv-card-body" style="padding-top:6px;">
        <?php if (!$history): ?>
          <p class="fv-hint">Aucune campagne journalisée pour le moment.</p>
        <?php else: ?>
          <div class="fv-list">
            <?php foreach ($history as $h): ?>
              <div class="fv-list-item" style="cursor:default;">
                <span style="min-width:0;flex:1;">
                  <b><?= e($h['subject']) ?></b>
                  <small><?= e(fv_date($h['created_at'])) ?> · <?= (int) $h['recipients'] ?> contact<?= (int) $h['recipients'] > 1 ? 's' : '' ?><?= $h['member_name'] ? ' · ' . e($h['member_name']) : '' ?></small>
                  <small><?= e($h['segment']) ?></small>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </aside>
</div>

<?php fv_layout_end(); ?>
