<?php
/**
 * Générateur de QR code autonome (aucune bibliothèque ni service externe :
 * le lien d'accès des bénévoles ne quitte pas le serveur).
 *
 * Mode « octets », niveau de correction d'erreur M (~15 % de dégâts tolérés),
 * versions 1 à 10 (jusqu'à 213 caractères : largement assez pour une URL).
 * Algorithme de la norme ISO/IEC 18004, d'après l'implémentation de référence
 * de Project Nayuki (licence MIT).
 *
 *   echo qr_svg('https://exemple.fr/comptage/', 'Lien du comptage');
 */

final class QrCode
{
    private const MAX_VERSION = 10;
    // Niveau M : octets de correction par bloc et nombre de blocs, par version (index 0 inutilisé).
    private const ECC_PER_BLOCK = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];
    private const NUM_BLOCKS = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];

    public int $version;
    public int $size;
    /** @var array<int,array<int,bool>> modules[y][x] : true = module sombre */
    public array $modules = [];
    private array $isFunction = [];

    private function __construct(int $version, array $dataCodewords)
    {
        $this->version = $version;
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFunction = array_fill(0, $this->size, $row);

        $this->drawFunctionPatterns();
        $this->drawCodewords($this->addEccAndInterleave($dataCodewords));

        $best = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($m = 0; $m < 8; $m++) {
            $this->applyMask($m);
            $this->drawFormatBits($m);
            $p = $this->penaltyScore();
            if ($p < $bestPenalty) { $bestPenalty = $p; $best = $m; }
            $this->applyMask($m);   // le masque est involutif : on l'annule
        }
        $this->applyMask($best);
        $this->drawFormatBits($best);
        $this->isFunction = [];
    }

    /** @throws InvalidArgumentException si le texte est trop long pour une version 10 niveau M. */
    public static function encodeText(string $text): self
    {
        $len = strlen($text);
        for ($ver = 1; $ver <= self::MAX_VERSION; $ver++) {
            $capacityBits = self::numDataCodewords($ver) * 8;
            $countBits = $ver < 10 ? 8 : 16;
            if (4 + $countBits + 8 * $len > $capacityBits) continue;

            $bits = [];
            self::appendBits($bits, 0x4, 4);            // mode octets
            self::appendBits($bits, $len, $countBits);
            foreach (str_split($text) as $ch) self::appendBits($bits, ord($ch), 8);
            self::appendBits($bits, 0, min(4, $capacityBits - count($bits)));   // terminateur
            self::appendBits($bits, 0, (8 - count($bits) % 8) % 8);
            for ($pad = 0xEC; count($bits) < $capacityBits; $pad ^= 0xEC ^ 0x11) self::appendBits($bits, $pad, 8);

            $codewords = array_fill(0, intdiv(count($bits), 8), 0);
            foreach ($bits as $i => $b) $codewords[$i >> 3] |= $b << (7 - ($i & 7));
            return new self($ver, $codewords);
        }
        throw new InvalidArgumentException('Texte trop long pour le QR code.');
    }

    private static function appendBits(array &$bits, int $value, int $count): void
    {
        for ($i = $count - 1; $i >= 0; $i--) $bits[] = ($value >> $i) & 1;
    }

    /* ---------- dimensionnement ---------- */

    private static function numRawDataModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) $result -= 36;
        }
        return $result;
    }

    private static function numDataCodewords(int $ver): int
    {
        return intdiv(self::numRawDataModules($ver), 8) - self::ECC_PER_BLOCK[$ver] * self::NUM_BLOCKS[$ver];
    }

    /* ---------- motifs de fonction ---------- */

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }
        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);

        $pos = $this->alignmentPositions();
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) continue;
                $this->drawAlignment($pos[$i], $pos[$j]);
            }
        }
        $this->drawFormatBits(0);   // réserve la place (réécrit une fois le masque choisi)
        $this->drawVersion();
    }

    private function alignmentPositions(): array
    {
        if ($this->version === 1) return [];
        $numAlign = intdiv($this->version, 7) + 2;
        $step = $this->version === 32 ? 26 : (int) (ceil(($this->version * 4 + 4) / ($numAlign * 2 - 2)) * 2);
        $result = [6];
        for ($pos = $this->size - 7; count($result) < $numAlign; $pos -= $step) array_splice($result, 1, 0, [$pos]);
        return $result;
    }

    private function drawFinder(int $cx, int $cy): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $x = $cx + $dx; $y = $cy + $dy;
                if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                    $this->setFunction($x, $y, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function drawAlignment(int $cx, int $cy): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (0 << 3) | $mask;                  // niveau de correction M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = fn(int $i): bool => (($bits >> $i) & 1) !== 0;

        for ($i = 0; $i <= 5; $i++) $this->setFunction(8, $i, $bit($i));
        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) $this->setFunction(14 - $i, 8, $bit($i));

        for ($i = 0; $i < 8; $i++) $this->setFunction($this->size - 1 - $i, 8, $bit($i));
        for ($i = 8; $i < 15; $i++) $this->setFunction(8, $this->size - 15 + $i, $bit($i));
        $this->setFunction(8, $this->size - 8, true);   // module sombre fixe
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) return;
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        $bits = ($this->version << 12) | $rem;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) !== 0;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunction($a, $b, $dark);
            $this->setFunction($b, $a, $dark);
        }
    }

    /* ---------- correction d'erreur (Reed-Solomon sur GF(256)) ---------- */

    private function addEccAndInterleave(array $data): array
    {
        $ver = $this->version;
        $numBlocks = self::NUM_BLOCKS[$ver];
        $blockEccLen = self::ECC_PER_BLOCK[$ver];
        $rawCodewords = intdiv(self::numRawDataModules($ver), 8);
        $numShort = $numBlocks - $rawCodewords % $numBlocks;
        $shortLen = intdiv($rawCodewords, $numBlocks);

        $divisor = self::rsDivisor($blockEccLen);
        $blocks = [];
        for ($i = 0, $k = 0; $i < $numBlocks; $i++) {
            $len = $shortLen - $blockEccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k += $len;
            $ecc = self::rsRemainder($dat, $divisor);
            if ($i < $numShort) $dat[] = 0;   // case vide, ignorée à l'entrelacement
            $blocks[] = array_merge($dat, $ecc);
        }

        $result = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortLen - $blockEccLen || $j >= $numShort) $result[] = $block[$i];
            }
        }
        return $result;
    }

    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMul($result[$j], $root);
                if ($j + 1 < $degree) $result[$j] ^= $result[$j + 1];
            }
            $root = self::gfMul($root, 0x02);
        }
        return $result;
    }

    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) $result[$i] ^= self::gfMul($coef, $factor);
        }
        return $result;
    }

    private static function gfMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    /* ---------- placement des données et masque ---------- */

    private function drawCodewords(array $data): void
    {
        $total = count($data) * 8;
        $i = 0;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) !== 0;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invert && !$this->isFunction[$y][$x]) $this->modules[$y][$x] = !$this->modules[$y][$x];
            }
        }
    }

    /** Score de pénalité de la norme (4 règles) : plus il est bas, plus le QR est lisible. */
    private function penaltyScore(): int
    {
        $n = $this->size;
        $rows = [];
        $cols = array_fill(0, $n, '');
        $dark = 0;
        for ($y = 0; $y < $n; $y++) {
            $line = '';
            for ($x = 0; $x < $n; $x++) {
                $c = $this->modules[$y][$x] ? '1' : '0';
                $line .= $c;
                $cols[$x] .= $c;
                if ($c === '1') $dark++;
            }
            $rows[] = $line;
        }

        $score = 0;
        foreach (array_merge($rows, $cols) as $line) {
            // Règle 1 : séries de 5 modules identiques ou plus.
            if (preg_match_all('/0{5,}|1{5,}/', $line, $m)) {
                foreach ($m[0] as $run) $score += 3 + strlen($run) - 5;
            }
            // Règle 3 : motifs ressemblant aux repères de position (1:1:3:1:1 entouré de blanc).
            $score += 40 * preg_match_all('/(?=10111010000|00001011101)/', $line);
        }
        // Règle 2 : blocs de 2×2 modules identiques.
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $this->modules[$y][$x];
                if ($c === $this->modules[$y][$x + 1] && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) $score += 3;
            }
        }
        // Règle 4 : équilibre entre modules sombres et clairs.
        $total = $n * $n;
        $k = (int) ceil(abs($dark * 20 - $total * 10) / $total) - 1;
        return $score + max(0, $k) * 10;
    }
}

/**
 * QR code en SVG (noir sur blanc, zone de silence comprise), prêt à intégrer
 * dans une page ou à imprimer. Reste noir sur blanc même en thème sombre : un
 * QR inversé n'est pas lu par tous les téléphones.
 */
function qr_svg(string $text, string $label = 'QR code', int $border = 4): string
{
    $qr = QrCode::encodeText($text);
    $dim = $qr->size + $border * 2;
    $path = '';
    for ($y = 0; $y < $qr->size; $y++) {
        for ($x = 0; $x < $qr->size; $x++) {
            if (!$qr->modules[$y][$x]) continue;
            $run = 1;
            while ($x + $run < $qr->size && $qr->modules[$y][$x + $run]) $run++;
            $path .= 'M' . ($x + $border) . ',' . ($y + $border) . 'h' . $run . 'v1h-' . $run . 'z';
            $x += $run - 1;
        }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" role="img" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" shape-rendering="crispEdges">'
        . '<rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
}
