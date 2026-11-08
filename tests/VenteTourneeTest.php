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
}
