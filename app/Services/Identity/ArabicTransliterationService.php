<?php

namespace App\Services\Identity;

use App\Models\LieuNaissance;

/**
 * Translittération arabe → latin DÉTERMINISTE (§7-8, §13-14).
 *
 * Pipeline par mot : normalisation arabe → dictionnaire des noms
 * propres courants (tunisiens) → repli lettre-à-lettre (conventions
 * francophones tunisiennes) → capitalisation des noms propres.
 *
 * Exemples :
 *   محمد   → Mohamed   (dictionnaire — un repli lettre-à-lettre
 *                      donnerait « Mhmd », pas la forme attendue)
 *   بن علي → Ben Ali
 *   صفاقس  → Sfax      (table administrative des lieux, §14)
 *
 * §13 : CHAMPS STRUCTURÉS (CIN, dates, sexe) = normalisation ;
 *       NOMS PROPRES (nom, prénom, lieu) = translittération.
 * Ce service ne traite QUE la seconde famille.
 */
final class ArabicTransliterationService implements TransliterationServiceInterface
{
    /**
     * Dictionnaire des TOKENS de noms propres courants (extensible).
     * Les clés sont les formes NORMALISÉES (ArabicNormalizer::normaliser
     * est appliqué avant recherche : أحمد → احمد).
     */
    private const DICTIONNAIRE = [
        // Particules et théophores
        'بن' => 'Ben', 'عبد' => 'Abd', 'العبد' => 'Elabd', 'ابن' => 'Ibn',

        // Prénoms masculins fréquents
        'محمد' => 'Mohamed', 'احمد' => 'Ahmed', 'علي' => 'Ali',
        'محمود' => 'Mahmoud', 'خالد' => 'Khaled', 'يوسف' => 'Youssef',
        'كريم' => 'Karim', 'سامي' => 'Sami', 'هشام' => 'Hichem',
        'طارق' => 'Tarek', 'وسيم' => 'Wassim', 'بلال' => 'Bilel',
        'ايوب' => 'Ayoub', 'زياد' => 'Zied', 'انيس' => 'Anis',
        'نبيل' => 'Nabil', 'رشيد' => 'Rachid', 'منصف' => 'Moncef',
        'الحبيب' => 'Elhabib', 'صلاح' => 'Salah', 'فوزي' => 'Fawzi',
        'سليم' => 'Slim', 'منير' => 'Mounir', 'جمال' => 'Jamel',
        'عز الدين' => 'Ezzedine', 'محمد علي' => 'Mohamed Ali',

        // Prénoms féminins fréquents
        'فاطمة' => 'Fatma', 'مريم' => 'Meriem', 'امينة' => 'Amina',
        'سلمى' => 'Salma', 'زينب' => 'Zineb', 'هند' => 'Hend',
        'اسماء' => 'Asma', 'رانية' => 'Rania', 'ايهاب' => 'Ihab',
        'سارة' => 'Sarra', 'نبيلة' => 'Nabila', 'سونيا' => 'Sonia',
        'درة' => 'Dorra', 'بسمة' => 'Basma', 'وليدة' => 'Walida',
        'فاطمة الزهراء' => 'Fatma Ezzahra',

        // Nationalités / gentilés (§4)
        'تونسية' => 'Tunisienne', 'تونسي' => 'Tunisien', 'تونسيين' => 'Tunisiens',

        // Tokens courants (compléments fixture/réels CIN — §10/§27)
        'ياسين' => 'Yassine',
        'بوناب' => 'Bounab',
        'قيس' => 'Kaies', 'رياض' => 'Riadh', 'وسام' => 'Wassim',
        'أمين' => 'Amine', 'باسم' => 'Bassem', 'فادي' => 'Fadi',
        'نور' => 'Nour', 'إيمان' => 'Imen', 'نسرين' => 'Nesrine',
        'شكري' => 'Chokri', 'هشام' => 'Hichem',
    ];

    /**
     * Repli lettre-à-lettre (conventions francophones tunisiennes).
     * S'applique à tout token absent du dictionnaire — déterministe.
     */
    private const LETTRES = [
        'ا' => 'a', 'ب' => 'b', 'ت' => 't', 'ث' => 'th', 'ج' => 'j',
        'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'dh', 'ر' => 'r',
        'ز' => 'z', 'س' => 's', 'ش' => 'ch', 'ص' => 's', 'ض' => 'd',
        'ط' => 't', 'ظ' => 'z', 'ع' => '', 'غ' => 'gh', 'ف' => 'f',
        'ق' => 'k', 'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n',
        'ه' => 'h', 'و' => 'ou', 'ي' => 'i', 'ة' => 'a', 'ى' => 'a',
        'ء' => '', 'آ' => 'a', 'أ' => 'a', 'إ' => 'a', 'ؤ' => 'ou',
        'ئ' => 'i', 'ٱ' => 'a',
    ];

    public function transliterate(string $texte): string
    {
        $texte = ArabicNormalizer::normaliser($texte);

        if ($texte === '') {
            return '';
        }

        // Chaque mot est traité indépendamment ; les mots déjà latins
        // (documents mixtes) sont conservés tels quels.
        $mots = preg_split('/\s+/u', $texte) ?: [];
        $translittères = [];

        foreach ($mots as $mot) {
            $translittères[] = $this->translittererMot($mot);
        }

        $resultat = implode(' ', $translittères);

        // Capitalisation des noms propres : « ben ali » → « Ben Ali »
        return mb_convert_case($resultat, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Lieu de naissance (§14) : correspondance CONTRÔLÉE via la table
     * administrative `lieux_naissance` en priorité — jamais une simple
     * traduction automatique. Repli : translittération lettre-à-lettre
     * si le lieu est inconnu de la table.
     */
    public function transliterateLieu(string $arabe): string
    {
        $officiel = LieuNaissance::resoudreNomLatin($arabe);

        if ($officiel !== null) {
            return $officiel;
        }

        return $this->transliterate($arabe);
    }

    /**
     * Le texte contient-il de l'arabe à translittérer ?
     */
    public function necessiteTranslitteration(string $texte): bool
    {
        return ArabicNormalizer::contientArabe($texte);
    }

    private function translittererMot(string $mot): string
    {
        // Token déjà latin (document mixte) : conservé tel quel
        if (! ArabicNormalizer::contientArabe($mot)) {
            return $mot;
        }

        // Expressions multi-mots du dictionnaire d'abord
        $normalise = ArabicNormalizer::normaliser($mot);

        if (isset(self::DICTIONNAIRE[$normalise])) {
            return self::DICTIONNAIRE[$normalise];
        }

        // Repli déterministe lettre-à-lettre
        $lettre = '';
        $caracteres = mb_str_split($normalise);

        foreach ($caracteres as $caractere) {
            $lettre .= self::LETTRES[$caractere]
                ?? (ArabicNormalizer::contientArabe($caractere) ? '' : $caractere);
        }

        return $lettre;
    }
}
