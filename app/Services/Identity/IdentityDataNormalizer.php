<?php

namespace App\Services\Identity;

/**
 * Normalisation des données extraites (§6) : l'OCR produit du bruit,
 * la normalisation nettoie AVANT toute comparaison en base :
 * - identifiants (CIN / n° passeport) : espaces et parasites supprimés,
 *   majuscules, confusions OCR courantes corrigées (O→0, I/l→1) ;
 * - noms : majuscules, ponctuation parasite retirée ;
 * - dates : plausibilité vérifiée (checkdate, non future) ;
 * - sexe : M/F uniquement.
 *
 * La normalisation ne « répare » JAMAIS une donnée ambiguë au point de
 * la faire passer pour fiable : les ambiguïtés dégradent la confiance
 * au niveau du parseur (CinParser/PassportParser), pas ici.
 */
final class IdentityDataNormalizer
{
    /**
     * CIN tunisienne : 8 chiffres attendus (max 30 caractères au sens
     * métier du projet). Retourne NULL si rien d'exploitable.
     *
     * @param  array<string, ?string>  $brut
     * @return array<string, ?string>
     */
    public function normaliserCin(array $brut): array
    {
        $brut['document_number'] = $this->identifiant($brut['document_number'] ?? null, correctionOcr: true);

        return $this->finaliser($brut);
    }

    /**
     * Passeport : n° normalisé sans correction OCR agressive (lettres
     * légitimes) ; les « < » MRZ résiduels sont retirés.
     *
     * @param  array<string, ?string>  $brut
     * @return array<string, ?string>
     */
    public function normaliserPasseport(array $brut): array
    {
        $numero = $brut['document_number'] ?? null;

        if ($numero !== null) {
            $numero = mb_strtoupper(preg_replace('/[\s<]/u', '', $numero) ?? '');
            $numero = preg_replace('/[^A-Z0-9\-]/', '', $numero) ?? '';
            $numero = trim($numero, '-');

            if ($numero === '') {
                $numero = null;
            }
        }

        $brut['document_number'] = $numero;

        return $this->finaliser($brut);
    }

    /**
     * Nettoyage commun : noms, dates, sexe, lieu, nationalité.
     *
     * @param  array<string, ?string>  $brut
     * @return array<string, ?string>
     */
    private function finaliser(array $brut): array
    {
        foreach (['last_name', 'first_name', 'place_of_birth', 'nationality'] as $champTexte) {
            $brut[$champTexte] = $this->nom($brut[$champTexte] ?? null);
        }

        foreach (['date_of_birth', 'expiry_date'] as $champDate) {
            $brut[$champDate] = $this->date($brut[$champDate] ?? null, futureAutorisee: $champDate === 'expiry_date');
        }

        $sexe = $brut['sex'] ?? null;
        $sexe = $sexe !== null ? strtoupper(mb_substr(trim($sexe), 0, 1)) : null;
        $brut['sex'] = in_array($sexe, ['M', 'F'], true) ? $sexe : null;

        return array_filter($brut, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Identifiant : espaces/parasites supprimés, majuscules ; correction
     * des confusions OCR O→0, I/l→1 UNIQUEMENT si correctionOcr (CIN
     * exclusivement numérique — jamais pour un n° de passeport légitime).
     */
    private function identifiant(?string $valeur, bool $correctionOcr): ?string
    {
        if ($valeur === null) {
            return null;
        }

        $nettoye = mb_strtoupper(trim($valeur));
        // Séparateurs et parasites supprimés (« - » est un vrai tiret :
        // « 12 345-678. » → « 12345678 »)
        $nettoye = preg_replace('/[\s._,;:\/-]+/u', '', $nettoye) ?? '';

        if ($correctionOcr) {
            $nettoye = str_replace(['O', 'I', 'L'], ['0', '1', '1'], $nettoye);
        }

        $nettoye = preg_replace('/[^0-9A-Z\-]/', '', $nettoye) ?? '';
        $nettoye = trim($nettoye, '-');

        return $nettoye !== '' ? mb_substr($nettoye, 0, 30) : null;
    }

    /**
     * Nom : majuscules (cohérent avec le registre), ponctuation parasite
     * retirée, espaces multiples réduits.
     */
    private function nom(?string $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }

        $nettoye = mb_strtoupper(trim($valeur));
        $nettoye = preg_replace('/[^A-ZÀ-ÖØ-Þ\s\'\-]/u', '', $nettoye) ?? '';
        $nettoye = preg_replace('/\s+/u', ' ', $nettoye) ?? '';
        $nettoye = trim($nettoye, " '-");

        return $nettoye !== '' ? mb_substr($nettoye, 0, 100) : null;
    }

    /**
     * Date Y-m-d plausible : valide (checkdate) et non future (sauf
     * expiration, explicitement future).
     */
    private function date(?string $valeur, bool $futureAutorisee): ?string
    {
        if ($valeur === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur)) {
            return null;
        }

        [$annee, $mois, $jour] = array_map('intval', explode('-', $valeur));

        if (! checkdate($mois, $jour, $annee)) {
            return null;
        }

        if (! $futureAutorisee && $valeur > date('Y-m-d')) {
            return null;
        }

        return $valeur;
    }
}
