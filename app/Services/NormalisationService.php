<?php

namespace App\Services;

/**
 * Normalisation des données d'identité (§19.8) : formes canoniques
 * partagées par l'import, les formulaires et la détection de
 * doublons. Ne modifie jamais silencieusement une donnée sensible :
 * chaque appelant décide d'appliquer ou de proposer.
 */
class NormalisationService
{
    /**
     * LOOP saisies §3 : règle de validation de la CIN tunisienne —
     * exactement 8 chiffres ASCII, préservée comme chaîne (zéros
     * initiaux inclus, jamais un entier). Source UNIQUE partagée par
     * les formulaires, les imports et l'API. La forme ≠ l'identité :
     * ce contrôle vérifie UNIQUEMENT le format, jamais l'existence
     * réelle du document.
     */
    public const MOTIF_CIN = '/\A[0-9]{8}\z/';

    public const REGLE_CIN = 'regex:'.self::MOTIF_CIN;

    /**
     * LOOP saisies §3 : vérification de forme d'une CIN (8 chiffres
     * ASCII). Utilisée par les imports et les tests — les formulaires
     * Laravel emploient directement la constante REGLE_CIN.
     */
    public function cinValide(?string $cin): bool
    {
        return $cin !== null && preg_match(self::MOTIF_CIN, $cin) === 1;
    }

    public function email(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalise = mb_strtolower(trim($email));

        return $normalise === '' ? null : $normalise;
    }

    public function telephone(?string $telephone): ?string
    {
        if ($telephone === null) {
            return null;
        }

        $chiffres = preg_replace('/[^0-9]/', '', $telephone) ?? '';

        if ($chiffres === '') {
            return null;
        }

        // Numéros tunisiens : 8 chiffres → +216 ; 00216/216 préfixés.
        if (str_starts_with($chiffres, '00216')) {
            $chiffres = substr($chiffres, 5);
        } elseif (str_starts_with($chiffres, '216') && strlen($chiffres) === 11) {
            $chiffres = substr($chiffres, 3);
        }

        if (strlen($chiffres) === 8) {
            return '+216'.$chiffres;
        }

        return '+'.$chiffres;
    }

    public function cin(?string $cin): ?string
    {
        if ($cin === null) {
            return null;
        }

        $normalise = mb_strtoupper(preg_replace('/\s+/', '', $cin) ?? '');

        return $normalise === '' ? null : $normalise;
    }

    public function nomPropre(?string $nom): ?string
    {
        if ($nom === null) {
            return null;
        }

        $normalise = preg_replace('/\s+/', ' ', trim($nom)) ?? '';

        if ($normalise === '') {
            return null;
        }

        return mb_convert_case($normalise, MB_CASE_TITLE, 'UTF-8');
    }

    public function code(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $normalise = mb_strtoupper(trim($code));

        return $normalise === '' ? null : $normalise;
    }

    /**
     * LOOP A1 (enterprise) : forme COMPARABLE d'un libellé — source
     * UNIQUE de l'appariement par nom (standalone, backfill) : casse +
     * espaces + accents neutralisés (même convention que
     * `SearchNormalizer`, sans le volet arabe). Sans elle, la collation
     * MySQL (insensible aux accents) et PHP (`mb_strtoupper` seul)
     * divergent — ex. « Bases de donnees » ↔ « Bases de données ».
     */
    public function nomComparable(?string $nom): string
    {
        $texte = (string) preg_replace('/\s+/', ' ', trim((string) $nom));
        $texte = mb_strtoupper($texte, 'UTF-8');
        $decompose = normalizer_normalize($texte, \Normalizer::FORM_D) ?: $texte;

        return (string) preg_replace('/\p{Mn}/u', '', $decompose);
    }
}
