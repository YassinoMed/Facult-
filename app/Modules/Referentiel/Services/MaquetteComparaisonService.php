<?php

namespace App\Modules\Referentiel\Services;

use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\MaquetteUe;
use App\Modules\Referentiel\Models\MaquetteUeEcue;
use Illuminate\Support\Collection;

/**
 * Comparaison de deux versions d'une même maquette (ENTERPRISE LOOP 04) :
 * lecture seule — UE/ECUE ajoutées ou retirées, champs modifiés
 * (crédits, coefficients, volumes, ordre, régime, choix, semestre du
 * plan), prérequis (référence canonique, état actuel), écarts de totaux
 * et métadonnées de validation. Les versions ne sont jamais modifiées.
 *
 * @throws \DomainException si les maquettes ne sont pas comparables.
 */
final class MaquetteComparaisonService
{
    /**
     * Champs d'occurrence ECUE comparés (avant → après).
     */
    private const CHAMPS_ECUE = [
        'ordre', 'coefficient', 'credits', 'volume_cm', 'volume_td',
        'volume_tp', 'type_evaluation', 'regime', 'obligatoire',
        'groupe_choix', 'conditions',
    ];

    /**
     * @return array<string, mixed>
     */
    public function comparer(Maquette $reference, Maquette $comparee): array
    {
        if ((int) $reference->programme_id !== (int) $comparee->programme_id) {
            throw new \DomainException('Maquettes de programmes différents : non comparables.');
        }

        $reference->loadMissing(['programme', 'anneeUniversitaire']);
        $comparee->loadMissing(['programme', 'anneeUniversitaire']);

        $lignesRef = $this->lignes($reference);
        $lignesCmp = $this->lignes($comparee);

        // UE : clé ue_id (le semestre du plan fait partie de la comparaison).
        $ueRef = $lignesRef->keyBy('ue_id');
        $ueCmp = $lignesCmp->keyBy('ue_id');

        $ueAjoutees = [];
        $ueRetirees = [];
        $ueModifiees = [];

        foreach ($ueCmp as $ueId => $ligne) {
            if (! $ueRef->has($ueId)) {
                $ueAjoutees[] = $this->resumeUe($ligne);
            }
        }

        foreach ($ueRef as $ueId => $ligne) {
            if (! $ueCmp->has($ueId)) {
                $ueRetirees[] = $this->resumeUe($ligne);
            } else {
                $changements = $this->changementsUe($ligne, $ueCmp->get($ueId));

                if ($changements !== []) {
                    $ueModifiees[] = [
                        'ue' => $ligne->ue?->code ?? "#{$ueId}",
                        'changements' => $changements,
                    ];
                }
            }
        }

        // ECUE : clé ue_id × matiere_id.
        $ecueRef = $this->occurrences($lignesRef);
        $ecueCmp = $this->occurrences($lignesCmp);

        $ecueAjoutees = [];
        $ecueRetirees = [];
        $ecueModifiees = [];

        foreach ($ecueCmp as $cle => $occurrence) {
            if (! $ecueRef->has($cle)) {
                $ecueAjoutees[] = $this->resumeOccurrence($occurrence);
            }
        }

        foreach ($ecueRef as $cle => $occurrence) {
            if (! $ecueCmp->has($cle)) {
                $ecueRetirees[] = $this->resumeOccurrence($occurrence);
            } else {
                $changements = $this->changementsOccurrence($occurrence, $ecueCmp->get($cle));

                if ($changements !== []) {
                    $ecueModifiees[] = [
                        'matiere' => $occurrence->matiere?->code ?? "#{$occurrence->matiere_id}",
                        'ue' => $occurrence->maquetteUe?->ue?->code,
                        'changements' => $changements,
                    ];
                }
            }
        }

        // Prérequis : référence canonique (état actuel partagé).
        $prerequis = $this->comparerPrerequis($ecueRef, $ecueCmp);

        $totauxAvant = $this->totaux($lignesRef);
        $totauxApres = $this->totaux($lignesCmp);

        $identiques = $ueAjoutees === [] && $ueRetirees === [] && $ueModifiees === []
            && $ecueAjoutees === [] && $ecueRetirees === [] && $ecueModifiees === [];

        return [
            'reference' => $this->meta($reference),
            'comparee' => $this->meta($comparee),
            'identiques' => $identiques,
            'ue_ajoutees' => $ueAjoutees,
            'ue_retirees' => $ueRetirees,
            'ue_modifiees' => $ueModifiees,
            'ecue_ajoutees' => $ecueAjoutees,
            'ecue_retirees' => $ecueRetirees,
            'ecue_modifiees' => $ecueModifiees,
            'prerequis' => $prerequis,
            'totaux' => [
                'avant' => $totauxAvant,
                'apres' => $totauxApres,
                'ecarts' => [
                    'credits' => $totauxApres['credits'] - $totauxAvant['credits'],
                    'cm' => $totauxApres['cm'] - $totauxAvant['cm'],
                    'td' => $totauxApres['td'] - $totauxAvant['td'],
                    'tp' => $totauxApres['tp'] - $totauxAvant['tp'],
                ],
            ],
        ];
    }

    /**
     * @return Collection<int, MaquetteUe>
     */
    private function lignes(Maquette $maquette)
    {
        return MaquetteUe::where('maquette_id', $maquette->id)
            ->with(['ue.ecues', 'semestre', 'ecues.matiere.prerequis'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<string, MaquetteUeEcue>
     */
    private function occurrences(iterable $lignes)
    {
        $occurrences = collect();

        foreach ($lignes as $ligne) {
            foreach ($ligne->ecues as $occurrence) {
                $occurrences->put($ligne->ue_id.'×'.$occurrence->matiere_id, $occurrence);
            }
        }

        return $occurrences;
    }

    /**
     * @return array{code: ?string, semestre: ?string}
     */
    private function resumeUe(MaquetteUe $ligne): array
    {
        return [
            'code' => $ligne->ue?->code,
            'nom' => $ligne->ue?->nom,
            'semestre' => $ligne->semestre?->code ?? $ligne->ue?->semestre?->code,
        ];
    }

    /**
     * @return array{code: ?string, matiere: ?string, ue: ?string}
     */
    private function resumeOccurrence(MaquetteUeEcue $occurrence): array
    {
        return [
            'code' => $occurrence->matiere?->code,
            'nom' => $occurrence->matiere?->nom,
            'ue' => $occurrence->maquetteUe?->ue?->code,
            'credits' => (int) $occurrence->credits,
        ];
    }

    /**
     * @return array<int, array{champ: string, avant: mixed, apres: mixed}>
     */
    private function changementsUe(MaquetteUe $avant, MaquetteUe $apres): array
    {
        $changements = [];

        $semestreAvant = $avant->semestre?->code ?? $avant->ue?->semestre?->code;
        $semestreApres = $apres->semestre?->code ?? $apres->ue?->semestre?->code;

        if ($semestreAvant !== $semestreApres) {
            $changements[] = ['champ' => 'semestre', 'avant' => $semestreAvant, 'apres' => $semestreApres];
        }

        // Champs canoniques de l'UE (présentation vs pédagogique : tout
        // changement est signalé, au responsable de le qualifier).
        foreach (['nom' => 'nom', 'credits' => 'credits', 'coefficient' => 'coefficient', 'type' => 'type'] as $champ => $attr) {
            $valeurAvant = $avant->ue?->$attr;
            $valeurApres = $apres->ue?->$attr;

            if ((string) $valeurAvant !== (string) $valeurApres) {
                $changements[] = ['champ' => 'ue.'.$champ, 'avant' => $valeurAvant, 'apres' => $valeurApres];
            }
        }

        return $changements;
    }

    /**
     * @return array<int, array{champ: string, avant: mixed, apres: mixed}>
     */
    private function changementsOccurrence(MaquetteUeEcue $avant, MaquetteUeEcue $apres): array
    {
        $changements = [];

        foreach (self::CHAMPS_ECUE as $champ) {
            $valeurAvant = $this->normaliser($avant->$champ);
            $valeurApres = $this->normaliser($apres->$champ);

            if ($valeurAvant !== $valeurApres) {
                $changements[] = ['champ' => $champ, 'avant' => $avant->$champ, 'apres' => $apres->$champ];
            }
        }

        return $changements;
    }

    private function normaliser(mixed $valeur): string
    {
        if (is_array($valeur)) {
            return json_encode($valeur, JSON_UNESCAPED_UNICODE);
        }

        if (is_bool($valeur)) {
            return $valeur ? '1' : '0';
        }

        return (string) ($valeur ?? '');
    }

    /**
     * Prérequis ajoutés/retirés par ECUE commune (état canonique actuel,
     * partagé par les deux versions — indiqué comme tel dans la vue).
     *
     * @return array<int, array{matiere: ?string, ajoutes: array, retires: array}>
     */
    private function comparerPrerequis(iterable $ecueRef, iterable $ecueCmp): array
    {
        $resultat = [];

        foreach ($ecueRef as $cle => $occurrence) {
            if (! isset($ecueCmp[$cle])) {
                continue;
            }

            $avant = $occurrence->matiere?->prerequis->pluck('code')->sort()->values()->all() ?? [];
            $apres = $ecueCmp[$cle]->matiere?->prerequis->pluck('code')->sort()->values()->all() ?? [];
            $ajoutes = array_values(array_diff($apres, $avant));
            $retires = array_values(array_diff($avant, $apres));

            if ($ajoutes !== [] || $retires !== []) {
                $resultat[] = [
                    'matiere' => $occurrence->matiere?->code,
                    'ajoutes' => $ajoutes,
                    'retires' => $retires,
                ];
            }
        }

        return $resultat;
    }

    /**
     * @return array{credits: int, cm: int, td: int, tp: int}
     */
    private function totaux(iterable $lignes): array
    {
        $totaux = ['credits' => 0, 'cm' => 0, 'td' => 0, 'tp' => 0];

        foreach ($lignes as $ligne) {
            foreach ($ligne->ecues as $occurrence) {
                $totaux['credits'] += (int) $occurrence->credits;
                $totaux['cm'] += (int) $occurrence->volume_cm;
                $totaux['td'] += (int) $occurrence->volume_td;
                $totaux['tp'] += (int) $occurrence->volume_tp;
            }
        }

        return $totaux;
    }

    /**
     * @return array{version: mixed, statut: mixed, annee: ?string}
     */
    private function meta(Maquette $maquette): array
    {
        return [
            'id' => $maquette->id,
            'version' => $maquette->version,
            'statut' => $maquette->statut,
            'annee' => $maquette->anneeUniversitaire?->libelle,
        ];
    }
}
