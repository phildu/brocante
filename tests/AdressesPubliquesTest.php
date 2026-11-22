<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/adresses-publiques.php';

final class AdressesPubliquesTest extends TestCase
{
    private const G = ['lespuces' => 'https://aux-puces.com/de-villeurbanne'];
    private const B = ['petit-chalet' => 'https://petit-chalet.aux-puces.com/de-villeurbanne'];

    public function testCibleGalerieEtBoutique(): void
    {
        $this->assertSame(['type' => 'galerie', 'slug' => 'lespuces'], adresse_publique_cible('aux-puces.com', '/de-villeurbanne', self::G, self::B));
        $this->assertSame(['type' => 'galerie', 'slug' => 'lespuces'], adresse_publique_cible('www.Aux-Puces.com:443', '/de-villeurbanne/', self::G, self::B));
        $this->assertSame(['type' => 'boutique', 'slug' => 'petit-chalet'], adresse_publique_cible('petit-chalet.aux-puces.com', '/de-villeurbanne', self::G, self::B));
        $this->assertNull(adresse_publique_cible('www.bricbrok.com', '/de-villeurbanne', self::G, self::B));
        $this->assertNull(adresse_publique_cible('aux-puces.com', '/', self::G, self::B));
        $this->assertNull(adresse_publique_cible('aux-puces.com', '/de-villeurbanne/x', self::G, self::B));
    }

    public function testAnciennesAdressesRedirigentEnProduction(): void
    {
        $this->assertSame('https://aux-puces.com/de-villeurbanne', adresse_redirection('www.bricbrok.com', '/galerie/lespuces/', self::G, self::B));
        $this->assertSame('https://aux-puces.com/de-villeurbanne?x=1', adresse_redirection('aux-puces.com', '/galerie/lespuces?x=1', self::G, self::B));
        $this->assertSame('https://petit-chalet.aux-puces.com/de-villeurbanne', adresse_redirection('www.bricbrok.com', '/petit-chalet/', self::G, self::B));
        // Racine du domaine de la boutique → son accueil choisi.
        $this->assertSame('https://petit-chalet.aux-puces.com/de-villeurbanne', adresse_redirection('petit-chalet.aux-puces.com', '/', self::G, self::B));
    }

    public function testPasDeRedirectionAilleurs(): void
    {
        // Préprod et local : anciennes adresses conservées.
        $this->assertSame('', adresse_redirection('pre.bricbrok.com', '/galerie/lespuces/', self::G, self::B));
        $this->assertSame('', adresse_redirection('brocenstock.test', '/petit-chalet/', self::G, self::B));
        // Autres galeries, autres pages de la boutique, nouvelles adresses elles-mêmes : rien.
        $this->assertSame('', adresse_redirection('www.bricbrok.com', '/galerie/autre/', self::G, self::B));
        $this->assertSame('', adresse_redirection('www.bricbrok.com', '/petit-chalet/produit.php?ref=1', self::G, self::B));
        $this->assertSame('', adresse_redirection('aux-puces.com', '/de-villeurbanne', self::G, self::B));
        $this->assertSame('', adresse_redirection('petit-chalet.aux-puces.com', '/de-villeurbanne', self::G, self::B));
        $this->assertSame('', adresse_redirection('aux-puces.com', '/', self::G, self::B));
    }

    public function testBoutiquesMembresRecoiventLeurAdresse(): void
    {
        $b = adresse_boutiques(['lespuces' => ['petit-chalet', 'la-fripe-a-naty', 'avec_souligne']]);
        $this->assertSame('https://petit-chalet.aux-puces.com/de-villeurbanne', $b['petit-chalet']);
        $this->assertSame('https://la-fripe-a-naty.aux-puces.com/de-villeurbanne', $b['la-fripe-a-naty']);
        $this->assertArrayNotHasKey('avec_souligne', $b);   // « _ » interdit dans un nom de domaine
        $this->assertSame(['type' => 'boutique', 'slug' => 'la-fripe-a-naty'], adresse_publique_cible('la-fripe-a-naty.aux-puces.com', '/de-villeurbanne', self::G, $b));
        $this->assertSame('https://la-fripe-a-naty.aux-puces.com/de-villeurbanne', adresse_redirection('www.bricbrok.com', '/la-fripe-a-naty/', self::G, $b));
        $this->assertContains('aux-puces.com', adresse_domaines_boutiques());
    }

    public function testSousDomaineInconnuVersLaGalerie(): void
    {
        $this->assertSame('https://aux-puces.com/de-villeurbanne', adresse_redirection('inconnu.aux-puces.com', '/produit.php', self::G, self::B));
        $this->assertSame('', adresse_redirection('petit-chalet.aux-puces.com', '/produit.php', self::G, self::B));
    }

    public function testCheminsDeclares(): void
    {
        // Le .htaccess route ces chemins vers index.php : la liste doit le suivre.
        $htaccess = (string) file_get_contents(__DIR__ . '/../.htaccess');
        foreach (adresse_chemins() as $path) {
            $this->assertStringContainsString('RewriteRule ^' . ltrim($path, '/') . '/?$ index.php', $htaccess, $path);
        }
    }
}
