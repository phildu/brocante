<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../portail/vente/_geo.php';
require_once __DIR__ . '/../portail/vente/_tournee.php';

// Choix des arrêts d'une tournée : rue déduite de l'adresse, comparaison sans accents, filtres cumulables.
final class VenteTourneeTest extends TestCase
{
    private const TODAY = '2026-11-08';
    private const WEEK = '2026-11-15';

    private function p(array $over = []): array
    {
        return $over + [
            'id' => 1, 'business' => 'Brocante du Port', 'name' => 'Jeanne', 'kind' => 'Brocanteur', 'address' => '12 rue des Lilas', 'postal' => '35400', 'city' => 'Saint-Malo',
            'status' => 'a_contacter', 'zone' => 'Nord', 'priority' => 2, 'owner_id' => 3, 'next_action_at' => null, 'lat' => null, 'lng' => null,
        ];
    }

    private function match(array $p, array $filters = []): bool
    {
        return fv_tournee_match($p, fv_tournee_filters($filters), self::TODAY, self::WEEK);
    }

    #[DataProvider('streets')]
    public function testStreetWithoutNumber(string $address, string $street): void
    {
        $this->assertSame($street, fv_street_of($address));
    }

    public static function streets(): array
    {
        return [
            ['12 rue des Lilas', 'rue des Lilas'],
            ['12bis rue des Lilas', 'rue des Lilas'],
            ['12 bis, rue des Lilas', 'rue des Lilas'],
            ['3-5 avenue Foch', 'avenue Foch'],
            ['14 B rue Neuve', 'rue Neuve'],
            ['n° 7 place du 8 Mai', 'place du 8 Mai'],
            ['Rue Saint-Vincent', 'Rue Saint-Vincent'],
            ['  45   boulevard  de la République, Résidence Les Pins ', 'boulevard de la République'],
            ['8 chemin d’Alsace', 'chemin d’Alsace'],
            ['10 quai Duguay-Trouin 35400 Saint-Malo', 'quai Duguay-Trouin'],
            ['', ''],
        ];
    }

    public function testFoldIgnoresAccentsAndCase(): void
    {
        $this->assertSame('evry', fv_fold('ÉVRY'));
        $this->assertSame('saint-etienne', fv_fold('Saint-Étienne'));
    }

    public function testClosedRecordsAreHiddenUnlessAskedFor(): void
    {
        $closed = $this->p(['status' => 'perdu']);
        $this->assertFalse($this->match($closed));
        $this->assertTrue($this->match($closed, ['tous' => 1]));
        $this->assertTrue($this->match($closed, ['statut' => 'perdu']));
        $this->assertFalse($this->match($this->p(), ['statut' => 'perdu']));
    }

    public function testCityAndStreetFiltersIgnoreAccentsAndNumber(): void
    {
        $p = $this->p(['city' => 'Évry', 'address' => '5 rue Édouard Branly']);
        $this->assertTrue($this->match($p, ['ville' => 'evry']));
        $this->assertTrue($this->match($p, ['rue' => 'edouard branly']));
        $this->assertFalse($this->match($p, ['rue' => '5 rue']));       // le numéro ne fait pas partie de la rue
        $this->assertFalse($this->match($p, ['ville' => 'paris']));
    }

    public function testTextSearchNeedsEveryWord(): void
    {
        $p = $this->p();
        $this->assertTrue($this->match($p, ['q' => 'brocante malo']));
        $this->assertTrue($this->match($p, ['q' => 'lilas 35400']));
        $this->assertFalse($this->match($p, ['q' => 'brocante paris']));
    }

    public function testCategoryZonePriorityOwner(): void
    {
        $p = $this->p();
        $this->assertTrue($this->match($p, ['cat' => 'brocanteur']));
        $this->assertFalse($this->match($p, ['cat' => 'Café']));
        $this->assertTrue($this->match($this->p(['kind' => '']), ['cat' => '~vide']));
        $this->assertFalse($this->match($p, ['cat' => '~vide']));
        $this->assertTrue($this->match($p, ['zone' => 'Nord']));
        $this->assertFalse($this->match($p, ['zone' => 'Sud']));
        $this->assertTrue($this->match($p, ['prio' => '2']));
        $this->assertFalse($this->match($p, ['prio' => '3']));
        $this->assertTrue($this->match($p, ['owner' => '3']));
        $this->assertFalse($this->match($p, ['owner' => '4']));
    }

    public function testNextActionFilters(): void
    {
        $late = $this->p(['next_action_at' => '2026-11-01']);
        $soon = $this->p(['next_action_at' => '2026-11-12']);
        $none = $this->p();
        $this->assertTrue($this->match($late, ['due' => 'today']));
        $this->assertFalse($this->match($soon, ['due' => 'today']));
        $this->assertTrue($this->match($soon, ['due' => 'week']));
        $this->assertFalse($this->match($none, ['due' => 'week']));
        $this->assertTrue($this->match($none, ['due' => 'none']));
        $this->assertFalse($this->match($late, ['due' => 'none']));
    }

    public function testGpsFilter(): void
    {
        $placed = $this->p(['lat' => 48.65, 'lng' => -2.02]);
        $this->assertTrue($this->match($placed, ['gps' => 'oui']));
        $this->assertFalse($this->match($placed, ['gps' => 'non']));
        $this->assertTrue($this->match($this->p(), ['gps' => 'non']));
        $this->assertFalse($this->match($this->p(), ['gps' => 'oui']));
    }

    public function testFiltersAreParsedAndSerialized(): void
    {
        $f = fv_tournee_filters(['ville' => ' Lyon ', 'prio' => '9', 'due' => 'jamais', 'gps' => 'oui', 'tous' => '1']);
        $this->assertSame('Lyon', $f['ville']);
        $this->assertSame(3, $f['prio']);
        $this->assertSame('', $f['due']);
        $this->assertTrue(fv_tournee_filtered($f));
        $this->assertFalse(fv_tournee_filtered(fv_tournee_filters([])));
        $this->assertSame(['ville' => 'Lyon', 'prio' => 3, 'gps' => 'oui', 'tous' => 1], fv_tournee_filter_params($f));
    }

    public function testCompactRow(): void
    {
        $row = fv_tournee_row($this->p(['id' => 9]));
        $this->assertSame(9, $row['id']);
        $this->assertSame('Brocante du Port', $row['b']);
        $this->assertSame('Jeanne', $row['n']);
        $this->assertSame('rue des Lilas', $row['s']);
        $this->assertSame('12 rue des Lilas, 35400 Saint-Malo', $row['l']);
        $this->assertSame(0, $row['g']);
        $this->assertSame('Marie', fv_tournee_row($this->p(['business' => '', 'name' => 'Marie']))['b']);
    }

    // ── Horaires d'ouverture ──

    private function hours(string $text): ?array
    {
        return fv_hours_parse($text);
    }

    public function testHoursOsmFormat(): void
    {
        $w = $this->hours('Mo-Fr 09:00-19:15, Sa 09:00-19:00');
        $this->assertSame([[540, 1155]], $w[0]);
        $this->assertSame([[540, 1140]], $w[5]);
        $this->assertSame([], $w[6]);
        $this->assertTrue(fv_hours_open($w, 3, 10 * 60));
        $this->assertFalse(fv_hours_open($w, 3, 20 * 60));
        $this->assertFalse(fv_hours_open($w, 7, null));            // dimanche : fermé
        $this->assertTrue(fv_hours_open($w, 6, null));
    }

    public function testHoursSplitDayAndSeveralRules(): void
    {
        $w = $this->hours('We-Fr 12:00-14:00, 19:00-22:00; Sa 12:00-14:30, 19:00-22:30; Su 12:00-14:30');
        $this->assertSame([[720, 840], [1140, 1320]], $w[2]);
        $this->assertSame([[720, 870]], $w[6]);
        $this->assertSame([], $w[0]);                              // lundi et mardi : non cités = fermé
        $this->assertFalse(fv_hours_open($w, 3, 15 * 60));
        $this->assertTrue(fv_hours_open($w, 3, 20 * 60));
    }

    public function testHoursWrappedDaysAndPastMidnight(): void
    {
        $w = $this->hours('Th-Sa 10:00-03:00, Su-We 10:00-01:00');
        $this->assertCount(7, array_filter($w));                   // tous les jours ont des créneaux
        $this->assertTrue(fv_hours_open($w, 5, 2 * 60));           // vendredi 02:00 : encore ouvert (créneau du jeudi)
        $this->assertFalse(fv_hours_open($w, 5, 4 * 60));
        $this->assertTrue(fv_hours_open($w, 1, 30));               // lundi 00:30 : créneau du dimanche
        $this->assertTrue(fv_hours_open($w, 4, 30));               // jeudi 00:30 : créneau du mercredi
        $this->assertFalse(fv_hours_open($w, 4, 90));
    }

    public function testHoursLaterRuleOverridesEarlierOne(): void
    {
        $w = $this->hours('Mo-Su 10:00-20:00; Su off; Sa 10:00-12:00');
        $this->assertSame([[600, 720]], $w[5]);
        $this->assertSame([], $w[6]);
        $this->assertSame([[600, 1200]], $w[1]);
    }

    public function testHoursAlwaysAndHolidaysIgnored(): void
    {
        $this->assertTrue(fv_hours_open($this->hours('24/7'), 7, 3 * 60));
        $w = $this->hours('Mo-Fr 08:00-12:00; PH off');
        $this->assertTrue(fv_hours_open($w, 1, 9 * 60));
        $this->assertSame([], $w[5]);
        $this->assertSame([[0, 1440]], $this->hours('Mo 00:00-24:00')[0]);
    }

    public function testHoursHolidaysAndDatedExceptionsAreSkipped(): void
    {
        $w = $this->hours('Mo-Sa 07:00-01:00; Su,PH 08:30-01:00');
        $this->assertSame([[510, 1500]], $w[6]);
        $w = $this->hours('Mo-Fr 09:00-12:30,14:00-18:00; Sa 10:00-13:00; 2026 Aug 19-22 off');
        $this->assertSame([[540, 750], [840, 1080]], $w[0]);
        $this->assertSame([[600, 780]], $w[5]);
        $this->assertSame([[600, 1050]], $this->hours('We-Mo,PH 10:00-17:30')[2]);
        $this->assertSame([[600, 1050]], $this->hours('We-Mo,PH 10:00-17:30')[0]);
        $this->assertSame([], $this->hours('We-Mo,PH 10:00-17:30')[1]);
    }

    public function testHoursSimpleFrench(): void
    {
        $w = $this->hours('du lundi au vendredi de 9h à 17h30');
        $this->assertSame([[540, 1050]], $w[0]);
        $this->assertSame([], $w[5]);
        $this->assertSame([[540, 720], [840, 1140]], $this->hours('Lun-Sam 9h-12h, 14h-19h')[2]);
        $this->assertSame([[600, 1080]], $this->hours('tous les jours 10h-18h')[6]);
        $this->assertSame([[540, 720]], $this->hours('Mo,We,Fr 09:00-12:00')[4]);       // liste de jours
        $this->assertSame([[540, 720]], $this->hours('Mo,We,Fr 09:00-12:00')[0]);
        $this->assertSame([], $this->hours('Mo,We,Fr 09:00-12:00')[1]);
    }

    public function testHoursUnreadableStaysUnknown(): void
    {
        foreach (['', 'sur rendez-vous', 'Mo-Fr sunrise-sunset', 'Ouvert de 7h à 19h, du mercredi au dimanche !', 'Mo-Fr', 'Mo-Fr 10:00+'] as $text) {
            $this->assertNull($this->hours($text), $text);
        }
    }

    public function testOpenFilter(): void
    {
        $withHours = $this->p(['custom_fields' => json_encode(['Horaires' => 'Mo-Fr 09:00-12:00, 14:00-19:00; Sa 09:00-12:00'])]);
        $noHours = $this->p();
        $weirdHours = $this->p(['custom_fields' => json_encode(['Horaires' => 'sur rendez-vous'])]);
        $match = static fn (array $p, array $f, array $now = ['d' => 2, 'm' => 600]): bool => fv_tournee_match($p, fv_tournee_filters($f), self::TODAY, self::WEEK, $now);

        $this->assertTrue($match($withHours, ['ouvert' => 'now']));                         // mardi 10:00
        $this->assertFalse($match($withHours, ['ouvert' => 'now'], ['d' => 2, 'm' => 13 * 60]));   // mardi 13:00 : pause
        $this->assertFalse($match($withHours, ['ouvert' => 'now'], ['d' => 7, 'm' => 600]));       // dimanche
        $this->assertTrue($match($withHours, ['ouvert' => '6']));                            // le samedi
        $this->assertFalse($match($withHours, ['ouvert' => '7']));
        $this->assertTrue($match($withHours, ['ouvert' => '6', 'heure' => '11:00']));
        $this->assertFalse($match($withHours, ['ouvert' => '6', 'heure' => '15:00']));
        // horaires inconnus : écartés, sauf si on demande de les garder
        $this->assertFalse($match($noHours, ['ouvert' => 'now']));
        $this->assertTrue($match($noHours, ['ouvert' => 'now', 'hinc' => 1]));
        $this->assertTrue($match($weirdHours, ['ouvert' => '1', 'hinc' => 1]));
        $this->assertFalse($match($weirdHours, ['ouvert' => '1']));
        // sans filtre « ouvert », les horaires ne comptent pas
        $this->assertTrue($match($noHours, []));
    }

    public function testRowCarriesHours(): void
    {
        $row = fv_tournee_row($this->p(['custom_fields' => json_encode(['Horaires' => 'Mo-Fr 09:00-12:00'])]));
        $this->assertSame('Mo-Fr 09:00-12:00', $row['h']);
        $this->assertSame([[540, 720]], $row['w'][0]);
        $this->assertArrayNotHasKey('h', fv_tournee_row($this->p()));
        $this->assertNull(fv_tournee_row($this->p(['custom_fields' => json_encode(['Horaires' => 'sur rendez-vous'])]))['w']);
    }

    public function testOpenFilterIsSerialized(): void
    {
        $f = fv_tournee_filters(['ouvert' => '6', 'heure' => '09:30', 'hinc' => '1']);
        $this->assertSame(['ouvert' => '6', 'heure' => '09:30', 'hinc' => 1], fv_tournee_filter_params($f));
        $this->assertSame([], fv_tournee_filter_params(fv_tournee_filters(['ouvert' => '9', 'heure' => '25:00'])));
        $this->assertTrue(fv_tournee_filtered($f));
    }
}
