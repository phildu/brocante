<?php
// Connexion sociale — RETOUR du fournisseur.
//   - clés de la PLATEFORME : sur l'adresse centrale (la seule déclarée chez Google, Facebook…), puis renvoi vers la boutique d'où vient le visiteur ;
//   - clés PROPRES à une boutique : sur l'adresse de cette boutique, qui est aussi la page d'arrivée.
// Pas de session ici : le state signé tient lieu de contexte. On échange le code contre le profil, puis on renvoie le visiteur sur la boutique
// avec un justificatif signé de 2 minutes (voir includes/oauth.php).
require_once __DIR__ . '/../includes/oauth.php';

$in = $_GET + $_POST;   // Apple répond en POST (response_mode=form_post)
$state = oauth_verify_any((string) ($in['state'] ?? ''));
$return = (string) ($state['o'] ?? '');
$mode = (string) ($state['m'] ?? '');
// Plateforme : le domaine de retour doit être connu. Clés propres : le retour est cette boutique, pas une autre.
$returnOk = $mode === 'own' ? strtolower($return) === strtolower(oauth_return_base()) : ($mode === 'platform' && oauth_return_allowed($return));
if (!$state || !$returnOk) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Ce lien de connexion a expiré ou n'est pas valable : revenez sur le site et recommencez.");
}

$claims = ['aud' => $return, 'n' => (string) ($state['n'] ?? ''), 'c' => (string) ($state['c'] ?? ''), 'p' => (string) ($state['p'] ?? ''), 'm' => $mode];
$creds = $mode === 'own' ? oauth_own_creds($claims['p']) : oauth_platform_creds($claims['p']);
try {
    if (!$creds) throw new RuntimeException('Cette méthode de connexion n\'est plus disponible.');
    if (!empty($in['error']) || empty($in['code'])) {
        throw new RuntimeException(($in['error'] ?? '') === 'access_denied' || ($in['error'] ?? '') === 'user_cancelled_authorize'
            ? 'Connexion annulée.' : 'Le fournisseur n\'a pas confirmé la connexion' . (!empty($in['error_description']) ? ' (' . mb_substr((string) $in['error_description'], 0, 120) . ')' : '') . '.');
    }
    $profile = oauth_fetch_profile($claims['p'], (string) $in['code'], $creds);
    [$first, $last] = oauth_split_name($profile['name'], $profile['first'], $profile['last']);
    if ($claims['p'] === 'apple' && !empty($in['user'])) { // Apple ne donne le nom qu'à la première autorisation, à part
        $u = json_decode((string) $in['user'], true) ?: [];
        $first = (string) ($u['name']['firstName'] ?? $first);
        $last = (string) ($u['name']['lastName'] ?? $last);
    }
    $claims += ['sub' => $profile['sub'], 'email' => $profile['email'], 'v' => $profile['verified'], 'fn' => mb_substr($first, 0, 60), 'ln' => mb_substr($last, 0, 60)];
} catch (RuntimeException $e) {
    error_log('oauth callback ' . $claims['p'] . ' : ' . $e->getMessage());
    $claims['err'] = $e->getMessage();
}
header('Location: ' . $return . '/oauth/finish.php?a=' . oauth_sign($claims, 120, $mode === 'own' ? oauth_shop_config()['signing_key'] : oauth_config()['signing_key']), true, 303);
exit;
