<?php

namespace App\Services\Identity;

/**
 * Parseur passeport (§7) : la MRZ est PRIORITAIRE — c'est la donnée la
 * plus fiable du document (checksums ICAO). L'extraction par libellés
 * OCR n'est qu'un repli à faible confiance.
 *
 * Une extraction OCR ne constitue JAMAIS une preuve d'identité.
 */
final class PassportParser
{
    public function __construct(private readonly IdentityDataNormalizer $normalizer) {}

    /**
     * @return array{
     *     data: array{document_number: ?string, last_name: ?string, first_name: ?string,
     *         date_of_birth: ?string, place_of_birth: ?string, nationality: ?string,
     *         sex: ?string, expiry_date: ?string},
     *     confidence: array<string, float>,
     *     mrz_valide: ?bool
     * }
     */
    public function parse(string $texteBrut): array
    {
        $mrz = MrzParser::parse($texteBrut);

        if ($mrz !== null) {
            return $this->depuisMrz($mrz);
        }

        return $this->depuisLibelles($texteBrut);
    }

    /**
     * MRZ détectée : champs normalisés, confiance élevée si checksums
     * valides — sinon fortement dégradée (vérification humaine).
     *
     * @param  array{valide: bool, raison: ?string, champs: array<string, mixed>}  $mrz
     */
    private function depuisMrz(array $mrz): array
    {
        $brut = [
            'document_number' => $mrz['champs']['document_number'] ?? null,
            'last_name' => $mrz['champs']['last_name'] ?? null,
            'first_name' => $mrz['champs']['first_name'] ?? null,
            'date_of_birth' => $mrz['champs']['date_of_birth'] ?? null,
            'nationality' => $mrz['champs']['nationality'] ?? null,
            'sex' => $mrz['champs']['sex'] ?? null,
            'expiry_date' => $mrz['champs']['expiry_date'] ?? null,
        ];

        $donnees = $this->normalizer->normaliserPasseport($brut);

        // Confiance MRZ : fiable par champ quand TOUS les checksums sont
        // valides ; un checksum invalide dégrade l'ensemble (on ne sait
        // pas quel champ a été corrompu).
        $niveau = $mrz['valide'] ? 0.98 : 0.45;

        $confiance = [];
        foreach ($donnees as $champ => $valeur) {
            if ($valeur !== null) {
                $confiance[$champ] = $niveau;
            }
        }

        if (isset($donnees['expiry_date'])) {
            // Contrôle de plausibilité (§16) : un passeport expiré
            // n'invalide pas le scan mais dégrade la confiance.
            if ($donnees['expiry_date'] < date('Y-m-d')) {
                $confiance['expiry_date'] = 0.40;
            }
        }

        return [
            'data' => $donnees,
            'confidence' => $confiance,
            'mrz_valide' => $mrz['valide'],
        ];
    }

    /**
     * Repli : champs à libellés (« Nom/Nom de famille : … »), faible
     * confiance (0.60) — pré-remplissage avec avertissement.
     *
     * @return array{data: array<string, ?string>, confidence: array<string, float>, mrz_valide: null}
     */
    private function depuisLibelles(string $texteBrut): array
    {
        $lignes = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\n|\r/', $texteBrut) ?: []),
            fn (string $l) => $l !== '',
        ));

        $extraire = function (array $libelles) use ($lignes): ?string {
            foreach ($lignes as $ligne) {
                foreach ($libelles as $libelle) {
                    if (preg_match('/^\s*'.preg_quote($libelle, '/').'\s*[:\-—]?\s*(.+)$/iu', $ligne, $m)) {
                        $valeur = trim($m[1]);

                        if ($valeur !== '') {
                            return $valeur;
                        }
                    }
                }
            }

            return null;
        };

        $donnees = $this->normalizer->normaliserPasseport([
            'document_number' => $extraire(['passeport n°', 'passport no', 'document no', 'n° passeport']),
            'last_name' => $extraire(['nom', 'nom de famille', 'surname', 'last name']),
            'first_name' => $extraire(['prenom', 'prénom', 'given name', 'first name']),
            'date_of_birth' => $this->extraireDate($lignes),
            'nationality' => $extraire(['nationalite', 'nationalité', 'nationality']),
            'sex' => $extraire(['sexe', 'sex']),
            'expiry_date' => $this->extraireDate($lignes, secondaire: true),
        ]);

        $confiance = [];
        foreach ($donnees as $champ => $valeur) {
            if ($valeur !== null) {
                $confiance[$champ] = 0.60;
            }
        }

        return ['data' => $donnees, 'confidence' => $confiance, 'mrz_valide' => null];
    }

    /**
     * Première date dd/mm/yyyy d'une ligne « née le / date of birth »
     * (ou la seconde pour expiry quand secondaire = true).
     *
     * @param  array<int, string>  $lignes
     */
    private function extraireDate(array $lignes, bool $secondaire = false): ?string
    {
        $dates = [];

        foreach ($lignes as $ligne) {
            if (preg_match('/\b([0-9]{2})[\/\-.]([0-9]{2})[\/\-.]([0-9]{4})\b/', $ligne, $m)
                && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                $dates[] = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
            }
        }

        $choisie = $secondaire ? ($dates[1] ?? null) : ($dates[0] ?? null);

        return $choisie !== null && $choisie <= date('Y-m-d')
            ? $choisie
            : ($secondaire ? ($dates[0] ?? null) : $choisie);
    }
}
