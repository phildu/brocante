<?php
// Univers (rayons) de la boutique : enregistrement, retour aux univers d'origine, propositions de l'IA.
//   POST save    → key[], label[], icon[] (le formulaire de admin/universes.php)
//   POST reset   → revient aux univers du fichier du commerce
//   POST suggest → JSON : univers proposés par l'IA d'après la boutique et son catalogue
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/universes.php');
    exit;
}
$action = (string) ($_POST['action'] ?? '');

if ($action === 'suggest') {
    header('Content-Type: application/json; charset=utf-8');
    if (!GEMINI_API_KEY) {
        echo json_encode(['ok' => false, 'error' => 'Clé Gemini non configurée (Réglages du site).']);
        exit;
    }
    session_write_close();
    @set_time_limit(90);
    $found = universes_suggest(mb_substr((string) ($_POST['hint'] ?? ''), 0, 200));
    echo json_encode($found
        ? ['ok' => true, 'profile' => $found['profile'], 'universes' => $found['universes'], 'sectors' => $found['sectors'], 'source' => $found['source'], 'seen' => $found['seen']]
        : ['ok' => false, 'error' => "L'IA n'a pas pu répondre (service surchargé ?) : réessayez dans un instant. Si cela persiste, décrivez votre boutique dans le champ « Ce que vend votre boutique » puis cliquez sur « Proposer des univers »."], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($action === 'save') {
        $rows = universes_normalize((array) ($_POST['key'] ?? []), (array) ($_POST['label'] ?? []), (array) ($_POST['icon'] ?? []));
        universes_save($rows);
        if (isset($_POST['profile'])) shop_profile_save((string) $_POST['profile']);
        $orphans = count(universes_orphan_refs());
        flash_set('Univers enregistrés (' . count($rows) . ').' . ($orphans ? " $orphans pièce(s) ont un univers qui n'existe plus : reclassez-les ci-dessous." : ''));
    } elseif ($action === 'reset') {
        universes_reset();
        flash_set("Univers d'origine rétablis.");
    }
} catch (InvalidArgumentException $e) {
    flash_set($e->getMessage(), 'error');
}
header('Location: /admin/universes.php');
exit;
