<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/tenant-factory.php';
require_once __DIR__ . '/../includes/unified-auth.php';

/** Lien de mot de passe envoyé à l'admin d'une boutique depuis le portail, et adresse retenue dans tenant.php. */
final class AdminResetLinkTest extends TestCase
{
    private string $slug;
    private string $dir;

    protected function setUp(): void
    {
        $this->slug = 'zz-test-reset-' . getmypid();
        $this->dir = tenant_dir($this->slug);
        mkdir($this->dir, 0775, true);
        file_put_contents($this->dir . '/tenant.php', "<?php\n\nreturn [\n    'name' => 'Boutique d\\'essai',\n    'admin_user' => 'admin',\n    'admin_password' => 'x',\n    'db_file' => 'essai.db',\n];\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir);
    }

    public function testAdminEmailIsInsertedThenReplaced(): void
    {
        set_tenant_admin_email($this->slug, 'Boss@Example.com');
        self::assertSame('boss@example.com', tenant_load($this->slug)['admin_email']);
        set_tenant_admin_email($this->slug, 'autre@example.com');
        self::assertSame('autre@example.com', tenant_load($this->slug)['admin_email']);
        self::assertSame(1, substr_count((string) file_get_contents($this->dir . '/tenant.php'), "'admin_email'"));
        self::assertSame('admin', tenant_load($this->slug)['admin_user']);
    }

    public function testInvalidEmailIsRefusedAndFileUntouched(): void
    {
        $before = (string) file_get_contents($this->dir . '/tenant.php');
        $this->expectException(InvalidArgumentException::class);
        try {
            set_tenant_admin_email($this->slug, 'pas-une-adresse');
        } finally {
            self::assertSame($before, (string) file_get_contents($this->dir . '/tenant.php'));
        }
    }

    public function testSendRefusesInvalidEmail(): void
    {
        self::assertSame('Adresse e-mail invalide.', ua_shop_admin_reset_send($this->slug, 'nope'));
    }

    public function testSendNeedsTheShopDatabase(): void
    {
        // Pas de base pour cette boutique : aucun lien créé, une raison lisible est rendue.
        self::assertStringContainsString('Lien non créé', (string) ua_shop_admin_reset_send($this->slug, 'boss@example.com'));
    }
}
