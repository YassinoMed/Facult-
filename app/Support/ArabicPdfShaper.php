<?php

namespace App\Support;

/**
 * Moteur de mise en forme contextuelle des glyphes arabes et BiDi pour Dompdf.
 *
 * Résout la limitation documentée de Dompdf (absence de GSUB / HarfBuzz / BiDi) :
 * 1. Détection des caractères arabes dans les nœuds de texte HTML ;
 * 2. Mise en forme contextuelle Unicode Presentation Forms-B (isolé, final, initial, médian) ;
 * 3. Fusion des ligatures Lam-Alif (لا, لأ, لإ, لآ) ;
 * 4. Réordonnancement visuel BiDi (inversion des runs arabes pour le moteur LTR de Dompdf,
 *    avec préservation stricte de l'ordre LTR pour les nombres, dates, et portions latines).
 */
final class ArabicPdfShaper
{
    private const DUAL_JOINING = [
        0x0626, 0x0628, 0x062A, 0x062B, 0x062C, 0x062D, 0x062E,
        0x0633, 0x0634, 0x0635, 0x0636, 0x0637, 0x0638, 0x0639, 0x063A,
        0x0640, 0x0641, 0x0642, 0x0643, 0x0644, 0x0645, 0x0646, 0x0647, 0x064A,
    ];

    private const RIGHT_JOINING = [
        0x0622, 0x0623, 0x0624, 0x0625, 0x0627, 0x0629, 0x062F, 0x0630,
        0x0631, 0x0632, 0x0648, 0x0649, 0x0671,
    ];

    /**
     * Table des glyphes [isolé, final, initial, médian].
     */
    private const GLYPHS = [
        0x0621 => [0xFE80, 0xFE80, 0xFE80, 0xFE80], // ء
        0x0622 => [0xFE81, 0xFE82, 0xFE81, 0xFE82], // آ
        0x0623 => [0xFE83, 0xFE84, 0xFE83, 0xFE84], // أ
        0x0624 => [0xFE85, 0xFE86, 0xFE85, 0xFE86], // ؤ
        0x0625 => [0xFE87, 0xFE88, 0xFE87, 0xFE88], // إ
        0x0626 => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C], // ئ
        0x0627 => [0xFE8D, 0xFE8E, 0xFE8D, 0xFE8E], // ا
        0x0628 => [0xFE8F, 0xFE90, 0xFE91, 0xFE92], // ب
        0x0629 => [0xFE93, 0xFE94, 0xFE93, 0xFE94], // ة
        0x062A => [0xFE95, 0xFE96, 0xFE97, 0xFE98], // ت
        0x062B => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C], // ث
        0x062C => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0], // ج
        0x062D => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4], // ح
        0x062E => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8], // خ
        0x062F => [0xFEA9, 0xFEAA, 0xFEA9, 0xFEAA], // د
        0x0630 => [0xFEAB, 0xFEAC, 0xFEAB, 0xFEAC], // ذ
        0x0631 => [0xFEAD, 0xFEAE, 0xFEAD, 0xFEAE], // ر
        0x0632 => [0xFEAF, 0xFEB0, 0xFEAF, 0xFEB0], // ز
        0x0633 => [0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4], // س
        0x0634 => [0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8], // ش
        0x0635 => [0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC], // ص
        0x0636 => [0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0], // ض
        0x0637 => [0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4], // ط
        0x0638 => [0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8], // ظ
        0x0639 => [0xFEC9, 0xFECA, 0xFECB, 0xFECC], // ع
        0x063A => [0xFECD, 0xFECE, 0xFECF, 0xFED0], // غ
        0x0640 => [0x0640, 0x0640, 0x0640, 0x0640], // ـ
        0x0641 => [0xFED1, 0xFED2, 0xFED3, 0xFED4], // ف
        0x0642 => [0xFED5, 0xFED6, 0xFED7, 0xFED8], // ق
        0x0643 => [0xFED9, 0xFEDA, 0xFEDB, 0xFEDC], // ك
        0x0644 => [0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0], // ل
        0x0645 => [0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4], // م
        0x0646 => [0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8], // ن
        0x0647 => [0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC], // ه
        0x0648 => [0xFEED, 0xFEEE, 0xFEED, 0xFEEE], // و
        0x0649 => [0xFEEF, 0xFEF0, 0xFEEF, 0xFEF0], // ى
        0x064A => [0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4], // ي
        0x0671 => [0xFB50, 0xFB51, 0xFB50, 0xFB51], // ٱ
    ];

    /**
     * Ligatures Lam-Alif [isolé, final].
     */
    private const LAM_ALIF = [
        0x0622 => [0xFEF5, 0xFEF6],
        0x0623 => [0xFEF7, 0xFEF8],
        0x0625 => [0xFEF9, 0xFEFA],
        0x0627 => [0xFEFB, 0xFEFC],
        0x0671 => [0xFEFB, 0xFEFC],
    ];

    /**
     * Met en forme et réordonne l'ensemble du HTML pour Dompdf.
     */
    public static function preparerHtmlPourDompdf(string $html): string
    {
        if (! preg_match('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $html)) {
            return $html;
        }

        // Protéger le bloc <style>...</style> et <script>...</script> (les SVG sont façonnés pour leurs balises <text>)
        $proteges = [];
        $htmlSansProteges = preg_replace_callback(
            '/<(style|script)\b[^>]*>.*?<\/\1>/si',
            function ($matches) use (&$proteges) {
                $cle = '___PROTEGE_'.count($proteges).'___';
                $proteges[$cle] = $matches[0];

                return $cle;
            },
            $html
        );

        // Découper le HTML en alternance balises / texte
        $parties = preg_split('/(<[^>]+>)/u', $htmlSansProteges, -1, PREG_SPLIT_DELIM_CAPTURE);
        $resultat = '';

        foreach ($parties as $partie) {
            if ($partie === '') {
                continue;
            }

            // Balise HTML : laisser intacte
            if ($partie[0] === '<' && substr($partie, -1) === '>') {
                $resultat .= $partie;

                continue;
            }

            // Placeholder protégé
            if (isset($proteges[$partie])) {
                $resultat .= $proteges[$partie];
                unset($proteges[$partie]);

                continue;
            }

            // Texte : appliquer le façonnage arabe s'il contient de l'arabe
            if (preg_match('/[\x{0600}-\x{06FF}]/u', $partie)) {
                $resultat .= self::traiterTexte($partie);
            } else {
                $resultat .= $partie;
            }
        }

        // Restaurer les éléments protégés restants au cas où
        foreach ($proteges as $cle => $contenu) {
            $resultat = str_replace($cle, $contenu, $resultat);
        }

        return $resultat;
    }

    /**
     * Traite un nœud de texte : façonne les glyphes arabes et réordonne BiDi.
     */
    public static function traiterTexte(string $texte): string
    {
        $texte = html_entity_decode($texte, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Préserver les retours à la ligne
        $lignes = explode("\n", $texte);
        $lignesTraitees = [];

        foreach ($lignes as $ligne) {
            if (! preg_match('/[\x{0600}-\x{06FF}]/u', $ligne)) {
                $lignesTraitees[] = $ligne;

                continue;
            }

            $lignesTraitees[] = self::traiterLigne($ligne);
        }

        return implode("\n", $lignesTraitees);
    }

    /**
     * Façonne et inverse une ligne de texte mixte.
     */
    public static function traiterLigne(string $ligne): string
    {
        // Découper en segments arabes vs non-arabes
        $parties = preg_split('/([\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]+)/u', $ligne, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $segmentsTraites = [];

        foreach ($parties as $partie) {
            if (preg_match('/[\x{0600}-\x{06FF}]/u', $partie)) {
                // Segment arabe : mise en forme contextuelle des glyphes + inversion de l'ordre des caractères
                $faconne = self::faconnerGlyphes($partie);
                $segmentsTraites[] = self::inverserCaracteresSimples($faconne);
            } else {
                // Segment non-arabe (mots latins, dates, nombres, ponctuation, espaces)
                $segmentsTraites[] = self::traiterSegmentNonArabe($partie);
            }
        }

        // Inverser l'ordre des segments dans la ligne pour l'affichage RTL sous Dompdf LTR
        return implode('', array_reverse($segmentsTraites));
    }

    /**
     * Traite les portions non-arabes (conserve l'ordre LTR des mots et nombres, inverse/miroir ponctuation).
     */
    private static function traiterSegmentNonArabe(string $partie): string
    {
        // Sépare les blocs LTR (mots latins / chiffres / dates avec tirets/slashes) de la ponctuation / espaces
        $sousTokens = preg_split('/([\p{L}\p{N}]+(?:[\s\/\.\-@&][\p{L}\p{N}]+)*)/u', $partie, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $traites = [];

        foreach ($sousTokens as $st) {
            if (preg_match('/[\p{L}\p{N}]/u', $st) && ! preg_match('/[\x{0600}-\x{06FF}]/u', $st)) {
                // Bloc LTR (nom propre, chiffre, date, mention en français) : conservé dans l'ordre de lecture
                $traites[] = $st;
            } else {
                // Ponctuation, parenthèses, crochets : miroir et inversion
                $traites[] = self::inverserEtMiroirPonctuation($st);
            }
        }

        return implode('', array_reverse($traites));
    }

    /**
     * Convertit les caractères arabes de $chaine en leurs formes contextuelles Presentation Forms-B.
     */
    public static function faconnerGlyphes(string $chaine): string
    {
        $codes = [];
        $len = mb_strlen($chaine, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $codes[] = mb_ord(mb_substr($chaine, $i, 1, 'UTF-8'), 'UTF-8');
        }

        $codesFaçonnes = [];
        $count = count($codes);
        $peutConnecterPrecedent = false;

        for ($i = 0; $i < $count; $i++) {
            $code = $codes[$i];

            // Tashkeel / diacritiques arabes : préserver sans casser la liaison
            if (($code >= 0x064B && $code <= 0x065F) || $code === 0x0670) {
                $codesFaçonnes[] = $code;

                continue;
            }

            // Ligature Lam-Alif
            if ($code === 0x0644 && $i + 1 < $count && isset(self::LAM_ALIF[$codes[$i + 1]])) {
                $codeAlef = $codes[$i + 1];
                $codesFaçonnes[] = $peutConnecterPrecedent
                    ? self::LAM_ALIF[$codeAlef][1]
                    : self::LAM_ALIF[$codeAlef][0];
                $peutConnecterPrecedent = false;
                $i++;

                continue;
            }

            // Caractère non arabe standard dans la table
            if (! isset(self::GLYPHS[$code])) {
                $codesFaçonnes[] = $code;
                $peutConnecterPrecedent = false;

                continue;
            }

            $estDoubleLiaison = in_array($code, self::DUAL_JOINING, true);
            $suivantPeutConnecter = false;

            if ($i + 1 < $count) {
                $k = $i + 1;
                // Sauter les tashkeel pour inspecter le caractère suivant
                while ($k < $count && (($codes[$k] >= 0x064B && $codes[$k] <= 0x065F) || $codes[$k] === 0x0670)) {
                    $k++;
                }
                if ($k < $count) {
                    $codeSuivant = $codes[$k];
                    $suivantPeutConnecter = in_array($codeSuivant, self::DUAL_JOINING, true)
                        || in_array($codeSuivant, self::RIGHT_JOINING, true);
                }
            }

            $connecteSuivant = $estDoubleLiaison && $suivantPeutConnecter;

            $indexForme = match (true) {
                $peutConnecterPrecedent && $connecteSuivant => 3, // Médian
                $peutConnecterPrecedent && ! $connecteSuivant => 1, // Final
                ! $peutConnecterPrecedent && $connecteSuivant => 2, // Initial
                default => 0, // Isolé
            };

            $codesFaçonnes[] = self::GLYPHS[$code][$indexForme];
            $peutConnecterPrecedent = $estDoubleLiaison && $connecteSuivant;
        }

        $sortie = '';
        foreach ($codesFaçonnes as $c) {
            $sortie .= mb_chr($c, 'UTF-8');
        }

        return $sortie;
    }

    /**
     * Inverse simplement les caractères d'un mot arabe déjà façonné.
     */
    private static function inverserCaracteresSimples(string $texte): string
    {
        $inverse = '';
        $len = mb_strlen($texte, 'UTF-8');

        for ($i = $len - 1; $i >= 0; $i--) {
            $inverse .= mb_substr($texte, $i, 1, 'UTF-8');
        }

        return $inverse;
    }

    /**
     * Inverse et applique le miroir BiDi sur la ponctuation et les délimiteurs.
     */
    private static function inverserEtMiroirPonctuation(string $texte): string
    {
        $inverse = '';
        $len = mb_strlen($texte, 'UTF-8');

        for ($i = $len - 1; $i >= 0; $i--) {
            $c = mb_substr($texte, $i, 1, 'UTF-8');
            $inverse .= match ($c) {
                '(' => ')',
                ')' => '(',
                '[' => ']',
                ']' => '[',
                '{' => '}',
                '}' => '{',
                '«' => '»',
                '»' => '«',
                default => $c,
            };
        }

        return $inverse;
    }
}
