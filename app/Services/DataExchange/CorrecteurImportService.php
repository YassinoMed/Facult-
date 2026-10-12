<?php

namespace App\Services\DataExchange;

use App\Services\DataExchange\Contracts\ImportDefinitionInterface;

/**
 * Moteur de correction automatique versionné (PHASE 3) : n'applique que
 * des transformations DÉTERMINISTES et non ambiguës, en réutilisant
 * `ValueNormalizer` (zéro règle réinventée). Chaque application est
 * tracée (originale → proposée, règle@version) ; l'ambigu est ABSTENU
 * et versé en `a_revoir` (revue humaine PHASE 4), jamais deviné.
 *
 * Champs sensibles (identité, secrets, notes/décisions) : JAMAIS
 * touchés (ni espaces, ni chiffres, ni téléphones, ni dates).
 * L'application répétée est idempotente (testée).
 */
class CorrecteurImportService
{
    public const VERSION = '1.0';

    /**
     * Colonnes normalisées exclues de TOUTE correction automatique.
     */
    public const SENSIBLES = [
        'cin', 'nom', 'prenom', 'email', 'matricule', 'num_inscription',
        'password', 'mot_de_passe', 'note',
    ];

    /**
     * Colonnes normalisées éligibles à la normalisation téléphone (+216).
     */
    public const COLONNES_TELEPHONE = [
        'telephone', 'tel', 'gsm', 'portable', 'phone', 'mobile',
    ];

    /**
     * Colonnes normalisées éligibles à l'uniformisation de date ISO.
     */
    public const COLONNES_DATE = [
        'date', 'date_naissance', 'naissance', 'date_debut', 'date_fin', 'date_effet',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $lignes  (clés canoniques + `ligne`)
     * @return array{lignes: array<int, array<string, mixed>>, corrections: array<int, array{ligne: int|string, champ: string, originale: string, proposee: string, regle: string}>, a_revoir: array<int, array{ligne: int|string, champ: string, valeur: string, regle: string, raison: string}>}
     */
    public function corriger(array $lignes, ImportDefinitionInterface $definition): array
    {
        $corrections = [];
        $aRevoir = [];

        foreach ($lignes as $index => $ligne) {
            foreach ($definition->colonnes() as $colonne) {
                $valeur = $ligne[$colonne] ?? null;

                if (! is_string($valeur) || trim($valeur) === '') {
                    continue;
                }

                $numero = $ligne['ligne'] ?? '?';

                if (in_array($colonne, self::SENSIBLES, true)) {
                    continue;
                }

                // ESPACES@1.0 — NFC + invisibles + collapse (toujours sûr).
                $proposee = ValueNormalizer::texte($valeur);

                if ($proposee !== $valeur) {
                    $corrections[] = $this->trace($numero, $colonne, $valeur, $proposee, 'ESPACES');
                    $valeur = $proposee;
                }

                // CHIFFRES@1.0 — arabo-indiens → latins (même valeur numérique).
                $proposee = ValueNormalizer::chiffresLatins($valeur);

                if ($proposee !== $valeur) {
                    $corrections[] = $this->trace($numero, $colonne, $valeur, $proposee, 'CHIFFRES');
                    $valeur = $proposee;
                }

                // TELEPHONE@1.0 — +216 explicite, colonnes téléphone seules.
                if (in_array($colonne, self::COLONNES_TELEPHONE, true)) {
                    $proposee = ValueNormalizer::telephone($valeur);

                    if ($proposee !== $valeur) {
                        $corrections[] = $this->trace($numero, $colonne, $valeur, $proposee, 'TELEPHONE');
                        $valeur = $proposee;
                    }
                }

                // DATE@1.0 — ISO non ambiguë seule ; ambiguë/illisible →
                // abstention + revue humaine (jamais de devinette).
                if (in_array($colonne, self::COLONNES_DATE, true)) {
                    ['date' => $convertie, 'avertissement' => $avertissement] = ValueNormalizer::date($valeur);

                    if ($convertie !== null
                        && ($avertissement === null || str_starts_with($avertissement, 'Date Excel convertie'))
                        && $convertie !== $valeur) {
                        $corrections[] = $this->trace($numero, $colonne, $valeur, $convertie, 'DATE');
                        $valeur = $convertie;
                    } elseif ($convertie === null || ($avertissement !== null && ! str_starts_with($avertissement, 'Date Excel convertie'))) {
                        $aRevoir[] = [
                            'ligne' => $numero,
                            'champ' => $colonne,
                            'valeur' => $ligne[$colonne],
                            'regle' => 'DATE@'.self::VERSION,
                            'raison' => $avertissement ?? "Date illisible : « {$ligne[$colonne]} ».",
                        ];
                    }
                }

                $lignes[$index][$colonne] = $valeur;
            }
        }

        return ['lignes' => $lignes, 'corrections' => $corrections, 'a_revoir' => $aRevoir];
    }

    /**
     * @return array{ligne: int|string, champ: string, originale: string, proposee: string, regle: string}
     */
    private function trace(int|string $ligne, string $champ, string $originale, string $proposee, string $regle): array
    {
        return [
            'ligne' => $ligne,
            'champ' => $champ,
            'originale' => $originale,
            'proposee' => $proposee,
            'regle' => $regle.'@'.self::VERSION,
        ];
    }
}
