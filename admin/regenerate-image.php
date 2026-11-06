<?php
// Régénère par IA une image du site (bandeau ou photo du diaporama). Chaque génération réussie est décomptée du crédit IA de la boutique.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/autofeed.php';
require_admin();

$ajax = ($_POST['ajax'] ?? '') === '1';
$reply = static function (bool $ok, string $message, string $path = '') use ($ajax): void {
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message, 'path' => $path], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash_set($message, $ok ? 'ok' : 'error');
    header('Location: ' . ['hero' => '/admin/hero.php', 'banners' => '/admin/banners.php', 'logos' => '/admin/appearance.php#logos'][$_POST['back'] ?? ''] ?? '/admin/index.php');
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php');
    exit;
}

$target = (string) ($_POST['target'] ?? '');
$hint = trim((string) ($_POST['hint'] ?? ''));
$message = '';
@set_time_limit(120);

/** Libellé d'une déclinaison de logo pour la médiathèque. */
$logoMediaLabel = static function (string $variant): string {
    $title = defined('LOGO_VARIANTS') ? (string) (LOGO_VARIANTS[$variant][0] ?? $variant) : $variant;
    $siteName = trim((string) (get_content()['site_name'] ?? ''));
    return 'Logo ' . mb_strtolower($title) . ($siteName !== '' ? ' — ' . $siteName : '');
};
/** Range les logos composés dans la médiathèque (une ligne par déclinaison). */
$registerLogos = static function (array $paths, string $source = 'Logo IA') use ($logoMediaLabel): void {
    foreach ($paths as $variant => $variantPath) {
        media_library_register((string) $variantPath, $logoMediaLabel((string) $variant), $source, 'logo');
    }
};

try {
    if ($target === 'hero') {
        $path = autofeed_regenerate('hero', $hint);
        db()->prepare('UPDATE content SET hero_photo = ? WHERE id = 1')->execute([$path]);
        media_library_register($path, 'Photo de couverture IA', 'Couverture IA', 'couverture, ia');
    } elseif ($target === 'slide') {
        $id = (int) ($_POST['id'] ?? 0);
        $exists = db()->prepare('SELECT 1 FROM hero_slides WHERE id = ?');
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) throw new RuntimeException('Photo introuvable dans le diaporama.');
        $path = autofeed_regenerate('hero', $hint);
        db()->prepare('UPDATE hero_slides SET path = ? WHERE id = ?')->execute([$path, $id]);
        media_library_register($path, "Slide IA — page d'accueil", 'Diaporama accueil IA', 'diaporama, ia, slide');
    } elseif ($target === 'hero_slides') {
        // Compteur de la page Diaporama hero : plusieurs slides d'un coup.
        $count = max(1, min(10, (int) ($_POST['count'] ?? 1)));
        $generated = 0;
        $errors = [];
        @set_time_limit(600);
        for ($i = 0; $i < $count; $i++) {
            try {
                $slidePath = autofeed_regenerate('hero', $hint);
            } catch (Throwable $e) {
                $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'La génération a échoué.';
                break;
            }
            add_hero_slide($slidePath);
            media_library_register($slidePath, "Slide IA — page d'accueil" . ($count > 1 ? ' n°' . ($i + 1) : ''), 'Diaporama accueil IA', 'diaporama, ia, slide');
            $generated++;
            $path = $slidePath;
        }
        if ($generated === 0) throw new RuntimeException($errors[0] ?? 'Aucune image n’a pu être générée : rien n’a été débité.');
        $message = $generated . ' slide' . ($generated > 1 ? 's' : '') . ' générée' . ($generated > 1 ? 's' : '')
            . ' et ajoutée' . ($generated > 1 ? 's' : '') . ' au diaporama et à la médiathèque ('
            . ai_format_eur($generated * autofeed_regen_cost_eur()) . ' décomptés de votre crédit IA).';
        if ($generated < $count) {
            $message .= ' ' . ($count - $generated) . ' sur ' . $count . ' n’ont pas pu être générées : ' . ($errors[0] ?? 'erreur inconnue');
        }
    } elseif ($target === 'banner') {
        $pageKey = (string) ($_POST['page_key'] ?? '');
        if (!array_key_exists($pageKey, page_banner_targets())) throw new RuntimeException('Page inconnue.');
        $prompt = (string) ($_POST['prompt'] ?? '');
        $path = autofeed_regenerate('hero', '', $prompt);
        $existing = get_page_banner($pageKey);
        set_page_banner($pageKey, 'image', $path, $existing ? (int) $existing['overlay'] : 40);
        $shared = banner_library_store(dirname(__DIR__) . '/' . $path, $prompt, $pageKey);
        media_library_register($path, 'Bandeau IA — ' . (page_banner_targets()[$pageKey] ?? $pageKey), 'Bandeau IA', 'bandeau, ia');
        $message = 'Bandeau généré (' . ai_format_eur(autofeed_regen_cost_eur()) . ' décomptés de votre crédit IA) et ajouté à la médiathèque. '
            . ($shared !== null ? 'Il rejoint aussi la médiathèque commune et pourra resservir sur une autre page ou un autre site.' : '');
    } elseif ($target === 'logo') {
        $generated = autofeed_generate_logos((string) ($_POST['prompt'] ?? ''));
        if (!$generated['paths']) {
            throw new RuntimeException('Aucune des trois déclinaisons n’a pu être générée (clé refusée, crédit épuisé ou quota) : rien n’a été débité.');
        }
        $registerLogos($generated['paths']);
        $count = count($generated['paths']);
        $path = $generated['paths']['horizontal'] ?? reset($generated['paths']);
        $message = 'Symbole généré et composé en ' . $count . ' déclinaison' . ($count > 1 ? 's' : '')
            . ' avec le nom de la boutique (' . ai_format_eur(autofeed_regen_cost_eur()) . ' décomptés de votre crédit IA). '
            . 'Le symbole rejoint la médiathèque partagée et les logos la médiathèque de la boutique.'
            . ($generated['failed'] ? ' Échec pour : ' . implode(', ', $generated['failed']) . ' — vous pouvez réessayer.' : '');
    } elseif ($target === 'logo_from_library') {
        $symbol = (string) ($_POST['symbol'] ?? '');
        $abs = logo_library_abs($symbol);
        if ($abs === null) throw new RuntimeException('Symbole introuvable dans la médiathèque.');
        if (!logo_library_claim($symbol)) throw new RuntimeException('Ce symbole a déjà été utilisé : un logo ne peut servir qu’à une seule boutique. Générez-en un nouveau.');
        $composed = logo_compose_all($abs);
        if (!$composed['paths']) throw new RuntimeException('Aucune déclinaison n’a pu être composée.');
        $registerLogos($composed['paths'], 'Logo (médiathèque)');
        $count = count($composed['paths']);
        $path = $composed['paths']['horizontal'] ?? reset($composed['paths']);
        $message = $count . ' déclinaison' . ($count > 1 ? 's' : '') . ' composée' . ($count > 1 ? 's' : '')
            . ' depuis la médiathèque, sans crédit IA, et ajoutée' . ($count > 1 ? 's' : '') . ' à la médiathèque de la boutique.'
            . ($composed['failed'] ? ' Échec pour : ' . implode(', ', $composed['failed']) . '.' : '');
    } elseif ($target === 'logo_improve') {
        $variant = (string) ($_POST['variant'] ?? '');
        $path = logo_improve_with_ai($variant, (string) ($_POST['prompt'] ?? ''));
        media_library_register($path, $logoMediaLabel($variant), 'Logo IA', 'logo, ia');
    } else {
        throw new RuntimeException('Image inconnue.');
    }
} catch (Throwable $e) {
    $reply(false, $e instanceof RuntimeException ? $e->getMessage() : 'La génération a échoué, rien n’a été débité. Réessayez.');
}
$reply(true, $message !== '' ? $message : 'Nouvelle image générée (' . ai_format_eur(autofeed_regen_cost_eur()) . ' décomptés de votre crédit IA).', $path);
