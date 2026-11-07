<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/remember.php';

final class RememberTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function testIssuedTokenLogsInAndIsRotated(): void
    {
        $issued = remember_issue($this->pdo, 'admin', null, 'Mozilla/5.0 (Macintosh; Intel Mac OS X) Safari/605', 1000);
        $first = remember_consume($this->pdo, $issued['value'], 2000);
        self::assertSame(['admin', null], [$first['role'], $first['id']]);
        self::assertNotSame($issued['value'], $first['value']);
        // Le nouveau cookie fonctionne ; l'ancien, rejoué, révoque l'appareil.
        self::assertNotNull(remember_consume($this->pdo, $first['value'], 3000));
        self::assertNull(remember_consume($this->pdo, $issued['value'], 4000));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM admin_remember')->fetchColumn());
    }

    public function testExpiredOrMalformedTokenIsRefused(): void
    {
        $issued = remember_issue($this->pdo, 'community_manager', 7, '', 1000);
        self::assertNull(remember_consume($this->pdo, 'n-importe-quoi', 2000));
        self::assertNull(remember_consume($this->pdo, $issued['value'], 1000 + (REMEMBER_DAYS + 1) * 86400));
    }

    public function testStaffAccountIsKept(): void
    {
        $issued = remember_issue($this->pdo, 'community_manager', 7, '', 1000);
        $found = remember_consume($this->pdo, $issued['value'], 2000);
        self::assertSame(['community_manager', 7], [$found['role'], $found['id']]);
    }

    public function testRevocation(): void
    {
        $a = remember_issue($this->pdo, 'admin', null, '', 1000);
        $b = remember_issue($this->pdo, 'admin', null, '', 1000);
        $c = remember_issue($this->pdo, 'photographe', 9, '', 1000);
        remember_revoke_cookie($this->pdo, $a['value']);
        self::assertNull(remember_consume($this->pdo, $a['value'], 2000));
        self::assertSame(1, remember_revoke_all($this->pdo, null));   // le compte principal : l'autre appareil
        self::assertNull(remember_consume($this->pdo, $b['value'], 2000));
        self::assertNotNull(remember_consume($this->pdo, $c['value'], 2000));
    }

    public function testDeviceLabel(): void
    {
        self::assertSame('Safari · macOS', remember_device_label('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605 Version/17 Safari/605'));
        self::assertSame('Chrome · Windows', remember_device_label('Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537'));
        self::assertSame('Navigateur', remember_device_label(''));
    }
}
