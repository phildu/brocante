<?php

use PHPUnit\Framework\TestCase;

/** Règles de contrôle des fiches de l'extension Chrome (JavaScript) : exécutées avec Node quand il est disponible. */
final class ExtensionQualityTest extends TestCase
{
    public function testQualityRulesPassInNode(): void
    {
        $node = trim((string) @shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('Node n’est pas installé : tests JavaScript de l’extension ignorés.');
        }
        $dir = dirname(__DIR__) . '/packs/annonces-marche/extension';
        $output = [];
        $code = 0;
        exec(escapeshellarg($node) . ' ' . escapeshellarg($dir . '/tests/quality.test.js') . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
        self::assertStringContainsString('tous les tests passent', implode("\n", $output));
    }

    public function testExtensionScriptsAreSyntacticallyValid(): void
    {
        $node = trim((string) @shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            self::markTestSkipped('Node n’est pas installé.');
        }
        $dir = dirname(__DIR__) . '/packs/annonces-marche/extension';
        foreach (['background.js', 'popup.js', 'quality.js', 'content/fill.js', 'content/leboncoin.js', 'content/vinted.js'] as $file) {
            $output = [];
            $code = 0;
            exec(escapeshellarg($node) . ' --check ' . escapeshellarg($dir . '/' . $file) . ' 2>&1', $output, $code);
            self::assertSame(0, $code, $file . ' : ' . implode("\n", $output));
        }
    }
}
