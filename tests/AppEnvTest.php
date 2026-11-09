<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/app-env.php';

final class AppEnvTest extends TestCase
{
    public function testDeclaredEnvironmentWins(): void
    {
        self::assertSame('prod', app_env_resolve('prod', false));
        self::assertSame('preprod', app_env_resolve('preprod', true));
        self::assertSame('local', app_env_resolve('local', false));
    }

    public function testSynonymsAndWhitespace(): void
    {
        self::assertSame('prod', app_env_resolve("  Production\n", false));
        self::assertSame('preprod', app_env_resolve('staging', false));
        self::assertSame('local', app_env_resolve('development', false));
    }

    public function testUndeclaredFallsBackToLocalOnDevMachine(): void
    {
        self::assertSame('local', app_env_resolve(null, true));
        self::assertSame('local', app_env_resolve('', true));
    }

    public function testIndexationNeedsProdAndOpenSwitch(): void
    {
        self::assertTrue(app_indexation_allowed('prod', true));
        self::assertFalse(app_indexation_allowed('prod', false));
        self::assertFalse(app_indexation_allowed('preprod', true));
        self::assertFalse(app_indexation_allowed('local', true));
    }

    public function testCommittedSwitchIsClosed(): void
    {
        // Garde-fou : l'ouverture à l'indexation ne se commite que sur le « go » de l'utilisateur.
        self::assertFalse(require __DIR__ . '/../includes/indexation.php');
    }

    public function testRobotsTxt(): void
    {
        self::assertSame("User-agent: *\nDisallow: /\n", app_robots_txt(false));
        self::assertStringNotContainsString("Disallow: /\n", app_robots_txt(true));
        self::assertStringContainsString('Disallow: /admin/', app_robots_txt(true));
    }

    public function testUndeclaredServerIsNeverProd(): void
    {
        self::assertSame('preprod', app_env_resolve(null, false));
        self::assertSame('preprod', app_env_resolve('', false));
        self::assertSame('preprod', app_env_resolve('n-importe-quoi', false));
    }
    public function testBadgeShownInLocalAndPreprodForEveryone(): void
    {
        self::assertStringContainsString('LOCAL', app_env_badge_html('local', false));
        self::assertStringContainsString('PRÉPROD', app_env_badge_html('preprod', false));
    }

    public function testBadgeInProdOnlyForAdmins(): void
    {
        self::assertSame('', app_env_badge_html('prod', false));
        self::assertStringContainsString('PRODUCTION', app_env_badge_html('prod', true));
    }

    public function testBadgeInsertedBeforeLastBodyTagOnly(): void
    {
        $badge = app_env_badge_html('preprod', false);
        $out = app_env_badge_inject('<html><body>a</body></html>', $badge);
        self::assertSame(1, substr_count($out, 'app-env-badge" aria-hidden'));
        self::assertLessThan(strpos($out, '</body>'), strpos($out, 'app-env-badge'));
        self::assertSame('{"ok":true}', app_env_badge_inject('{"ok":true}', $badge));
        self::assertSame('<p>extrait</p>', app_env_badge_inject('<p>extrait</p>', $badge));
    }
}
