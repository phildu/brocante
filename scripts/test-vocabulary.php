<?php

// Test du vocabulaire des extensions (includes/vocabulary.php) : mots, articles, élision, accords, enregistrement, portées.
// Usage : php scripts/test-vocabulary.php — n'écrit que dans un fichier temporaire.

$tmp = sys_get_temp_dir() . '/brocante-test-vocab-' . getmypid() . '.json';
putenv('BROCANTE_VOCAB_FILE=' . $tmp);
register_shutdown_function(fn() => is_file($tmp) && unlink($tmp));
require_once __DIR__ . '/../includes/vocabulary.php';

$checks = 0;
$failures = 0;
function check(string $label, $actual, $expected): void
{
    global $checks, $failures;
    $checks++;
    if ($actual === $expected) { echo "  ✓ $label\n"; return; }
    $failures++;
    echo "  ✗ $label\n      attendu : " . var_export($expected, true) . "\n      obtenu  : " . var_export($actual, true) . "\n";
}

echo "Valeurs d'origine (aucun fichier)\n";
check('singulier', ext_word('singular'), 'extension');
check('pluriel', ext_word('plural'), 'extensions');
check('majuscule', ext_word('singular', true), 'Extension');
check('« {Ext} activé{e} »', ext_t('{Ext} activé{e}'), 'Extension activée');
check('« {Exts} » en titre', ext_t('{Exts} · nom et portée'), 'Extensions · nom et portée');
check('« {un ext} »', ext_t('Ajouter {un ext}'), 'Ajouter une extension');
check('« {le ext} » élidé', ext_t('Résilier {le ext}'), 'Résilier l’extension');
check('« {ce ext} »', ext_t('{Ce ext} est incluse'), 'Cette extension est incluse');
check('« {du ext} » élidé', ext_t('Le prix {du ext}'), 'Le prix de l’extension');
check('« {les exts} »', ext_t('Toutes {les exts}'), 'Toutes les extensions');
check('« {des exts} »', ext_t('La liste {des exts}'), 'La liste des extensions');
check('accord au pluriel « proposé{es} »', ext_t('{Exts} proposé{es}'), 'Extensions proposées');
check('choix selon le genre « {?inclus|incluse} »', ext_t('{Ext} {?inclus|incluse}'), 'Extension incluse');
check('pronom « {?ils|elles} »', ext_t('si {?ils|elles} sont'), 'si elles sont');
check('texte sans jeton inchangé', ext_t('Aucun jeton ici { ext } {CHECKOUT_SESSION_ID}'), 'Aucun jeton ici { ext } {CHECKOUT_SESSION_ID}');

echo "\nLes six mots proposés\n";
$expected = [
    'extension' => ['Une extension activée', 'l’extension', 'cette extension', 'de l’extension', 'extensions'],
    'option' => ['Une option activée', 'l’option', 'cette option', 'de l’option', 'options'],
    'plugin' => ['Un plugin activé', 'le plugin', 'ce plugin', 'du plugin', 'plugins'],
    'module' => ['Un module activé', 'le module', 'ce module', 'du module', 'modules'],
    'complement' => ['Un complément activé', 'le complément', 'ce complément', 'du complément', 'compléments'],
    'fonction' => ['Une fonction activée', 'la fonction', 'cette fonction', 'de la fonction', 'fonctions'],
];
foreach (vocab_presets() as $key => [$sing, $plur, $gender]) {
    check("enregistrer « $sing »", vocab_save(['singular' => $sing, 'plural' => $plur, 'gender' => $gender]), null);
    [$un, $le, $ce, $du, $pl] = $expected[$key];
    check("$key : {Un ext} activé{e}", ext_t('{Un ext} activé{e}'), $un);
    check("$key : {le ext}", ext_t('{le ext}'), $le);
    check("$key : {ce ext}", ext_t('{ce ext}'), $ce);
    check("$key : {du ext}", ext_t('{du ext}'), $du);
    check("$key : {au ext}", ext_t('{au ext}'), [
        'extension' => 'à l’extension', 'option' => 'à l’option', 'plugin' => 'au plugin', 'module' => 'au module', 'complement' => 'au complément', 'fonction' => 'à la fonction',
    ][$key]);
    check("$key : {exts}", ext_t('{exts}'), $pl);
    check("$key : {?inclus|incluse}", ext_t('{?inclus|incluse}'), $gender === 'f' ? 'incluse' : 'inclus');
    check("$key : proposé{es}", ext_t('{exts} proposé{es}'), $pl . (vocab_load()['gender'] === 'f' ? ' proposées' : ' proposés'));
}

echo "\nMot libre et validation\n";
check('mot libre « add-on »', vocab_save(['singular' => 'add-on', 'plural' => 'add-ons', 'gender' => 'm']), null);
check('relu : « un add-on »', ext_t('{un ext}'), 'un add-on');
check('élision devant « h »', (vocab_save(['singular' => 'hôte', 'plural' => 'hôtes', 'gender' => 'm']) ?? '') . ext_t('{le ext}'), 'l’hôte');
check('apostrophe droite -> typographique', (vocab_save(['singular' => "s'extension", 'plural' => "s'extensions", 'gender' => 'f']) ?? '') . ext_word(), 's’extension');
check('refus d\'un mot vide', is_string(vocab_save(['singular' => '', 'plural' => 'x', 'gender' => 'f'])), true);
check('refus de balises', is_string(vocab_save(['singular' => '<b>plugin</b>', 'plural' => 'plugins', 'gender' => 'm'])), true);
check('refus d\'un mot trop long', is_string(vocab_save(['singular' => str_repeat('a', 31), 'plural' => 'x', 'gender' => 'f'])), true);
check('refus d\'un chiffre', is_string(vocab_save(['singular' => 'plugin2', 'plural' => 'plugins', 'gender' => 'm'])), true);
check('le dernier mot valide est conservé après un refus', ext_word(), 's’extension');

echo "\nPortées\n";
vocab_save(['singular' => 'extension', 'plural' => 'extensions', 'gender' => 'f',
    'scopes' => ['buvette' => 'gallery', 'bourse' => 'both', 'carte' => 'gallery', 'x y' => 'both', 'zzz' => 'inconnue']],
    ['carte' => 'gallery', 'buvette' => 'shop']);
check('portée d\'origine d\'un plugin de boutique', ext_scope('registre-police'), 'shop');
check('portée d\'origine d\'une option de galerie', ext_scope('organisateur', 'gallery'), 'gallery');
check('portée modifiée (boutique -> galerie)', ext_scope('buvette'), 'gallery');
check('portée « les deux »', ext_scope('bourse'), 'both');
check('portée identique à l\'origine non conservée', array_key_exists('carte', vocab_load()['scopes']), false);
check('identifiant invalide ignoré', array_key_exists('x y', vocab_load()['scopes']), false);
check('portée invalide ignorée', array_key_exists('zzz', vocab_load()['scopes']), false);
check('étiquette', ext_scope_label('both'), 'Les deux');
check('pastille échappée', str_contains(ext_scope_badge('gallery'), '>Galerie</span>'), true);

echo "\n" . ($failures === 0 ? "OK : $checks contrôles réussis.\n" : "ÉCHEC : $failures sur $checks.\n");
exit($failures === 0 ? 0 : 1);
