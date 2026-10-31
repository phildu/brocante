<?php
// Formulaire du dossier de subvention : rendu partagé entre la page /dossier/ et le gestionnaire (réaffichage en cas d'erreur).

require_once __DIR__ . '/lib.php';

function ddoc_form_style(): string
{
    return <<<'CSS'
<style>
.dossier-layout { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 28px; align-items: start; }
.dossier-layout .form-card { max-width: none; }
.dossier-side { position: sticky; top: 20px; display: grid; gap: 14px; }
.dossier-sum { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 18px 20px; }
.dossier-sum h2 { font-size: .78rem; letter-spacing: .12em; text-transform: uppercase; color: var(--ink-soft); margin: 0 0 10px; }
.dossier-sum dl { display: grid; grid-template-columns: 1fr auto; gap: 7px 12px; margin: 0; font-size: .92rem; }
.dossier-sum dt { color: var(--ink-soft); }
.dossier-sum dd { margin: 0; text-align: right; font-weight: 600; font-variant-numeric: tabular-nums; }
.dossier-sum dl .total { border-top: 1px solid var(--line); padding-top: 8px; }
.dossier-sum p { font-size: .8rem; color: var(--ink-soft); margin: 12px 0 0; line-height: 1.45; }
.dossier-checks { display: grid; gap: 10px; }
.dossier-checks label { display: flex; gap: 9px; align-items: flex-start; font-size: .92rem; }
.dossier-checks input { margin-top: 3px; }
.dossier-form h2 { font-family: var(--display); font-size: 1.3rem; margin: 0 0 14px; }
.dossier-form section + section { margin-top: 26px; padding-top: 22px; border-top: 1px solid var(--line); }
.dossier-form .field small { line-height: 1.35; }
.dossier-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 26px; }
.dossier-hero .actions { margin-top: 24px; }
.dossier-steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px; margin: 8px 0 34px; }
.dossier-steps div { border-top: 2px solid var(--accent); padding-top: 10px; }
.dossier-steps b { display: block; margin-bottom: 4px; }
.dossier-steps span { color: var(--ink-soft); font-size: .92rem; }
@media (max-width: 900px) { .dossier-layout { grid-template-columns: 1fr; } .dossier-side { position: static; } }
</style>
CSS;
}

function ddoc_f(string $name, string $label, array $p, array $errors, string $hint = '', string $type = 'text', string $attrs = '', string $cls = ''): void
{
    $err = (string) ($errors[$name] ?? '');
    $classes = 'field' . ($err !== '' ? ' has-error' : '') . ($cls !== '' ? ' ' . $cls : '');
    ?>
    <label class="<?= $classes ?>">
      <span><?= ddoc_e($label) ?></span>
      <input type="<?= ddoc_e($type) ?>" name="<?= ddoc_e($name) ?>" value="<?= ddoc_e($p[$name] ?? '') ?>"<?= $attrs !== '' ? ' ' . $attrs : '' ?>>
      <?php if ($hint !== ''): ?><small><?= ddoc_e($hint) ?></small><?php endif; ?>
      <?php if ($err !== ''): ?><small class="err"><?= ddoc_e($err) ?></small><?php endif; ?>
    </label>
    <?php
}

/** Liste déroulante : chaque option porte la mise en place correspondante (data-setup) pour la synthèse en direct. */
function ddoc_select(string $name, string $label, array $p, array $options, string $hint = ''): void
{
    $cur = (string) ($p[$name] ?? '');
    ?>
    <label class="field">
      <span><?= ddoc_e($label) ?></span>
      <select name="<?= ddoc_e($name) ?>">
        <?php foreach ($options as $key => $row): ?>
          <option value="<?= ddoc_e($key) ?>" data-setup="<?= (int) $row['setup'] ?>"<?= $key === $cur ? ' selected' : '' ?>><?= ddoc_e($row['label'] . ' — ' . ddoc_eur($row['setup']) . ' HT') ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($hint !== ''): ?><small><?= ddoc_e($hint) ?></small><?php endif; ?>
    </label>
    <?php
}

/** Chiffres affichés dans la colonne de synthèse (les mêmes calculs que ddoc_parse, sans validation). */
function ddoc_preview_numbers(array $p): array
{
    $members = max(1, (int) ddoc_num($p['members'] ?? 20, 20));
    $grid = ddoc_setup_grid();
    $taille = (string) ($p['taille'] ?? '');
    if (!isset($grid[$taille])) $taille = ddoc_taille_for($members);
    $setup = ddoc_num($p['setup'] ?? '', 0);
    if ($setup <= 0) $setup = $grid[$taille]['setup'];
    $rate = min(100, ddoc_num($p['rate'] ?? 40, 40));
    $shops = max(0, (int) ddoc_num($p['shops'] ?? 20, 20));
    $monthly = ddoc_num($p['sub_monthly'] ?? 25, 25);
    $subsidy = $setup * $rate / 100;
    $selffund = max(0.0, $setup - $subsidy);
    return [
        'setup' => $setup, 'rate' => $rate, 'members' => $members, 'shops' => $shops,
        'subsidy' => $subsidy, 'selffund' => $selffund, 'per_member' => $selffund / $members,
        'subs_year' => $monthly * $shops * 12, 'monthly' => $monthly,
    ];
}

function ddoc_render_summary(array $p): void
{
    $n = ddoc_preview_numbers($p);
    $pages = 5 + (!empty($p['include_reponse']) ? 1 : 0) + (!empty($p['include_grille']) ? 1 : 0) + (!empty($p['include_pieces']) ? 2 : 0);
    ?>
    <div class="dossier-sum">
      <h2>Ce que demandera le dossier</h2>
      <dl>
        <dt>Mise en place HT</dt><dd id="sum-setup"><?= ddoc_e(ddoc_eur($n['setup'])) ?></dd>
        <dt>Subvention demandée</dt><dd id="sum-subsidy"><?= ddoc_e(ddoc_eur($n['subsidy'])) ?></dd>
        <dt>Autofinancement</dt><dd id="sum-selffund"><?= ddoc_e(ddoc_eur($n['selffund'])) ?></dd>
        <dt>Par adhérent</dt><dd id="sum-permember"><?= ddoc_e(ddoc_eur($n['per_member'], 2)) ?></dd>
        <dt>Abonnements par an</dt><dd id="sum-subsyear"><?= ddoc_e(ddoc_eur($n['subs_year'])) ?></dd>
        <dt class="total">Année 1, une boutique</dt><dd class="total" id="sum-year1"><?= ddoc_e(ddoc_eur($n['setup'] + $n['monthly'] * 12)) ?></dd>
      </dl>
      <p>Les abonnements ne sont pas éligibles : ils restent portés par les cotisations. TVA non applicable, article 293 B du CGI.</p>
    </div>
    <div class="dossier-sum">
      <h2>Le dossier</h2>
      <p id="sum-pages" style="font-size:.92rem;color:inherit"><?= $pages ?> pages A4, prêtes à imprimer en PDF.</p>
      <p>Formulaires préremplis : lettre, note de cadrage, budget, calendrier de l'instruction. Rien n'est enregistré sur le serveur.</p>
    </div>
    <?php
}

function ddoc_render_form(array $p, array $errors, array $warnings): void
{
    ?>
    <form class="form-card dossier-form" method="post" action="/dossier/generer.php" id="dossier-form">
      <input type="hidden" name="brand" value="<?= ddoc_e($p['brand'] ?? 'Brocs') ?>">
      <?php if ($errors): ?>
        <div class="notice error"><?= ddoc_e($errors['form'] ?? "Le dossier n'a pas pu être généré : corrigez les champs signalés, puis relancez la préparation.") ?></div>
      <?php endif; ?>
      <?php if ($warnings): ?>
        <div class="notice"><?php foreach ($warnings as $w): ?><div>· <?= ddoc_e($w) ?></div><?php endforeach; ?></div>
      <?php endif; ?>

      <section>
        <h2>L'association</h2>
        <div class="form-grid">
          <?php ddoc_f('assoc_name', "Nom de l'association", $p, $errors, 'Tel qu\'il figure sur les statuts et le RIB.', 'text', 'maxlength="120" autocomplete="organization"', 'full'); ?>
          <?php ddoc_f('assoc_address', 'Adresse du siège', $p, $errors, 'Numéro et rue, comme sur l\'avis SIRENE.', 'text', 'maxlength="160" autocomplete="street-address"', 'full'); ?>
          <?php ddoc_f('assoc_cp', 'Code postal', $p, $errors, '', 'text', 'maxlength="10" inputmode="numeric" autocomplete="postal-code"'); ?>
          <?php ddoc_f('assoc_city', 'Ville', $p, $errors, '', 'text', 'maxlength="120" autocomplete="address-level2"'); ?>
          <?php ddoc_f('siret', 'SIRET', $p, $errors, '14 chiffres, tirés de l\'avis de situation SIRENE.', 'text', 'maxlength="20" inputmode="numeric"'); ?>
          <?php ddoc_f('rep_name', 'Nom du signataire', $p, $errors, 'La personne qui signe la lettre.', 'text', 'maxlength="120"'); ?>
          <?php ddoc_f('rep_role', 'Fonction', $p, $errors, '', 'text', 'maxlength="80"'); ?>
          <?php ddoc_f('letter_date', 'Date du courrier', $p, $errors, '', 'date'); ?>
        </div>
      </section>

      <section>
        <h2>Le projet</h2>
        <div class="form-grid">
          <?php ddoc_f('project_name', 'Nom du projet', $p, $errors, 'Il apparaît sur la page de garde et la lettre.', 'text', 'maxlength="120" placeholder="Ex. Le numérique des commerces de la Croix-Rousse"', 'full'); ?>
          <?php ddoc_f('territory', 'Quartier ou commune', $p, $errors, 'Le périmètre couvert par le collectif.', 'text', 'maxlength="120"'); ?>
          <?php ddoc_f('launch', 'Lancement prévu', $p, $errors, 'Facultatif : sert à calculer la date de dépôt conseillée.', 'date'); ?>
          <?php ddoc_f('members', 'Nombre d\'adhérents', $p, $errors, 'Base du coût par adhérent.', 'number', 'min="1" max="9999" step="1" inputmode="numeric"'); ?>
          <?php ddoc_f('shops', 'Boutiques ouvertes', $p, $errors, 'Un adhérent peut ouvrir plusieurs boutiques.', 'number', 'min="0" max="9999" step="1" inputmode="numeric"'); ?>
        </div>
      </section>

      <section>
        <h2>Les chiffres</h2>
        <div class="form-grid">
          <?php ddoc_select('taille', 'Taille du déploiement', $p, ddoc_setup_grid(), 'Grille de mise en place : 1 900 €, 3 900 € ou 5 900 € HT selon le nombre d\'adhérents.'); ?>
          <?php ddoc_f('setup', 'Mise en place HT (€)', $p, $errors, 'Reprise du devis envoyé, abonnements exclus. Corrigez si le devis diffère de la grille.', 'number', 'min="0" max="1000000" step="1" inputmode="decimal"'); ?>
          <?php ddoc_f('rate', 'Taux d\'aide demandé (%)', $p, $errors, 'Les dispositifs de la Métropole plafonnent en général entre 30 et 50 %.', 'number', 'min="0" max="100" step="1" inputmode="numeric"'); ?>
          <?php ddoc_f('sub_monthly', 'Abonnement par boutique (€ / mois)', $p, $errors, 'Hors subvention, porté par les cotisations.', 'number', 'min="0" max="10000" step="0.01" inputmode="decimal"'); ?>
        </div>
      </section>

      <section>
        <h2>Le prestataire</h2>
        <div class="form-grid">
          <?php ddoc_f('vendor_name', 'Interlocuteur', $p, $errors, 'La personne qui suit le dossier côté prestataire.', 'text', 'maxlength="120"'); ?>
          <?php ddoc_f('vendor_phone', 'Téléphone', $p, $errors, '', 'tel', 'maxlength="30" autocomplete="tel"'); ?>
          <?php ddoc_f('vendor_email', 'E-mail', $p, $errors, '', 'email', 'maxlength="120" autocomplete="email"', 'full'); ?>
        </div>
      </section>

      <section>
        <h2>Pièces à générer</h2>
        <div class="dossier-checks">
          <label><input type="checkbox" name="include_reponse" value="1"<?= !empty($p['include_reponse']) ? ' checked' : '' ?>> Réponse à cahier des charges, côté prestataire</label>
          <label><input type="checkbox" name="include_grille" value="1"<?= !empty($p['include_grille']) ? ' checked' : '' ?>> Grille d'évaluation du prestataire sur 100 points</label>
          <label><input type="checkbox" name="include_pieces" value="1"<?= !empty($p['include_pieces']) ? ' checked' : '' ?>> Compte Tiers et liste des pièces à joindre</label>
        </div>
      </section>

      <div class="dossier-actions">
        <button class="btn" type="submit" name="action" value="apercu">Prévisualiser le dossier</button>
        <button class="btn ghost" type="submit" name="action" value="zip">Télécharger l'archive ZIP</button>
      </div>
    </form>
    <?php
}

/** Page complète : bandeau, formulaire et synthèse chiffrée. $intro ajoute le mode d'emploi (page d'entrée). */
function ddoc_page_form(array $p, array $errors, array $warnings, bool $intro = false): void
{
    $cfg = saas_config();
    saas_page_start('Dossier de subvention — ' . $cfg['name'], 'Préparez et téléchargez les documents d\'une demande de subvention : lettre, note de cadrage, budget, calendrier, compte Tiers et pièces à joindre.', '');
    echo ddoc_form_style();
    ?>
    <div class="wrap">
      <div class="page-title">
        <p class="eyebrow">Dossier de subvention</p>
        <h1>Le dossier, prérempli.</h1>
        <p class="lede">Renseignez l'association, le projet et les chiffres : la lettre, la note de cadrage, le budget et le calendrier se remplissent avec vos montants. Les documents s'impriment en PDF depuis le navigateur.</p>
      </div>
      <?php if ($intro): ?>
        <div class="dossier-steps">
          <div><b>1 · Remplir</b><span>Association, projet, montants. Le devis fait foi : le dossier reprend la mise en place HT et applique le taux d'aide.</span></div>
          <div><b>2 · Vérifier l'aperçu</b><span>Chaque feuillet A4 est relu avant l'envoi. Les erreurs bloquantes — SIRET, montants — sont signalées.</span></div>
          <div><b>3 · Télécharger</b><span>Une archive avec les documents HTML et un budget au format tableur. Rien n'est conservé sur le serveur.</span></div>
        </div>
      <?php endif; ?>
      <div class="dossier-layout">
        <div><?php ddoc_render_form($p, $errors, $warnings); ?></div>
        <aside class="dossier-side"><?php ddoc_render_summary($p); ?></aside>
      </div>
    </div>
    <script>
    (function () {
      var f = document.getElementById('dossier-form');
      if (!f) return;
      var nf = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 });
      var num = function (name) {
        var el = f.elements[name];
        if (!el) return 0;
        var v = parseFloat(String(el.value || '').replace(/[\s\u00a0\u202f]/g, '').replace(',', '.'));
        return isFinite(v) && v > 0 ? v : 0;
      };
      var set = function (id, value) { var el = document.getElementById(id); if (el) el.textContent = value; };
      var eur = function (n) { return nf.format(n) + ' €'; };
      /* La taille du déploiement se déduit du nombre d'adhérents ; le montant du devis n'est écrasé que s'il venait de la grille. */
      function syncTaille() {
        var sel = f.elements.taille, setupEl = f.elements.setup;
        if (!sel || !setupEl) return;
        var m = Math.max(1, Math.round(num('members')) || 1);
        var key = m <= 30 ? 's' : (m <= 80 ? 'm' : 'l');
        if (sel.value === key) return;
        var fromGrid = false;
        for (var i = 0; i < sel.options.length; i++) {
          if (sel.options[i].getAttribute('data-setup') === setupEl.value) fromGrid = true;
        }
        sel.value = key;
        if (fromGrid && sel.selectedIndex >= 0) setupEl.value = sel.options[sel.selectedIndex].getAttribute('data-setup');
      }
      function update() {
        var setup = num('setup'), rate = Math.min(100, num('rate'));
        var members = Math.max(1, Math.round(num('members')) || 1);
        var shops = Math.round(num('shops')), monthly = num('sub_monthly');
        var subsidy = setup * rate / 100, self = Math.max(0, setup - subsidy);
        set('sum-setup', eur(setup));
        set('sum-subsidy', eur(subsidy));
        set('sum-selffund', eur(self));
        set('sum-permember', eur(self / members));
        set('sum-subsyear', eur(monthly * shops * 12));
        set('sum-year1', eur(setup + monthly * 12));
        var pages = 5;
        if (f.elements.include_reponse && f.elements.include_reponse.checked) pages++;
        if (f.elements.include_grille && f.elements.include_grille.checked) pages++;
        if (f.elements.include_pieces && f.elements.include_pieces.checked) pages += 2;
        set('sum-pages', pages + ' pages A4, prêtes à imprimer en PDF.');
      }
      if (f.elements.taille) {
        f.elements.taille.addEventListener('change', function () {
          var opt = f.elements.taille.options[f.elements.taille.selectedIndex];
          if (opt && opt.getAttribute('data-setup')) f.elements.setup.value = opt.getAttribute('data-setup');
        });
      }
      /* Seul le nombre d'adhérents recalcule la taille : un choix fait dans la liste est respecté. */
      var onField = function (e) {
        if (e.target && e.target.name === 'members') syncTaille();
        update();
      };
      f.addEventListener('input', onField);
      f.addEventListener('change', onField);
      update();
    })();
    </script>
    <?php
    saas_page_end();
}
