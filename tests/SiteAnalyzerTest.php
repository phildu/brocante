<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/site-analyzer.php';

/**
 * Lecture d'une page d'accueil : validation d'adresse, extraction JSON-LD / meta, solution e-commerce reconnue.
 * Aucune requête réseau n'est faite ici.
 */
final class SiteAnalyzerTest extends TestCase
{
    public function testValidatesOnlyPublicHttpAddresses(): void
    {
        self::assertTrue(site_analyzer_url_valid('https://www.brocante-exemple.fr/'));
        self::assertTrue(site_analyzer_url_valid('http://exemple.com'));
        self::assertFalse(site_analyzer_url_valid('ftp://exemple.com'));
        self::assertFalse(site_analyzer_url_valid('javascript:alert(1)'));
        self::assertFalse(site_analyzer_url_valid('https://user:pass@exemple.com'));
        self::assertFalse(site_analyzer_url_valid('https://pas un domaine'));
        self::assertFalse(site_analyzer_url_valid(''));
    }

    public function testResolvesRelativeLinks(): void
    {
        self::assertSame('https://exemple.fr/a/b', site_analyzer_resolve_url('https://exemple.fr/a/page', 'b'));
        self::assertSame('https://exemple.fr/img/logo.png', site_analyzer_resolve_url('https://exemple.fr/a/page', '/img/logo.png'));
        self::assertSame('https://cdn.exemple.fr/x.jpg', site_analyzer_resolve_url('https://exemple.fr/', 'https://cdn.exemple.fr/x.jpg'));
        self::assertSame('https://exemple.fr/a/?x=1', site_analyzer_resolve_url('https://exemple.fr/a/', '?x=1'));
        self::assertSame('', site_analyzer_resolve_url('https://exemple.fr/', 'javascript:void(0)'));
        self::assertSame('', site_analyzer_resolve_url('https://exemple.fr/', '#ancre'));
    }

    public function testExtractsBusinessFromJsonLd(): void
    {
        $html = <<<'HTML'
<!doctype html><html><head>
<title>Ma Brocante</title>
<meta name="description" content="Meubles anciens et objets chinés.">
<meta property="og:image" content="/img/hero.jpg">
<meta name="theme-color" content="#B5502E">
<link rel="apple-touch-icon" href="/img/icone.png">
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"LocalBusiness","name":"Ma Brocante",
 "description":"Meubles anciens et objets chinés à Lyon.",
 "telephone":"+33 4 78 00 00 00","email":"bonjour@ma-brocante.fr",
 "address":{"@type":"PostalAddress","streetAddress":"12 rue des Arts","postalCode":"69001","addressLocality":"Lyon"},
 "openingHoursSpecification":[{"@type":"OpeningHoursSpecification","dayOfWeek":["https://schema.org/Tuesday"],"opens":"10:00","closes":"18:30"}],
 "sameAs":["https://www.instagram.com/ma-brocante","javascript:alert(1)"],
 "logo":{"@type":"ImageObject","url":"/img/logo.png"}}
</script>
</head><body><address>12 rue des Arts, 69001 Lyon</address></body></html>
HTML;
        $p = site_analyzer_extract($html, 'https://www.ma-brocante.fr/');
        self::assertSame('Ma Brocante', $p['name']);
        self::assertStringContainsString('Meubles anciens', $p['description']);
        self::assertSame('+33 4 78 00 00 00', $p['phone']);
        self::assertSame('bonjour@ma-brocante.fr', $p['email']);
        self::assertStringContainsString('12 rue des Arts', $p['address']);
        self::assertStringContainsString('Tuesday', $p['hours']);
        self::assertStringContainsString('10:00', $p['hours']);
        self::assertSame(['https://www.instagram.com/ma-brocante'], $p['socials']);
        self::assertSame('#b5502e', $p['accent']);
        self::assertSame('https://www.ma-brocante.fr/img/logo.png', $p['logo']);
        self::assertSame('https://www.ma-brocante.fr/img/hero.jpg', $p['image']);
        self::assertContains('https://www.ma-brocante.fr/img/hero.jpg', $p['images']);
    }

    public function testFallsBackToMetaAndTelLinks(): void
    {
        $html = '<html><head><title>Le Petit Chalet</title><meta property="og:site_name" content="Le Petit Chalet">'
            . '<meta property="og:description" content="Chalet et décoration de montagne.">'
            . '<link rel="icon" href="/favicon.ico"></head><body><a href="tel:+33612345678">Appeler</a>'
            . '<a href="mailto:contact@chalet.fr?subject=Bonjour">Écrire</a></body></html>';
        $p = site_analyzer_extract($html, 'https://chalet.fr/');
        self::assertSame('Le Petit Chalet', $p['name']);
        self::assertSame('Chalet et décoration de montagne.', $p['tagline']);
        self::assertSame('+33612345678', $p['phone']);
        self::assertSame('contact@chalet.fr', $p['email']);
        self::assertSame('https://chalet.fr/favicon.ico', $p['logo']);
        self::assertSame('', $p['image']); // aucun og:image : le favicon sert de logo, pas d'illustration
    }

    public function testRecognisesEcommerceSolutions(): void
    {
        self::assertSame('shopify', site_analyzer_platform('<script src="https://cdn.shopify.com/s/files/x.js"></script>', 'https://exemple.fr/'));
        self::assertSame('woocommerce', site_analyzer_platform('<body class="woocommerce-page">', 'https://exemple.fr/'));
        self::assertSame('prestashop', site_analyzer_platform('<script>var prestashop = {};</script>', 'https://exemple.fr/'));
        self::assertSame('wix', site_analyzer_platform('<img src="https://static.wixstatic.com/x.jpg">', 'https://exemple.fr/'));
        self::assertSame('generic', site_analyzer_platform('<p>Bonjour</p>', 'https://exemple.fr/'));
        self::assertSame('shopify', site_analyzer_platform('<p>Rien</p>', 'https://boutique.myshopify.com/'));
    }

    public function testTextCleansMarkupAndTruncates(): void
    {
        self::assertSame('Meuble & objets', site_analyzer_text('<b>Meuble</b> &amp; objets', 50));
        self::assertSame('abc', site_analyzer_text('<p>  abc  </p>', 3));
        self::assertSame('', site_analyzer_text(null));
    }

    public function testAnalyzeRejectsUnsupportedSchemeWithoutNetwork(): void
    {
        self::assertNull(site_analyzer_analyze('ftp://exemple.fr'));
        self::assertNull(site_analyzer_analyze('pas une adresse'));
    }
}