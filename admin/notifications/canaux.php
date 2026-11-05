<?php
require_once __DIR__ . '/../../includes/notifications-layout.php';
nt_admin_init();
$self = '/admin/notifications/canaux.php';

function nt_saved(bool $ok, string $what, string $anchor): void
{
    global $self;
    nt_redirect($self . '#' . $anchor, $ok ? $what . ' enregistré.' : 'Impossible d\'écrire le fichier de configuration (.secrets/) : vérifiez les droits du dossier.', $ok ? 'ok' : 'error');
}

$in = fn(string $k): string => trim((string) ($_POST[$k] ?? ''));

switch (nt_post_action()) {
    case 'save_general': {
        $cc = preg_replace('/\D/', '', $in('country_code'));
        if ($cc === '' || strlen($cc) > 4) nt_redirect($self . '#general', 'Indicatif pays invalide (ex. 33 pour la France).', 'error');
        $start = max(0, min(23, (int) ($_POST['quiet_start'] ?? 20)));
        $end = max(0, min(23, (int) ($_POST['quiet_end'] ?? 8)));
        $ok = nt_config_update('general', ['country_code' => $cc, 'site_name' => mb_substr($in('site_name'), 0, 80)])
            && nt_config_update('quiet', ['start' => $start, 'end' => $end, 'sunday' => !empty($_POST['quiet_sunday']) ? 1 : 0]);
        nt_saved($ok, 'Réglages généraux', 'general');
    }

    case 'save_email': {
        $transport = $in('transport') === 'smtp' ? 'smtp' : 'mail';
        $from = $in('from_email');
        $reply = $in('reply_to');
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) nt_redirect($self . '#email', 'Adresse d\'expédition invalide.', 'error');
        if ($reply !== '' && !filter_var($reply, FILTER_VALIDATE_EMAIL)) nt_redirect($self . '#email', 'Adresse de réponse invalide.', 'error');
        $port = (int) ($_POST['smtp_port'] ?? 587);
        if ($transport === 'smtp' && ($in('smtp_host') === '' || $port < 1 || $port > 65535)) nt_redirect($self . '#email', 'Serveur SMTP : hôte et port (1–65535) obligatoires.', 'error');
        $secure = in_array($in('smtp_secure'), ['tls', 'ssl'], true) ? $in('smtp_secure') : '';
        nt_saved(nt_config_update('email', [
            'transport' => $transport, 'from_email' => $from, 'from_name' => mb_substr($in('from_name'), 0, 80), 'reply_to' => $reply,
            'smtp_host' => $in('smtp_host'), 'smtp_port' => $port, 'smtp_secure' => $secure, 'smtp_user' => $in('smtp_user'), 'smtp_pass' => $in('smtp_pass'),
        ]), 'Réglages e-mail', 'email');
    }

    case 'save_twilio': {
        $sid = $in('account_sid');
        $mg = $in('messaging_service_sid');
        $smsFrom = $in('sms_from');
        $waFrom = $in('whatsapp_from');
        if ($sid !== '' && !preg_match('/^AC[0-9A-Za-z]{20,40}$/', $sid)) nt_redirect($self . '#twilio', 'Account SID invalide (commence par « AC »).', 'error');
        if ($mg !== '' && !preg_match('/^MG[0-9A-Za-z]{20,40}$/', $mg)) nt_redirect($self . '#twilio', 'Messaging Service SID invalide (commence par « MG »).', 'error');
        if ($smsFrom !== '') {
            $norm = nt_normalize_phone($smsFrom);
            if ($norm !== null) $smsFrom = $norm;
            elseif (!preg_match('/^[A-Za-z][A-Za-z0-9 ]{1,10}$/', $smsFrom)) nt_redirect($self . '#twilio', 'Expéditeur SMS : numéro international (+33…) ou nom de 11 caractères maximum.', 'error');
        }
        if ($waFrom !== '') {
            $waFrom = nt_normalize_phone($waFrom) ?? '';
            if ($waFrom === '') nt_redirect($self . '#twilio', 'Numéro WhatsApp expéditeur invalide.', 'error');
        }
        $ok = nt_config_update('twilio', ['account_sid' => $sid, 'auth_token' => $in('auth_token')])
            && nt_config_update('sms', ['from' => $smsFrom, 'messaging_service_sid' => $mg])
            && nt_config_update('whatsapp', ['from' => $waFrom]);
        nt_saved($ok, 'Réglages Twilio (SMS & WhatsApp)', 'twilio');
    }

    case 'save_discord': {
        $t = $in('bot_token');
        if ($t !== '' && (strlen($t) < 30 || preg_match('/\s/', $t))) nt_redirect($self . '#discord', 'Jeton de bot Discord invalide.', 'error');
        nt_saved(nt_config_update('discord', ['bot_token' => $t]), 'Réglages Discord', 'discord');
    }

    case 'save_telegram': {
        $t = $in('bot_token');
        if ($t !== '' && !preg_match('/^\d{3,12}:[A-Za-z0-9_-]{20,}$/', $t)) nt_redirect($self . '#telegram', 'Jeton Telegram invalide (format 123456:ABC…, fourni par @BotFather).', 'error');
        nt_saved(nt_config_update('telegram', ['bot_token' => $t]), 'Réglages Telegram', 'telegram');
    }

    case 'clear_secret': {
        $map = ['smtp_pass' => ['email', 'smtp_pass'], 'twilio' => ['twilio', 'auth_token'], 'discord' => ['discord', 'bot_token'], 'telegram' => ['telegram', 'bot_token']];
        $which = $in('which');
        if (isset($map[$which])) {
            $cfg = nt_config();
            $cfg[$map[$which][0]][$map[$which][1]] = '';
            nt_config_save($cfg);
        }
        nt_redirect($self, 'Secret supprimé.');
    }

    case 'quick_test': {
        $ch = $in('channel');
        $addr = nt_normalize_address($ch, $in('address'));
        if (!isset(nt_channels()[$ch]) || $addr === null) nt_redirect($self . '#test-rapide', 'Adresse invalide pour ce canal.', 'error');
        if (!nt_channel_ready($ch, $addr)) nt_redirect($self . '#test-rapide', nt_channel_label($ch) . ' n\'est pas encore configuré.', 'error');
        $r = nt_driver_send($ch, $addr, ['subject' => 'Message de test', 'text' => 'Ceci est un message de test envoyé par ' . nt_site_name() . '. Si vous le lisez, ce canal fonctionne !', 'unsubscribe_url' => '']);
        nt_redirect($self . '#test-rapide', $r['ok'] ? 'Test envoyé par ' . nt_channel_label($ch) . ' à ' . nt_mask_address($ch, $addr) . '.' : 'Échec : ' . $r['error'], $r['ok'] ? 'ok' : 'error');
    }
}

$cfg = nt_config();
$status = nt_channel_status();
$secretNote = fn(string $path) => nt_cfg($path) !== '' ? '<span class="nt-badge ok">enregistré</span> <span class="nt-muted" style="font-size:.8rem;">laisser vide pour le conserver</span>' : '<span class="nt-badge warn">non renseigné</span>';

nt_admin_header('Canaux & réglages', 'canaux');
?>
<p class="nt-hint">Les identifiants sont enregistrés dans <code class="nt-code">.secrets/notifications.json</code> sur le serveur : jamais dans la base, jamais dans Git, jamais écrasés par un déploiement. Ils ne sont pas réaffichés ; laisser un champ secret vide conserve la valeur actuelle.</p>

<div class="nt-card" id="test-rapide">
  <h2 class="nt-h2">Test rapide</h2>
  <p class="nt-hint">Envoie un message de test directement à une adresse, sans créer d'utilisateur — pratique pour valider un canal juste après l'avoir réglé.</p>
  <?= nt_form_open('quick_test') ?>
    <div class="nt-form-grid">
      <div class="field"><label for="qtc">Canal</label>
        <select class="nt-input" id="qtc" name="channel"><?php foreach (nt_channels() as $k => $d): ?><option value="<?= h($k) ?>"><?= h($d['label']) ?><?= $status[$k]['ready'] ? '' : ' (non configuré)' ?></option><?php endforeach; ?></select></div>
      <div class="field span-2"><label for="qta">Adresse (e-mail, mobile, ID Discord ou webhook, chat ID Telegram)</label><input class="nt-input" id="qta" name="address" required autocomplete="off"></div>
    </div>
    <div class="nt-actions"><button class="btn" type="submit">Envoyer un test</button></div>
  </form>
</div>

<div class="nt-card" id="email">
  <h2 class="nt-h2">E-mail <?= $status['email']['ready'] ? nt_badge('Prêt', 'ok') : nt_badge('Non configuré', 'warn') ?></h2>
  <p class="nt-hint">Par défaut, le serveur d'envoi de l'hébergement (<code class="nt-code">mail()</code>) : simple, mais prévoyez une adresse d'expédition du même domaine que le site. Pour une meilleure délivrabilité, utilisez un serveur SMTP (celui de votre hébergeur ou d'un service d'envoi).</p>
  <?= nt_form_open('save_email') ?>
    <div class="nt-form-grid">
      <div class="field"><label for="fe">Adresse d'expédition</label><input class="nt-input" id="fe" type="email" name="from_email" value="<?= h($cfg['email']['from_email']) ?>" placeholder="boutique@votre-domaine.fr" required></div>
      <div class="field"><label for="fn">Nom affiché</label><input class="nt-input" id="fn" name="from_name" value="<?= h($cfg['email']['from_name']) ?>" maxlength="80" placeholder="La Brocante du Petit Chalet"></div>
      <div class="field"><label for="rt">Adresse de réponse (facultatif)</label><input class="nt-input" id="rt" type="email" name="reply_to" value="<?= h($cfg['email']['reply_to']) ?>"></div>
      <div class="field"><label for="tr">Envoi par</label>
        <select class="nt-input" id="tr" name="transport"><option value="mail"<?= $cfg['email']['transport'] === 'mail' ? ' selected' : '' ?>>Serveur de l'hébergement (mail)</option><option value="smtp"<?= $cfg['email']['transport'] === 'smtp' ? ' selected' : '' ?>>Serveur SMTP</option></select></div>
      <div class="field"><label for="sh">Serveur SMTP</label><input class="nt-input" id="sh" name="smtp_host" value="<?= h($cfg['email']['smtp_host']) ?>" placeholder="smtp.exemple.fr"></div>
      <div class="field"><label for="sp">Port</label><input class="nt-input" id="sp" type="number" name="smtp_port" value="<?= (int) $cfg['email']['smtp_port'] ?>" min="1" max="65535"></div>
      <div class="field"><label for="ss">Chiffrement</label>
        <select class="nt-input" id="ss" name="smtp_secure"><option value="tls"<?= $cfg['email']['smtp_secure'] === 'tls' ? ' selected' : '' ?>>STARTTLS (587)</option><option value="ssl"<?= $cfg['email']['smtp_secure'] === 'ssl' ? ' selected' : '' ?>>SSL/TLS (465)</option><option value=""<?= $cfg['email']['smtp_secure'] === '' ? ' selected' : '' ?>>Aucun (déconseillé)</option></select></div>
      <div class="field"><label for="su">Identifiant SMTP</label><input class="nt-input" id="su" name="smtp_user" value="<?= h($cfg['email']['smtp_user']) ?>" autocomplete="off"></div>
      <div class="field"><label for="sw">Mot de passe SMTP</label><input class="nt-input" id="sw" type="password" name="smtp_pass" autocomplete="new-password" placeholder="••••••••"><div class="nt-counter"><?= $secretNote('email.smtp_pass') ?></div></div>
    </div>
    <div class="nt-actions"><button class="btn btn-primary" type="submit">Enregistrer l'e-mail</button></div>
  </form>
  <?php if (nt_cfg('email.smtp_pass') !== ''): ?>
    <?= nt_form_open('clear_secret', ['which' => 'smtp_pass'], 'Supprimer le mot de passe SMTP enregistré ?', 'nt-inline') ?><button class="btn-small nt-btn-danger" type="submit" style="margin-top:8px;">Supprimer le mot de passe enregistré</button></form>
  <?php endif; ?>
</div>

<div class="nt-card" id="twilio">
  <h2 class="nt-h2">SMS & WhatsApp (Twilio) <?= $status['sms']['ready'] ? nt_badge('SMS prêt', 'ok') : nt_badge('SMS non configuré', 'warn') ?> <?= $status['whatsapp']['ready'] ? nt_badge('WhatsApp prêt', 'ok') : nt_badge('WhatsApp non configuré', 'warn') ?></h2>
  <p class="nt-hint">Un compte <a href="https://www.twilio.com/" target="_blank" rel="noopener">Twilio</a> sert aux deux canaux. SMS : un numéro expéditeur ou un « Messaging Service ». WhatsApp : un numéro approuvé par Meta (ou le bac à sable Twilio pour essayer). <strong>WhatsApp</strong> n'autorise un message libre que dans les 24 h suivant la dernière réponse de la personne ; au-delà, un modèle de message approuvé est requis (non géré ici).</p>
  <?= nt_form_open('save_twilio') ?>
    <div class="nt-form-grid">
      <div class="field"><label for="tsid">Account SID</label><input class="nt-input" id="tsid" name="account_sid" value="<?= h($cfg['twilio']['account_sid']) ?>" placeholder="AC…" autocomplete="off"></div>
      <div class="field"><label for="ttok">Auth Token</label><input class="nt-input" id="ttok" type="password" name="auth_token" autocomplete="new-password" placeholder="••••••••"><div class="nt-counter"><?= $secretNote('twilio.auth_token') ?></div></div>
      <div class="field"><label for="tsf">Expéditeur SMS (numéro +33… ou nom)</label><input class="nt-input" id="tsf" name="sms_from" value="<?= h($cfg['sms']['from']) ?>"></div>
      <div class="field"><label for="tmg">ou Messaging Service SID (prioritaire)</label><input class="nt-input" id="tmg" name="messaging_service_sid" value="<?= h($cfg['sms']['messaging_service_sid']) ?>" placeholder="MG…"></div>
      <div class="field"><label for="twf">Numéro WhatsApp expéditeur</label><input class="nt-input" id="twf" name="whatsapp_from" value="<?= h($cfg['whatsapp']['from']) ?>" placeholder="+14155238886"></div>
    </div>
    <div class="nt-actions"><button class="btn btn-primary" type="submit">Enregistrer Twilio</button></div>
  </form>
  <?php if (nt_cfg('twilio.auth_token') !== ''): ?>
    <?= nt_form_open('clear_secret', ['which' => 'twilio'], 'Supprimer l\'Auth Token Twilio enregistré ? SMS et WhatsApp seront désactivés.', 'nt-inline') ?><button class="btn-small nt-btn-danger" type="submit" style="margin-top:8px;">Supprimer l'Auth Token enregistré</button></form>
  <?php endif; ?>
</div>

<div class="nt-card" id="discord">
  <h2 class="nt-h2">Discord <?= $status['discord']['ready'] ? nt_badge('Prêt', 'ok') : nt_badge('Webhooks seulement', 'warn') ?></h2>
  <p class="nt-hint">Deux façons d'adresser un utilisateur : une <strong>URL de webhook</strong> (message dans un salon, aucun réglage ici) ou son <strong>ID Discord</strong> (message privé, nécessite un bot : créez une application sur <a href="https://discord.com/developers/applications" target="_blank" rel="noopener">discord.com/developers</a>, ajoutez un bot et collez son jeton). Le bot et l'utilisateur doivent partager un serveur, et l'utilisateur accepter les messages privés.</p>
  <?= nt_form_open('save_discord') ?>
    <div class="nt-form-grid">
      <div class="field span-2"><label for="dtok">Jeton du bot (messages privés)</label><input class="nt-input" id="dtok" type="password" name="bot_token" autocomplete="new-password" placeholder="••••••••"><div class="nt-counter"><?= $secretNote('discord.bot_token') ?></div></div>
    </div>
    <div class="nt-actions"><button class="btn btn-primary" type="submit">Enregistrer Discord</button></div>
  </form>
  <?php if (nt_cfg('discord.bot_token') !== ''): ?>
    <?= nt_form_open('clear_secret', ['which' => 'discord'], 'Supprimer le jeton du bot Discord enregistré ?', 'nt-inline') ?><button class="btn-small nt-btn-danger" type="submit" style="margin-top:8px;">Supprimer le jeton enregistré</button></form>
  <?php endif; ?>
</div>

<div class="nt-card" id="telegram">
  <h2 class="nt-h2">Telegram <?= $status['telegram']['ready'] ? nt_badge('Prêt', 'ok') : nt_badge('Non configuré', 'warn') ?></h2>
  <p class="nt-hint">Créez un bot avec <strong>@BotFather</strong> et collez son jeton. Chaque personne doit d'abord écrire « /start » à votre bot ; son <em>chat ID</em> (nombre) s'obtient par exemple via @userinfobot.</p>
  <?= nt_form_open('save_telegram') ?>
    <div class="nt-form-grid">
      <div class="field span-2"><label for="ttk">Jeton du bot</label><input class="nt-input" id="ttk" type="password" name="bot_token" autocomplete="new-password" placeholder="123456:ABC…"><div class="nt-counter"><?= $secretNote('telegram.bot_token') ?></div></div>
    </div>
    <div class="nt-actions"><button class="btn btn-primary" type="submit">Enregistrer Telegram</button></div>
  </form>
  <?php if (nt_cfg('telegram.bot_token') !== ''): ?>
    <?= nt_form_open('clear_secret', ['which' => 'telegram'], 'Supprimer le jeton du bot Telegram enregistré ?', 'nt-inline') ?><button class="btn-small nt-btn-danger" type="submit" style="margin-top:8px;">Supprimer le jeton enregistré</button></form>
  <?php endif; ?>
</div>

<div class="nt-card" id="general">
  <h2 class="nt-h2">Réglages généraux</h2>
  <?= nt_form_open('save_general') ?>
    <div class="nt-form-grid">
      <div class="field"><label for="gsn">Nom du site dans les messages</label><input class="nt-input" id="gsn" name="site_name" value="<?= h($cfg['general']['site_name']) ?>" placeholder="<?= h(nt_site_name()) ?>" maxlength="80"></div>
      <div class="field"><label for="gcc">Indicatif pays des numéros sans « + »</label><input class="nt-input" id="gcc" name="country_code" value="<?= h($cfg['general']['country_code']) ?>" maxlength="4" inputmode="numeric"></div>
      <div class="field"><label for="qs">Heures calmes : à partir de (h)</label><input class="nt-input" id="qs" type="number" min="0" max="23" name="quiet_start" value="<?= (int) $cfg['quiet']['start'] ?>"></div>
      <div class="field"><label for="qe">…jusqu'à (h)</label><input class="nt-input" id="qe" type="number" min="0" max="23" name="quiet_end" value="<?= (int) $cfg['quiet']['end'] ?>"></div>
      <label class="nt-check"><input type="checkbox" name="quiet_sunday" value="1"<?= !empty($cfg['quiet']['sunday']) ? ' checked' : '' ?>> Pas d'envoi le dimanche</label>
    </div>
    <p class="nt-hint" style="margin-top:10px;">Les <strong>heures calmes</strong> s'appliquent aux SMS et WhatsApp des rubriques <em>marketing</em> uniquement : ils sont gardés en file et partent à la première heure autorisée (par défaut pas entre 20 h et 8 h, ni le dimanche). Les jours fériés ne sont pas gérés. C'est une aide, pas un avis juridique : vérifiez les règles de prospection qui s'appliquent à votre activité.</p>
    <div class="nt-actions"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
  </form>
</div>
<?php nt_admin_footer();
