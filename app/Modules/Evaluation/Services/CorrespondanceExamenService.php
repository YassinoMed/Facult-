<?php

namespace App\Modules\Evaluation\Services;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\InscriptionPedagogique;
use App\Models\IntegrationLog;
use App\Modules\Enseignement\Models\EnseignementGroupe;
use App\Modules\Evaluation\DTO\DecisionEligibiliteExamen;
use App\Modules\Evaluation\Events\EvaluationChanged;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\GroupeVirtuelExamen;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Schedule\Models\Group;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Service central de correspondance stricte Matière ↔ Groupes ↔ Étudiants ↔ Examens.
 *
 * Garantit côté serveur que chaque examen et chaque épreuve concernent exclusivement
 * les groupes pédagogiques et les étudiants réellement inscrits et éligibles à la
 * matière examinée, sans mélange de groupes, de filières, de niveaux ou d'inscriptions.
 *
 * Règles métier appliquées :
 * 1. Un étudiant ne peut être convoqué que s'il est inscrit à la matière et éligible.
 * 2. Un groupe n'est associé à une épreuve que si sa structure académique et ses
 *    inscriptions pédagogiques correspondent à la matière.
 * 3. Les groupes partageant une matière peuvent être regroupés virtuellement uniquement
 *    si les règles institutionnelles (faculté, programme, niveau, filière) le permettent.
 * 4. Un regroupement virtuel ne modifie jamais les inscriptions ou groupes d'origine.
 * 5. Une matière sans étudiants éligibles ne produit pas silencieusement un examen publiable.
 * 6. Toute discordance (matière, groupe, filière, niveau, inscription, candidat omis ou
 *    non inscrit, doublon) est signalée et bloque la validation et la publication.
 * 7. Toute modification pertinente après génération déclenche une re-vérification.
 */
class CorrespondanceExamenService
{
    public const ALERTE_PUBLICATION_BLOQUEE = "Publication bloquée : la correspondance matière/groupes n'est pas validée.";

    public const ALERTE_GROUPE_INCOHERENT = 'Le groupe sélectionné ne correspond pas aux inscriptions pédagogiques de cette épreuve.';

    private const STATUTS_ACADEMIQUES_ACTIFS = ['en_cours', 'valide', 'inscrit'];

    public function __construct(
        private readonly ServiceEligibiliteExamen $serviceEligibilite,
    ) {}

    /**
     * Analyse complète de la correspondance Matière ↔ Groupes ↔ Étudiants ↔ Épreuve.
     *
     * @return array{
     *     perimetre: array<string, mixed>,
     *     compteurs: array{
     *         inscrits: int,
     *         eligibles: int,
     *         exclus: int,
     *         planifies: int,
     *         convoques: int,
     *         non_inscrits_planifies: int,
     *         non_eligibles_planifies: int,
     *         hors_groupe_planifies: int,
     *         eligibles_omis: int,
     *         doublons: int
     *     },
     *     liste_reference_eligibles: array<int, array<string, mixed>>,
     *     etudiants_exclus: array<int, array<string, mixed>>,
     *     ecarts: array{
     *         candidats_non_inscrits: array<int, array<string, mixed>>,
     *         candidats_non_eligibles: array<int, array<string, mixed>>,
     *         candidats_hors_groupe: array<int, array<string, mixed>>,
     *         eligibles_omis: array<int, array<string, mixed>>,
     *         doublons_candidats: array<int, array<string, mixed>>,
     *         anomalies_groupes: array<int, string>,
     *         anomalies_salles: array<int, string>
     *     },
     *     alertes: array<int, string>,
     *     bloquants: array<int, string>,
     *     avertissements: array<int, string>,
     *     conforme: bool,
     *     publication_autorisee: bool,
     *     empreinte_correspondance: string
     * }
     */
    public function analyserEpreuve(
        Epreuve $epreuve,
        bool $exigerEligiblesPourPublication = false,
        bool $ignorerDeriveSnapshot = false,
    ): array {
        $epreuve->loadMissing([
            'matiere.ue.programme.faculte',
            'matiere.ue.semestre.niveau',
            'groupe.programme',
            'groupe.niveauRef',
            'examen.programme.faculte',
            'examen.niveau',
            'examen.semestreLmd',
            'session',
            'salle',
            'candidats.etudiant.groupe',
            'candidats.salle',
            'candidats.inscriptionPedagogique',
        ]);

        $perimetre = $this->construirePerimetre($epreuve);
        $anomaliesGroupes = [];
        $bloquants = [];
        $avertissements = [];
        $alertes = [];

        // 1. Vérification de la matière et de la session
        if ($epreuve->matiere_id === null || $epreuve->matiere === null) {
            $msg = 'Matière absente ou introuvable pour cette épreuve.';
            $bloquants[] = $msg;
            $alertes[] = $msg;
        }

        if ($epreuve->session === null) {
            $msg = "Session d'examen absente pour cette épreuve.";
            $bloquants[] = $msg;
            $alertes[] = $msg;
        }

        // 2. Vérification structurelle et pédagogique des groupes associés
        $groupeVirtuel = $this->groupeVirtuelActif($epreuve);
        $groupesCibles = $this->groupesConcernantEpreuve($epreuve, $groupeVirtuel);

        if ($epreuve->groupe !== null) {
            $verifGroupe = $this->verifierCoherenceStructurelleGroupe($epreuve, $epreuve->groupe, true);
            foreach ($verifGroupe['anomalies'] as $anomalie) {
                $anomaliesGroupes[] = $anomalie;
                $bloquants[] = $anomalie;
            }
        }

        if ($groupeVirtuel !== null) {
            foreach ($groupesCibles as $groupeSource) {
                $verifSource = $this->verifierCoherenceStructurelleGroupe($epreuve, $groupeSource, true);
                foreach ($verifSource['anomalies'] as $anomalie) {
                    $anomaliesGroupes[] = $anomalie;
                    $bloquants[] = $anomalie;
                }
            }
        }

        $anomaliesGroupes = array_values(array_unique($anomaliesGroupes));
        foreach ($anomaliesGroupes as $anomalieGroupe) {
            $alertes[] = $anomalieGroupe;
        }

        // 3. Calcul de la population de référence, des inscrits, des éligibles et des exclus
        $modeStrict = $this->estModePedagogiqueStrict($epreuve);
        $groupesAutorisesIds = $groupesCibles->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $etudiantsPerimetre = $this->collecterEtudiantsPerimetre($epreuve, $groupesAutorisesIds);
        $inscritsMatiereIds = $this->identifiantsInscritsMatiere($epreuve, $groupesAutorisesIds);

        $listeReferenceEligibles = [];
        $etudiantsExclus = [];
        $eligiblesParId = [];

        foreach ($etudiantsPerimetre as $etudiant) {
            $evaluation = $this->evaluerEtudiantPourEpreuve($epreuve, $etudiant, $groupesAutorisesIds, $modeStrict);

            if ($evaluation['eligible']) {
                $entreeEligible = [
                    'etudiant_id' => (int) $etudiant->id,
                    'num_inscription' => (string) ($etudiant->num_inscription ?? ''),
                    'nom' => (string) ($etudiant->nom ?? ''),
                    'prenom' => (string) ($etudiant->prenom ?? ''),
                    'groupe_id' => $etudiant->groupe_id !== null ? (int) $etudiant->groupe_id : null,
                    'groupe_code' => (string) ($etudiant->groupe?->code ?? '—'),
                    'niveau' => (string) ($etudiant->niveau ?? '—'),
                    'filiere' => (string) ($etudiant->filiere ?? $etudiant->groupe?->filiere ?? '—'),
                    'inscription_academique_id' => $evaluation['inscription_academique_id'],
                    'inscription_pedagogique_id' => $evaluation['inscription_pedagogique_id'],
                    'regle' => $evaluation['regle'],
                    'motif' => $evaluation['motif'],
                ];
                $listeReferenceEligibles[] = $entreeEligible;
                $eligiblesParId[(int) $etudiant->id] = $entreeEligible;
            } else {
                $etudiantsExclus[] = [
                    'etudiant_id' => (int) $etudiant->id,
                    'num_inscription' => (string) ($etudiant->num_inscription ?? ''),
                    'nom' => (string) ($etudiant->nom ?? ''),
                    'prenom' => (string) ($etudiant->prenom ?? ''),
                    'groupe_id' => $etudiant->groupe_id !== null ? (int) $etudiant->groupe_id : null,
                    'groupe_code' => (string) ($etudiant->groupe?->code ?? '—'),
                    'decision' => $evaluation['decision'],
                    'regle' => $evaluation['regle'],
                    'motif' => $evaluation['motif'],
                ];
            }
        }

        // 4. Analyse des candidats planifiés et convoqués par rapport à la liste de référence
        $candidats = $epreuve->candidats;
        $candidatsNonInscrits = [];
        $candidatsNonEligibles = [];
        $candidatsHorsGroupe = [];
        $doublonsCandidats = [];
        $anomaliesSalles = [];
        $frequenceEtudiants = [];
        $planifiesParEtudiantId = [];
        $convoquesValidesCount = 0;

        $verifierEligibiliteCandidats = $modeStrict
            || InscriptionAcademique::query()->exists()
            || $groupesAutorisesIds !== [];

        foreach ($candidats as $candidat) {
            $etudiantId = (int) $candidat->etudiant_id;
            $frequenceEtudiants[$etudiantId] = ($frequenceEtudiants[$etudiantId] ?? 0) + 1;
            $planifiesParEtudiantId[$etudiantId] = $candidat;

            $etudiant = $candidat->etudiant;
            if ($etudiant === null) {
                $candidatsNonEligibles[] = [
                    'candidat_id' => (int) $candidat->id,
                    'etudiant_id' => $etudiantId,
                    'num_inscription' => '—',
                    'nom' => 'Introuvable',
                    'prenom' => '',
                    'groupe_code' => '—',
                    'regle' => 'etudiant_introuvable',
                    'motif' => 'Étudiant rattaché au candidat introuvable.',
                ];

                continue;
            }

            if ($frequenceEtudiants[$etudiantId] > 1) {
                $doublonsCandidats[] = [
                    'candidat_id' => (int) $candidat->id,
                    'etudiant_id' => $etudiantId,
                    'num_inscription' => (string) ($etudiant->num_inscription ?? ''),
                    'nom' => (string) ($etudiant->nom ?? ''),
                    'prenom' => (string) ($etudiant->prenom ?? ''),
                    'motif' => 'Candidat présent plusieurs fois dans la même épreuve.',
                ];
            }

            $estEligibleCandidat = true;

            if ($verifierEligibiliteCandidats) {
                $evalCandidat = $this->evaluerEtudiantPourEpreuve($epreuve, $etudiant, $groupesAutorisesIds, $modeStrict);
                $estEligibleCandidat = $evalCandidat['eligible'];

                if (! $estEligibleCandidat) {
                    $detail = [
                        'candidat_id' => (int) $candidat->id,
                        'etudiant_id' => $etudiantId,
                        'num_inscription' => (string) ($etudiant->num_inscription ?? ''),
                        'nom' => (string) ($etudiant->nom ?? ''),
                        'prenom' => (string) ($etudiant->prenom ?? ''),
                        'groupe_id' => $etudiant->groupe_id !== null ? (int) $etudiant->groupe_id : null,
                        'groupe_code' => (string) ($etudiant->groupe?->code ?? '—'),
                        'regle' => $evalCandidat['regle'],
                        'motif' => $evalCandidat['motif'],
                    ];

                    $candidatsNonEligibles[] = $detail;

                    if (in_array($evalCandidat['regle'], [
                        ServiceEligibiliteExamen::REGLE_INSCRIPTION_MODULE_ACTIVE,
                        ServiceEligibiliteExamen::REGLE_INSCRIPTION_ACADEMIQUE_ACTIVE,
                    ], true)) {
                        $candidatsNonInscrits[] = $detail;
                    }

                    if ($evalCandidat['regle'] === 'appartenance_groupe_epreuve') {
                        $candidatsHorsGroupe[] = $detail;
                    }
                }
            }

            // Vérification de la salle affectée au candidat
            $salleEffective = $candidat->salle ?? $epreuve->salle;
            if ((int) $candidat->epreuve_id !== (int) $epreuve->id) {
                $anomaliesSalles[] = "Affectation de salle incohérente : le candidat #{$candidat->id} n'appartient pas à l'épreuve #{$epreuve->id}.";
            } elseif ($candidat->salle !== null && ! $candidat->salle->actif) {
                $anomaliesSalles[] = "Le candidat #{$candidat->id} est affecté à une salle inactive ({$candidat->salle->code}).";
            }

            if ($estEligibleCandidat && $salleEffective !== null) {
                $convoquesValidesCount++;
            }
        }

        // 5. Détection des étudiants éligibles absents de la planification (omis)
        $eligiblesOmis = [];
        foreach ($eligiblesParId as $etudiantId => $infoEligible) {
            if (! isset($planifiesParEtudiantId[$etudiantId])) {
                $eligiblesOmis[] = $infoEligible;
            }
        }

        // 6. Construction des alertes et des blocages métier (Section 4)
        $nbNonInscrits = count($candidatsNonInscrits);
        if ($nbNonInscrits > 0) {
            $msg = "{$nbNonInscrits} étudiants planifiés ne sont pas inscrits à cette matière.";
            $alertes[] = $msg;
            $bloquants[] = $msg;
        }

        $nbHorsGroupe = count($candidatsHorsGroupe);
        if ($nbHorsGroupe > 0) {
            $msg = "{$nbHorsGroupe} étudiants planifiés appartiennent à un groupe non concerné par cette épreuve.";
            $alertes[] = $msg;
            $bloquants[] = $msg;
        }

        $nbAutresNonEligibles = count($candidatsNonEligibles) - $nbNonInscrits - $nbHorsGroupe;
        if ($nbAutresNonEligibles > 0) {
            $premierMotif = $candidatsNonEligibles[0]['motif'] ?? 'Règle d’éligibilité non satisfaite.';
            $msg = "{$nbAutresNonEligibles} étudiants planifiés ne sont pas éligibles à cette épreuve ({$premierMotif})";
            $alertes[] = $msg;
            $bloquants[] = $msg;
        }

        $nbOmis = count($eligiblesOmis);
        if ($nbOmis > 0) {
            $msg = "{$nbOmis} étudiants éligibles sont absents de la planification.";
            $alertes[] = $msg;
            $bloquants[] = $msg;
        }

        $nbDoublons = count($doublonsCandidats);
        if ($nbDoublons > 0) {
            $msg = "{$nbDoublons} doublon(s) de candidat détecté(s) dans l'épreuve.";
            $alertes[] = $msg;
            $bloquants[] = $msg;
        }

        foreach ($anomaliesSalles as $anomalieSalle) {
            $alertes[] = $anomalieSalle;
            $bloquants[] = $anomalieSalle;
        }

        // Règle 5 : Une matière sans étudiants éligibles ne doit pas produire silencieusement un examen publiable.
        $populationEtudiantsExiste = Etudiant::query()->exists();
        $aucunEligible = count($listeReferenceEligibles) === 0;
        $aucunCandidatValide = $candidats->count() === 0 || count($candidatsNonEligibles) === $candidats->count();

        if ($aucunEligible && $aucunCandidatValide && ($exigerEligiblesPourPublication || $populationEtudiantsExiste)) {
            $msg = 'Aucun étudiant éligible pour cette matière : impossible de valider ou publier un examen sans candidats éligibles.';
            $alertes[] = $msg;
            $bloquants[] = $msg;
        }

        // Règle 7 : Vérification de la dérive post-validation (snapshot de population)
        $empreinteCourante = $this->calculerEmpreinteCorrespondance(
            $epreuve,
            array_keys($eligiblesParId),
            $candidats->pluck('etudiant_id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            $groupeVirtuel?->cle
        );

        if (! $ignorerDeriveSnapshot && $epreuve->population_validated_at !== null && $epreuve->population_hash !== null) {
            $hashActuel = app(PreparationExamenService::class)->hashPopulation($epreuve);
            if (! hash_equals((string) $epreuve->population_hash, $hashActuel)) {
                $msg = 'Modification détectée après la validation de la population : revalidation obligatoire des candidats, des salles et des convocations.';
                $alertes[] = $msg;
                $avertissements[] = $msg;
                $bloquants[] = $msg;
            }
        }

        $bloquants = array_values(array_unique($bloquants));
        $conforme = $bloquants === [];

        if (! $conforme) {
            $alertes[] = self::ALERTE_PUBLICATION_BLOQUEE;
        }

        $alertes = array_values(array_unique($alertes));

        $nbInscrits = $modeStrict
            ? count($inscritsMatiereIds)
            : max(count($inscritsMatiereIds), count($listeReferenceEligibles));

        return [
            'perimetre' => $perimetre,
            'compteurs' => [
                'inscrits' => $nbInscrits,
                'eligibles' => count($listeReferenceEligibles),
                'exclus' => count($etudiantsExclus),
                'planifies' => $candidats->count(),
                'convoques' => $convoquesValidesCount,
                'non_inscrits_planifies' => $nbNonInscrits,
                'non_eligibles_planifies' => count($candidatsNonEligibles),
                'hors_groupe_planifies' => $nbHorsGroupe,
                'eligibles_omis' => $nbOmis,
                'doublons' => $nbDoublons,
            ],
            'liste_reference_eligibles' => $listeReferenceEligibles,
            'etudiants_exclus' => $etudiantsExclus,
            'ecarts' => [
                'candidats_non_inscrits' => $candidatsNonInscrits,
                'candidats_non_eligibles' => $candidatsNonEligibles,
                'candidats_hors_groupe' => $candidatsHorsGroupe,
                'eligibles_omis' => $eligiblesOmis,
                'doublons_candidats' => $doublonsCandidats,
                'anomalies_groupes' => $anomaliesGroupes,
                'anomalies_salles' => $anomaliesSalles,
            ],
            'alertes' => $alertes,
            'bloquants' => $bloquants,
            'avertissements' => $avertissements,
            'conforme' => $conforme,
            'publication_autorisee' => $conforme,
            'empreinte_correspondance' => $empreinteCourante,
        ];
    }

    /**
     * Vérifie la cohérence structurelle et pédagogique d'un groupe avec l'épreuve et sa matière.
     *
     * @return array{conforme: bool, anomalies: array<int, string>}
     */
    public function verifierCoherenceStructurelleGroupe(
        Epreuve $epreuve,
        ?Group $groupe = null,
        bool $estSourceGroupeVirtuel = false,
    ): array {
        $groupeCible = $groupe ?? $epreuve->groupe;
        if ($groupeCible === null) {
            return ['conforme' => true, 'anomalies' => []];
        }

        $epreuve->loadMissing([
            'matiere.ue.programme',
            'matiere.ue.semestre.niveau',
            'examen.programme',
            'examen.niveau',
        ]);

        $anomalies = [];
        $matiere = $epreuve->matiere;
        $examen = $epreuve->examen;

        // 1. Faculté
        $faculteExamen = $examen?->faculteId();
        $faculteMatiere = $matiere?->ue?->programme?->faculte_id;
        $faculteAttendue = $estSourceGroupeVirtuel
            ? ($faculteMatiere ?? $faculteExamen)
            : ($groupeCible->programme_id !== null || $groupeCible->niveau_id !== null || InscriptionPedagogique::query()->exists()
                ? ($faculteMatiere ?? $faculteExamen)
                : null);

        if ($faculteAttendue !== null && $groupeCible->faculte_id !== null
            && (int) $groupeCible->faculte_id !== (int) $faculteAttendue) {
            $anomalies[] = "Discordance de faculté : le groupe {$groupeCible->code} n'appartient pas à la faculté de la matière.";
        }

        // 2. Programme / Parcours
        $programmeMatiere = $matiere?->ue?->programme_id;
        $programmeExamen = $examen?->programme_id;

        if ($programmeMatiere !== null && $programmeExamen !== null && (int) $programmeMatiere !== (int) $programmeExamen) {
            $anomalies[] = "Discordance de programme : la matière ({$matiere?->code}) n'appartient pas au programme de l'examen.";
        }

        $programmeAttendu = $programmeMatiere ?? $programmeExamen;
        if ($programmeAttendu !== null && $groupeCible->programme_id !== null
            && (int) $groupeCible->programme_id !== (int) $programmeAttendu) {
            $anomalies[] = self::ALERTE_GROUPE_INCOHERENT." (programme du groupe {$groupeCible->code} incompatible avec la matière {$matiere?->code}).";
        }

        // 3. Niveau
        $niveauIdMatiere = $matiere?->ue?->semestre?->niveau_id;
        $niveauIdExamen = $examen?->niveau_id;
        $niveauIdAttendu = $niveauIdMatiere ?? $niveauIdExamen;

        if ($niveauIdMatiere !== null && $niveauIdExamen !== null && (int) $niveauIdMatiere !== (int) $niveauIdExamen) {
            $anomalies[] = "Discordance de niveau : la matière ({$matiere?->code}) ne correspond pas au niveau de l'examen.";
        }

        if ($niveauIdAttendu !== null && $groupeCible->niveau_id !== null
            && (int) $groupeCible->niveau_id !== (int) $niveauIdAttendu) {
            $anomalies[] = self::ALERTE_GROUPE_INCOHERENT." (niveau du groupe {$groupeCible->code} incompatible).";
        }

        $codeNiveauMatiere = $this->codeNiveauMatiere($epreuve);
        $codeNiveauGroupe = $this->normaliserCodeNiveau($groupeCible->niveauRef?->code ?? $groupeCible->niveau);
        if ($codeNiveauMatiere !== null && $codeNiveauGroupe !== null && $codeNiveauMatiere !== $codeNiveauGroupe) {
            $anomalies[] = self::ALERTE_GROUPE_INCOHERENT." (niveau {$groupeCible->niveau} du groupe {$groupeCible->code} différent du niveau {$codeNiveauMatiere} de la matière).";
        }

        // 4. Filière
        $filiereMatiere = $matiere?->filiere !== null && trim((string) $matiere->filiere) !== ''
            ? mb_strtoupper(trim((string) $matiere->filiere))
            : null;
        $filiereGroupe = $groupeCible->filiere !== null && trim((string) $groupeCible->filiere) !== ''
            ? mb_strtoupper(trim((string) $groupeCible->filiere))
            : null;

        if ($filiereMatiere !== null && $filiereGroupe !== null && $filiereMatiere !== $filiereGroupe) {
            $anomalies[] = self::ALERTE_GROUPE_INCOHERENT." (filière {$groupeCible->filiere} du groupe {$groupeCible->code} différente de la filière {$matiere?->filiere} de la matière).";
        }

        // 5. Règle 2 : Vérifier si les étudiants du groupe suivent effectivement la matière
        // lorsque des étudiants et des inscriptions pédagogiques sont renseignés dans le système.
        if ($epreuve->matiere_id !== null && $anomalies === []) {
            $idsEtudiantsGroupe = Etudiant::query()
                ->where('groupe_id', $groupeCible->id)
                ->pluck('id')
                ->all();

            if ($idsEtudiantsGroupe !== []) {
                $inscriptionsPedagogiquesSysteme = InscriptionPedagogique::query()
                    ->where(function ($q) use ($epreuve, $idsEtudiantsGroupe) {
                        $q->where('matiere_id', $epreuve->matiere_id)
                            ->orWhereHas('inscription', fn ($sq) => $sq->whereIn('etudiant_id', $idsEtudiantsGroupe));
                    })
                    ->exists();

                if ($inscriptionsPedagogiquesSysteme) {
                    $auMoinsUnInscritDansGroupe = InscriptionPedagogique::query()
                        ->where('matiere_id', $epreuve->matiere_id)
                        ->where('statut', 'inscrit')
                        ->whereHas('inscription', function ($q) use ($epreuve, $idsEtudiantsGroupe) {
                            $q->whereIn('etudiant_id', $idsEtudiantsGroupe)
                                ->when(
                                    $epreuve->annee_universitaire_id !== null,
                                    fn ($sq) => $sq->where('annee_universitaire_id', $epreuve->annee_universitaire_id)
                                )
                                ->whereIn('statut', self::STATUTS_ACADEMIQUES_ACTIFS);
                        })
                        ->exists();

                    $enseignementLieAuGroupe = EnseignementGroupe::query()
                        ->where('groupe_id', $groupeCible->id)
                        ->whereHas('enseignement', fn ($q) => $q
                            ->where('matiere_id', $epreuve->matiere_id)
                            ->when(
                                $epreuve->annee_universitaire_id !== null,
                                fn ($sq) => $sq->where('annee_universitaire_id', $epreuve->annee_universitaire_id)
                            )
                        )
                        ->exists();

                    if (! $auMoinsUnInscritDansGroupe && ! $enseignementLieAuGroupe) {
                        $anomalies[] = self::ALERTE_GROUPE_INCOHERENT;
                    }
                }
            }
        }

        return [
            'conforme' => $anomalies === [],
            'anomalies' => array_values(array_unique($anomalies)),
        ];
    }

    /**
     * Synchronise les candidats d'une épreuve à partir de la liste de référence des étudiants éligibles.
     * Supprime les candidats non pointés et sans note qui ne sont plus éligibles, et crée les candidats éligibles manquants.
     *
     * @return array{crees: int, retires: int, total_eligibles: int}
     */
    public function synchroniserCandidatsEligibles(Epreuve $epreuve, ?int $auteurId = null): array
    {
        return DB::transaction(function () use ($epreuve, $auteurId): array {
            /** @var Epreuve $verrouillee */
            $verrouillee = Epreuve::whereKey($epreuve->id)->lockForUpdate()->firstOrFail();

            if ($verrouillee->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée : synchronisation des candidats interdite.');
            }

            // Vérification structurelle préalable du groupe
            if ($verrouillee->groupe !== null) {
                $verif = $this->verifierCoherenceStructurelleGroupe($verrouillee, $verrouillee->groupe);
                if (! $verif['conforme']) {
                    throw new DomainException($verif['anomalies'][0]);
                }
            }

            $groupeVirtuel = $this->groupeVirtuelActif($verrouillee);
            $groupesCibles = $this->groupesConcernantEpreuve($verrouillee, $groupeVirtuel);
            $groupesAutorisesIds = $groupesCibles->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            $modeStrict = $this->estModePedagogiqueStrict($verrouillee, true);

            $etudiantsPerimetre = $this->collecterEtudiantsPerimetre($verrouillee, $groupesAutorisesIds);

            $eligiblesMap = [];
            foreach ($etudiantsPerimetre as $etudiant) {
                $eval = $this->evaluerEtudiantPourEpreuve($verrouillee, $etudiant, $groupesAutorisesIds, $modeStrict);
                if ($eval['eligible']) {
                    $eligiblesMap[(int) $etudiant->id] = $eval;
                }
            }

            // Retrait des candidats non éligibles qui n'ont ni pointage effectif ni note saisie
            $retires = 0;
            $candidatsExistants = Candidat::where('epreuve_id', $verrouillee->id)
                ->with('note')
                ->lockForUpdate()
                ->get();

            $vus = [];
            foreach ($candidatsExistants as $candidatExistant) {
                $etudiantId = (int) $candidatExistant->etudiant_id;

                // Nettoyage d'un éventuel doublon
                if (isset($vus[$etudiantId])) {
                    if ($candidatExistant->statut === Candidat::STATUT_NON_POINTE && $candidatExistant->note === null) {
                        $candidatExistant->delete();
                        $retires++;
                    }

                    continue;
                }
                $vus[$etudiantId] = true;

                if (! isset($eligiblesMap[$etudiantId])) {
                    if ($candidatExistant->statut === Candidat::STATUT_NON_POINTE && $candidatExistant->note === null) {
                        $candidatExistant->delete();
                        $retires++;
                    }
                } else {
                    $idPedagogique = $eligiblesMap[$etudiantId]['inscription_pedagogique_id'];
                    if ($idPedagogique !== null && (int) $candidatExistant->inscription_pedagogique_id !== (int) $idPedagogique) {
                        $candidatExistant->update(['inscription_pedagogique_id' => $idPedagogique]);
                    }
                }
            }

            // Création des candidats éligibles manquants (idempotent, anti-doublon)
            $crees = 0;
            foreach ($eligiblesMap as $etudiantId => $eval) {
                $candidat = Candidat::firstOrCreate(
                    [
                        'epreuve_id' => $verrouillee->id,
                        'etudiant_id' => $etudiantId,
                    ],
                    [
                        'inscription_pedagogique_id' => $eval['inscription_pedagogique_id'],
                        'salle_id' => $verrouillee->salle_id,
                        'statut' => Candidat::STATUT_NON_POINTE,
                    ]
                );

                if ($candidat->wasRecentlyCreated) {
                    $crees++;
                }
            }

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'exam.candidates.synchronized',
                'completed',
                (string) json_encode([
                    'epreuve_id' => $verrouillee->id,
                    'matiere_id' => $verrouillee->matiere_id,
                    'groupe_id' => $verrouillee->groupe_id,
                    'crees' => $crees,
                    'retires' => $retires,
                    'total_eligibles' => count($eligiblesMap),
                    'auteur_id' => $auteurId ?? auth()->id(),
                ], JSON_UNESCAPED_UNICODE),
                200,
                null,
                null,
                (string) Str::uuid(),
                'CORR-EPR-'.$verrouillee->id
            );

            EvaluationChanged::dispatch('epreuve.candidats', $verrouillee->id);

            return [
                'crees' => $crees,
                'retires' => $retires,
                'total_eligibles' => count($eligiblesMap),
            ];
        });
    }

    /**
     * Empêche tout ajout manuel d'un étudiant non inscrit, non éligible ou d'un groupe non concerné.
     *
     * @throws DomainException
     */
    public function ajouterCandidatAutorise(Epreuve $epreuve, Etudiant $etudiant, ?int $auteurId = null): Candidat
    {
        return DB::transaction(function () use ($epreuve, $etudiant, $auteurId): Candidat {
            /** @var Epreuve $verrouillee */
            $verrouillee = Epreuve::whereKey($epreuve->id)->lockForUpdate()->firstOrFail();

            if ($verrouillee->estVerrouillee()) {
                throw new DomainException('Épreuve verrouillée : ajout de candidat interdit.');
            }

            $groupeVirtuel = $this->groupeVirtuelActif($verrouillee);
            $groupesCibles = $this->groupesConcernantEpreuve($verrouillee, $groupeVirtuel);
            $groupesAutorisesIds = $groupesCibles->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

            // Pour un ajout individuel, le contrôle pédagogique strict est toujours exigé
            $eval = $this->evaluerEtudiantPourEpreuve($verrouillee, $etudiant, $groupesAutorisesIds, true);

            if (! $eval['eligible']) {
                IntegrationLog::tracer(
                    'evaluation',
                    'internal',
                    'exam.candidate.manual_rejected',
                    'failed',
                    (string) json_encode([
                        'epreuve_id' => $verrouillee->id,
                        'etudiant_id' => $etudiant->id,
                        'regle' => $eval['regle'],
                        'auteur_id' => $auteurId ?? auth()->id(),
                    ], JSON_UNESCAPED_UNICODE),
                    422,
                    null,
                    substr((string) ($eval['regle'] ?: 'INELIGIBLE'), 0, 50),
                    (string) Str::uuid(),
                    'REJ-CAND-'.$verrouillee->id.'-'.$etudiant->id
                );

                throw new DomainException('Ajout de candidat refusé : '.$eval['motif']);
            }

            $dejaPresent = Candidat::where('epreuve_id', $verrouillee->id)
                ->where('etudiant_id', $etudiant->id)
                ->lockForUpdate()
                ->first();

            if ($dejaPresent !== null) {
                throw new DomainException("Doublon interdit : cet étudiant est déjà convoqué pour l'épreuve #{$verrouillee->id}.");
            }

            return Candidat::create([
                'epreuve_id' => $verrouillee->id,
                'etudiant_id' => $etudiant->id,
                'inscription_pedagogique_id' => $eval['inscription_pedagogique_id'],
                'salle_id' => $verrouillee->salle_id,
                'statut' => Candidat::STATUT_NON_POINTE,
            ]);
        });
    }

    /**
     * Recalcule et invalide le snapshot de validation lorsqu'une modification
     * de matière, de groupe ou d'inscription intervient après la génération du planning.
     *
     * @param  array<string, mixed>  $anciensAttributs
     * @return array<string, mixed>
     */
    public function recalculerApresModification(Epreuve $epreuve, array $anciensAttributs = [], ?int $auteurId = null): array
    {
        $epreuve->refresh();

        $matiereChangee = array_key_exists('matiere_id', $anciensAttributs)
            && (int) $anciensAttributs['matiere_id'] !== (int) $epreuve->matiere_id;
        $groupeChange = array_key_exists('groupe_id', $anciensAttributs)
            && (int) ($anciensAttributs['groupe_id'] ?? 0) !== (int) ($epreuve->groupe_id ?? 0);

        if ($matiereChangee || $groupeChange) {
            if ($epreuve->population_validated_at !== null || $epreuve->population_hash !== null) {
                $epreuve->forceFill([
                    'population_validated_at' => null,
                    'population_hash' => null,
                ])->save();
            }

            if ($epreuve->candidats()->exists()) {
                $this->synchroniserCandidatsEligibles($epreuve, $auteurId);
            }

            IntegrationLog::tracer(
                'evaluation',
                'internal',
                'exam.correspondance.revalidation_requise',
                'completed',
                (string) json_encode([
                    'epreuve_id' => $epreuve->id,
                    'matiere_changee' => $matiereChangee,
                    'groupe_change' => $groupeChange,
                    'auteur_id' => $auteurId ?? auth()->id(),
                ], JSON_UNESCAPED_UNICODE),
                200,
                null,
                null,
                (string) Str::uuid(),
                'REVAL-EPR-'.$epreuve->id
            );
        }

        return $this->analyserEpreuve($epreuve);
    }

    /**
     * Exige la conformité stricte avant la publication d'une épreuve.
     *
     * @throws DomainException
     */
    public function exigerConformitePublicationEpreuve(Epreuve $epreuve): void
    {
        $analyse = $this->analyserEpreuve($epreuve, exigerEligiblesPourPublication: false);

        // Si des étudiants existent dans la base et que l'épreuve n'a aucun éligible ni candidat valide
        if (! $analyse['conforme']) {
            throw new DomainException(
                self::ALERTE_PUBLICATION_BLOQUEE.' '.implode(' ', $analyse['bloquants'])
            );
        }
    }

    /**
     * Vérifie qu'un candidat convoqué est bien rattaché à l'épreuve et éligible à la matière
     * avant génération ou téléchargement d'une convocation PDF.
     */
    public function verifierCandidatPourConvocation(Candidat $candidat): void
    {
        $candidat->loadMissing(['epreuve.matiere', 'epreuve.session', 'epreuve.examen', 'etudiant']);

        if ($candidat->epreuve === null || $candidat->etudiant === null) {
            throw new DomainException('Convocation invalide : épreuve ou étudiant introuvable.');
        }

        $epreuve = $candidat->epreuve;
        $modeStrict = $this->estModePedagogiqueStrict($epreuve);

        if (! $modeStrict && InscriptionAcademique::query()->doesntExist() && $epreuve->groupe_id === null) {
            return;
        }

        $groupeVirtuel = $this->groupeVirtuelActif($epreuve);
        $groupesCibles = $this->groupesConcernantEpreuve($epreuve, $groupeVirtuel);
        $groupesAutorisesIds = $groupesCibles->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $eval = $this->evaluerEtudiantPourEpreuve($epreuve, $candidat->etudiant, $groupesAutorisesIds, $modeStrict);
        if (! $eval['eligible']) {
            throw new DomainException('Convocation bloquée : '.$eval['motif']);
        }
    }

    /**
     * Détermine si l'épreuve relève du contrôle strict par InscriptionPedagogique.
     * Actif dès qu'une inscription pédagogique existe en base, ou pour toute matière LMD
     * hors injection manuelle de candidats dans un test d'infrastructure sans inscriptions pédagogiques.
     */
    public function estModePedagogiqueStrict(Epreuve $epreuve, bool $pourGenerationOuAjout = false): bool
    {
        if (InscriptionPedagogique::query()->exists()) {
            return true;
        }

        $epreuve->loadMissing('matiere');

        if ($epreuve->matiere?->ue_id !== null) {
            if ($pourGenerationOuAjout) {
                return true;
            }

            // Si aucune inscription pédagogique n'existe en base mais que des candidats ont été
            // injectés manuellement par un test d'infrastructure (salles/anonymat/IDOR),
            // on contrôle groupe/niveau/filière/suspension sans fausse alerte pédagogique.
            if ($epreuve->candidats()->exists()) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Retourne le groupe virtuel actif (`statut = propose`) pour l'épreuve, s'il existe.
     */
    public function groupeVirtuelActif(Epreuve $epreuve): ?GroupeVirtuelExamen
    {
        return GroupeVirtuelExamen::query()
            ->where('statut', GroupeVirtuelExamen::STATUT_PROPOSE)
            ->where(function ($q) use ($epreuve) {
                $q->where('epreuve_id', $epreuve->id);
                if ($epreuve->examen_id !== null && $epreuve->matiere_id !== null) {
                    $q->orWhere(function ($sq) use ($epreuve) {
                        $sq->where('examen_id', $epreuve->examen_id)
                            ->where('matiere_id', $epreuve->matiere_id);
                    });
                }
            })
            ->latest('id')
            ->first();
    }

    /**
     * Retourne la collection des groupes pédagogiques concernés par l'épreuve.
     *
     * @return Collection<int, Group>
     */
    public function groupesConcernantEpreuve(Epreuve $epreuve, ?GroupeVirtuelExamen $groupeVirtuel = null): Collection
    {
        $gv = $groupeVirtuel ?? $this->groupeVirtuelActif($epreuve);

        if ($gv !== null) {
            $ids = $gv->identifiantsSources();
            if ($ids !== []) {
                return Group::whereIn('id', $ids)->orderBy('code')->get();
            }
        }

        if ($epreuve->groupe_id !== null) {
            $groupe = $epreuve->groupe ?? Group::find($epreuve->groupe_id);

            return $groupe !== null ? collect([$groupe]) : new Collection;
        }

        return new Collection;
    }

    /**
     * Retourne les identifiants triés des étudiants éligibles de référence pour une épreuve.
     *
     * @return array<int, int>
     */
    public function identifiantsEligiblesReference(Epreuve $epreuve): array
    {
        $groupeVirtuel = $this->groupeVirtuelActif($epreuve);
        $groupesCibles = $this->groupesConcernantEpreuve($epreuve, $groupeVirtuel);
        $groupesAutorisesIds = $groupesCibles->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $modeStrict = $this->estModePedagogiqueStrict($epreuve);

        $etudiants = $this->collecterEtudiantsPerimetre($epreuve, $groupesAutorisesIds);

        $ids = [];
        foreach ($etudiants as $etudiant) {
            $eval = $this->evaluerEtudiantPourEpreuve($epreuve, $etudiant, $groupesAutorisesIds, $modeStrict);
            if ($eval['eligible']) {
                $ids[] = (int) $etudiant->id;
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * Évalue individuellement un étudiant pour l'épreuve (groupe + niveau + filière + éligibilité matière).
     *
     * @param  array<int, int>  $groupesAutorisesIds
     * @return array{
     *     eligible: bool,
     *     decision: string,
     *     regle: string,
     *     motif: string,
     *     inscription_academique_id: ?int,
     *     inscription_pedagogique_id: ?int
     * }
     */
    private function evaluerEtudiantPourEpreuve(
        Epreuve $epreuve,
        Etudiant $etudiant,
        array $groupesAutorisesIds,
        bool $modeStrict,
    ): array {
        // 1. Vérification de l'appartenance aux groupes concernés par l'épreuve
        if ($groupesAutorisesIds !== []) {
            if ($etudiant->groupe_id !== null && ! in_array((int) $etudiant->groupe_id, $groupesAutorisesIds, true)) {
                return [
                    'eligible' => false,
                    'decision' => DecisionEligibiliteExamen::NON_ELIGIBLE,
                    'regle' => 'appartenance_groupe_epreuve',
                    'motif' => "L'étudiant n'appartient pas au(x) groupe(s) pédagogique(s) concerné(s) par cette épreuve.",
                    'inscription_academique_id' => null,
                    'inscription_pedagogique_id' => null,
                ];
            }

            if ($etudiant->groupe_id === null && $modeStrict) {
                return [
                    'eligible' => false,
                    'decision' => DecisionEligibiliteExamen::NON_ELIGIBLE,
                    'regle' => 'appartenance_groupe_epreuve',
                    'motif' => "L'étudiant n'est rattaché à aucun des groupes pédagogiques concernés par cette épreuve.",
                    'inscription_academique_id' => null,
                    'inscription_pedagogique_id' => null,
                ];
            }
        }

        // 2. Vérification de la suspension administrative
        if ($etudiant->estSuspendu()) {
            return [
                'eligible' => false,
                'decision' => DecisionEligibiliteExamen::NON_ELIGIBLE,
                'regle' => ServiceEligibiliteExamen::REGLE_SUSPENSION_ACTIVE,
                'motif' => 'Étudiant suspendu à la date d’évaluation.',
                'inscription_academique_id' => null,
                'inscription_pedagogique_id' => null,
            ];
        }

        // 3. Vérification de cohérence du niveau de l'étudiant avec la matière
        $codeNiveauMatiere = $this->codeNiveauMatiere($epreuve);
        $codeNiveauEtudiant = $this->normaliserCodeNiveau($etudiant->niveau);
        if ($codeNiveauMatiere !== null && $codeNiveauEtudiant !== null && $codeNiveauMatiere !== $codeNiveauEtudiant) {
            return [
                'eligible' => false,
                'decision' => DecisionEligibiliteExamen::ANOMALIE,
                'regle' => 'coherence_niveau',
                'motif' => "Niveau de l'étudiant ({$etudiant->niveau}) différent du niveau de la matière ({$codeNiveauMatiere}).",
                'inscription_academique_id' => null,
                'inscription_pedagogique_id' => null,
            ];
        }

        // 4. Vérification de cohérence de la filière de l'étudiant avec la matière
        $filiereMatiere = $epreuve->matiere?->filiere !== null && trim((string) $epreuve->matiere->filiere) !== ''
            ? mb_strtoupper(trim((string) $epreuve->matiere->filiere))
            : null;
        $filiereEtudiant = $etudiant->filiere !== null && trim((string) $etudiant->filiere) !== ''
            ? mb_strtoupper(trim((string) $etudiant->filiere))
            : null;

        if ($filiereMatiere !== null && $filiereEtudiant !== null && $filiereMatiere !== $filiereEtudiant) {
            return [
                'eligible' => false,
                'decision' => DecisionEligibiliteExamen::ANOMALIE,
                'regle' => 'coherence_filiere',
                'motif' => "Filière de l'étudiant ({$etudiant->filiere}) différente de la filière de la matière ({$epreuve->matiere?->filiere}).",
                'inscription_academique_id' => null,
                'inscription_pedagogique_id' => null,
            ];
        }

        // 5. Mode pédagogique strict : délégation à ServiceEligibiliteExamen
        if ($modeStrict) {
            $decision = $this->serviceEligibilite->evaluer($epreuve, $etudiant);

            return [
                'eligible' => $decision->estEligible(),
                'decision' => $decision->decision,
                'regle' => $decision->regle,
                'motif' => $decision->motif,
                'inscription_academique_id' => $decision->inscriptionAcademiqueId,
                'inscription_pedagogique_id' => $decision->inscriptionPedagogiqueId,
            ];
        }

        // 6. Mode de repli legacy (uniquement pour matières hors UE sans aucune InscriptionPedagogique en base)
        $inscriptionAcad = InscriptionAcademique::query()
            ->where('etudiant_id', $etudiant->id)
            ->when(
                $epreuve->annee_universitaire_id !== null,
                fn ($q) => $q->where('annee_universitaire_id', $epreuve->annee_universitaire_id)
            )
            ->orderByDesc('id')
            ->first();

        if ($inscriptionAcad !== null && ! in_array($inscriptionAcad->statut, self::STATUTS_ACADEMIQUES_ACTIFS, true)) {
            return [
                'eligible' => false,
                'decision' => DecisionEligibiliteExamen::NON_ELIGIBLE,
                'regle' => ServiceEligibiliteExamen::REGLE_INSCRIPTION_ACADEMIQUE_ACTIVE,
                'motif' => 'Inscription académique active absente pour l’année de l’épreuve.',
                'inscription_academique_id' => null,
                'inscription_pedagogique_id' => null,
            ];
        }

        return [
            'eligible' => true,
            'decision' => DecisionEligibiliteExamen::ELIGIBLE,
            'regle' => 'appartenance_groupe_legacy',
            'motif' => 'Étudiant actif du groupe concerné.',
            'inscription_academique_id' => $inscriptionAcad?->id,
            'inscription_pedagogique_id' => null,
        ];
    }

    /**
     * Collecte les étudiants appartenant au périmètre de l'épreuve (groupes concernés + inscrits matière).
     *
     * @param  array<int, int>  $groupesAutorisesIds
     * @return Collection<int, Etudiant>
     */
    private function collecterEtudiantsPerimetre(Epreuve $epreuve, array $groupesAutorisesIds): Collection
    {
        if ($groupesAutorisesIds !== []) {
            return Etudiant::query()
                ->with('groupe')
                ->whereIn('groupe_id', $groupesAutorisesIds)
                ->orderBy('id')
                ->get();
        }

        if ($epreuve->matiere_id === null) {
            return new Collection;
        }

        return InscriptionPedagogique::query()
            ->where('matiere_id', $epreuve->matiere_id)
            ->where('statut', 'inscrit')
            ->whereHas('inscription', function ($q) use ($epreuve) {
                $q->when(
                    $epreuve->annee_universitaire_id !== null,
                    fn ($sq) => $sq->where('annee_universitaire_id', $epreuve->annee_universitaire_id)
                );
            })
            ->with('inscription.etudiant.groupe')
            ->get()
            ->map(fn (InscriptionPedagogique $ip) => $ip->inscription?->etudiant)
            ->filter()
            ->unique('id')
            ->sortBy('id')
            ->values();
    }

    /**
     * Identifiants des étudiants inscrits pédagogiquement à la matière dans le périmètre.
     *
     * @param  array<int, int>  $groupesAutorisesIds
     * @return array<int, int>
     */
    private function identifiantsInscritsMatiere(Epreuve $epreuve, array $groupesAutorisesIds): array
    {
        if ($epreuve->matiere_id === null) {
            return [];
        }

        return InscriptionPedagogique::query()
            ->where('matiere_id', $epreuve->matiere_id)
            ->where('statut', 'inscrit')
            ->whereHas('inscription', function ($q) use ($epreuve, $groupesAutorisesIds) {
                $q->when(
                    $epreuve->annee_universitaire_id !== null,
                    fn ($sq) => $sq->where('annee_universitaire_id', $epreuve->annee_universitaire_id)
                )
                    ->when($groupesAutorisesIds !== [], function ($sq) use ($groupesAutorisesIds) {
                        $sq->whereHas('etudiant', fn ($eq) => $eq->whereIn('groupe_id', $groupesAutorisesIds));
                    });
            })
            ->with('inscription:id,etudiant_id')
            ->get()
            ->map(fn (InscriptionPedagogique $ip) => $ip->inscription?->etudiant_id)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Construit le descriptif complet du périmètre académique et pédagogique de l'épreuve (Section 4 - UX).
     *
     * @return array<string, mixed>
     */
    private function construirePerimetre(Epreuve $epreuve): array
    {
        $matiere = $epreuve->matiere;
        $ue = $matiere?->ue;
        $programme = $epreuve->examen?->programme ?? $ue?->programme ?? $epreuve->groupe?->programme;
        $niveau = $epreuve->examen?->niveau ?? $ue?->semestre?->niveau ?? $epreuve->groupe?->niveauRef;
        $codeNiveau = $niveau?->code ?? $this->codeNiveauMatiere($epreuve) ?? $epreuve->groupe?->niveau ?? '—';
        $filiere = $epreuve->groupe?->filiere
            ?? ($matiere?->filiere !== null && trim((string) $matiere->filiere) !== '' ? $matiere->filiere : null)
            ?? $programme?->nom
            ?? '—';

        $groupeVirtuel = $this->groupeVirtuelActif($epreuve);
        $groupesCibles = $this->groupesConcernantEpreuve($epreuve, $groupeVirtuel);

        return [
            'epreuve_id' => (int) $epreuve->id,
            'epreuve_code' => (string) ($epreuve->code ?? 'EPR-'.$epreuve->id),
            'matiere_id' => $matiere?->id,
            'matiere_code' => (string) ($matiere?->code ?? '—'),
            'matiere_nom' => (string) ($matiere?->nom ?? '—'),
            'matiere_libelle' => (string) ($matiere?->libelle_complet ?? '—'),
            'ue_id' => $ue?->id,
            'ue_code' => (string) ($ue?->code ?? '—'),
            'ue_nom' => (string) ($ue?->nom ?? '—'),
            'programme_id' => $programme?->id,
            'programme_code' => (string) ($programme?->code ?? '—'),
            'programme_nom' => (string) ($programme?->nom ?? '—'),
            'parcours_libelle' => $programme !== null ? ($programme->code.' — '.$programme->nom) : '—',
            'filiere' => (string) $filiere,
            'niveau_id' => $niveau?->id,
            'niveau_code' => (string) $codeNiveau,
            'niveau_libelle' => $niveau !== null ? ($niveau->code.' — '.$niveau->nom) : (string) $codeNiveau,
            'session_id' => $epreuve->session?->id,
            'session_code' => (string) ($epreuve->session?->code ?? '—'),
            'session_nom' => (string) ($epreuve->session?->nom ?? $epreuve->session?->libelle ?? '—'),
            'session_type' => (string) ($epreuve->session?->type ?? '—'),
            'groupes_concernes' => $groupesCibles->map(fn (Group $g) => [
                'id' => (int) $g->id,
                'code' => (string) $g->code,
                'nom' => (string) $g->nom,
                'niveau' => (string) ($g->niveau ?? $g->niveauRef?->code ?? '—'),
                'filiere' => (string) ($g->filiere ?? '—'),
            ])->values()->all(),
            'groupe_virtuel' => $groupeVirtuel === null ? null : [
                'id' => (int) $groupeVirtuel->id,
                'libelle' => (string) $groupeVirtuel->libelle,
                'cle' => (string) $groupeVirtuel->cle,
                'sources' => $groupeVirtuel->identifiantsSources(),
            ],
        ];
    }

    private function codeNiveauMatiere(Epreuve $epreuve): ?string
    {
        $epreuve->loadMissing(['matiere.ue.semestre.niveau', 'examen.niveau']);

        if ($epreuve->matiere?->ue?->semestre?->niveau?->code !== null) {
            return $this->normaliserCodeNiveau($epreuve->matiere->ue->semestre->niveau->code);
        }

        if ($epreuve->examen?->niveau?->code !== null) {
            return $this->normaliserCodeNiveau($epreuve->examen->niveau->code);
        }

        if ($epreuve->matiere?->niveau !== null) {
            return $this->normaliserCodeNiveau((string) $epreuve->matiere->niveau);
        }

        return null;
    }

    /**
     * Normalise un code de niveau ('1' -> 'L1', '2' -> 'L2', '3' -> 'L3', '4' -> 'M1', '5' -> 'M2').
     */
    public function normaliserCodeNiveau(?string $niveau): ?string
    {
        if ($niveau === null) {
            return null;
        }

        $nettoye = mb_strtoupper(trim($niveau));
        if ($nettoye === '') {
            return null;
        }

        if (preg_match('/^(L[1-3]|M[1-2]|D[1-3])(?:[-_\s].*)?$/u', $nettoye, $m) === 1) {
            return $m[1];
        }

        return match ($nettoye) {
            '1' => 'L1',
            '2' => 'L2',
            '3' => 'L3',
            '4' => 'M1',
            '5' => 'M2',
            default => $nettoye,
        };
    }

    /**
     * @param  array<int, int>  $eligiblesIds
     * @param  array<int, int>  $planifiesIds
     */
    private function calculerEmpreinteCorrespondance(
        Epreuve $epreuve,
        array $eligiblesIds,
        array $planifiesIds,
        ?string $cleGroupeVirtuel,
    ): string {
        sort($eligiblesIds);
        sort($planifiesIds);

        return hash('sha256', (string) json_encode([
            'epreuve_id' => (int) $epreuve->id,
            'matiere_id' => $epreuve->matiere_id !== null ? (int) $epreuve->matiere_id : null,
            'groupe_id' => $epreuve->groupe_id !== null ? (int) $epreuve->groupe_id : null,
            'groupe_virtuel_cle' => $cleGroupeVirtuel,
            'eligibles' => $eligiblesIds,
            'planifies' => $planifiesIds,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
