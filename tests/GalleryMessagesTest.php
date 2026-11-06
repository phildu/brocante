<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/gallery-messages.php';

/**
 * Messagerie interne d'une galerie : identifiants de fil, validation des messages et compteurs de non-lus.
 * Aucun fichier de données n'est écrit.
 */
final class GalleryMessagesTest extends TestCase
{
    public function testThreadIdValidation(): void
    {
        self::assertTrue(gmsg_id_valid('abcdef0123456789'));
        self::assertTrue(gmsg_id_valid('x0123456789abcdef0123456'));
        self::assertFalse(gmsg_id_valid('court'));
        self::assertFalse(gmsg_id_valid('ABCDEF0123456789'));
        self::assertFalse(gmsg_id_valid('abcdef0123456789/../x'));
        self::assertFalse(gmsg_id_valid(''));
    }

    public function testBookingThreadIdIsStableAndOpaque(): void
    {
        $token = str_repeat('a', 32);
        $id = gmsg_booking_id('galerie-test', $token);
        self::assertMatchesRegularExpression('/^x[a-f0-9]{23}$/', $id);
        self::assertSame($id, gmsg_booking_id('galerie-test', $token));
        self::assertNotSame($id, gmsg_booking_id('autre-galerie', $token));
        self::assertNotSame($id, gmsg_booking_id('galerie-test', str_repeat('b', 32)));
        self::assertStringNotContainsString($token, $id);
        self::assertSame('', gmsg_booking_id('galerie-test', 'pas-un-jeton'));
    }

    public function testBodyCheck(): void
    {
        self::assertSame('Écrivez un message.', gmsg_body_check('   '));
        self::assertNull(gmsg_body_check('Bonjour, une question sur mon emplacement.'));
        self::assertNotNull(gmsg_body_check(str_repeat('a', GMSG_MAX_BODY + 1)));
        self::assertNull(gmsg_body_check(str_repeat('a', GMSG_MAX_BODY)));
    }

    public function testThreadNormalizationCountsUnread(): void
    {
        $t = gmsg_thread_normalize('abcdef0123456789', [
            'kind' => 'exposant', 'slot_id' => 'ev3', 'name' => '  Jean  ', 'email' => 'JEAN@EXAMPLE.TEST',
            'subject' => 'Place 3', 'created' => 100, 'updated' => 90, 'read_admin' => 250, 'read_guest' => 100,
            'messages' => [
                ['id' => '1', 'from' => 'admin', 'body' => 'Bonjour', 'at' => 150, 'by' => 'Orga'],
                ['id' => '2', 'from' => 'guest', 'body' => 'Merci', 'at' => 200, 'by' => 'Jean'],
                ['id' => '3', 'from' => 'guest', 'body' => '   ', 'at' => 210],
                ['id' => '4', 'from' => 'guest', 'body' => 'Autre', 'at' => 300, 'by' => 'Jean'],
            ],
        ]);
        self::assertSame('exposant', $t['kind']);
        self::assertSame('ev3', $t['slot_id']);
        self::assertSame('Jean', $t['name']);
        self::assertSame('jean@example.test', $t['email']);
        self::assertCount(3, $t['messages']);            // le message vide est ignoré
        self::assertSame(1, $t['unread_admin']);         // « Autre » après la dernière lecture
        self::assertSame(1, $t['unread_guest']);         // « Bonjour » après la dernière lecture
        self::assertSame(300, $t['updated']);
        self::assertSame('Autre', $t['last']['body']);
    }

    public function testContactThreadDefaults(): void
    {
        $t = gmsg_thread_normalize('abcdef0123456789', ['messages' => []]);
        self::assertSame('contact', $t['kind']);
        self::assertSame('', $t['slot_id']);
        self::assertNull($t['last']);
        self::assertSame(0, $t['unread_admin']);
        self::assertSame(0, $t['unread_guest']);
        self::assertSame(0, $t['created']);
    }

    public function testSlotLabelFallsBackToRowLabel(): void
    {
        self::assertSame('Place 7', gmsg_slot_label([], ['slot' => 'Place 7']));
        self::assertSame('', gmsg_slot_label([], []));
    }
}
