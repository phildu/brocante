<?php

// Formulaire de connexion commun à tous les espaces de la plateforme :
// portail de l'exploitant, administration de la boutique, Studio, compte
// client et administration de galerie.
//
// Même champ « Identifiant, nom ou e-mail », même œil sur le mot de passe
// (assets/pwd-toggle.js), mêmes boutons de réseaux sociaux et mêmes liens
// (mot de passe oublié, création de compte). Chaque page garde sa carte et ses
// couleurs : seuls les champs et leur ordre sont communs.

if (!function_exists('login_h')) {
    function login_h(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/** Attributs HTML d'un champ, sans valeur vide ni attribut booléen faux. */
function login_field_attrs(array $attrs): string
{
    $html = '';
    foreach ($attrs as $key => $value) {
        if ($value === false || $value === null || $value === '') {
            continue;
        }
        $html .= ' ' . $key . ($value === true ? '' : '="' . login_h($value) . '"');
    }
    return $html;
}

/**
 * Champ texte commun (identifiant, e-mail, téléphone, code).
 * $o : name, id, label, type, value, autocomplete, autofocus, maxlength,
 *      inputmode, placeholder, attrs (attributs supplémentaires).
 */
function login_field_html(array $o = []): string
{
    $name = (string) ($o['name'] ?? 'login');
    $id = (string) ($o['id'] ?? 'login-' . preg_replace('/[^a-z0-9_-]+/i', '-', $name));
    $label = (string) ($o['label'] ?? 'Identifiant, nom ou e-mail');
    $attrs = (array) ($o['attrs'] ?? []);
    $attrs += [
        'type' => (string) ($o['type'] ?? 'text'),
        'name' => $name,
        'id' => $id,
        'autocomplete' => (string) ($o['autocomplete'] ?? 'username'),
        'autocapitalize' => 'none',
        'spellcheck' => 'false',
        'required' => true,
    ];
    if (!empty($o['autofocus'])) {
        $attrs['autofocus'] = true;
    }
    if (isset($o['maxlength'])) {
        $attrs['maxlength'] = (int) $o['maxlength'];
    }
    if (isset($o['inputmode'])) {
        $attrs['inputmode'] = (string) $o['inputmode'];
    }
    if (isset($o['placeholder']) && $o['placeholder'] !== '') {
        $attrs['placeholder'] = (string) $o['placeholder'];
    }
    $value = (string) ($o['value'] ?? ($_POST[$name] ?? ''));
    return '<label class="login-field" for="' . login_h($id) . '">' . login_h($label)
        . '<input' . login_field_attrs($attrs) . ' value="' . login_h($value) . '"></label>';
}

/** Champ mot de passe commun ; l'œil est ajouté par assets/pwd-toggle.js. */
function login_password_field_html(array $o = []): string
{
    $name = (string) ($o['name'] ?? 'password');
    $id = (string) ($o['id'] ?? 'login-' . preg_replace('/[^a-z0-9_-]+/i', '-', $name));
    $label = (string) ($o['label'] ?? 'Mot de passe');
    $attrs = (array) ($o['attrs'] ?? []);
    $attrs += [
        'type' => 'password',
        'name' => $name,
        'id' => $id,
        'autocomplete' => (string) ($o['autocomplete'] ?? 'current-password'),
        'required' => true,
    ];
    if (!empty($o['autofocus'])) {
        $attrs['autofocus'] = true;
    }
    if (isset($o['minlength'])) {
        $attrs['minlength'] = (int) $o['minlength'];
    }
    if (isset($o['maxlength'])) {
        $attrs['maxlength'] = (int) $o['maxlength'];
    }
    if (isset($o['placeholder']) && $o['placeholder'] !== '') {
        $attrs['placeholder'] = (string) $o['placeholder'];
    }
    return '<label class="login-field" for="' . login_h($id) . '">' . login_h($label)
        . '<input' . login_field_attrs($attrs) . ' value=""></label>';
}

/** Lien secondaire : ['url' => …, 'label' => …, 'class' => …, 'attrs' => …]. */
function login_link_html(array $link): string
{
    $url = (string) ($link['url'] ?? '');
    $label = (string) ($link['label'] ?? $url);
    $class = (string) ($link['class'] ?? '');
    $attrs = (array) ($link['attrs'] ?? []);
    if ($url !== '') {
        $attrs['href'] = $url;
    }
    return '<a' . login_field_attrs($attrs) . ($class !== '' ? ' class="' . login_h($class) . '"' : '') . '>' . $label . '</a>';
}

/**
 * Formulaire de connexion commun (réseaux sociaux, identifiant, mot de passe,
 * lien « mot de passe oublié », liens secondaires).
 *
 * $o : action, method, id, hidden, identifier_name, identifier_label,
 *      identifier_value, identifier_autocomplete, password_name, submit,
 *      social (HTML déjà produit), forgot_url, forgot_label, links (tableau),
 *      remember (bool), remember_name, remember_label, remember_checked,
 *      autofocus, note, form_class.
 */
function login_form_html(array $o = []): string
{
    $id = preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($o['id'] ?? 'login')) ?: 'login';
    $method = strtolower((string) ($o['method'] ?? 'post')) === 'get' ? 'get' : 'post';
    $action = (string) ($o['action'] ?? '');
    $identifierName = (string) ($o['identifier_name'] ?? 'login');
    $identifierLabel = (string) ($o['identifier_label'] ?? 'Identifiant, nom ou e-mail');
    $passwordName = (string) ($o['password_name'] ?? 'password');
    $submit = (string) ($o['submit'] ?? 'Se connecter');
    $social = (string) ($o['social'] ?? '');
    $hidden = (array) ($o['hidden'] ?? []);
    $forgotUrl = (string) ($o['forgot_url'] ?? '');
    $forgotLabel = (string) ($o['forgot_label'] ?? 'Mot de passe oublié ?');
    $links = (array) ($o['links'] ?? []);
    $formClass = trim('login-unified-form ' . (string) ($o['form_class'] ?? ''));

    $html = '<div class="login-unified">';
    if ($social !== '') {
        $html .= $social . '<div class="login-sep"><span>ou</span></div>';
    }
    $html .= '<form class="' . login_h($formClass) . '" method="' . $method . '"'
        . ($action !== '' ? ' action="' . login_h($action) . '"' : '') . ' autocomplete="on">';
    foreach ($hidden as $name => $value) {
        $html .= '<input type="hidden" name="' . login_h($name) . '" value="' . login_h($value) . '">';
    }
    $html .= login_field_html([
        'name' => $identifierName,
        'id' => $id . '-id',
        'label' => $identifierLabel,
        'autocomplete' => (string) ($o['identifier_autocomplete'] ?? 'username'),
        'autofocus' => (bool) ($o['autofocus'] ?? true),
        'value' => (string) ($o['identifier_value'] ?? ($_POST[$identifierName] ?? '')),
    ]);
    $html .= login_password_field_html([
        'name' => $passwordName,
        'id' => $id . '-pw',
        'autocomplete' => 'current-password',
    ]);
    if (!empty($o['remember'])) {
        $rememberName = (string) ($o['remember_name'] ?? 'remember');
        $html .= '<label class="login-check"><input type="checkbox" name="' . login_h($rememberName) . '" value="1"'
            . (!empty($o['remember_checked']) ? ' checked' : '') . '> <span>' . login_h((string) ($o['remember_label'] ?? 'Rester connecté')) . '</span></label>';
    }
    $html .= '<button class="btn btn-primary login-submit" type="submit">' . login_h($submit) . '</button>';
    if ($forgotUrl !== '' || $links) {
        $html .= '<p class="login-links">';
        if ($forgotUrl !== '') {
            $html .= login_link_html(['url' => $forgotUrl, 'label' => login_h($forgotLabel)]);
        }
        foreach ($links as $link) {
            if (is_array($link)) {
                $html .= login_link_html($link);
            }
        }
        $html .= '</p>';
    }
    if (!empty($o['note'])) {
        $html .= '<p class="login-note">' . (string) $o['note'] . '</p>';
    }
    $html .= '</form></div>';
    return $html;
}

/**
 * Formulaire « mot de passe oublié » commun : un identifiant, puis un bouton
 * pour recevoir le lien par e-mail et, si un envoi de SMS est configuré, un
 * second bouton pour recevoir un code par SMS. Le nom du bouton envoyé
 * (method=email|sms) indique la méthode choisie.
 */
function login_forgot_form_html(array $o = []): string
{
    $id = preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($o['id'] ?? 'forgot')) ?: 'forgot';
    $identifierName = (string) ($o['identifier_name'] ?? 'login');
    $hidden = (array) ($o['hidden'] ?? []);
    $sms = !empty($o['sms']);
    $html = '<div class="login-unified"><form class="login-unified-form login-forgot-form" method="post" autocomplete="on">';
    foreach ($hidden as $name => $value) {
        $html .= '<input type="hidden" name="' . login_h($name) . '" value="' . login_h($value) . '">';
    }
    $html .= login_field_html([
        'name' => $identifierName,
        'id' => $id . '-id',
        'label' => (string) ($o['identifier_label'] ?? 'Identifiant, nom ou e-mail'),
        'autocomplete' => 'username',
        'autofocus' => true,
        'value' => (string) ($o['identifier_value'] ?? ($_POST[$identifierName] ?? '')),
    ]);
    $html .= '<button class="btn btn-primary login-submit" type="submit" name="method" value="email">'
        . login_h((string) ($o['email_label'] ?? 'Recevoir le lien par e-mail')) . '</button>';
    if ($sms) {
        $html .= '<button class="btn login-submit" type="submit" name="method" value="sms">'
            . login_h((string) ($o['sms_label'] ?? 'Recevoir un code par SMS')) . '</button>';
    }
    if (!empty($o['note'])) {
        $html .= '<p class="login-note">' . (string) $o['note'] . '</p>';
    }
    $html .= '</form></div>';
    return $html;
}

/**
 * Formulaire de vérification d'un code SMS et choix du nouveau mot de passe.
 * $o : action, hidden, identifier_name, identifier_value, code_name,
 *      password_name, confirm_name, submit, minlength.
 */
function login_otp_form_html(array $o = []): string
{
    $id = preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($o['id'] ?? 'otp')) ?: 'otp';
    $identifierName = (string) ($o['identifier_name'] ?? 'login');
    $codeName = (string) ($o['code_name'] ?? 'code');
    $passwordName = (string) ($o['password_name'] ?? 'password');
    $confirmName = (string) ($o['confirm_name'] ?? 'confirm');
    $hidden = (array) ($o['hidden'] ?? []);
    $min = (int) ($o['minlength'] ?? 8);
    $html = '<div class="login-unified"><form class="login-unified-form login-otp-form" method="post" autocomplete="off">';
    foreach ($hidden as $name => $value) {
        $html .= '<input type="hidden" name="' . login_h($name) . '" value="' . login_h($value) . '">';
    }
    $html .= login_field_html([
        'name' => $identifierName,
        'id' => $id . '-id',
        'label' => (string) ($o['identifier_label'] ?? 'Identifiant, nom ou e-mail'),
        'value' => (string) ($o['identifier_value'] ?? ($_POST[$identifierName] ?? '')),
        'attrs' => ['readonly' => true],
    ]);
    $html .= login_field_html([
        'name' => $codeName,
        'id' => $id . '-code',
        'label' => 'Code reçu par SMS',
        'type' => 'text',
        'autocomplete' => 'one-time-code',
        'inputmode' => 'numeric',
        'maxlength' => 6,
        'autofocus' => true,
        'value' => '',
    ]);
    $html .= login_password_field_html([
        'name' => $passwordName,
        'id' => $id . '-pw',
        'label' => 'Nouveau mot de passe',
        'autocomplete' => 'new-password',
        'minlength' => $min,
    ]);
    $html .= login_password_field_html([
        'name' => $confirmName,
        'id' => $id . '-pw2',
        'label' => 'Confirmer le mot de passe',
        'autocomplete' => 'new-password',
        'minlength' => $min,
    ]);
    $html .= '<button class="btn btn-primary login-submit" type="submit">'
        . login_h((string) ($o['submit'] ?? 'Valider le code et changer le mot de passe')) . '</button>';
    if (!empty($o['note'])) {
        $html .= '<p class="login-note">' . (string) $o['note'] . '</p>';
    }
    $html .= '</form></div>';
    return $html;
}
