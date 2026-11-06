<?php
// Inscription à l'annuaire d'un commerce qui a déjà son propre site (hors Brocs).
// Étape 1 : adresse du site → lecture de la page d'accueil. Étape 2 : fiche corrigée → demande envoyée à l'exploitant.
// Validation humaine dans le portail (/portail/annuaire-demandes.php) avant publication.
require_once __DIR__ . '/../includes/saas.php';
require_once __DIR__ . '/../includes/annuaire-externe.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$cfg = saas_config();
$origin = saas_origin();
$errors = [];
$scan = null;
$sent = (int) ($_GET['recu'] ?? 0) === 1;

function annuaire_ext_csrf(): string
{
    if (empty($_SESSION['annuaire_ext_csrf'])) $_SESSION['annuaire_ext_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['annuaire_ext_csrf'];
}

function annuaire_ext_csrf_ok(): bool
{
    return hash_equals((string) ($_SESSION['annuaire_ext_csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''));
}

function annuaire_ext_old(string $key, string $default = ''): string
{
    return (string) ($_POST[$key] ?? $default);
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$action = (string) ($_POST['action'] ?? '');
$input = [
    'url' => trim((string) ($_POST['url'] ?? '')),
    'name' => annuaire_ext_old('name'),
    'tagline' => annuaire_ext_old('tagline'),
    'description' => annuaire_ext_old('description'),
    'address' => annuaire_ext_old('address'),
    'phone' => annuaire_ext_old('phone'),
    'contact_name' => annuaire_ext_old('contact_name'),
    'contact_email' => annuaire_ext_old('contact_email'),
    'universes' => annuaire_ext_old('universes'),
    'hours' => annuaire_ext_old('hours'),
    'logo' => annuaire_ext_old('logo'),
    'image' => annuaire_ext_old('image'),
    'platform' => annuaire_ext_old('platform', 'generic'),
    'images' => array_filter(array_map('trim', explode("\n", annuaire_ext_old('images')))),
    'socials' => array_filter(array_map('trim', explode("\n", annuaire_ext_old('socials')))),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!annuaire_ext_csrf_ok()) {
        $errors[] = 'La page a expiré. Recommencez depuis le début.';
    } elseif (trim((string) ($_POST['societe'] ?? '')) !== '') {
        // Piège à robots : on affiche la confirmation sans rien enregistrer.
        $sent = true;
    } elseif (!annuaire_ext_rate_limit($ip)) {
        $errors[] = 'Trop de demandes ont été envoyées depuis votre connexion. Réessayez dans une heure.';
    } elseif ($action === 'scan') {
        $scan = annuaire_ext_scan($input['url']);
        if (!$scan) {
            $errors[] = "Impossible de lire cette adresse. Vérifiez qu'elle répond (https://…) et que la page d'accueil est accessible.";
        } else {
            $existing = annuaire_ext_find($input['url']);
            if ($existing && $existing['status'] !== 'rejected') {
                $errors[] = $existing['status'] === 'approved' ? 'Ce site figure déjà dans l’annuaire.' : 'Une demande est déjà en cours pour ce site.';
            }
        }
    } elseif ($action === 'submit') {
        $existing = annuaire_ext_find($input['url']);
        if ($existing && $existing['status'] !== 'rejected') {
            $errors[] = $existing['status'] === 'approved' ? 'Ce site figure déjà dans l’annuaire.' : 'Une demande est déjà en cours pour ce site.';
        } else {
            $result = annuaire_ext_submit($input, $input);
            if (!$result['ok']) {
                $errors[] = $result['error'];
            } else {
                annuaire_ext_notify($result['record']);
                unset($_SESSION['annuaire_ext_csrf']);
                header('Location: /annuaire/inscrire.php?recu=1');
                exit;
            }
        }
    }
}

// Pré-remplissage : fiche lue, puis corrections de l'internaute.
$draft = $scan ? array_merge($scan, array_filter($input, static fn ($v) => $v !== '' && $v !== [])) : $input;
$draft['url'] = $scan['final_url'] ?? $input['url'];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, follow">
<title>Inscrire mon site à l’annuaire — <?= saas_e($cfg['name']) ?></title>
<meta name="description" content="Votre commerce a déjà un site ? Inscrivez-le gratuitement à l’annuaire et ajoutez vos pièces aux galeries.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&family=Archivo:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/saas.css">
<style>:root { --accent: <?= saas_e($cfg['accent']) ?>; --accent-ink: <?= saas_e(saas_ink_on($cfg['accent'])) ?>; }</style>
</head>
<body>
<a class="skip" href="#contenu">Aller au contenu</a>
<header class="site-head"><div class="wrap bar">
  <a class="brand" href="/"><span class="mark" aria-hidden="true"><?= saas_e(mb_strtoupper(mb_substr($cfg['name'], 0, 1))) ?></span><?= saas_e($cfg['name']) ?></a>
  <nav aria-label="Navigation principale">
    <a href="/annuaire/">Annuaire</a>
    <a href="/galerie/">Galeries</a>
    <a class="cta" href="/inscription/">Créer ma boutique</a>
  </nav>
</div></header>
<main id="contenu"><div class="wrap">
  <div class="page-title">
    <p class="eyebrow">Annuaire</p>
    <h1><?= $sent ? 'Demande envoyée' : 'Mon commerce a déjà un site' ?></h1>
    <p class="lede"><?= $sent ? 'Merci ! Nous vérifions votre fiche avant de la publier.' : 'Inscrivez votre site existant à l’annuaire : la page d’accueil est lue pour préremplir la fiche, vous corrigez, nous validons. Aucune boutique à recréer.' ?></p>
  </div>

  <?php foreach ($errors as $error): ?><div class="notice error" role="alert"><?= saas_e($error) ?></div><?php endforeach; ?>

  <?php if ($sent): ?>
    <div class="form-card">
      <h2 style="margin-top:0">Votre demande est enregistrée</h2>
      <p>L’exploitant de <?= saas_e($cfg['name']) ?> vérifie la fiche, puis elle apparaît dans <a href="/annuaire/">l’annuaire</a>. Vous recevrez une réponse à l’adresse indiquée.</p>
      <p><a class="btn" href="/annuaire/">Voir l’annuaire</a> <a class="btn ghost" href="/annuaire/inscrire.php">Inscrire un autre site</a></p>
    </div>
  <?php elseif (!$scan): ?>
    <form class="form-card" method="post" action="/annuaire/inscrire.php" novalidate>
      <input type="hidden" name="csrf" value="<?= saas_e(annuaire_ext_csrf()) ?>">
      <input type="hidden" name="action" value="scan">
      <div class="form-grid">
        <label class="field full"><span>Adresse de votre site</span>
          <input type="url" name="url" required maxlength="500" placeholder="https://www.mon-commerce.fr" value="<?= saas_e($input['url']) ?>" autocomplete="url">
          <small>Shopify, WooCommerce, PrestaShop, Wix… ou tout autre site en ligne.</small>
        </label>
        <label class="field"><span>Votre nom</span><input type="text" name="contact_name" maxlength="60" value="<?= saas_e($input['contact_name']) ?>" autocomplete="name"></label>
        <label class="field"><span>Votre e-mail</span><input type="email" name="contact_email" required maxlength="120" value="<?= saas_e($input['contact_email']) ?>" autocomplete="email"></label>
        <label class="field full" aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden">
          <span>Société</span><input type="text" name="societe" tabindex="-1" autocomplete="off">
        </label>
      </div>
      <p style="margin:18px 0 0"><button class="btn" type="submit">Lire mon site</button></p>
      <p><small>Gratuit, sans création de compte. La page d’accueil est lue une fois (adresse, téléphone, images) pour vous éviter la saisie.</small></p>
    </form>
  <?php else: ?>
    <form class="form-card" method="post" action="/annuaire/inscrire.php" novalidate>
      <input type="hidden" name="csrf" value="<?= saas_e(annuaire_ext_csrf()) ?>">
      <input type="hidden" name="action" value="submit">
      <input type="hidden" name="url" value="<?= saas_e($draft['url']) ?>">
      <input type="hidden" name="logo" value="<?= saas_e($draft['logo'] ?? '') ?>">
      <input type="hidden" name="image" value="<?= saas_e($draft['image'] ?? '') ?>">
      <input type="hidden" name="platform" value="<?= saas_e($draft['platform'] ?? 'generic') ?>">
      <input type="hidden" name="images" value="<?= saas_e(implode("\n", (array) ($draft['images'] ?? []))) ?>">
      <input type="hidden" name="socials" value="<?= saas_e(implode("\n", (array) ($draft['socials'] ?? []))) ?>">
      <input type="hidden" name="societe" value="">

      <p class="notice ok" style="display:flex;gap:14px;align-items:center">
        <?php if (!empty($draft['image'])): ?><img src="<?= saas_e($draft['image']) ?>" alt="" style="width:84px;height:84px;object-fit:cover;border-radius:12px" loading="lazy"><?php endif; ?>
        <span>Page lue : <strong><?= saas_e(parse_url($draft['url'], PHP_URL_HOST) ?: $draft['url']) ?></strong><br>
        Solution reconnue : <?= saas_e(annuaire_ext_platform_label((string) ($draft['platform'] ?? 'generic'))) ?>
        <?php if (!empty($draft['feeds'][$draft['platform'] ?? ''])): ?> · catalogue lisible pour les promos<?php endif; ?></span>
      </p>

      <div class="form-grid">
        <label class="field full"><span>Nom du commerce</span><input type="text" name="name" required maxlength="90" value="<?= saas_e($draft['name'] ?? '') ?>"></label>
        <label class="field full"><span>Accroche</span><input type="text" name="tagline" maxlength="160" value="<?= saas_e($draft['tagline'] ?? '') ?>"></label>
        <label class="field full"><span>Description</span><textarea name="description" maxlength="400"><?= saas_e($draft['description'] ?? '') ?></textarea></label>
        <label class="field"><span>Type de commerce</span><input type="text" name="universes" maxlength="120" placeholder="Brocante, décoration, livres anciens…" value="<?= saas_e($draft['universes'] ?? '') ?>">
          <small>Visible dans les filtres de l’annuaire (3 mots-clés au plus, séparés par des virgules).</small></label>
        <label class="field"><span>Téléphone</span><input type="tel" name="phone" maxlength="40" value="<?= saas_e($draft['phone'] ?? '') ?>" autocomplete="tel"></label>
        <label class="field full"><span>Adresse</span><input type="text" name="address" maxlength="160" value="<?= saas_e($draft['address'] ?? '') ?>" autocomplete="street-address"></label>
        <label class="field full"><span>Horaires</span><input type="text" name="hours" maxlength="200" value="<?= saas_e($draft['hours'] ?? '') ?>"></label>
        <label class="field"><span>Votre nom</span><input type="text" name="contact_name" maxlength="60" value="<?= saas_e($draft['contact_name'] ?? '') ?>" autocomplete="name"></label>
        <label class="field"><span>Votre e-mail</span><input type="email" name="contact_email" required maxlength="120" value="<?= saas_e($draft['contact_email'] ?? '') ?>" autocomplete="email"></label>
      </div>
      <p style="margin:18px 0 0"><button class="btn" type="submit">Envoyer ma fiche</button> <a class="btn ghost" href="/annuaire/inscrire.php">Changer d’adresse</a></p>
      <p><small>Votre fiche sera publiée après vérification. Vous pourrez aussi rattacher vos articles aux promos d’une galerie depuis le portail.</small></p>
    </form>
  <?php endif; ?>
</div></main>
<footer class="site-foot"><div class="wrap foot">
  <div><strong><?= saas_e($cfg['name']) ?></strong><br><span><?= saas_e($cfg['tagline']) ?></span></div>
  <nav aria-label="Pied de page"><a href="/">Accueil</a><a href="/annuaire/">Annuaire</a><a href="/galerie/">Galeries</a><a href="/inscription/">Créer ma boutique</a><?php if ($cfg['contact'] !== ''): ?><a href="mailto:<?= saas_e($cfg['contact']) ?>">Contact</a><?php endif; ?></nav>
</div></footer>
</body>
</html>