<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/gallery-admin.php';

/** Adresse de connexion de l'administration d'une galerie : propre à chaque galerie, générique en dernier recours. */
final class GalleryLoginUrlTest extends TestCase
{
    public function testEachGalleryHasItsOwnLoginAddress(): void
    {
        self::assertSame('/galerie/rue-des-arts/admin', ga_login_url('rue-des-arts'));
        self::assertSame('/galerie/rc-vide-grenier-test/admin', ga_login_url('  RC-Vide-Grenier-Test '));
    }

    public function testWithoutAValidSlugItFallsBackToTheGenericLogin(): void
    {
        self::assertSame('/galerie/admin.php', ga_login_url());
        self::assertSame('/galerie/admin.php', ga_login_url(null));
        self::assertSame('/galerie/admin.php', ga_login_url(''));
        // Un identifiant invalide ne doit jamais finir dans l'adresse (traversée de chemin, caractères libres).
        self::assertSame('/galerie/admin.php', ga_login_url('../portail'));
        self::assertSame('/galerie/admin.php', ga_login_url('a/b'));
        self::assertSame('/galerie/admin.php', ga_login_url('x"><script>'));
    }
}
