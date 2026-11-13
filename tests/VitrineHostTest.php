<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/vitrine-host.php';

/** Domaines qui ouvrent une vitrine dédiée à leur racine (aux-puces.com). */
final class VitrineHostTest extends TestCase
{
    public function testAuxPucesOpensItsSalesPage(): void
    {
        $this->assertSame('aux-puces/index.php', vitrine_page_for_host('aux-puces.com'));
    }

    public function testWwwPortAndCaseAreIgnored(): void
    {
        $this->assertSame('aux-puces/index.php', vitrine_page_for_host('www.aux-puces.com'));
        $this->assertSame('aux-puces/index.php', vitrine_page_for_host('AUX-PUCES.com:443'));
    }

    public function testOtherDomainsKeepThePlatformHome(): void
    {
        $this->assertNull(vitrine_page_for_host('www.bricbrok.com'));
        $this->assertNull(vitrine_page_for_host('pre.bricbrok.com'));
        $this->assertNull(vitrine_page_for_host('petit-chalet.aux-puces.com'));
        $this->assertNull(vitrine_page_for_host('aux-puces.com.evil.example'));
        $this->assertNull(vitrine_page_for_host(''));
    }

    public function testEveryVitrinePageExists(): void
    {
        foreach (VITRINE_HOSTS as $host => $page) {
            $this->assertFileExists(__DIR__ . '/../' . $page, "Page de vitrine manquante pour $host");
        }
    }
}
