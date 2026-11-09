<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/contacts-import.php';

// Import de contacts : valeurs « vides » des fichiers robots, numéros +33 (0)…, base SQLite d'annuaire, colonnes reconnues d'office.
final class ContactsImportTest extends TestCase
{
    public function testPlaceholders(): void
    {
        foreach (['Non trouvé', 'non spécifié', 'N/A', 'Aucune description trouvée', '-', ' ', 'null'] as $v) {
            $this->assertTrue(ci_placeholder($v), $v);
        }
        foreach (['Madeleine', 'Les Traboules', '43 rue des Halles'] as $v) {
            $this->assertFalse(ci_placeholder($v), $v);
        }
    }

    public function testPhoneDropsParenthesisedZero(): void
    {
        $this->assertSame('+33699655396', ci_phone('+33(0)6 99 65 53 96'));
        $this->assertSame('+33469856628', ci_phone('+33 (0) 4 69 85 66 28'));
        $this->assertSame('+33478283387', ci_phone('04 78 28 33 87'));
        $this->assertSame('', ci_phone('Non spécifié'));
    }

    public function testCleanKeepsContactWhenEmailIsPlaceholderOrBroken(): void
    {
        $seen = [];
        $c = ci_clean(['email' => 'Non trouvé', 'name' => '43', 'company' => 'Jan Jeunink', 'phone' => '06 87 15 05 11', 'note' => 'Aucune description trouvée'], 2, $seen);
        $this->assertSame('', $c['skip']);
        $this->assertSame('', $c['email']);
        $this->assertSame('', $c['name']);          // numéro de stand, pas un nom
        $this->assertSame('', $c['note']);
        $c = ci_clean(['email' => '– roland@orange.fr', 'name' => 'ROLAND TANZILLI'], 3, $seen);
        $this->assertSame('roland@orange.fr', $c['email']);
        $this->assertSame('Roland Tanzilli', $c['name']);
        $c = ci_clean(['email' => 'sylvie@gmail', 'phone' => '0612345678'], 4, $seen);
        $this->assertSame('', $c['skip']);
        $this->assertSame('', $c['email']);
        $c = ci_clean(['email' => 'sylvie@gmail', 'company' => 'X'], 5, $seen);
        $this->assertSame('e-mail invalide', $c['skip']);
    }

    public function testGuessMappingForMarketplaceSheet(): void
    {
        $headers = ['Nom boutique', 'Contact', 'Téléphone', 'Email', 'Site internet', 'Facebook', 'Instagram', 'Emplacement', 'Numéro d\'emplacement', 'Spécialité / Description', 'URL'];
        [$map] = ci_guess_mapping($headers, true);
        $this->assertSame(0, $map['company']);
        $this->assertSame(1, $map['name']);
        $this->assertSame(2, $map['phone']);
        $this->assertSame(3, $map['email']);
        $this->assertSame(9, $map['note']);
        $this->assertSame('Site web', $map['custom'][4]['label']);
        $this->assertSame('Instagram', $map['custom'][6]['label']);
        [$plain] = ci_guess_mapping($headers);
        $this->assertArrayNotHasKey('custom', $plain);      // reconnaissance automatique réservée aux pages qui la demandent
    }

    public function testSqliteDirectory(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'annuaire') . '.db';
        $pdo = new PDO('sqlite:' . $file);
        $pdo->exec('CREATE TABLE rues(id INTEGER PRIMARY KEY, nom TEXT, nb INTEGER)');
        $pdo->exec('CREATE TABLE commerces(id INTEGER PRIMARY KEY, rue_id INTEGER, nom TEXT, categorie TEXT, numero TEXT, tel TEXT, email TEXT, lat REAL, lon REAL)');
        $pdo->exec("INSERT INTO rues VALUES (1, 'Rue d''Algérie', 2)");
        $pdo->exec("INSERT INTO commerces VALUES (1, 1, 'Le Voxx', 'Bars', '1', '04 78 28 33 87', '', 45.76, 4.82), (2, 1, 'Sans rien', 'Mode', '', '', '', NULL, NULL)");
        $pdo = null;
        [$headers, $rows] = ci_parse_sqlite($file);
        unlink($file);
        $this->assertContains('commerce', $headers);
        $this->assertContains('adresse', $headers);
        $this->assertNotContains('numero', $headers);
        $this->assertCount(2, $rows);
        $this->assertSame('1 Rue d\'Algérie', $rows[0][array_search('adresse', $headers, true)]);
        [$map] = ci_guess_mapping($headers, true);
        $contacts = ci_contacts_from_rows($rows, $map);
        $this->assertSame('Le Voxx', $contacts[0]['company']);
        $this->assertSame('+33478283387', $contacts[0]['phone']);
        $this->assertSame('45.76', $contacts[0]['custom_fields']['Latitude']);
        $this->assertSame('ni e-mail ni téléphone', $contacts[1]['skip']);
    }

    public function testSqliteRejectsOtherFiles(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pas-sqlite');
        file_put_contents($file, "nom;email\nA;a@b.fr\n");
        $this->expectException(InvalidArgumentException::class);
        try {
            ci_parse_sqlite($file);
        } finally {
            unlink($file);
        }
    }
}
