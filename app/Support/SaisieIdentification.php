<?php

namespace App\Support;

use DateTime;

/**
 * Normalisation des saisies d'identification publique (CIN + date de
 * naissance) — forme canonique UNIQUE partagée par tous les points
 * d'entrée (recherche dossier existant, identification du wizard,
 * parcours autonome) : les services en aval (MENS, `whereDate`,
 * vérification) ne voient JAMAIS le format de saisie.
 *
 * Contrôle strict à 4 niveaux (HTML pattern, JS/Alpine, validation
 * serveur, règle métier) :
 * - CIN : exactement 8 chiffres ASCII — `filtrerCin` sert le filtre de
 *   saisie client, la règle serveur `regex:\A[0-9]{8}\z` fait foi ;
 * - date : saisie publique JJ/MM/AAAA → convertie vers Y-m-d (format
 *   interne). Une date IMPOSSIBLE (ex : 31/02/2025) n'est JAMAIS
 *   roulée silencieusement : laissée telle quelle, c'est la règle
 *   `date_format` qui la rejette avec le message dédié. Le format
 *   Y-m-d reste accepté (compatibilité API/tests).
 */
final class SaisieIdentification
{
    /** Âge minimum requis (années révolues) pour un dossier étudiant. */
    public const AGE_MINIMUM = 18;

    /** Âge maximum plausible (années révolues). */
    public const AGE_MAXIMUM = 120;

    /**
     * Filtre de saisie CIN côté client : uniquement les chiffres ASCII,
     * borné à 8 caractères (la validation serveur reste souveraine).
     */
    public static function filtrerCin(string $cin): string
    {
        $chiffres = preg_replace('/[^0-9]/', '', $cin);

        return substr(is_string($chiffres) ? $chiffres : '', 0, 8);
    }

    /**
     * Validation CANONIQUE du champ « Date de naissance » — processus à
     * 7 étapes, contrat de réponse structuré :
     *
     *   ['valid' => true,  'date' => '17/05/2003', 'age' => 23, 'message' => 'Date valide', 'code' => 'valide']
     *   ['valid' => false, 'message' => '…', 'code' => 'vide'|'format'|'impossible'|'future'|'age']
     *
     * 1. obligatoire (vide → message dédié) ;
     * 2. format exact — saisie publique JJ/MM/AAAA (jour 01-31, mois
     *    01-12, année 4 chiffres), le format interne/legacy Y-m-d reste
     *    accepté (compatibilité API/tests) ;
     * 3. conversion en date ;
     * 4. existence RÉELLE : `31/02/2003` est impossible (roulement
     *    détecté par comparaison aller-retour), `29/02/2024` est valide
     *    (année bissextile) ;
     * 5. jamais postérieure à aujourd'hui ;
     * 6. âge en années révolues ;
     * 7. bornes d'âge : AGE_MINIMUM <= âge <= AGE_MAXIMUM.
     *
     * Le `code` machine complète le contrat humain `message` (les
     * FormRequests l'ignorent — seul `message` fait foi côté Laravel).
     */
    public static function validerDateNaissance(string $valeur): array
    {
        $valeur = trim($valeur);

        // 1. Obligatoire.
        if ($valeur === '') {
            return ['valid' => false, 'code' => 'vide', 'message' => 'La date de naissance est obligatoire.'];
        }

        // 2. Format exact (saisie publique JJ/MM/AAAA, legacy Y-m-d).
        $saisiePublique = preg_match('/^(0[1-9]|[12][0-9]|3[01])\/(0[1-9]|1[0-2])\/([0-9]{4})$/', $valeur) === 1;
        $formatInterne = ! $saisiePublique && preg_match('/^([0-9]{4})-(0[1-9]|[12][0-9]|3[01])-(0[1-9]|[12][0-9]|3[01])$/', $valeur) === 1;

        if (! $saisiePublique && ! $formatInterne) {
            return ['valid' => false, 'code' => 'format', 'message' => 'La date doit être au format JJ/MM/AAAA et correspondre à une date réelle.'];
        }

        // 3. Conversion (le format d'affichage du contrat reste JJ/MM/AAAA).
        $format = $saisiePublique ? 'd/m/Y' : 'Y-m-d';
        $dt = DateTime::createFromFormat($format, $valeur);

        // 4. Existence réelle : createFromFormat ROULE les dates
        //    impossibles (31/02 → 03/03) — la comparaison aller-retour
        //    les rejette ; 29/02/2024 (bissextile) passe.
        if ($dt === false || $dt->format($format) !== $valeur) {
            return ['valid' => false, 'code' => 'impossible', 'message' => 'La date doit être au format JJ/MM/AAAA et correspondre à une date réelle.'];
        }

        // 5. Jamais dans le futur.
        if ($dt > new DateTime('today')) {
            return ['valid' => false, 'code' => 'future', 'message' => 'La date de naissance ne peut pas être postérieure à aujourd\'hui.'];
        }

        // 6. Âge en années révolues.
        $age = (int) (new DateTime('today'))->diff($dt)->y;

        // 7. Bornes d'âge.
        if ($age < self::AGE_MINIMUM || $age > self::AGE_MAXIMUM) {
            return ['valid' => false, 'code' => 'age', 'age' => $age, 'message' => 'L\'âge doit être compris entre '.self::AGE_MINIMUM.' et '.self::AGE_MAXIMUM.' ans.'];
        }

        // 8. Succès : date au format de saisie publique + âge calculé.
        return ['valid' => true, 'date' => $dt->format('d/m/Y'), 'age' => $age, 'message' => 'Date valide', 'code' => 'valide'];
    }

    /**
     * Date de naissance JJ/MM/AAAA → Y-m-d. Retourne la chaîne INCHANGÉE
     * si elle est déjà au format Y-m-d (compatibilité) ou si elle est
     * impossible/invalide — le rejet appartient alors à la règle
     * `date_format`, jamais à un roulement silencieux.
     */
    public static function normaliserDateNaissance(string $date): string
    {
        $date = trim($date);

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date) !== 1) {
            return $date; // Y-m-d (legacy/API) ou saisie incomplète → validation
        }

        $dt = DateTime::createFromFormat('d/m/Y', $date);

        if ($dt === false || $dt->format('d/m/Y') !== $date) {
            return $date; // 31/02/2025 → comparaison aller-retour en échec
        }

        return $dt->format('Y-m-d');
    }
}
