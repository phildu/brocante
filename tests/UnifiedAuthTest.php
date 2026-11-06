<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/unified-auth.php';

final class UnifiedAuthTest extends TestCase
{
    public function testGalleryIdentityKeepsSlugNames(): void
    {
        $public = ua_public_identity([
            'space' => 'gallery',
            'email' => 'admin@example.test',
            'galleries' => ['bonpasteur' => '$2y$12$hash', 'saint-antoine' => '$2y$12$autre'],
        ]);
        self::assertSame(['bonpasteur', 'saint-antoine'], $public['galleries']);
        self::assertArrayNotHasKey('_hash', $public);
    }

    public function testGalleryIdentityIsIdempotent(): void
    {
        // ua_login() rend une identité publique, que ua_ticket_create() repasse ici : la liste de slugs ne doit pas redevenir [0].
        $once = ua_public_identity(['space' => 'gallery', 'galleries' => ['bonpasteur' => 'h']]);
        $twice = ua_public_identity($once);
        self::assertSame(['bonpasteur'], $twice['galleries']);
    }

    public function testGalleryIdentityDropsNonStrings(): void
    {
        $public = ua_public_identity(['space' => 'gallery', 'galleries' => ['bonpasteur', 0, ['x'], 'naty']]);
        self::assertSame(['bonpasteur', 'naty'], $public['galleries']);
    }

    public function testShopIdentityKeepsItsNumberedAccount(): void
    {
        $public = ua_public_identity([
            'space' => 'shop',
            'tenant' => 'petit-chalet',
            'account_id' => 7,
            '_hash' => 'secret',
        ]);
        self::assertSame(7, $public['account_id']);
        self::assertArrayNotHasKey('_hash', $public);
    }
}
