<?php

namespace App\Modules\ParcoursAcademique\Services;

use App\Modules\ParcoursAcademique\Models\ParcoursRegle;
use Illuminate\Support\Facades\DB;

/**
 * Règles académiques configurables du parcours (§16).
 * Résolution : spécifique (année+programme) > programme >
 * globale > socle. Chaque calcul identifie code+version.
 * Versionnée : anciennes simulations restent compréhensibles.
 */
class ParcoursRegleService
{
    /**
     * Socle LMD lorsque rien n'est configuré (documenté, jamais caché).
     *
     * @return array<string, mixed>
     */
    public static function socle(string $code): array
    {
        return match ($code) {
            'PASSAGE_L1_L2', 'PASSAGE_L2_L3' => [
                'credits_requis' => 60, 'moyenne_min' => 10.0, 'dettes_max' => 2,
                'source' => 'socle',
            ],
            'ADMISSION_MASTER' => [
                'credits_requis' => 180, 'moyenne_min' => 10.0,
                'decision_exigee' => 'admis', 'diplome_requis' => 'Licence',
                'source' => 'socle',
            ],
            'PASSAGE_M1_M2' => [
                'credits_requis' => 60, 'moyenne_min' => 10.0, 'source' => 'socle',
            ],
            'REDOUBLEMENT' => ['autorise' => true, 'source' => 'socle'],
            'COMPENSATION' => ['renvoi' => 'evaluation_regles', 'source' => 'socle'],
            'RISQUE' => [
                'seuils' => ['faible' => 0, 'moyen' => 3, 'eleve' => 6, 'critique' => 9],
                'dette_critique' => 5, 'moyenne_faible' => 8.0, 'moyenne_alerte' => 10.0,
                'source' => 'socle',
            ],
            default => ['source' => 'socle'],
        };
    }

    /**
     * Résout la règle active la plus spécifique.
     *
     * @return array{code: string, version: ?int, libelle: string, criteres: array<string, mixed>, reference: string}
     */
    public function resoudre(string $code, ?int $anneeId = null, ?int $programmeId = null): array
    {
        $query = ParcoursRegle::where('code', $code)->where('actif', true);

        if ($anneeId !== null && $programmeId !== null) {
            $specifique = (clone $query)->where('annee_universitaire_id', $anneeId)
                ->where('programme_id', $programmeId)->orderByDesc('version')->first();
            if ($specifique !== null) {
                return $this->decrire($specifique);
            }
        }

        if ($programmeId !== null) {
            $programme = (clone $query)->where('programme_id', $programmeId)
                ->whereNull('annee_universitaire_id')->orderByDesc('version')->first();
            if ($programme !== null) {
                return $this->decrire($programme);
            }
        }

        $globale = (clone $query)->whereNull('programme_id')
            ->whereNull('annee_universitaire_id')->orderByDesc('version')->first();

        if ($globale !== null) {
            return $this->decrire($globale);
        }

        return [
            'code' => $code,
            'version' => null,
            'libelle' => 'Socle LMD (aucune règle configurée)',
            'criteres' => self::socle($code),
            'reference' => $code.'@socle',
        ];
    }

    /**
     * @return array{code: string, version: ?int, libelle: string, criteres: array<string, mixed>, reference: string}
     */
    private function decrire(ParcoursRegle $regle): array
    {
        return [
            'code' => $regle->code,
            'version' => $regle->version,
            'libelle' => $regle->libelle,
            'criteres' => $regle->criteres ?? [],
            'reference' => $regle->code.'@v'.$regle->version,
        ];
    }

    /**
     * Crée une nouvelle version (ancienne conservée pour traçabilité).
     */
    public function versionner(string $code, string $libelle, array $criteres, ?int $anneeId, ?int $programmeId, bool $desactiverAnciennes = false): ParcoursRegle
    {
        return DB::transaction(function () use ($code, $libelle, $criteres, $anneeId, $programmeId, $desactiverAnciennes) {
            if ($desactiverAnciennes) {
                ParcoursRegle::where('code', $code)->where('actif', true)->update(['actif' => false]);
            }

            $max = (int) (ParcoursRegle::where('code', $code)->max('version') ?? 0);

            return ParcoursRegle::create([
                'code' => $code,
                'libelle' => $libelle,
                'version' => $max + 1,
                'actif' => true,
                'annee_universitaire_id' => $anneeId,
                'programme_id' => $programmeId,
                'criteres' => $criteres,
            ]);
        });
    }
}
