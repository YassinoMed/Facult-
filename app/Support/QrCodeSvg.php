<?php

namespace App\Support;

use DomainException;

/**
 * Générateur QR Code natif en pur PHP (sans dépendance externe).
 * Produit un SVG vectoriel propre et léger, compatible Dompdf et navigateurs,
 * lisible par tout lecteur QR standard (téléphone, passerelle, etc.).
 *
 * Encodage réel ISO/IEC 18004 (mode octet UTF-8) : mireurs + synchronisation
 * + alignement, Reed-Solomon GF(256), information de format BCH(15,5) avec
 * redondance double, masque sélectionné par les règles de pénalité 1-4.
 * Versions 1-10 (21×21 à 57×57), niveau M puis bascule L au-delà — couverture
 * documentée : URL de vérification d'attestation (~90 octets) et jetons
 * d'émargement signés (~170 octets). Au-delà de 271 octets (version 10-L),
 * exception explicite : un vrai QR plus grand serait un cas nécessitant une
 * dépendance externe validée (audit LOT 07), jamais un faux QR visuel.
 */
class QrCodeSvg
{
    /**
     * Capacités mode octet par niveau (versions 1-10) : octets de données − 2
     * (v1-9, compte 8 bits) puis − 3 (v10, compte 16 bits), en-tête + terminateur.
     */
    private const CAPACITES_M = [14, 26, 42, 62, 84, 106, 122, 152, 180, 213];

    private const CAPACITES_L = [17, 32, 53, 78, 106, 134, 154, 192, 230, 271];

    /**
     * Découpage du flux par version — tables ISO/IEC 18004 transposées depuis
     * l'implémentation de référence qrcode-generator (zéro reconstitution de
     * mémoire) : groupes [blocs, octets données/bloc, octets RS/bloc].
     * Invariant : Σ(données) + Σ(RS) = codewords totaux de la version.
     */
    private const BLOCS_M = [
        [[1, 16, 10]], [[1, 28, 16]], [[1, 44, 26]], [[2, 32, 18]],
        [[2, 43, 24]], [[4, 27, 16]], [[4, 31, 18]],
        [[2, 38, 22], [2, 39, 22]], [[3, 36, 22], [2, 37, 22]],
        [[4, 43, 26], [1, 44, 26]],
    ];

    private const BLOCS_L = [
        [[1, 19, 7]], [[1, 34, 10]], [[1, 55, 15]], [[1, 80, 20]],
        [[1, 108, 26]], [[2, 68, 18]], [[2, 78, 20]],
        [[2, 97, 24]], [[2, 116, 30]],
        [[2, 68, 18], [2, 69, 18]],
    ];

    /** Positions d'alignement par version (v2-10, coordonnées de la spec). */
    private const ALIGNEMENTS = [
        [6, 18], [6, 22], [6, 26], [6, 30], [6, 34],
        [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50],
    ];

    /** Polynôme primitif GF(256) 0x11D (ISO/IEC 18004). */
    private const POLYNOME_PRIMITIF = 0x11D;

    /** Générateur BCH(15,5) de l'information de format. */
    private const GENERATEUR_FORMAT = 0x537;

    private const MASQUE_XOR_FORMAT = 0x5412;

    /** Générateur BCH(18,6) de l'information de version (v7+). */
    private const GENERATEUR_VERSION = 0x1F25;

    /** Table GF : exp[i] = alpha^i. */
    private static ?array $exp = null;

    private static ?array $log = null;

    /**
     * Génère une représentation SVG d'un QR code scannable pour les données fournies.
     */
    public static function generer(string $texte, int $taille = 100): string
    {
        $matrice = self::creerMatrice($texte);
        $tailleMatrice = count($matrice);
        $tailleModule = 3;
        $marge = 4;
        $dimensionTotale = ($tailleMatrice + ($marge * 2)) * $tailleModule;

        $rectangles = [];
        for ($y = 0; $y < $tailleMatrice; $y++) {
            for ($x = 0; $x < $tailleMatrice; $x++) {
                if ($matrice[$y][$x]) {
                    $posX = ($x + $marge) * $tailleModule;
                    $posY = ($y + $marge) * $tailleModule;
                    $rectangles[] = sprintf(
                        '<rect x="%d" y="%d" width="%d" height="%d" fill="#0f172a" />',
                        $posX,
                        $posY,
                        $tailleModule,
                        $tailleModule
                    );
                }
            }
        }

        $rectsSvg = implode("\n    ", $rectangles);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {$dimensionTotale} {$dimensionTotale}" width="{$taille}" height="{$taille}" shape-rendering="crispEdges">
    <rect width="100%" height="100%" fill="#ffffff" />
    {$rectsSvg}
</svg>
SVG;
    }

    /**
     * Construit la matrice QR finale (ISO/IEC 18004), marge de silence exclue.
     *
     * @return array<int, array<int, bool>>
     */
    public static function creerMatrice(string $texte): array
    {
        if ($texte === '') {
            throw new DomainException('QR : contenu vide, rien à encoder.');
        }

        $blocBinaire = self::preparerBlocBinaire($texte);
        $matriceFonctions = self::construireFonctions($blocBinaire['version']);
        $matrice = self::placerZigzag($matriceFonctions['matrice'], $matriceFonctions['fonctions'], $blocBinaire['flux']);

        return self::appliquerMeilleurMasque($matrice, $matriceFonctions['fonctions'], $blocBinaire['niveau']);
    }

    // ─────────────────────────── Flux de données ───────────────────────────

    /**
     * Choix version + niveau, trame de mode octet, remplissage et entrelacement
     * de la correction Reed-Solomon.
     *
     * @return array{flux: string, version: int, niveau: string}
     */
    private static function preparerBlocBinaire(string $texte): array
    {
        $longueur = strlen($texte);

        foreach (['M' => self::CAPACITES_M, 'L' => self::CAPACITES_L] as $niveau => $capacites) {
            foreach ($capacites as $index => $capacite) {
                if ($longueur <= $capacite) {
                    $version = $index + 1;

                    return [
                        'flux' => self::encoderEtEntrelacer($texte, $version, $niveau),
                        'version' => $version,
                        'niveau' => $niveau,
                    ];
                }
            }
        }

        throw new DomainException(
            "QR : contenu trop long ({$longueur} octets ; maximum sans dépendance externe : 271, version 10-L)."
        );
    }

    /**
     * Trame ISO complète : 0100 + longueur(8) + octets + terminateurs + padding,
     * découpé en blocs, Reed-Solomon par bloc, entrelacement données puis RS.
     */
    private static function encoderEtEntrelacer(string $texte, int $version, string $niveau): string
    {
        $blocs = [];
        foreach (self::decoupageBloc($version, $niveau) as [$nbBlocs, $octetsDonnees, $octetsRs]) {
            for ($index = 0; $index < $nbBlocs; $index++) {
                $blocs[] = ['donnees' => $octetsDonnees, 'rs' => $octetsRs];
            }
        }

        // v1-9 : compte sur 8 bits ; v10+ : 16 bits (spec §8.4.1).
        $bitsCompte = $version <= 9 ? 8 : 16;
        $capaciteBits = array_sum(array_column($blocs, 'donnees')) * 8;
        $bits = '0100'.str_pad(decbin(strlen($texte)), $bitsCompte, '0', STR_PAD_LEFT);

        foreach (unpack('C*', $texte) ?: [] as $octet) {
            $bits .= str_pad(decbin($octet), 8, '0', STR_PAD_LEFT);
        }

        // Terminateurs (≤ 4 bits), alignement octet, padding 0xEC/0x11 alterné.
        $bits .= str_repeat('0', min(4, $capaciteBits - strlen($bits)));
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        }
        $pad = true;
        while (strlen($bits) < $capaciteBits) {
            $bits .= str_pad(decbin($pad ? 0xEC : 0x11), 8, '0', STR_PAD_LEFT);
            $pad = ! $pad;
        }

        // Découpage séquentiel en blocs, Reed-Solomon par bloc (longueurs
        // propres à chaque tranche dès la v8 : groupes courts + longs).
        $donnees = [];
        $codesR = [];
        $decalage = 0;
        foreach ($blocs as $bloc) {
            $contenu = substr($bits, $decalage, $bloc['donnees'] * 8);
            $donnees[] = $contenu;
            $codesR[] = self::correctionReedSolomon($contenu, $bloc['rs']);
            $decalage += $bloc['donnees'] * 8;
        }

        // Entrelacement : données en tourniquet (les blocs longs émettent
        // seuls en fin de cycle), puis RS en tourniquet.
        $entrelace = '';
        $plusLong = max(array_column($blocs, 'donnees'));
        for ($position = 0; $position < $plusLong; $position++) {
            foreach ($donnees as $index => $bloc) {
                if ($position < $blocs[$index]['donnees']) {
                    $entrelace .= substr($bloc, $position * 8, 8);
                }
            }
        }
        $plusLongRs = max(array_column($blocs, 'rs'));
        for ($position = 0; $position < $plusLongRs; $position++) {
            foreach ($codesR as $index => $code) {
                if ($position < $blocs[$index]['rs']) {
                    $entrelace .= substr($code, $position * 8, 8);
                }
            }
        }

        return $entrelace.str_repeat('0', self::bitsRestants($version));
    }

    private static function decoupageBloc(int $version, string $niveau): array
    {
        return ($niveau === 'M' ? self::BLOCS_M : self::BLOCS_L)[$version - 1];
    }

    /** Bits de reste (0 pour v1 et v7-13, 7 pour v2-6). */
    private static function bitsRestants(int $version): int
    {
        return ($version === 1 || $version >= 7) ? 0 : 7;
    }

    // ─────────────────────────── Reed-Solomon GF(256) ───────────────────────────

    /**
     * Reste de la division polynômiale (données × x^n) / générateur —
     * synthèse codewords RS d'un bloc (champ GF(2^8), poly primitif 0x11D).
     */
    private static function correctionReedSolomon(string $bitsDonnees, int $degres): string
    {
        $donnees = [];
        for ($i = 0; $i < strlen($bitsDonnees); $i += 8) {
            $donnees[] = (int) bindec(substr($bitsDonnees, $i, 8));
        }

        $generateur = [1];
        for ($i = 0; $i < $degres; $i++) {
            $generateur = self::multiplicationPolynome($generateur, [1, self::alpha($i)]);
        }

        $receptacle = array_merge($donnees, array_fill(0, $degres, 0));
        $longueurGenerateur = count($generateur);

        for ($i = 0; $i < count($donnees); $i++) {
            $coefficient = $receptacle[$i];
            if ($coefficient === 0) {
                continue;
            }
            for ($j = 1; $j < $longueurGenerateur; $j++) {
                $receptacle[$i + $j] ^= self::gfMultiplication($generateur[$j], $coefficient);
            }
        }

        $reste = array_slice($receptacle, count($donnees));
        $retour = '';
        foreach ($reste as $octet) {
            $retour .= str_pad(decbin($octet), 8, '0', STR_PAD_LEFT);
        }

        return $retour;
    }

    private static function multiplicationPolynome(array $a, array $b): array
    {
        $resultat = array_fill(0, count($a) + count($b) - 1, 0);
        foreach ($a as $i => $coefA) {
            if ($coefA === 0) {
                continue;
            }
            foreach ($b as $j => $coefB) {
                $resultat[$i + $j] ^= self::gfMultiplication($coefA, $coefB);
            }
        }

        return $resultat;
    }

    private static function alpha(int $indice): int
    {
        self::initialiserChamp();

        return self::$exp[$indice % 255];
    }

    private static function gfMultiplication(int $a, int $b): int
    {
        self::initialiserChamp();

        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::$exp[(self::$log[$a] + self::$log[$b]) % 255];
    }

    private static function initialiserChamp(): void
    {
        if (self::$exp !== null) {
            return;
        }

        $exp = array_fill(0, 255, 0);
        $log = array_fill(0, 256, 0);
        $valeur = 1;
        foreach ($exp as $indice => $rien) {
            $exp[$indice] = $valeur;
            $log[$valeur] = $indice;
            $valeur <<= 1;
            if ($valeur & 0x100) {
                $valeur ^= self::POLYNOME_PRIMITIF;
            }
        }

        self::$exp = $exp;
        self::$log = $log;
    }

    // ─────────────────────────── Patrons fonctionnels ───────────────────────────

    /**
     * Matrice des patrons fonctionnels (mireurs, séparateurs, synchronisation,
     * alignement, zones de format, module sombre toujours actif).
     *
     * @return array{matrice: array<int, array<int, ?bool>>, fonctions: array<int, array<int, bool>>}
     */
    private static function construireFonctions(int $version): array
    {
        $taille = $version * 4 + 17;
        $matrice = array_fill(0, $taille, array_fill(0, $taille, null));
        $fonctions = array_fill(0, $taille, array_fill(0, $taille, false));

        foreach ([[0, 0], [$taille - 7, 0], [0, $taille - 7]] as [$x, $y]) {
            self::placerMireur($matrice, $fonctions, $x, $y);
        }

        // Synchronisation : alternance sombre/claire depuis (6, 6).
        for ($i = 8; $i < $taille - 8; $i++) {
            $sombre = $i % 2 === 0;
            $matrice[6][$i] = $sombre;
            $fonctions[6][$i] = true;
            $matrice[$i][6] = $sombre;
            $fonctions[$i][6] = true;
        }

        // Alignements (v2+) : toutes paires de coordonnées de la version,
        // hors zones de mireurs (les centres sur la ligne/colonne 6 sont
        // valides et interrompent la synchronisation, spec §E.2).
        if ($version >= 2) {
            foreach (self::ALIGNEMENTS[$version - 2] as $x) {
                foreach (self::ALIGNEMENTS[$version - 2] as $y) {
                    $chevaucheMireur = ($x <= 8 && $y <= 8)
                        || ($x >= $taille - 9 && $y <= 8)
                        || ($x <= 8 && $y >= $taille - 9);
                    if (! $chevaucheMireur) {
                        self::placerAlignement($matrice, $fonctions, $x, $y);
                    }
                }
            }
        }

        // Information de version (v7+) : BCH(18,6), deux copies.
        if ($version >= 7) {
            self::dessinerVersion($matrice, $fonctions, $version);
        }

        // Zones d'information de format (double copie + module sombre).
        self::reserverZonesFormat($matrice, $fonctions, $taille);

        return ['matrice' => $matrice, 'fonctions' => $fonctions];
    }

    private static function placerMireur(array &$matrice, array &$fonctions, int $x, int $y): void
    {
        $taille = count($matrice);
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                if ($x + $c < 0 || $x + $c >= $taille || $y + $r < 0 || $y + $r >= $taille) {
                    continue;
                }
                $bord = (($r >= 0 && $r <= 6 && $c >= 0 && $c <= 6)
                        && ($c === 0 || $c === 6 || $r === 0 || $r === 6))
                    || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                $matrice[$y + $r][$x + $c] = $bord;
                $fonctions[$y + $r][$x + $c] = true;
            }
        }
    }

    private static function placerAlignement(array &$matrice, array &$fonctions, int $centreX, int $centreY): void
    {
        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $bord = max(abs($r), abs($c)) === 2 || ($r === 0 && $c === 0);
                $matrice[$centreY + $r][$centreX + $c] = $bord;
                $fonctions[$centreY + $r][$centreX + $c] = true;
            }
        }
    }

    /**
     * Information de version (BCH 18,6, sans masque XOR — spec §E.1) :
     * deux copies de 18 modules, sous le mireur haut-droit et à droite
     * du mireur bas-gauche. Réservées comme modules fonctionnels.
     */
    private static function dessinerVersion(array &$matrice, array &$fonctions, int $version): void
    {
        $reste = $version << 12;
        while (self::longueurBinaire($reste) >= self::longueurBinaire(self::GENERATEUR_VERSION)) {
            $reste ^= self::GENERATEUR_VERSION
                << (self::longueurBinaire($reste) - self::longueurBinaire(self::GENERATEUR_VERSION));
        }
        $bits = ($version << 12) | $reste;

        $taille = count($matrice);
        for ($i = 0; $i < 18; $i++) {
            $bit = self::bit($bits, $i);
            $matrice[(int) ($i / 3)][$i % 3 + $taille - 11] = $bit;
            $fonctions[(int) ($i / 3)][$i % 3 + $taille - 11] = true;
            $matrice[$i % 3 + $taille - 11][(int) ($i / 3)] = $bit;
            $fonctions[$i % 3 + $taille - 11][(int) ($i / 3)] = true;
        }
    }

    private static function longueurBinaire(int $valeur): int
    {
        $longueur = 0;
        while ($valeur > 0) {
            $longueur++;
            $valeur >>= 1;
        }

        return $longueur;
    }

    private static function reserverZonesFormat(array &$matrice, array &$fonctions, int $taille): void
    {
        for ($i = 0; $i <= 8; $i++) {
            if ($i !== 6) {
                $matrice[8][$i] = null;
                $fonctions[8][$i] = true;
                $matrice[$i][8] = null;
                $fonctions[$i][8] = true;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $matrice[8][$taille - 1 - $i] = null;
            $fonctions[8][$taille - 1 - $i] = true;
            $matrice[$taille - 1 - $i][8] = null;
            $fonctions[$taille - 1 - $i][8] = true;
        }
        // Module sombre permanent (coin bas-gauche, spec §E.2.1).
        $matrice[$taille - 8][8] = true;
        $fonctions[$taille - 8][8] = true;
    }

    // ─────────────────────────── Données en zigzag ───────────────────────────

    /**
     * Placement en zigzag des bits (deux colonnes, montée-descente,
     * colonne de synchronisation évitée), patrons fonctionnels exclus.
     */
    private static function placerZigzag(array $matrice, array $fonctions, string $flux): array
    {
        $taille = count($matrice);
        $indice = 0;
        $longueur = strlen($flux);
        $versLeHaut = true;

        for ($colonne = $taille - 1; $colonne >= 1; $colonne -= 2) {
            if ($colonne === 6) {
                $colonne = 5;
            }
            for ($verticale = 0; $verticale < $taille; $verticale++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $colonne - $j;
                    $y = $versLeHaut ? $taille - 1 - $verticale : $verticale;
                    if ($fonctions[$y][$x] === false && $indice < $longueur) {
                        $matrice[$y][$x] = $flux[$indice] === '1';
                        $indice++;
                    }
                }
            }
            $versLeHaut = ! $versLeHaut;
        }

        if ($indice < $longueur) {
            throw new DomainException('QR : incohérence de capacité (bug d\'implémentation, à signaler).');
        }

        return $matrice;
    }

    // ─────────────────────────── Masques + information de format ───────────────────────────

    /**
     * Évalue les 8 masques (règles de pénalité 1-4, spec §8.8.2),
     * retient le moins pénalisé puis grave l'information de format.
     *
     * @param  array<int, array<int, ?bool>>  $matrice
     * @param  array<int, array<int, bool>>  $fonctions
     * @return array<int, array<int, bool>>
     */
    private static function appliquerMeilleurMasque(array $matrice, array $fonctions, string $niveau): array
    {
        $taille = count($matrice);
        $meilleurMasque = 0;
        $meilleurPenalite = PHP_INT_MAX;
        $meilleurMatrice = null;

        for ($masque = 0; $masque < 8; $masque++) {
            $candidat = self::masquer($matrice, $fonctions, $masque);
            $penalite = self::penalite($candidat);
            if ($penalite < $meilleurPenalite) {
                $meilleurPenalite = $penalite;
                $meilleurMasque = $masque;
                $meilleurMatrice = $candidat;
            }
        }

        self::dessinerFormat($meilleurMatrice, $niveau, $meilleurMasque);

        return array_map(fn (array $ligne): array => array_map(
            fn (?bool $module): bool => (bool) $module,
            $ligne
        ), $meilleurMatrice);
    }

    /**
     * Transformation de la matrice en définitive : les modules data
     * sont inversés par le masque, les fonctionnels restent littéraux.
     *
     * @param  array<int, array<int, ?bool>>  $matrice
     * @param  array<int, array<int, bool>>  $fonctions
     * @return array<int, array<int, bool>>
     */
    private static function masquer(array $matrice, array $fonctions, int $masque): array
    {
        $taille = count($matrice);
        $sortie = [];
        for ($y = 0; $y < $taille; $y++) {
            $sortie[$y] = [];
            for ($x = 0; $x < $taille; $x++) {
                $sombre = (bool) $matrice[$y][$x];
                if ($fonctions[$y][$x] === false) {
                    $sombre = $sombre !== self::moduleMasqueLit($masque, $x, $y);
                }
                $sortie[$y][$x] = $sombre;
            }
        }

        return $sortie;
    }

    private static function moduleMasqueLit(int $masque, int $x, int $y): bool
    {
        return match ($masque) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => ((int) ($x / 3) + (int) ($y / 2)) % 2 === 0,
            5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
            6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
            default => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
        };
    }

    /** Règles de pénalité 1-4 (spec §8.8.2) sur la matrice masquée. */
    private static function penalite(array $matrice): int
    {
        $taille = count($matrice);
        $score = 0;

        // Règle 1 : séries identiques en ligne et colonne (≥ 5 : +3 par excédent).
        $score += self::penaliteSeries($matrice, $taille, true) + self::penaliteSeries($matrice, $taille, false);

        // Règle 2 : blocs 2×2 monochromes : +3 chacun.
        for ($y = 0; $y < $taille - 1; $y++) {
            for ($x = 0; $x < $taille - 1; $x++) {
                if ($matrice[$y][$x] === $matrice[$y][$x + 1]
                    && $matrice[$y][$x] === $matrice[$y + 1][$x]
                    && $matrice[$y][$x] === $matrice[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        // Règle 3 : motif de mireur avec marge (horizontale ET verticale) : +40 chacun.
        $score += self::penaliteMireursLignes($matrice, $taille, true) + self::penaliteMireursLignes($matrice, $taille, false);

        // Règle 4 : écart à 50 % de sombres (paliers de 5 % : +10/chacun).
        $sombres = 0;
        foreach ($matrice as $ligne) {
            foreach ($ligne as $module) {
                if ($module) {
                    $sombres++;
                }
            }
        }
        $score += (int) (abs($sombres * 20 - $taille * $taille * 10) / ($taille * $taille)) * 10;

        return $score;
    }

    private static function penaliteSeries(array $matrice, int $taille, bool $horizontal): int
    {
        $score = 0;
        for ($axe = 0; $axe < $taille; $axe++) {
            $serie = 1;
            $precedent = null;
            for ($i = 0; $i < $taille; $i++) {
                $module = $horizontal ? $matrice[$axe][$i] : $matrice[$i][$axe];
                if ($module === $precedent) {
                    $serie++;
                } else {
                    if ($serie >= 5) {
                        $score += $serie - 2;
                    }
                    $serie = 1;
                    $precedent = $module;
                }
            }
            if ($serie >= 5) {
                $score += $serie - 2;
            }
        }

        return $score;
    }

    private static function penaliteMireursLignes(array $matrice, int $taille, bool $horizontal): int
    {
        $score = 0;

        for ($axe = 0; $axe < $taille; $axe++) {
            $ligne = '';
            for ($i = 0; $i < $taille; $i++) {
                $ligne .= ($horizontal ? $matrice[$axe][$i] : $matrice[$i][$axe]) ? '1' : '0';
            }
            $score += 40 * preg_match_all('/(?=(00001011101|10111010000))/', $ligne);
        }

        return $score;
    }

    /** Gravure des deux copies de l'information de format (BCH + XOR 0x5412). */
    private static function dessinerFormat(array &$matrice, string $niveau, int $masque): void
    {
        $bitsData = ($niveau === 'M' ? 0b00 : 0b01) << 3 | $masque;
        $reste = $bitsData;
        for ($i = 0; $i < 10; $i++) {
            $reste = ($reste << 1) ^ ((($reste >> 9) & 1) * self::GENERATEUR_FORMAT);
        }
        $bits = (($bitsData << 10) | ($reste & 0x3FF)) ^ self::MASQUE_XOR_FORMAT;

        $taille = count($matrice);
        for ($i = 0; $i <= 5; $i++) {
            $matrice[$i][8] = self::bit($bits, $i);
        }
        $matrice[7][8] = self::bit($bits, 6);
        $matrice[8][8] = self::bit($bits, 7);
        $matrice[8][7] = self::bit($bits, 8);
        for ($i = 9; $i < 15; $i++) {
            $matrice[8][14 - $i] = self::bit($bits, $i);
        }
        for ($i = 0; $i < 8; $i++) {
            $matrice[8][$taille - 1 - $i] = self::bit($bits, $i);
        }
        for ($i = 8; $i < 15; $i++) {
            $matrice[$taille - 15 + $i][8] = self::bit($bits, $i);
        }
    }

    private static function bit(int $valeur, int $position): bool
    {
        return (($valeur >> $position) & 1) === 1;
    }
}
