<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\Referentiel\Models\Programme;

/**
 * Service de contrôle d'éligibilité et d'orientation académique (LOOP 08).
 *
 * Source unique de vérité pour les règles d'éligibilité aux filières :
 * - Vérification de la cohérence Faculté ↔ Programme ↔ Niveaux LMD ;
 * - Statut d'ouverture et capacité d'accueil du programme ;
 * - Adéquation du type d'inscription (L1 pour première inscription vs admission préalable requise pour niveaux avancés) ;
 * - Traitement explicite et gracieux des référentiels incomplets ;
 * - Traçabilité des exceptions et dérogations administratives.
 *
 * Zéro divination IA : repose exclusivement sur les règles et données officielles.
 */
class EligibiliteParcoursService
{
    /**
     * Évalue l'éligibilité complète d'une candidature à un parcours.
     *
     * @param  array{
     *     faculte_id?: int|string|null,
     *     programme_id?: int|string|null,
     *     niveau?: string|null,
     *     type_inscription?: string|null,
     *     annee_academique?: string|null,
     *     annee_bac?: string|int|null,
     *     cin?: string|null,
     *     derogation_administrative?: string|null
     * }  $candidature
     * @return array{
     *     eligible: bool,
     *     statut: string,
     *     bloquants: array<string, string>,
     *     avertissements: array<int, string>,
     *     explications: array<string, string>,
     *     referentiel_incomplet: bool,
     *     exception_accordee: bool
     * }
     */
    public function evaluer(array $candidature): array
    {
        $bloquants = [];
        $avertissements = [];
        $explications = [];
        $referentielIncomplet = false;
        $exceptionAccordee = false;

        $faculteId = ! empty($candidature['faculte_id']) ? (int) $candidature['faculte_id'] : null;
        $programmeId = ! empty($candidature['programme_id']) ? (int) $candidature['programme_id'] : null;
        $niveau = ! empty($candidature['niveau']) ? (string) $candidature['niveau'] : 'L1';
        $typeInscription = (string) ($candidature['type_inscription'] ?? 'premiere');
        $anneeAcademique = ! empty($candidature['annee_academique']) ? (string) $candidature['annee_academique'] : null;
        $anneeBac = ! empty($candidature['annee_bac']) ? (string) $candidature['annee_bac'] : null;
        $cin = ! empty($candidature['cin']) ? strtoupper(trim((string) $candidature['cin'])) : null;
        $derogation = ! empty($candidature['derogation_administrative']) ? trim((string) $candidature['derogation_administrative']) : null;

        // 1. Contrôle Faculté
        if ($faculteId === null) {
            $bloquants['faculte_id'] = 'La faculté d’accueil est obligatoire.';
        } else {
            $faculte = Faculte::find($faculteId);
            if (! $faculte || ! $faculte->active) {
                $bloquants['faculte_id'] = 'La faculté sélectionnée est inactive ou introuvable.';
            }
        }

        // 2. Contrôle Programme (si sélectionné ou disponible)
        $programme = null;
        if ($programmeId !== null) {
            $programme = Programme::with('niveaux')->find($programmeId);

            if (! $programme) {
                $bloquants['programme_id'] = 'Le parcours de formation sélectionné est introuvable.';
            } else {
                // Cohérence Faculté ↔ Programme
                if ($faculteId !== null && (int) $programme->faculte_id !== $faculteId) {
                    $bloquants['programme_id'] = "Le parcours « {$programme->nom} » n'est pas dispensé par la faculté sélectionnée.";
                }

                // Statut d'activité du programme
                if ($programme->statut !== Programme::STATUT_ACTIF && ! $programme->actif) {
                    $bloquants['programme_id'] = "Le parcours « {$programme->nom} » n'est pas ouvert aux inscriptions pour cette session.";
                }

                // Capacité d'accueil
                if ($programme->capacite !== null && $programme->capacite > 0) {
                    $inscrits = Etudiant::where('programme_id', $programme->id)
                        ->when($anneeAcademique !== null, fn ($q) => $q->where('annee_academique', $anneeAcademique))
                        ->count();

                    if ($inscrits >= $programme->capacite) {
                        if ($derogation !== null) {
                            $exceptionAccordee = true;
                            $avertissements[] = "Capacité d'accueil atteinte ({$programme->capacite} places), inscription acceptée au titre d'une dérogation administrative.";
                        } else {
                            $bloquants['programme_id'] = "La capacité d'accueil de ce parcours ({$programme->capacite} places) est atteinte pour cette session.";
                        }
                    }
                }
            }
        } elseif ($faculteId !== null && empty($bloquants['faculte_id'])) {
            // Aucun programme spécifié : vérifier si la faculté a des programmes
            $nbProgrammesActifs = Programme::where('faculte_id', $faculteId)->actifs()->count();
            if ($nbProgrammesActifs === 0) {
                $referentielIncomplet = true;
                $explications['programme'] = 'Aucun parcours spécifique n’est paramétré pour cette faculté : inscription enregistrée selon le cursus général.';
            }
        }

        // 3. Contrôle Niveau vs Programme
        if ($programme !== null) {
            $niveauxActifs = $programme->niveaux->where('actif', true);

            if ($niveauxActifs->isNotEmpty()) {
                $matchNiveau = $niveauxActifs->firstWhere('code', $niveau);
                if (! $matchNiveau) {
                    $codesDisponibles = $niveauxActifs->pluck('code')->join(', ');
                    $bloquants['niveau'] = "Le niveau {$niveau} n'est pas proposé dans le parcours « {$programme->nom} ». Niveaux proposés : {$codesDisponibles}.";
                }
            } else {
                $referentielIncomplet = true;
                $explications['niveau'] = "Niveaux non paramétrés dans le référentiel pour ce parcours : niveau {$niveau} accepté par défaut.";
            }
        }

        // 4. Contrôle Type d'inscription vs Niveau d'accès
        if ($typeInscription === 'premiere') {
            // Accès direct de plein droit en L1 (et D1 en doctorat)
            $niveauxAvances = ['L2', 'L3', 'M1', 'M2', 'D2', 'D3'];
            if (in_array($niveau, $niveauxAvances, true)) {
                // Recherche d'une admission officielle préalable
                $aAdmission = false;
                if ($cin !== null) {
                    $etudiantExistant = Etudiant::where('cin', $cin)->first();
                    if ($etudiantExistant) {
                        $aAdmission = Admission::where('etudiant_id', $etudiantExistant->id)
                            ->when($programme !== null, fn ($q) => $q->where('programme_id', $programme->id))
                            ->whereIn('decision', ['admis', 'admis_conditionnel'])
                            ->exists();
                    }
                }

                if (! $aAdmission) {
                    if ($derogation !== null) {
                        $exceptionAccordee = true;
                        $avertissements[] = "Accès direct au niveau {$niveau} en première inscription autorisé par dérogation administrative.";
                    } else {
                        $bloquants['niveau'] = "L'accès direct au niveau {$niveau} en première inscription requiert une admission officielle préalable ou une procédure de passerelle/transfert.";
                    }
                }
            }
        } elseif ($typeInscription === 'reinscription') {
            if ($cin !== null) {
                $dossierExiste = Etudiant::where('cin', $cin)->exists();
                if (! $dossierExiste) {
                    $bloquants['type_inscription'] = "Aucun dossier étudiant antérieur trouvé pour ce CIN ({$cin}). Veuillez sélectionner « Première inscription » ou vérifier votre identification.";
                }
            }
        } elseif ($typeInscription === 'transfert') {
            $avertissements[] = "Procédure de transfert : la validation définitive est conditionnée par l'avis de la commission d'équivalence.";
        }

        // 5. Fraîcheur du Baccalauréat
        if ($typeInscription === 'premiere' && $niveau === 'L1' && $anneeBac !== null) {
            $anneeCourante = (int) date('Y');
            $diffAnnees = $anneeCourante - (int) $anneeBac;
            if ($diffAnnees > 3) {
                $avertissements[] = "Baccalauréat antérieur à plus de 3 ans (session {$anneeBac}) : votre dossier fera l'objet d'un examen pédagogique particulier pour reprise d'études.";
            }
        }

        $estEligible = empty($bloquants);
        $statut = $estEligible
            ? (empty($avertissements) ? 'VALIDE' : 'AVERTISSEMENT')
            : 'BLOQUANT';

        return [
            'eligible' => $estEligible,
            'statut' => $statut,
            'bloquants' => $bloquants,
            'avertissements' => $avertissements,
            'explications' => $explications,
            'referentiel_incomplet' => $referentielIncomplet,
            'exception_accordee' => $exceptionAccordee,
        ];
    }

    /**
     * Fournit la liste des options de parcours et de niveaux pour une faculté donnée,
     * avec explications des options disponibles et des contraintes d'éligibilité.
     *
     * @return array{
     *     faculte_id: int,
     *     programmes: array<int, array{
     *         id: int,
     *         code: string,
     *         nom: string,
     *         actif: bool,
     *         statut: string,
     *         capacite: ?int,
     *         complet: bool,
     *         niveaux: array<int, array{code: string, nom: string, eligible: bool, explication: ?string}>,
     *         explication: ?string
     *     }>,
     *     referentiel_incomplet: bool
     * }
     */
    public function optionsOrientation(
        int $faculteId,
        string $typeInscription = 'premiere',
        ?string $anneeBac = null,
        ?string $cin = null,
        ?string $anneeAcademique = null
    ): array {
        $programmes = Programme::where('faculte_id', $faculteId)
            ->with(['niveaux' => fn ($q) => $q->where('actif', true)->orderBy('ordre')])
            ->get();

        if ($programmes->isEmpty()) {
            return [
                'faculte_id' => $faculteId,
                'programmes' => [],
                'referentiel_incomplet' => true,
            ];
        }

        $resultats = [];
        foreach ($programmes as $prog) {
            $estActif = $prog->statut === Programme::STATUT_ACTIF && $prog->actif;
            $inscrits = ($prog->capacite !== null && $prog->capacite > 0)
                ? Etudiant::where('programme_id', $prog->id)
                    ->when($anneeAcademique !== null, fn ($q) => $q->where('annee_academique', $anneeAcademique))
                    ->count()
                : 0;

            $estComplet = ($prog->capacite !== null && $prog->capacite > 0 && $inscrits >= $prog->capacite);

            $explicationProg = null;
            if (! $estActif) {
                $explicationProg = 'Ce parcours n’est pas ouvert aux inscriptions pour cette session.';
            } elseif ($estComplet) {
                $explicationProg = "Capacité d'accueil maximale atteinte ({$prog->capacite} places).";
            }

            $niveauxOptions = [];
            foreach ($prog->niveaux as $niv) {
                $nivEligible = true;
                $nivExplication = null;

                if (! $estActif) {
                    $nivEligible = false;
                    $nivExplication = 'Parcours inactif';
                } elseif ($typeInscription === 'premiere' && in_array($niv->code, ['L2', 'L3', 'M1', 'M2', 'D2', 'D3'], true)) {
                    $nivEligible = false;
                    $nivExplication = 'Admission officielle ou procédure de passerelle requise';
                }

                $niveauxOptions[] = [
                    'code' => $niv->code,
                    'nom' => $niv->nom,
                    'eligible' => $nivEligible,
                    'explication' => $nivExplication,
                ];
            }

            $resultats[] = [
                'id' => $prog->id,
                'code' => $prog->code,
                'nom' => $prog->nom,
                'actif' => $estActif,
                'statut' => $prog->statut,
                'capacite' => $prog->capacite,
                'complet' => $estComplet,
                'niveaux' => $niveauxOptions,
                'explication' => $explicationProg,
            ];
        }

        return [
            'faculte_id' => $faculteId,
            'programmes' => $resultats,
            'referentiel_incomplet' => false,
        ];
    }
}
