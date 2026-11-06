<?php
// Télécharge le dossier extension/ en ZIP (chargement manuel dans chrome://extensions).
defined('PLUGIN_RUNTIME') || exit;

$dir = realpath(__DIR__ . '/../extension');
if ($dir === false || !is_dir($dir)) plugin_json(['error' => 'Extension introuvable sur le serveur.'], 404);

if (!class_exists('ZipArchive')) {
    plugin_json(['error' => 'ZipArchive indisponible sur ce serveur : chargez le dossier packs/annonces-marche/extension en mode développeur.'], 501);
}

$tmp = tempnam(sys_get_temp_dir(), 'am-ext-');
@unlink($tmp);
$zipPath = $tmp . '.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    plugin_json(['error' => 'Impossible de créer l\'archive.'], 500);
}

$root = 'annonces-marche-extension';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iterator as $file) {
    $path = $file->getPathname();
    $rel = substr($path, strlen($dir) + 1);
    if ($rel === false || $rel === '') continue;
    $rel = str_replace('\\', '/', $rel);
    if ($file->isDir()) $zip->addEmptyDir("$root/$rel");
    else $zip->addFile($path, "$root/$rel");
}
$zip->close();

header_remove('Content-Type');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="annonces-marche-extension.zip"');
header('Content-Length: ' . (string) filesize($zipPath));
header('Cache-Control: no-store');
readfile($zipPath);
@unlink($zipPath);
exit;
