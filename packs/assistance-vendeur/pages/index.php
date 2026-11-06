<?php
// Assistance vendeur : disponibilité, alertes en direct (voix + liste), visiteurs, pièces retenues et réglages.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/av.php';

$s = av_settings();
$sentUrl = static fn (string $route): string => $pluginApi($route);
?>
<link rel="stylesheet" href="<?= h($pluginAsset('assistant.css')) ?>">
<div id="av-page" class="av-admin"
     data-api-state="<?= h($sentUrl('state')) ?>"
     data-api-save="<?= h($sentUrl('save')) ?>"
     data-api-action="<?= h($sentUrl('action')) ?>"
     data-api-test="<?= h($sentUrl('test')) ?>"
     data-csrf="<?= h($pluginCsrf) ?>"
     data-voice="<?= $s['voice'] === '1' ? '1' : '0' ?>">

  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1>Assistance vendeur</h1>
  <p class="av-admin-lede">Déclarez-vous disponible : le site affiche votre présence aux visiteurs, qui peuvent poser une question par e-mail ou demander un appel WhatsApp. Quand un visiteur s’attarde sur une pièce, remplit son panier ou ouvre le paiement, une alerte arrive ici (voix et liste), par e-mail, SMS ou WhatsApp selon les accords de chacun. Vous pouvez retenir la pièce quelques minutes, le temps de répondre ou de la vendre en boutique.</p>
  <p class="av-msg" id="av-flash" role="status"></p>

  <div class="av-admin-grid">
    <section class="av-card">
      <h2>Ma disponibilité</h2>
      <p class="av-live" id="av-avail-state">Chargement…</p>
      <div class="av-row">
        <button type="button" class="btn-small" data-avail="30">30 min</button>
        <button type="button" class="btn-small" data-avail="60">1 heure</button>
        <button type="button" class="btn-small" data-avail="120">2 heures</button>
        <button type="button" class="btn-small av-ghost" data-avail="0">Je ne suis plus disponible</button>
      </div>
      <label class="av-field">Note affichée aux visiteurs (facultatif)
        <input type="text" id="av-avail-note" maxlength="160" placeholder="Je réponds entre deux clients." value="<?= h($s['available_note']) ?>">
      </label>
      <p class="hint">La disponibilité s’arrête d’elle-même à l’échéance. Elle n’est jamais automatique : c’est vous qui l’activez.</p>
    </section>

    <section class="av-card">
      <h2>À l’écoute</h2>
      <p class="av-live" id="av-live-state">Chargement…</p>
      <div class="av-row">
        <button type="button" class="btn-small" id="av-sound">Activer la voix</button>
        <button type="button" class="btn-small av-ghost" id="av-test">Alerte de démonstration</button>
      </div>
      <p class="hint" id="av-channels-note">Canaux : chargement…</p>
      <p class="hint">La voix et le son ne fonctionnent que sur cet onglet d’administration, ouvert. Pour être prévenu ailleurs, l’e-mail, le SMS et WhatsApp (accord de chaque vendeur) prennent le relais.</p>
    </section>
  </div>

  <section class="av-card">
    <div class="av-card-head">
      <h2>Alertes <span class="av-badge" id="av-unseen" hidden></span></h2>
      <div class="av-row">
        <button type="button" class="btn-small av-ghost" id="av-seen">Tout marquer comme vu</button>
      </div>
    </div>
    <div id="av-alerts" class="av-list"><p class="hint">Chargement…</p></div>
  </section>

  <div class="av-admin-grid">
    <section class="av-card">
      <h2>Visiteurs en ligne</h2>
      <p class="hint">Navigateurs ayant vu une page il y a moins de 5 minutes.</p>
      <div id="av-visitors" class="av-list"><p class="hint">Chargement…</p></div>
    </section>

    <section class="av-card">
      <h2>Pièces retenues</h2>
      <p class="hint">Une pièce retenue n’est plus achetables en ligne (sauf par le visiteur pour qui elle est gardée), le temps de répondre ou de la vendre en boutique.</p>
      <div id="av-holds" class="av-list"><p class="hint">Chargement…</p></div>
    </section>
  </div>

  <section class="av-card">
    <div class="av-card-head">
      <h2>Vendeurs prévenus</h2>
      <button type="button" class="btn-small av-ghost" id="av-sync">Reprendre les comptes de l’équipe</button>
    </div>
    <p class="hint">Repris automatiquement des comptes de l’équipe (administrateur, community manager, photographe). Un accord par canal : l’e-mail suit la fiche, le SMS et WhatsApp exigent un portable et une case cochée.</p>
    <div id="av-sellers" class="av-list"><p class="hint">Chargement…</p></div>
    <form id="av-seller-form" class="av-seller-form">
      <h3>Ajouter un vendeur</h3>
      <div class="av-form-grid">
        <label class="av-field">Nom<input type="text" name="name" maxlength="80" placeholder="Nathalie"></label>
        <label class="av-field">E-mail<input type="email" name="email" maxlength="160" required placeholder="nathalie@exemple.fr"></label>
        <label class="av-field">Portable<input type="tel" name="phone" maxlength="24" placeholder="06 12 34 56 78"></label>
      </div>
      <div class="av-row">
        <label class="av-check"><input type="checkbox" name="consent_mail" value="1" checked> E-mail</label>
        <label class="av-check"><input type="checkbox" name="consent_sms" value="1"> SMS</label>
        <label class="av-check"><input type="checkbox" name="consent_wa" value="1"> WhatsApp</label>
        <label class="av-check"><input type="checkbox" name="active" value="1" checked> Actif</label>
      </div>
      <div class="av-row"><button type="submit" class="btn-small">Ajouter</button> <span class="av-msg" data-role="msg" role="status"></span></div>
    </form>
  </section>

  <section class="av-card">
    <h2>Réglages</h2>
    <form id="av-settings" class="av-settings">
      <fieldset>
        <legend>Assistance</legend>
        <label class="av-check"><input type="checkbox" name="active" value="1"> Module actif (alertes et widget)</label>
        <label class="av-check"><input type="checkbox" name="widget" value="1"> Widget visible sur le site</label>
        <div class="av-form-grid">
          <label class="av-field">Attente sur une pièce avant alerte (secondes)<input type="number" name="dwell_seconds" min="20" max="600" step="10"></label>
          <label class="av-field">Répétition minimale d’une alerte (minutes)<input type="number" name="cooldown_minutes" min="5" max="180"></label>
          <label class="av-field">Retenue d’une pièce (minutes)<input type="number" name="hold_minutes" min="5" max="120"></label>
          <label class="av-field">Conservation des données visiteurs (jours)<input type="number" name="retention_days" min="1" max="90"></label>
        </div>
        <div class="av-form-grid">
          <label class="av-field">Message d’accueil<input type="text" name="welcome" maxlength="200"></label>
          <label class="av-field">Message quand personne n’est disponible<input type="text" name="away" maxlength="200"></label>
        </div>
      </fieldset>

      <fieldset>
        <legend>Quelles alertes&nbsp;?</legend>
        <label class="av-check"><input type="checkbox" name="alert_cart" value="1"> Panier ouvert par un visiteur</label>
        <label class="av-check"><input type="checkbox" name="alert_cart_add" value="1"> Pièce ajoutée au panier</label>
        <label class="av-check"><input type="checkbox" name="alert_checkout" value="1"> Paiement ouvert ou coordonnées en cours de saisie</label>
        <label class="av-check"><input type="checkbox" name="notify_visit" value="1"> Première visite (au plus une fois par demi-heure)</label>
        <label class="av-check"><input type="checkbox" name="notify_intent" value="1"> Envoyer aussi les alertes d’intention par e-mail, SMS et WhatsApp</label>
        <label class="av-check"><input type="checkbox" name="voice" value="1"> Lire les alertes à voix haute dans cette page</label>
        <p class="hint">Sans cette dernière case, seules les alertes importantes (question, paiement ouvert) partent par e-mail, SMS et WhatsApp ; les autres restent dans cette page.</p>
      </fieldset>

      <fieldset>
        <legend>Canaux</legend>
        <div class="av-row">
          <label class="av-check"><input type="checkbox" name="channels[]" value="mail"> E-mail</label>
          <label class="av-check"><input type="checkbox" name="channels[]" value="sms"> SMS</label>
          <label class="av-check"><input type="checkbox" name="channels[]" value="whatsapp"> WhatsApp (Meta)</label>
        </div>
        <p class="hint" id="av-channels-ready">—</p>
        <label class="av-field">WhatsApp de la boutique (bouton « Discuter sur WhatsApp »)
          <input type="tel" name="whatsapp" maxlength="24" placeholder="+33 6 12 34 56 78">
        </label>
        <p class="hint">Vide : le numéro du module Messagerie est utilisé s’il existe.</p>
      </fieldset>

      <div class="av-row"><button type="submit" class="btn-small">Enregistrer les réglages</button> <span class="av-msg" data-role="msg" role="status"></span></div>
    </form>
  </section>
</div>
<script src="<?= h($pluginAsset('assistant.js')) ?>" defer></script>