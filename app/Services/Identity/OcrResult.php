<?php

namespace App\Services\Identity;

/**
 * Résultat intermédiaire de l'extraction OCR d'un document d'identité (§5, §10).
 *
 * Les champs réellement disponibles dépendent du document (CIN tunisienne
 * — arabe, latine ou mixte (§21) —, passeport…). `confidence` associe
 * chaque champ extrait à un score [0..1] (§8/§17) : l'OCR n'est JAMAIS
 * considéré comme parfaitement fiable.
 *
 * §9/§10 : les NOMS PROPRES arabes sont conservés EN PARALLÈLE de leur
 * translittération (`originals` : raw arabe + translittération) — jamais
 * remplacés. Les propriétés nominales (lastName…) portent la valeur
 * TRANSLITTÉRÉE prête pour le formulaire latin.
 *
 * JAMAIS sérialisé tel quel vers le client : la réponse passe par
 * IdentityPrefillService (whitelist).
 */
final class OcrResult
{
    /** Langues possibles du document (§2). */
    public const LANGUE_ARABE = 'ar';

    public const LANGUE_LATIN = 'latin';

    public const LANGUE_MIXTE = 'mixte';

    /**
     * @param  array<string, mixed>  $rawFields  champs bruts du moteur OCR (jamais loggés)
     * @param  array<string, float>  $confidence  score par champ extrait
     * @param  array<string, array{raw: string, normalized_ar: string, transliterated: string}>  $originals
     *                                                                                                       originaux arabes par champ (§7/§9) : valeur brute ARABE,
     *                                                                                                       forme normalisée arabe et translittération latine —
     *                                                                                                       conservés séparément, jamais perdus, jamais remplacés.
     */
    public function __construct(
        public readonly string $documentType,
        public readonly string $language = '',
        public readonly ?string $documentNumber = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $filiation = null, // §11 : « بن محمد », champ DÉDIÉ
        public readonly ?string $dateOfBirth = null, // Y-m-d normalisé
        public readonly ?string $placeOfBirth = null,
        public readonly ?string $nationality = null, // §14 : null si non détecté
        public readonly ?string $sex = null, // §15 : M | F | null si non détecté
        public readonly ?string $expiryDate = null, // Y-m-d
        public readonly ?string $issueDate = null, // Y-m-d
        public readonly ?string $mrz = null,
        public readonly array $rawFields = [],
        public readonly array $confidence = [],
        public readonly array $originals = [],
    ) {}

    /**
     * Champs extraits avec leur confiance, ordonnés pour la réponse.
     *
     * @return array<string, string|null>
     */
    public function champsFormulaire(): array
    {
        return [
            'cin' => $this->documentNumber,
            'nom' => $this->lastName,
            'prenom' => $this->firstName,
            'filiation' => $this->filiation, // §11 : informatif, jamais fusionné
            'date_naissance' => $this->dateOfBirth,
            'sexe' => $this->sex,
            'lieu_naissance' => $this->placeOfBirth,
            'nationalite' => $this->nationality,
        ];
    }

    /**
     * Confiance par champ, remappée sur les clés du formulaire
     * (alignées sur champsFormulaire) — le frontend lit ces clés.
     *
     * @return array<string, float>
     */
    public function confidenceFormulaire(): array
    {
        $correspondance = [
            'document_number' => 'cin',
            'last_name' => 'nom',
            'first_name' => 'prenom',
            'filiation' => 'filiation',
            'date_of_birth' => 'date_naissance',
            'sex' => 'sexe',
            'place_of_birth' => 'lieu_naissance',
            'nationality' => 'nationalite',
            'expiry_date' => 'expiry_date',
        ];

        $remappee = [];
        foreach ($this->confidence as $champ => $score) {
            $cle = $correspondance[$champ] ?? $champ;
            $remappee[$cle] = $score;
        }

        return $remappee;
    }

    /**
     * Originaux arabes remappés sur les clés du formulaire (§12) :
     * l'UI affiche « Arabe : بن علي / Translittération : Ben Ali /
     * Valeur du formulaire : BEN ALI ».
     *
     * @return array<string, array{raw: string, transliterated: string}>
     */
    public function originauxFormulaire(): array
    {
        $correspondance = [
            'last_name' => 'nom',
            'first_name' => 'prenom',
            'filiation' => 'filiation',
            'place_of_birth' => 'lieu_naissance',
            'nationality' => 'nationalite',
        ];

        $remappes = [];
        foreach ($this->originals as $champ => $originale) {
            $cle = $correspondance[$champ] ?? $champ;
            $remappes[$cle] = $originale;
        }

        return $remappes;
    }
}
