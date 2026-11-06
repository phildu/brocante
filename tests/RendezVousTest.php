<?php

use PHPUnit\Framework\TestCase;

defined('PLUGIN_RUNTIME') || define('PLUGIN_RUNTIME', true);

require_once __DIR__ . '/../packs/rendez-vous/lib/rdv.php';

final class RendezVousTest extends TestCase
{
    public function testTimeParsingAcceptsLooseFormats(): void
    {
        self::assertSame('09:00', rdv_parse_time('9'));
        self::assertSame('09:00', rdv_parse_time('09:00'));
        self::assertSame('09:30', rdv_parse_time('9h30'));
        self::assertSame('14:00', rdv_parse_time('14 h'));
        self::assertSame('07:05', rdv_parse_time('7:5'));
    }

    public function testTimeParsingRejectsInvalidInput(): void
    {
        self::assertNull(rdv_parse_time('24:00'));
        self::assertNull(rdv_parse_time('9:75'));
        self::assertNull(rdv_parse_time('matin'));
        self::assertNull(rdv_parse_time(''));
    }

    public function testDateParsingAcceptsIsoAndFrenchFormats(): void
    {
        self::assertSame('2026-12-15', rdv_parse_date('2026-12-15'));
        self::assertSame('2026-12-15', rdv_parse_date('15/12/2026'));
        self::assertSame('2026-02-09', rdv_parse_date('9/2/2026'));
        self::assertNull(rdv_parse_date('31/02/2026'));
        self::assertNull(rdv_parse_date('demain'));
    }

    public function testSlotListParsesRangesLabelsAndSorts(): void
    {
        $parsed = rdv_parse_slots(implode("\n", [
            '2026-11-21 14:00-18:00 | Après-midi',
            '2026-11-20',
            '21/11/2026 9h 11h',
        ]));
        self::assertSame([], $parsed['errors']);
        self::assertCount(3, $parsed['slots']);
        self::assertSame('2026-11-20', $parsed['slots'][0]['slot_date']);
        self::assertSame('', $parsed['slots'][0]['start_time']);
        self::assertSame('09:00', $parsed['slots'][1]['start_time']);
        self::assertSame('11:00', $parsed['slots'][1]['end_time']);
        self::assertSame('14:00', $parsed['slots'][2]['start_time']);
        self::assertSame('Après-midi', $parsed['slots'][2]['label']);
    }

    public function testSlotListIgnoresDuplicates(): void
    {
        $parsed = rdv_parse_slots("2026-11-21 09:00-12:00\n2026-11-21 9h-12h\n2026-11-21 09:00 12:00");
        self::assertSame([], $parsed['errors']);
        self::assertCount(1, $parsed['slots']);
    }

    public function testSlotListReportsUnreadableLines(): void
    {
        $parsed = rdv_parse_slots("31/02/2026\n2026-11-21 18:00-14:00\n2026-11-22 25:00-26:00");
        self::assertCount(0, $parsed['slots']);
        self::assertCount(3, $parsed['errors']);
        self::assertStringContainsString('date illisible', $parsed['errors'][0]);
        self::assertStringContainsString('la fin doit suivre le début', $parsed['errors'][1]);
        self::assertStringContainsString('heure invalide', $parsed['errors'][2]);
    }

    public function testFrenchLabels(): void
    {
        self::assertSame('mardi 15 décembre 2026', rdv_fr_date('2026-12-15'));
        self::assertSame('mar. 15 déc. 2026', rdv_fr_date('2026-12-15', true));
        self::assertSame('9 h', rdv_time_label('09:00'));
        self::assertSame('9 h 30', rdv_time_label('09:30'));
        self::assertSame(
            'Matin · mardi 15 décembre 2026 · 9 h – 12 h',
            rdv_slot_label(['slot_date' => '2026-12-15', 'start_time' => '09:00', 'end_time' => '12:00', 'label' => 'Matin'])
        );
    }

    public function testPollRangeCoversFirstAndLastDay(): void
    {
        $slots = [
            ['slot_date' => '2026-11-20'],
            ['slot_date' => '2026-11-21'],
        ];
        self::assertSame('vendredi 20 novembre 2026', rdv_poll_range([$slots[0]]));
        self::assertSame('du vendredi 20 novembre 2026 au samedi 21 novembre 2026', rdv_poll_range($slots));
    }

    public function testBestSlotPrefersYesOverMaybe(): void
    {
        $slots = [['id' => 1], ['id' => 2], ['id' => 3]];
        $counts = [
            1 => ['yes' => 0, 'maybe' => 1],
            2 => ['yes' => 1, 'maybe' => 0],
            3 => ['yes' => 0, 'maybe' => 0],
        ];
        self::assertSame(2, rdv_best_slot($slots, $counts));
        self::assertNull(rdv_best_slot($slots, [1 => ['yes' => 0, 'maybe' => 0]]));
    }

    public function testEmailListIsLowercasedDeduplicatedAndCapped(): void
    {
        self::assertSame(
            ['jean@example.fr', 'marie@example.fr'],
            rdv_parse_emails('Jean@Example.FR, marie@example.fr; jean@example.fr')
        );
        self::assertSame([], rdv_parse_emails('pas une adresse'));
        self::assertCount(5, rdv_parse_emails('a@b.fr c@d.fr e@f.fr g@h.fr i@j.fr k@l.fr'));
    }

    public function testPollInputValidatesTitleAndClosingDate(): void
    {
        [$data, $errors] = rdv_poll_input([
            'title' => '  Dépôt   de meubles ',
            'closes_on' => '15/12/2026',
            'notify_email' => 'Boutique@Example.FR',
            'results_public' => '1',
        ]);
        self::assertSame([], $errors);
        self::assertSame('Dépôt   de meubles', $data['title']);
        self::assertSame('2026-12-15', $data['closes_on']);
        self::assertSame('boutique@example.fr', $data['notify_email']);
        self::assertSame(1, $data['results_public']);

        [, $errors] = rdv_poll_input(['title' => 'a', 'closes_on' => '31/02/2026']);
        self::assertCount(2, $errors);
    }

    public function testOpenStateDependsOnStatusAndClosingDate(): void
    {
        self::assertTrue(rdv_poll_open(['status' => 'open', 'closes_on' => '']));
        self::assertTrue(rdv_poll_open(['status' => 'open', 'closes_on' => rdv_today()]));
        self::assertFalse(rdv_poll_open(['status' => 'open', 'closes_on' => '2020-01-01']));
        self::assertFalse(rdv_poll_open(['status' => 'closed', 'closes_on' => '']));
    }

    public function testPastPollUsesLastSlot(): void
    {
        self::assertTrue(rdv_poll_is_past([], [['slot_date' => '2020-01-01']]));
        self::assertFalse(rdv_poll_is_past([], [['slot_date' => '2999-01-01']]));
        self::assertFalse(rdv_poll_is_past([], []));
    }
}
