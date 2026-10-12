<?php

namespace App\Services\Identity;

use Illuminate\Support\Str;

/**
 * Prétraitement image OCR (§4) — GD natif, aucune dépendance externe.
 *
 * Produit plusieurs VARIANTES (jamais un filtre unique) :
 *   original | gris_contraste | gris_net_agrandi | binarisee
 * + OCR par ZONES (§5) : découpe selon les coordonnées NORMALISÉES de
 * config/cin_tunisia.php (fractions 0-1 → indépendant de la résolution).
 *
 * Règles :
 * - fail-soft : image illisible → liste vide (saisie manuelle) ;
 * - fichiers temporaires UUID dans le même répertoire privé que le
 *   document (jamais public/) — SUPPRIMÉS par le service appelant ;
 * - correction d'orientation EXIF (photos téléphone) au chargement.
 */
final class ImagePreprocessor
{
    /**
     * Charge une image (JPEG/PNG) en ressource GD, orientation EXIF
     * corrigée (§4 étape 1) ; null si illisible (fail-soft).
     *
     * @return resource|\GdImage|null
     */
    public function charger(string $cheminAbsolu)
    {
        $contenu = @file_get_contents($cheminAbsolu);

        if ($contenu === false) {
            return null;
        }

        $image = @imagecreatefromstring($contenu);

        if ($image === false) {
            return null;
        }

        return $this->orienter($image, $cheminAbsolu);
    }

    /**
     * Variantes de prétraitement activées par la configuration (§4).
     * Retourne [clé => chemin PNG] — chemins temporaires UUID.
     *
     * @return array<string, string>
     */
    public function variantes(string $cheminAbsolu): array
    {
        $image = $this->charger($cheminAbsolu);

        if ($image === null) {
            return [];
        }

        $actives = array_keys(array_filter((array) config('cin_tunisia.variantes', [
            'original' => true, 'gris_contraste' => true, 'gris_net_agrandi' => true, 'binarisee' => true,
        ])));

        $sorties = [];
        $repertoire = dirname($cheminAbsolu);

        foreach ($actives as $variante) {
            $copie = $this->copier($image);

            switch ($variante) {
                case 'gris_contraste':
                    imagefilter($copie, IMG_FILTER_GRAYSCALE);
                    imagefilter($copie, IMG_FILTER_CONTRAST, -35);
                    break;

                case 'gris_net_agrandi':
                    imagefilter($copie, IMG_FILTER_GRAYSCALE);
                    imagefilter($copie, IMG_FILTER_CONTRAST, -35);
                    $copie = $this->agrandir($copie, 1.5);
                    $copie = $this->accentuer($copie);
                    break;

                case 'binarisee':
                    imagefilter($copie, IMG_FILTER_GRAYSCALE);
                    $copie = $this->binariser($copie, seuil: 140);
                    break;
            }

            $chemin = $repertoire.'/'.Str::uuid()->toString().'-'.$variante.'.png';

            if (imagepng($copie, $chemin)) {
                // GD écrit un chunk pHYs = 96 DPI qui fait dériver la
                // segmentation de leptonica (zones mal redimensionnées) →
                // retiré systématiquement du PNG écrit (§4 calibration).
                $this->retirerPhys($chemin);
                $sorties[$variante] = $chemin;
            }

            imagedestroy($copie);
        }

        imagedestroy($image);

        return $sorties;
    }

    /**
     * Découpe une zone selon une région NORMALISÉE [x1, y1, x2, y2]
     * (fractions 0-1, §5) → PNG temporaire, ou null si zone vide.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $rect
     */
    public function decouper(string $cheminAbsolu, array $rect): ?string
    {
        $image = $this->charger($cheminAbsolu);

        if ($image === null) {
            return null;
        }

        $largeur = imagesx($image);
        $hauteur = imagesy($image);

        $x1 = max(0, (int) round($rect[0] * $largeur));
        $y1 = max(0, (int) round($rect[1] * $hauteur));
        $x2 = min($largeur, (int) round($rect[2] * $largeur));
        $y2 = min($hauteur, (int) round($rect[3] * $hauteur));

        $zoneLargeur = $x2 - $x1;
        $zoneHauteur = $y2 - $y1;

        if ($zoneLargeur < 10 || $zoneHauteur < 10) {
            imagedestroy($image);

            return null;
        }

        $zone = imagecreatetruecolor($zoneLargeur, $zoneHauteur);
        imagecopy($zone, $image, 0, 0, $x1, $y1, $zoneLargeur, $zoneHauteur);
        imagedestroy($image);

        // Une petite marge blanche améliore la segmentation Tesseract
        $avecMarge = imagecreatetruecolor($zoneLargeur + 40, $zoneHauteur + 40);
        $blanc = imagecolorallocate($avecMarge, 255, 255, 255);
        imagefill($avecMarge, 0, 0, $blanc);
        imagecopy($avecMarge, $zone, 20, 20, 0, 0, $zoneLargeur, $zoneHauteur);
        imagedestroy($zone);

        $chemin = dirname($cheminAbsolu).'/'.Str::uuid()->toString().'-zone.png';
        $ok = imagepng($avecMarge, $chemin);
        imagedestroy($avecMarge);

        if (! $ok) {
            return null;
        }

        // Même cause corrective : le chunk pHYs écrit par GD (96 DPI)
        // dégrade la lecture Tesseract des zones → retiré du PNG écrit.
        $this->retirerPhys($chemin);

        return $chemin;
    }

    /**
     * Retire le chunk pHYs d'un fichier PNG écrit par GD.
     *
     * GD écrit systématiquement pHYs = 3780 px/m (96 DPI). Leptonica
     * (Tesseract) se fie au DPI déclaré pour redimensionner les petites
     * images — avec une zone de 1 700 px déclarée 96 DPI, le redimensionnement
     * automatique dégrade les glyphes arabes (تفسير : « محمد ياسين » devenait
     * « محمد با »). Sans chunk pHYs, Tesseract lit les zones fidèlement.
     *
     * Manipulation de chunks PNG : [4 octets longueur][4 octets type]
     * [données][4 octets CRC] — CRC conservé tel quel (données inchangées).
     */
    public function retirerPhys(string $chemin): void
    {
        $octets = @file_get_contents($chemin);

        if ($octets === false || mb_strpos($octets, "\x89PNG", 0) !== 0) {
            return;
        }

        $sortie = substr($octets, 0, 8);
        $offset = 8;
        $fin = strlen($octets);

        while ($offset + 8 < $fin) {
            $longueur = unpack('N', substr($octets, $offset, 4))[1];
            $type = substr($octets, $offset + 4, 4);

            // On ne conserve QUE les chunks essentiels et sûrs
            if (! in_array($type, ['pHYs', 'sRGB', 'gAMA', 'iCCP', 'cHRM', 'bKGD', 'tIME'], true)) {
                $sortie .= substr($octets, $offset, 8 + $longueur + 4);
            }

            $offset += 12 + $longueur;
        }

        file_put_contents($chemin, $sortie);
    }

    /**
     * Supprime un fichier temporaire (variantes/zones) — best effort.
     */
    public function purger(?string $chemin): void
    {
        if ($chemin !== null && str_ends_with($chemin, '.png') && @unlink($chemin)) {
            // purgé
        }
    }

    /**
     * Orientation EXIF (photos smartphone) : rotations/miroirs JPEG.
     *
     * @param  resource|\GdImage  $image
     * @return resource|\GdImage
     */
    private function orienter($image, string $cheminAbsolu)
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($cheminAbsolu);
        $orientation = $exif['Orientation'] ?? 1;

        $rotation = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($rotation === 0) {
            return $image;
        }

        $retourne = imagerotate($image, $rotation, 0);

        if ($retourne !== false) {
            imagedestroy($image);

            return $retourne;
        }

        return $image;
    }

    /**
     * @param  resource|\GdImage  $image
     * @return resource|\GdImage
     */
    private function copier($image)
    {
        $largeur = imagesx($image);
        $hauteur = imagesy($image);
        $copie = imagecreatetruecolor($largeur, $hauteur);
        imagecopy($copie, $image, 0, 0, 0, 0, $largeur, $hauteur);

        return $copie;
    }

    /**
     * @param  resource|\GdImage  $image
     * @return resource|\GdImage
     */
    private function agrandir($image, float $facteur)
    {
        $largeur = (int) round(imagesx($image) * $facteur);
        $hauteur = (int) round(imagesy($image) * $facteur);
        $agrandie = imagecreatetruecolor($largeur, $hauteur);
        imagecopyresampled($agrandie, $image, 0, 0, 0, 0, $largeur, $hauteur, imagesx($image), imagesy($image));
        imagedestroy($image);

        return $agrandie;
    }

    /**
     * Netteté par convolution (noyau standard) — lisibilité des points
     * arabes (ب/ي) avant OCR.
     *
     * @param  resource|\GdImage  $image
     * @return resource|\GdImage
     */
    private function accentuer($image)
    {
        $noyau = [
            [0.0, -1.0, 0.0],
            [-1.0, 5.0, -1.0],
            [0.0, -1.0, 0.0],
        ];

        // imageconvolution exige un divisor et offset
        $divisor = 1;
        imageconvolution($image, $noyau, $divisor, 0);

        return $image;
    }

    /**
     * Binarisation par seuil fixe sur image grisée (§4 étape 9).
     *
     * @param  resource|\GdImage  $image
     * @return resource|\GdImage
     */
    private function binariser($image, int $seuil)
    {
        $largeur = imagesx($image);
        $hauteur = imagesy($image);

        for ($x = 0; $x < $largeur; $x++) {
            for ($y = 0; $y < $hauteur; $y++) {
                $gris = (imagecolorat($image, $x, $y) & 0xFF);
                $couleur = $gris > $seuil ? 0xFFFFFF : 0x000000;
                imagesetpixel($image, $x, $y, $couleur);
            }
        }

        return $image;
    }
}
