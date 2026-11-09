<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../portail/vente/_geo.php';

// Carte de la tournée (forces de vente) : distance, ordre le plus court, lecture de la réponse du géocodeur.
final class VenteGeoTest extends TestCase
{
    private function stop(string $name, ?float $lat, ?float $lng): array
    {
        return ['business' => $name, 'lat' => $lat, 'lng' => $lng];
    }

    public function testDistanceParisLyon(): void
    {
        $d = fv_geo_distance_km(48.8566, 2.3522, 45.7640, 4.8357);
        $this->assertEqualsWithDelta(392, $d, 5);
        $this->assertSame(0.0, fv_geo_distance_km(46.0, 2.0, 46.0, 2.0));
    }

    public function testRouteOrderStartsAtFirstAndPutsUnlocatedLast(): void
    {
        // Points alignés d'ouest en est, donnés dans le désordre ; « X » n'a pas de position.
        $stops = [
            $this->stop('A', 48.0, 2.00),
            $this->stop('X', null, null),
            $this->stop('D', 48.0, 2.30),
            $this->stop('B', 48.0, 2.10),
            $this->stop('C', 48.0, 2.20),
        ];
        $names = array_column(fv_route_order($stops), 'business');
        $this->assertSame(['A', 'B', 'C', 'D', 'X'], $names);
    }

    public function testRouteOrderIsNeverLongerThanInput(): void
    {
        mt_srand(7);
        $stops = [];
        for ($i = 0; $i < 25; $i++) {
            $stops[] = $this->stop('S' . $i, 48.0 + mt_rand(0, 1000) / 10000, 2.0 + mt_rand(0, 1000) / 10000);
        }
        $ordered = fv_route_order($stops);
        $this->assertCount(25, $ordered);
        $this->assertSame('S0', $ordered[0]['business']);
        $this->assertLessThanOrEqual(fv_route_length_km($stops), fv_route_length_km($ordered));
        $this->assertEqualsCanonicalizing(array_column($stops, 'business'), array_column($ordered, 'business'));
    }

    public function testRouteOrderOnWholeBaseStaysFast(): void
    {
        mt_srand(11);
        $stops = [];
        for ($i = 0; $i < 600; $i++) {
            $stops[] = $this->stop('S' . $i, 48.0 + mt_rand(0, 5000) / 10000, 2.0 + mt_rand(0, 5000) / 10000);
        }
        $t = microtime(true);
        $ordered = fv_route_order($stops);
        $this->assertLessThan(5.0, microtime(true) - $t);
        $this->assertCount(600, $ordered);
        $this->assertSame('S0', $ordered[0]['business']);
        $this->assertLessThan(fv_route_length_km($stops), fv_route_length_km($ordered));
    }

    public function testRouteOrderSmallInputs(): void
    {
        $this->assertSame([], fv_route_order([]));
        $one = [$this->stop('A', 48.0, 2.0)];
        $this->assertSame($one, fv_route_order($one));
    }

    public function testAddressLine(): void
    {
        $this->assertSame('3 rue Haute, 80000 Amiens', fv_prospect_address_line(['address' => '3 rue Haute', 'postal' => '80000', 'city' => 'Amiens']));
        $this->assertSame('80000 Amiens', fv_prospect_address_line(['address' => '', 'postal' => '80000', 'city' => 'Amiens']));
        $this->assertSame('', fv_prospect_address_line([]));
    }

    public function testGeocodeParse(): void
    {
        $ok = '{"features":[{"geometry":{"type":"Point","coordinates":[2.33115,48.868989]},"properties":{"score":0.96}}]}';
        $this->assertSame(['lat' => 48.868989, 'lng' => 2.33115, 'score' => 0.96], fv_geocode_parse($ok));
        $weak = '{"features":[{"geometry":{"coordinates":[2.3,48.8]},"properties":{"score":0.2}}]}';
        $this->assertNull(fv_geocode_parse($weak));
        $this->assertNull(fv_geocode_parse('{"features":[]}'));
        $this->assertNull(fv_geocode_parse('pas du json'));
    }
}
