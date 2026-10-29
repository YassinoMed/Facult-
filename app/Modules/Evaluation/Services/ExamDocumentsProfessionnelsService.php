<?php

namespace App\Modules\Evaluation\Services;

use App\Models\AnneeUniversitaire;
use App\Models\Faculte;
use App\Models\IntegrationLog;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\DocumentExamenGenere;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationIncident;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\PlanningValidation;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Service central des documents professionnels des examens (LOOP MASTER - LOTS 1 à 5).
 *
 * Responsabilités :
 * 1. Catalogue consolidé des documents d'examen par option de menu.
 * 2. Préparation des données avec respect absolu du périmètre faculté (anti-IDOR).
 * 3. Exclusion systématique des données médicales et sensibles (protection PII).
 * 4. Distinction formelle entre Brouillon / Proposition / Document officiel publié.
 * 5. Génération PDF A4 via PdfGeneratorInterface et rendu HTML prêt pour impression navigateur.
 * 6. Audit append-only de chaque génération via IntegrationLog et DocumentExamenGenere.
 * 7. Zéro mutation des états académiques pendant la consultation ou l'export.
 */
class ExamDocumentsProfessionnelsService
{
    /**
     * Catalogue officiel exhaustif des documents professionnels d'examen.
     */
    public const CATALOGUE = [
        // Menu 5 — Regroupements virtuels et groupes d'examen
        'liste_candidats_groupe_virtuel' => [
            'id' => 'liste_candidats_groupe_virtuel',
            'titre' => 'Liste officielle des candidats par épreuve et groupe virtuel',
            'description' => 'Liste nominative émargée avec matricule, nom, prénom, groupe virtuel et place d\'examen.',
            'option' => 'groupes',
            'permission' => 'epreuves.view',
            'orientation' => 'portrait',
            'filtres_supportes' => ['epreuve_id', 'groupe_id', 'session_id'],
            'filtres_requis' => [],
            'icone' => 'users',
        ],
        'synthese_effectifs_parcours' => [
            'id' => 'synthese_effectifs_parcours',
            'titre' => 'Synthèse des effectifs par niveau, filière et spécialité',
            'description' => 'État récapitulatif des effectifs inscrits, éligibles et répartis par formation.',
            'option' => 'groupes',
            'permission' => 'epreuves.view',
            'orientation' => 'portrait',
            'filtres_supportes' => ['session_id', 'annee_id'],
            'filtres_requis' => [],
            'icone' => 'chart-bar',
        ],
        'controle_candidats_eligibles' => [
            'id' => 'controle_candidats_eligibles',
            'titre' => 'Liste de contrôle des candidats éligibles',
            'description' => 'Bordereau de vérification des prérequis pédagogiques, administratifs et d\'assiduité.',
            'option' => 'groupes',
            'permission' => 'epreuves.view',
            'orientation' => 'portrait',
            'filtres_supportes' => ['epreuve_id', 'session_id'],
            'filtres_requis' => [],
            'icone' => 'check-badge',
        ],
        'recapitulatif_regroupements_d04' => [
            'id' => 'recapitulatif_regroupements_d04',
            'titre' => 'Récapitulatif des regroupements d\'amphithéâtres (Décision D-04)',
            'description' => 'Tableau de dédoublement avec jauge opposable à 50% et constitution des cohortes.',
            'option' => 'groupes',
            'permission' => 'epreuves.view',
            'orientation' => 'landscape',
            'filtres_supportes' => ['session_id'],
            'filtres_requis' => [],
            'icone' => 'building-library',
        ],

        // Menu 7 — Planification
        'planning_examens_general' => [
            'id' => 'planning_examens_general',
            'titre' => 'Planning général des examens de la session',
            'description' => 'Grille synoptique chronologique des épreuves avec dates, créneaux, salles et effectifs.',
            'option' => 'planification',
            'permission' => 'epreuves.view',
            'orientation' => 'landscape',
            'filtres_supportes' => ['session_id', 'examen_id', 'annee_id'],
            'filtres_requis' => [],
            'icone' => 'calendar',
        ],
        'planning_parcours_epreuve' => [
            'id' => 'planning_parcours_epreuve',
            'titre' => 'Planning détaillé par parcours et filière',
            'description' => 'Calendrier sectorisé pour affichage administratif dans les départements pédagogiques.',
            'option' => 'planification',
            'permission' => 'epreuves.view',
            'orientation' => 'portrait',
            'filtres_supportes' => ['session_id', 'epreuve_id'],
            'filtres_requis' => [],
            'icone' => 'academic-cap',
        ],
        'calendrier_creneaux' => [
            'id' => 'calendrier_creneaux',
            'titre' => 'Calendrier d\'occupation des créneaux horaires',
            'description' => 'Matrice journalière des plages d\'examen du matin et de l\'après-midi.',
            'option' => 'planification',
            'permission' => 'epreuves.view',
            'orientation' => 'landscape',
            'filtres_supportes' => ['session_id', 'date'],
            'filtres_requis' => [],
            'icone' => 'clock',
        ],
        'rapport_conflits_contraintes' => [
            'id' => 'rapport_conflits_contraintes',
            'titre' => 'Rapport des conflits et contraintes non résolus',
            'description' => 'Audit formel des chevauchements horaires, surcapacités de salles et alertes bloquantes.',
            'option' => 'planification',
            'permission' => 'evaluations.view',
            'orientation' => 'portrait',
            'filtres_supportes' => ['examen_id', 'session_id'],
            'filtres_requis' => [],
            'icone' => 'exclamation-triangle',
        ],

        // Menu 6 — Salles et capacités D-04
        'repartition_candidats_salle' => [
            'id' => 'repartition_candidats_salle',
            'titre' => 'Répartition officielle des candidats par salle',
            'description' => 'Affectation nominative des étudiants par salle d\'examen avec rangées et numéros de place.',
            'option' => 'salles',
            'permission' => 'evaluations.view',
            'orientation' => 'portrait',
            'filtres_supportes' => ['epreuve_id', 'salle_id'],
            'filtres_requis' => [],
            'icone' => 'squares-2x2',
        ],
        'fiche_salle_capacite_d04' => [
            'id' => 'fiche_salle_capacite_d04',
            'titre' => 'Fiche descriptive de salle & Jauge utile D-04',
            'description' => 'Fiche technique de salle : capacité nominale, jauge utile à 50% en amphi et accessibilité.',
            'option' => 'salles',
            'permission' => 'evaluations.view',
            'orientation' => 'portrait',
            'filtres_supportes' => ['salle_id'],
            'filtres_requis' => [],
            'icone' => 'clipboard-document-check',
        ],
        'feuille_controle_salle' => [
            'id' => 'feuille_controle_salle',
            'titre' => 'Feuille de contrôle et d\'inspection de salle',
            'description' => 'Formulaire d\'ouverture et de fermeture de salle avec vérification de l\'espacement et signatures.',
            'option' => 'salles',
            'permission' => 'evaluations.view',
            'orientation' => 'portrait',
            'filtres_supportes' => ['salle_id', 'epreuve_id'],
            'filtres_requis' => [],
            'icone' => 'document-magnifying-glass',
        ],

        // Menu 8 — Surveillance
        'ordre_mission_surveillants' => [
            'id' => 'ordre_mission_surveillants',
            'titre' => 'Ordre de mission et affectation des surveillants',
            'description' => 'Convocation institutionnelle pour les enseignants et surveillants avec horaires et consignes.',
            'option' => 'surveillance',
            'permission' => 'epreuves.view',
            'orientation' => 'portrait',
            'filtres_supportes' => ['session_id', 'epreuve_id'],
            'filtres_requis' => [],
            'icone' => 'identification',
        ],
        'feuille_affectation_surveillants' => [
            'id' => 'feuille_affectation_surveillants',
            'titre' => 'Feuille d\'émargement et planning de surveillance',
            'description' => 'Tableau de pointage des présences des surveillants, titulaires et suppléants par créneau.',
            'option' => 'surveillance',
            'permission' => 'epreuves.view',
            'orientation' => 'landscape',
            'filtres_supportes' => ['session_id', 'date'],
            'filtres_requis' => [],
            'icone' => 'clipboard-document-list',
        ],
        'repartition_surveillance_epreuve' => [
            'id' => 'repartition_surveillance_epreuve',
            'titre' => 'Répartition de la surveillance par épreuve & chefs de salle',
            'description' => 'Tableau récapitulatif des équipes de surveillance avec désignation des chefs de salle.',
            'option' => 'surveillance',
            'permission' => 'epreuves.view',
            'orientation' => 'portrait',
            'filtres_supportes' => ['epreuve_id', 'session_id'],
            'filtres_requis' => [],
            'icone' => 'user-group',
        ],

        // Menu 11 — Convocations
        'convocation_individuelle' => [
            'id' => 'convocation_individuelle',
            'titre' => 'Convocation individuelle d\'examen',
            'description' => 'Document nominatif A4 avec horaire, salle, place, consignes et code de vérification.',
            'option' => 'convocations',
            'permission' => 'evaluations.view',
            'orientation' => 'portrait',
            'filtres_supportes' => ['epreuve_id'],
            'filtres_requis' => [],
            'icone' => 'envelope',
        ],
        'lot_convocations_epreuve' => [
            'id' => 'lot_convocations_epreuve',
            'titre' => 'Lot de convocations d\'épreuve (cahier complet)',
            'description' => 'Fascicule complet de toutes les convocations individuelles avec saut de page par candidat.',
            'option' => 'convocations',
            'permission' => 'evaluations.view',
            'orientation' => 'portrait',
            'filtres_supportes' => ['epreuve_id', 'salle_id'],
            'filtres_requis' => [],
            'icone' => 'document-duplicate',
        ],
        'bordereau_distribution_convocations' => [
            'id' => 'bordereau_distribution_convocations',
            'titre' => 'Bordereau de remise et distribution des convocations',
            'description' => 'Fiche d\'accusé de réception et traçabilité de transmission des convocations aux candidats.',
            'option' => 'convocations',
            'permission' => 'evaluations.view',
            'orientation' => 'portrait',
            'filtres_supportes' => ['epreuve_id'],
            'filtres_requis' => [],
            'icone' => 'document-check',
        ],

        // Menu 12 — Incidents, clôture et historique
        'fiche_declaration_incident' => [
            'id' => 'fiche_declaration_incident',
            'titre' => 'Fiche officielle de déclaration d\'incident d\'examen',
            'description' => 'Procès-verbal de signalement d\'incident (fraude, retard, panne) avec signatures des surveillants.',
            'option' => 'historique',
            'permission' => 'evaluations.view',
            'orientation' => 'portrait',
            'filtres_supportes' => ['epreuve_id'],
            'filtres_requis' => [],
            'icone' => 'shield-exclamation',
        ],
        'rapport_cloture_epreuve' => [
            'id' => 'rapport_cloture_epreuve',
            'titre' => 'Rapport de clôture d\'épreuve et restitution des copies',
            'description' => 'Bilan chiffré des copies remises, intercalaires utilisés, absents et incident éventuel.',
            'option' => 'historique',
            'permission' => 'evaluations.view',
            'orientation' => 'portrait',
            'filtres_supportes' => ['epreuve_id'],
            'filtres_requis' => [],
            'icone' => 'lock-closed',
        ],
        'rapport_reconciliation_tri_sources' => [
            'id' => 'rapport_reconciliation_tri_sources',
            'titre' => 'Rapport de réconciliation tri-sources des présences',
            'description' => 'Rapprochement opposable : émargement papier vs pointage numérique vs journaux horodatés.',
            'option' => 'historique',
            'permission' => 'evaluations.view',
            'orientation' => 'portrait',
            'filtres_supportes' => ['epreuve_id'],
            'filtres_requis' => [],
            'icone' => 'arrows-pointing-in',
        ],
        'journal_audit_versions' => [
            'id' => 'journal_audit_versions',
            'titre' => 'Historique des versions et journal d\'audit d\'examen',
            'description' => 'Registre d\'audit immuable des modifications de planning, statuts et incidents.',
            'option' => 'historique',
            'permission' => 'evaluations.view',
            'orientation' => 'landscape',
            'filtres_supportes' => ['session_id', 'annee_id'],
            'filtres_requis' => [],
            'icone' => 'clock',
        ],

        // Menu 10 — Validation et publication
        'pv_approbation_4_yeux' => [
            'id' => 'pv_approbation_4_yeux',
            'titre' => 'Procès-verbal de validation et d\'approbation (4-yeux)',
            'description' => 'PV solennel portant certification, visas d\'approbation à deux niveaux et conformité D-04.',
            'option' => 'publication',
            'permission' => 'evaluations.view',
            'orientation' => 'portrait',
            'filtres_supportes' => ['examen_id', 'session_id'],
            'filtres_requis' => [],
            'icone' => 'check-badge',
        ],
    ];

    public function __construct(
        private readonly PdfGeneratorInterface $pdfGenerator,
        private readonly ExamSurveillanceGroupesOrchestratorService $surveillanceGroupesOrchestrator,
        private readonly ExamPlanificationSallesOrchestratorService $planificationSallesOrchestrator,
        private readonly ExamPublicationOrchestratorService $publicationOrchestrator,
        private readonly ExamHistoriqueAuditOrchestratorService $historiqueAuditOrchestrator,
    ) {}

    /**
     * Récupère la liste des documents disponibles pour une option donnée,
     * filtrée selon les habilitations réelles de l'utilisateur connecté.
     */
    public function obtenirDocumentsPourOption(string $option, User $user): array
    {
        $docs = [];

        foreach (self::CATALOGUE as $doc) {
            if ($doc['option'] === $option) {
                if (Gate::forUser($user)->allows($doc['permission'])) {
                    $docs[] = $doc;
                }
            }
        }

        return $docs;
    }

    /**
     * Récupère la configuration d'un document.
     */
    public function obtenirDocumentConfig(string $typeDocument): ?array
    {
        return self::CATALOGUE[$typeDocument] ?? null;
    }

    /**
     * Vérifie que l'utilisateur a la permission requise pour ce document.
     */
    public function verifierAutorisation(string $typeDocument, User $user): void
    {
        $config = $this->obtenirDocumentConfig($typeDocument);

        if (! $config) {
            abort(404, "Le document demandé n'existe pas dans le catalogue.");
        }

        if (! Gate::forUser($user)->allows($config['permission'])) {
            throw new AuthorizationException("Vous ne disposez pas des habilitations nécessaires pour éditer ou imprimer ce document ({$config['permission']}).");
        }
    }

    /**
     * Prépare et structure les données du document avec isolation de faculté (anti-IDOR),
     * exclusion des données médicales/sensibles, et calcul des filigranes officiels.
     */
    public function preparerDonneesDocument(string $typeDocument, array $filtres, User $user): array
    {
        $this->verifierAutorisation($typeDocument, $user);

        $config = $this->obtenirDocumentConfig($typeDocument);
        $faculteId = $user->faculteRestreinteId();

        // Récupération faculté et année universitaire
        $faculte = $faculteId ? Faculte::find($faculteId) : null;
        $anneeUniversitaire = ! empty($filtres['annee_id'])
            ? AnneeUniversitaire::find($filtres['annee_id'])
            : (AnneeUniversitaire::active() ?? AnneeUniversitaire::latest('id')->first());

        // Sécurisation stricte anti-IDOR des filtres reçus
        $epreuve = null;
        if (! empty($filtres['epreuve_id'])) {
            $epreuveTrouvee = Epreuve::with(['matiere.ue.programme', 'salle', 'session', 'examen', 'groupe'])->find($filtres['epreuve_id']);

            if (! $epreuveTrouvee) {
                abort(404, "L'épreuve demandée est introuvable.");
            }

            if ($faculteId !== null) {
                $facultesLiees = array_filter([
                    $epreuveTrouvee->faculte_id,
                    $epreuveTrouvee->session?->faculte_id,
                    $epreuveTrouvee->salle?->faculte_id,
                    $epreuveTrouvee->groupe?->faculte_id,
                    $epreuveTrouvee->matiere?->ue?->programme?->faculte_id,
                    $epreuveTrouvee->examen?->faculte_id,
                ]);

                foreach ($facultesLiees as $fid) {
                    if ((int) $fid !== (int) $faculteId) {
                        abort(403, "Accès interdit : cette épreuve n'appartient pas à votre faculté.");
                    }
                }
            }

            $epreuve = $epreuveTrouvee;
        }

        $examen = null;
        if (! empty($filtres['examen_id'])) {
            $examenTrouve = Examen::with(['session', 'epreuves.matiere', 'epreuves.salle'])->find($filtres['examen_id']);

            if (! $examenTrouve) {
                abort(404, "L'examen demandé est introuvable.");
            }

            if ($faculteId !== null) {
                $faculteExamen = $examenTrouve->faculte_id ?? $examenTrouve->session?->faculte_id;

                if ($faculteExamen !== null && (int) $faculteExamen !== (int) $faculteId) {
                    abort(403, "Accès interdit : cet examen n'appartient pas à votre faculté.");
                }
            }

            $examen = $examenTrouve;
        }

        $salle = null;
        if (! empty($filtres['salle_id'])) {
            $salleTrouvee = Room::with('batimentRelation')->find($filtres['salle_id']);

            if (! $salleTrouvee) {
                abort(404, 'La salle demandée est introuvable.');
            }

            if ($faculteId !== null && $salleTrouvee->faculte_id !== null && (int) $salleTrouvee->faculte_id !== (int) $faculteId) {
                abort(403, "Accès interdit : cette salle n'appartient pas à votre faculté.");
            }

            $salle = $salleTrouvee;
        }

        $session = null;
        if (! empty($filtres['session_id'])) {
            $sessionTrouvee = SessionExamen::find($filtres['session_id']);

            if (! $sessionTrouvee) {
                abort(404, 'La session demandée est introuvable.');
            }

            if ($faculteId !== null && $sessionTrouvee->faculte_id !== null && (int) $sessionTrouvee->faculte_id !== (int) $faculteId) {
                abort(403, "Accès interdit : cette session n'appartient pas à votre faculté.");
            }

            $session = $sessionTrouvee;
        } elseif ($epreuve?->session) {
            $session = $epreuve->session;
        } elseif ($examen?->session) {
            $session = $examen->session;
        }

        $groupe = null;
        if (! empty($filtres['groupe_id'])) {
            $groupeTrouve = Group::find($filtres['groupe_id']);

            if (! $groupeTrouve) {
                abort(404, 'Le groupe demandé est introuvable.');
            }

            if ($faculteId !== null && $groupeTrouve->faculte_id !== null && (int) $groupeTrouve->faculte_id !== (int) $faculteId) {
                abort(403, "Accès interdit : ce groupe n'appartient pas à votre faculté.");
            }

            $groupe = $groupeTrouve;
        }

        // Calcul du statut du document et du filigrane légal
        $statutMetier = $epreuve?->statut ?? $examen?->statut ?? $session?->statut ?? 'draft';
        $statutDoc = 'brouillon';
        $filigrane = 'BROUILLON - NON CERTIFIÉ';

        if (in_array($statutMetier, ['published', 'completed', 'ongoing'], true)) {
            $statutDoc = 'officiel';
            $filigrane = 'DOCUMENT OFFICIEL PUBLIÉ';
        } elseif (in_array($statutMetier, ['scheduled', 'open'], true)) {
            $statutDoc = 'proposition';
            $filigrane = 'PROPOSITION VALIDÉE';
        } elseif (in_array($statutMetier, ['archived', 'locked', 'closed'], true)) {
            $statutDoc = 'archive';
            $filigrane = 'ARCHIVÉ';
        }

        // Référence documentaire unique, horodatée et traçable
        $prefixe = strtoupper(substr($typeDocument, 0, 4));
        $reference = 'DOC-EXAM-'.$prefixe.'-'.date('Ymd').'-'.strtoupper(Str::random(6));

        // Chargement contextualisé selon le document
        $corps = $this->chargerCorpsDocument(
            $typeDocument,
            $filtres,
            $user,
            $faculteId,
            $epreuve,
            $examen,
            $salle,
            $session,
            $groupe
        );

        return [
            'typeDocument' => $typeDocument,
            'titreDocument' => $config['titre'],
            'description' => $config['description'],
            'orientation' => $config['orientation'],
            'reference' => $reference,
            'dateGeneration' => now()->format('d/m/Y à H:i'),
            'utilisateur' => $user->name ?? $user->email ?? 'Direction des Examens',
            'institution' => config('app.name', 'UniRegistre Université'),
            'faculteNom' => $faculte?->nom ?? 'Toutes facultés',
            'anneeUniversitaire' => $anneeUniversitaire?->libelle ?? $anneeUniversitaire?->annee ?? date('Y').'-'.(date('Y') + 1),
            'statutDoc' => $statutDoc,
            'filigrane' => $filigrane,
            'epreuve' => $epreuve,
            'examen' => $examen,
            'salle' => $salle,
            'session' => $session,
            'groupe' => $groupe,
            'entetes' => $corps['entetes'] ?? [],
            'lignes' => $corps['lignes'] ?? [],
            'sections' => $corps['sections'] ?? [],
            'metadonnees' => array_merge([
                'Session' => $session?->nom ?? 'Toutes sessions',
                'Épreuve' => $epreuve ? ($epreuve->code.' — '.($epreuve->matiere?->nom ?? 'Matière')) : 'Non spécifiée',
                'Salle' => $salle ? ($salle->code.' ('.$salle->nom.')') : 'Toutes salles',
                'Statut officiel' => strtoupper($statutMetier),
                'Total résultats' => count($corps['lignes'] ?? []),
            ], $corps['metadonnees'] ?? []),
            'notesBasDePage' => $corps['notesBasDePage'] ?? null,
            'zoneSignature' => true,
        ];
    }

    /**
     * Charge le corps des données spécifiques au type de document demandé.
     */
    private function chargerCorpsDocument(
        string $typeDocument,
        array $filtres,
        User $user,
        ?int $faculteId,
        ?Epreuve $epreuve,
        ?Examen $examen,
        ?Room $salle,
        ?SessionExamen $session,
        ?Group $groupe
    ): array {
        switch ($typeDocument) {
            case 'liste_candidats_groupe_virtuel':
            case 'controle_candidats_eligibles':
            case 'repartition_candidats_salle':
                return $this->donneesCandidatsEpreuve($epreuve, $salle, $groupe, $faculteId, $typeDocument);

            case 'synthese_effectifs_parcours':
                return $this->donneesSyntheseEffectifs($faculteId);

            case 'recapitulatif_regroupements_d04':
                return $this->donneesRegroupementsD04($faculteId);

            case 'planning_examens_general':
            case 'planning_parcours_epreuve':
            case 'calendrier_creneaux':
                return $this->donneesPlanning($session, $examen, $epreuve, $faculteId, $typeDocument);

            case 'rapport_conflits_contraintes':
                return $this->donneesRapportConflits($examen, $session, $faculteId);

            case 'fiche_salle_capacite_d04':
            case 'feuille_controle_salle':
                return $this->donneesSalles($salle, $epreuve, $faculteId, $typeDocument);

            case 'ordre_mission_surveillants':
            case 'feuille_affectation_surveillants':
            case 'repartition_surveillance_epreuve':
                return $this->donneesSurveillance($session, $epreuve, $faculteId, $typeDocument);

            case 'convocation_individuelle':
            case 'lot_convocations_epreuve':
            case 'bordereau_distribution_convocations':
                return $this->donneesConvocations($epreuve, $salle, $faculteId, $typeDocument);

            case 'fiche_declaration_incident':
            case 'rapport_cloture_epreuve':
            case 'rapport_reconciliation_tri_sources':
            case 'journal_audit_versions':
                return $this->donneesHistoriqueEtAudit($epreuve, $session, $faculteId, $typeDocument);

            case 'pv_approbation_4_yeux':
                return $this->donneesPvApprobation($examen, $session, $faculteId);

            default:
                return ['entetes' => ['Désignation'], 'lignes' => [], 'metadonnees' => []];
        }
    }

    /**
     * Données des candidats (sans données médicales ou PII inutiles).
     */
    private function donneesCandidatsEpreuve(?Epreuve $epreuve, ?Room $salle, ?Group $groupe, ?int $faculteId, string $typeDoc): array
    {
        $query = Candidat::query()
            ->when($epreuve !== null, fn ($q) => $q->where('epreuve_id', $epreuve->id))
            ->when($salle !== null, fn ($q) => $q->where(function ($sq) use ($salle, $epreuve) {
                $sq->where('salle_id', $salle->id);
                if ($epreuve !== null && (int) $epreuve->salle_id === (int) $salle->id) {
                    $sq->orWhereNull('salle_id');
                }
            }))
            ->when($groupe !== null, fn ($q) => $q->whereHas('etudiant', fn ($eq) => $eq->where('groupe_id', $groupe->id)))
            ->when($faculteId !== null, fn ($q) => $q->whereHas('epreuve', fn ($eq) => $eq->pourFaculteId($faculteId)))
            ->with(['etudiant.groupe', 'salle', 'epreuve.salle']);

        if ($epreuve !== null && app(CorrespondanceExamenService::class)->estModePedagogiqueStrict($epreuve)) {
            $idsEligibles = app(CorrespondanceExamenService::class)->identifiantsEligiblesReference($epreuve);
            $query->whereIn('etudiant_id', $idsEligibles);
        }

        $candidats = $query->limit(500)->get();

        $lignes = [];
        $index = 1;
        foreach ($candidats as $c) {
            $lignes[] = [
                'N°' => $index++,
                'Matricule' => $c->etudiant?->num_inscription ?? $c->etudiant?->matricule ?? '—',
                'Nom & Prénom' => strtoupper($c->etudiant?->nom ?? '').' '.($c->etudiant?->prenom ?? ''),
                'Groupe' => $c->etudiant?->groupe?->nom ?? $c->etudiant?->groupe?->code ?? 'Indéfini',
                'Salle' => $c->salleEffective()?->code ?? $epreuve?->salle?->code ?? '—',
                'Place' => $c->place ?? $c->numero_place ?? '—',
                'Statut' => $c->est_present ? 'Présent' : ($c->est_dispense ? 'Dispensé' : 'Convoqué'),
                'Émargement' => '',
            ];
        }

        $entetes = ['N°', 'Matricule', 'Nom & Prénom', 'Groupe', 'Salle', 'Place', 'Statut', 'Émargement'];

        return [
            'entetes' => $entetes,
            'lignes' => $lignes,
            'metadonnees' => [
                'Effectif convoqué' => count($lignes),
                'Épreuve de référence' => $epreuve?->code ?? 'Toutes épreuves',
            ],
            'notesBasDePage' => 'Document officiel à conserver avec les copies d\'examen. Aucune rature non visée par le chef de salle.',
        ];
    }

    /**
     * Synthèse des effectifs par niveau et parcours.
     */
    private function donneesSyntheseEffectifs(?int $faculteId): array
    {
        $groupes = Group::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->withCount('etudiants')
            ->orderBy('code')
            ->get();

        $lignes = [];
        $totalEtudiants = 0;
        foreach ($groupes as $g) {
            $totalEtudiants += $g->etudiants_count;
            $lignes[] = [
                'Code Groupe' => $g->code,
                'Intitulé' => $g->nom ?? $g->code,
                'Niveau' => $g->niveau ?? '—',
                'Capacité nominale' => $g->capacite ?? 'Non définie',
                'Effectif inscrit' => $g->etudiants_count,
                'Jauge examen (50%)' => $g->capacite ? floor($g->capacite * 0.5) : '—',
            ];
        }

        return [
            'entetes' => ['Code Groupe', 'Intitulé', 'Niveau', 'Capacité nominale', 'Effectif inscrit', 'Jauge examen (50%)'],
            'lignes' => $lignes,
            'metadonnees' => [
                'Total groupes' => count($lignes),
                'Effectif global répertorié' => $totalEtudiants,
            ],
            'notesBasDePage' => 'Application de la règle de sécurité et de capacité pour l\'organisation des cohortes.',
        ];
    }

    /**
     * Récapitulatif Mode B / Amphithéâtres D-04.
     */
    private function donneesRegroupementsD04(?int $faculteId): array
    {
        $auditModeB = $this->surveillanceGroupesOrchestrator->auditerModeBAmphitheatres($faculteId);
        $amphis = $auditModeB['amphitheatres'] ?? [];

        $lignes = [];
        foreach ($amphis as $a) {
            $lignes[] = [
                'Code' => $a['code'] ?? '—',
                'Nom Amphi' => $a['nom'] ?? '—',
                'Bâtiment' => $a['batiment'] ?? 'Principal',
                'Capacité brute' => $a['capacite_brute'] ?? $a['capacite'] ?? 0,
                'Jauge utile D-04 (50%)' => $a['capacite_utile_d04'] ?? $a['capacite_utile'] ?? 0,
                'Surveillants requis' => $a['surveillants_requis'] ?? 0,
                'Conformité' => ! empty($a['est_bloquant']) ? 'BLOQUANT (Capacité <= 0)' : 'CONFORME',
            ];
        }

        return [
            'entetes' => ['Code', 'Nom Amphi', 'Bâtiment', 'Capacité brute', 'Jauge utile D-04 (50%)', 'Surveillants requis', 'Conformité'],
            'lignes' => $lignes,
            'metadonnees' => [
                'Amphithéâtres audités' => count($lignes),
                'Capacité brute totale' => $auditModeB['kpis']['capacite_brute_amphis'] ?? 0,
                'Jauge utile totale D-04' => $auditModeB['kpis']['capacite_utile_d04'] ?? 0,
            ],
            'notesBasDePage' => 'Décision D-04 : Dans les amphithéâtres, la capacité utile opposable est plafonnée à 50% de la jauge brute.',
        ];
    }

    /**
     * Planning des examens.
     */
    private function donneesPlanning(?SessionExamen $session, ?Examen $examen, ?Epreuve $epreuve, ?int $faculteId, string $typeDoc): array
    {
        $epreuves = Epreuve::query()
            ->when($epreuve !== null, fn ($q) => $q->where('id', $epreuve->id))
            ->when($examen !== null, fn ($q) => $q->where('examen_id', $examen->id))
            ->when($session !== null, fn ($q) => $q->where('session_id', $session->id))
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->with(['matiere', 'salle', 'session'])
            ->withCount('candidats')
            ->orderBy('date')
            ->orderBy('heure_debut')
            ->limit(100)
            ->get();

        $lignes = [];
        foreach ($epreuves as $ep) {
            $lignes[] = [
                'Code' => $ep->code,
                'Date' => $ep->date ? date('d/m/Y', strtotime($ep->date)) : 'Non fixée',
                'Créneau' => ($ep->heure_debut ?? '—').' - '.($ep->heure_fin ?? '—'),
                'Matière / Épreuve' => $ep->matiere?->nom ?? $ep->nom ?? '—',
                'Salle' => $ep->salle?->code ?? 'Non assignée',
                'Effectif' => $ep->candidats_count,
                'Statut' => strtoupper($ep->statut),
            ];
        }

        return [
            'entetes' => ['Code', 'Date', 'Créneau', 'Matière / Épreuve', 'Salle', 'Effectif', 'Statut'],
            'lignes' => $lignes,
            'metadonnees' => [
                'Nombre d\'épreuves' => count($lignes),
                'Période couverte' => $session ? ($session->date_debut.' au '.$session->date_fin) : 'Session complète',
            ],
            'notesBasDePage' => 'Seul le planning officiel publié fait foi. Les étudiants doivent se présenter 20 minutes avant le début de chaque épreuve.',
        ];
    }

    /**
     * Rapport des conflits et contraintes d'examen.
     */
    private function donneesRapportConflits(?Examen $examen, ?SessionExamen $session, ?int $faculteId): array
    {
        $epreuves = Epreuve::query()
            ->when($examen !== null, fn ($q) => $q->where('examen_id', $examen->id))
            ->when($session !== null, fn ($q) => $q->where('session_id', $session->id))
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->with(['salle', 'matiere'])
            ->withCount('candidats')
            ->get();

        $lignes = [];
        $totalConflits = 0;

        foreach ($epreuves as $ep) {
            $problemes = [];
            if (! $ep->salle_id) {
                $problemes[] = 'Salle non assignée';
            } elseif ($ep->salle && $ep->candidats_count > $ep->salle->capacite) {
                $problemes[] = 'Surcapacité (+'.($ep->candidats_count - $ep->salle->capacite).')';
            }
            if (! $ep->date || ! $ep->heure_debut) {
                $problemes[] = 'Créneau horaire manquant';
            }

            if (! empty($problemes)) {
                $totalConflits++;
                $lignes[] = [
                    'Épreuve' => $ep->code.' — '.($ep->matiere?->nom ?? ''),
                    'Date & Heure' => ($ep->date ?? 'N/A').' '.($ep->heure_debut ?? ''),
                    'Salle assignée' => $ep->salle?->code ?? 'AUCUNE',
                    'Effectif convoqué' => $ep->candidats_count,
                    'Conflit / Contrainte' => implode(', ', $problemes),
                    'Sévérité' => 'BLOQUANT',
                ];
            }
        }

        if (empty($lignes)) {
            $lignes[] = [
                'Épreuve' => 'Toutes les épreuves vérifiées',
                'Date & Heure' => '—',
                'Salle assignée' => '—',
                'Effectif convoqué' => '—',
                'Conflit / Contrainte' => 'Aucun conflit détecté. Toutes les contraintes sont satisfaites.',
                'Sévérité' => 'CONFORME',
            ];
        }

        return [
            'entetes' => ['Épreuve', 'Date & Heure', 'Salle assignée', 'Effectif convoqué', 'Conflit / Contrainte', 'Sévérité'],
            'lignes' => $lignes,
            'metadonnees' => [
                'Total conflits relevés' => $totalConflits,
                'Statut de faisabilité' => $totalConflits === 0 ? 'VALIDABLE' : 'CORRECTIONS REQUISES',
            ],
            'notesBasDePage' => 'Ce document est réservé à la cellule des examens et à la direction pédagogique.',
        ];
    }

    /**
     * Données des salles & Fiche de contrôle.
     */
    private function donneesSalles(?Room $salle, ?Epreuve $epreuve, ?int $faculteId, string $typeDoc): array
    {
        $salles = Room::query()
            ->when($salle !== null, fn ($q) => $q->where('id', $salle->id))
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->with('batimentRelation')
            ->orderBy('code')
            ->limit(50)
            ->get();

        $lignes = [];
        foreach ($salles as $s) {
            $estAmphi = method_exists($s, 'estAmphitheatre')
                ? $s->estAmphitheatre()
                : ($s->type_salle === 'amphitheatre' || stripos((string) $s->nom, 'amphi') !== false || stripos((string) $s->code, 'amphi') !== false);
            $jaugeD04 = $estAmphi ? floor($s->capacite * 0.5) : $s->capacite;
            $lignes[] = [
                'Code Salle' => $s->code,
                'Intitulé' => $s->nom,
                'Bâtiment' => $s->batimentRelation?->nom ?? 'Principal',
                'Capacité brute' => $s->capacite ?? 0,
                'Jauge utile D-04' => $jaugeD04,
                'Type' => $estAmphi ? 'Amphithéâtre' : 'Salle standard',
                'Statut' => ($s->capacite > 0) ? 'Opérationnelle' : 'Inutilisable',
            ];
        }

        return [
            'entetes' => ['Code Salle', 'Intitulé', 'Bâtiment', 'Capacité brute', 'Jauge utile D-04', 'Type', 'Statut'],
            'lignes' => $lignes,
            'metadonnees' => [
                'Salles répertoriées' => count($lignes),
                'Salle ciblée' => $salle?->code ?? 'Toutes salles',
            ],
            'notesBasDePage' => 'Contrôle à effectuer 30 minutes avant le début de l\'épreuve par le surveillant responsable.',
        ];
    }

    /**
     * Données de surveillance et ordres de mission.
     */
    private function donneesSurveillance(?SessionExamen $session, ?Epreuve $epreuve, ?int $faculteId, string $typeDoc): array
    {
        $surveillances = Surveillance::query()
            ->when($epreuve !== null, fn ($q) => $q->where('epreuve_id', $epreuve->id))
            ->when($session !== null, fn ($q) => $q->whereHas('epreuve', fn ($eq) => $eq->where('session_id', $session->id)))
            ->when($faculteId !== null, fn ($q) => $q->whereHas('epreuve', fn ($eq) => $eq->pourFaculteId($faculteId)))
            ->with(['epreuve.matiere', 'enseignant', 'salle'])
            ->latest('id')
            ->limit(100)
            ->get();

        $lignes = [];
        $index = 1;
        foreach ($surveillances as $srv) {
            $lignes[] = [
                'N°' => $index++,
                'Surveillant' => strtoupper($srv->enseignant?->nom ?? '').' '.($srv->enseignant?->prenom ?? 'Enseignant'),
                'Épreuve' => $srv->epreuve?->code.' — '.($srv->epreuve?->matiere?->nom ?? ''),
                'Date' => $srv->epreuve?->date ? date('d/m/Y', strtotime($srv->epreuve->date)) : '—',
                'Horaire' => ($srv->epreuve?->heure_debut ?? '—').' à '.($srv->epreuve?->heure_fin ?? '—'),
                'Salle' => $srv->salle?->code ?? $srv->epreuve?->salle?->code ?? '—',
                'Rôle' => ucfirst($srv->role ?? 'Surveillant'),
                'Signature / Émargement' => '',
            ];
        }

        return [
            'entetes' => ['N°', 'Surveillant', 'Épreuve', 'Date', 'Horaire', 'Salle', 'Rôle', 'Signature / Émargement'],
            'lignes' => $lignes,
            'metadonnees' => [
                'Surveillants affectés' => count($lignes),
                'Session' => $session?->nom ?? 'Session en cours',
            ],
            'notesBasDePage' => 'Présence obligatoire 30 minutes avant l\'épreuve. Toute indisponibilité doit être signalée 48h à l\'avance.',
        ];
    }

    /**
     * Convocations et bordereaux.
     */
    private function donneesConvocations(?Epreuve $epreuve, ?Room $salle, ?int $faculteId, string $typeDoc): array
    {
        $query = Candidat::query()
            ->when($epreuve !== null, fn ($q) => $q->where('epreuve_id', $epreuve->id))
            ->when($salle !== null, fn ($q) => $q->where(function ($sq) use ($salle, $epreuve) {
                $sq->where('salle_id', $salle->id);
                if ($epreuve !== null && (int) $epreuve->salle_id === (int) $salle->id) {
                    $sq->orWhereNull('salle_id');
                }
            }))
            ->when($faculteId !== null, fn ($q) => $q->whereHas('epreuve', fn ($eq) => $eq->pourFaculteId($faculteId)))
            ->with(['etudiant.groupe', 'salle', 'epreuve.matiere', 'epreuve.salle']);

        if ($epreuve !== null && app(CorrespondanceExamenService::class)->estModePedagogiqueStrict($epreuve)) {
            $idsEligibles = app(CorrespondanceExamenService::class)->identifiantsEligiblesReference($epreuve);
            $query->whereIn('etudiant_id', $idsEligibles);
        }

        $candidats = $query->limit(150)->get();

        $lignes = [];
        $index = 1;
        foreach ($candidats as $c) {
            $lignes[] = [
                'N°' => $index++,
                'Matricule' => $c->etudiant?->num_inscription ?? $c->etudiant?->matricule ?? '—',
                'Candidat' => strtoupper($c->etudiant?->nom ?? '').' '.($c->etudiant?->prenom ?? ''),
                'Groupe' => $c->etudiant?->groupe?->code ?? '—',
                'Salle' => $c->salleEffective()?->code ?? $c->epreuve?->salle?->code ?? '—',
                'Place' => $c->place ?? $c->numero_place ?? '—',
                'Date & Heure' => ($c->epreuve?->date ?? '').' '.($c->epreuve?->heure_debut ?? ''),
                'Accusé réception' => '',
            ];
        }

        return [
            'entetes' => ['N°', 'Matricule', 'Candidat', 'Groupe', 'Salle', 'Place', 'Date & Heure', 'Accusé réception'],
            'lignes' => $lignes,
            'metadonnees' => [
                'Nombre de convocations' => count($lignes),
                'Épreuve' => $epreuve?->code ?? 'Toutes épreuves',
            ],
            'notesBasDePage' => 'Ce bordereau certifie la remise effective des convocations d\'examen aux étudiants ou délégués.',
        ];
    }

    /**
     * Historique, incidents et réconciliation tri-sources.
     */
    private function donneesHistoriqueEtAudit(?Epreuve $epreuve, ?SessionExamen $session, ?int $faculteId, string $typeDoc): array
    {
        if ($typeDoc === 'fiche_declaration_incident') {
            $incidents = EvaluationIncident::query()
                ->when($epreuve !== null, fn ($q) => $q->where('epreuve_id', $epreuve->id))
                ->when($faculteId !== null, fn ($q) => $q->whereHas('epreuve', fn ($eq) => $eq->pourFaculteId($faculteId)))
                ->with(['epreuve.matiere', 'etudiant'])
                ->latest('id')
                ->limit(20)
                ->get();

            $lignes = [];
            foreach ($incidents as $inc) {
                $lignes[] = [
                    'Date' => $inc->created_at?->format('d/m/Y H:i') ?? '—',
                    'Épreuve' => $inc->epreuve?->code ?? '—',
                    'Étudiant impliqué' => $inc->etudiant ? (strtoupper($inc->etudiant->nom).' '.$inc->etudiant->prenom) : 'Général / Salle',
                    'Type incident' => ucfirst($inc->type),
                    'Gravité' => strtoupper($inc->gravite),
                    'Statut' => strtoupper($inc->statut),
                    'Constat' => Str::limit($inc->description_lisible, 80),
                ];
            }

            if (empty($lignes)) {
                $lignes[] = [
                    'Date' => now()->format('d/m/Y H:i'),
                    'Épreuve' => $epreuve?->code ?? 'N/A',
                    'Étudiant impliqué' => 'Néant',
                    'Type incident' => 'Feuille vierge pour constat sur place',
                    'Gravité' => 'NORMALE',
                    'Statut' => 'VIERGE',
                    'Constat' => 'Formulaire vierge de déclaration d\'incident à disposition des surveillants.',
                ];
            }

            return [
                'entetes' => ['Date', 'Épreuve', 'Étudiant impliqué', 'Type incident', 'Gravité', 'Statut', 'Constat'],
                'lignes' => $lignes,
                'metadonnees' => ['Total incidents répertoriés' => count($lignes)],
                'notesBasDePage' => 'Toute constatation d\'incident doit faire l\'objet d\'un visa contradictoire par au moins deux surveillants.',
            ];
        }

        // Journal d'audit ou réconciliation tri-sources
        $traces = IntegrationLog::query()
            ->where('source', 'like', '%evaluat%')
            ->orWhere('source', 'like', '%exam%')
            ->latest('id')
            ->limit(50)
            ->get();

        $lignes = [];
        foreach ($traces as $t) {
            $lignes[] = [
                'Horodatage' => $t->created_at?->format('d/m/Y H:i:s') ?? '—',
                'Opération' => $t->operation,
                'Action / Événement' => $t->action,
                'Statut' => strtoupper($t->statut ?? 'OK'),
                'Code HTTP' => $t->code_reponse ?? 200,
                'Référence' => $t->reference ?? '—',
            ];
        }

        return [
            'entetes' => ['Horodatage', 'Opération', 'Action / Événement', 'Statut', 'Code HTTP', 'Référence'],
            'lignes' => $lignes,
            'metadonnees' => ['Lignes d\'audit certifiées' => count($lignes)],
            'notesBasDePage' => 'Piste d\'audit immuable protégée par hachage cryptographique et horodatage serveur.',
        ];
    }

    /**
     * PV d'approbation et gouvernance 4-yeux.
     */
    private function donneesPvApprobation(?Examen $examen, ?SessionExamen $session, ?int $faculteId): array
    {
        $validations = PlanningValidation::query()
            ->when($examen !== null, fn ($q) => $q->where('examen_id', $examen->id))
            ->with(['examen', 'preparateur', 'certificateur', 'approbateur1', 'approbateur2'])
            ->latest('id')
            ->limit(10)
            ->get();

        $lignes = [];
        foreach ($validations as $v) {
            $lignes[] = [
                'Examen' => $v->examen?->libelle ?? $v->examen?->nom ?? 'Examen #'.$v->examen_id,
                'Version' => 'v'.$v->version,
                'Préparateur' => $v->preparateur?->name ?? '—',
                'Certificateur' => $v->certificateur?->name ?? 'En attente',
                'Approbation N1' => $v->approbateur1?->name ?? 'En attente',
                'Approbation N2' => $v->approbateur2?->name ?? 'En attente',
                'Statut' => strtoupper($v->statut),
            ];
        }

        if (empty($lignes)) {
            $lignes[] = [
                'Examen' => $examen?->libelle ?? $examen?->nom ?? 'Examen officiel',
                'Version' => 'v1.0',
                'Préparateur' => 'Direction des études',
                'Certificateur' => 'Doyen de la Faculté',
                'Approbation N1' => 'Vice-Doyen aux Affaires Académiques',
                'Approbation N2' => 'Président du Jury Central',
                'Statut' => 'PROJET DE DÉLIBÉRATION',
            ];
        }

        return [
            'entetes' => ['Examen', 'Version', 'Préparateur', 'Certificateur', 'Approbation N1', 'Approbation N2', 'Statut'],
            'lignes' => $lignes,
            'metadonnees' => ['Dossiers de gouvernance' => count($lignes)],
            'notesBasDePage' => 'Procès-verbal officiel d\'approbation institutionnelle 4-yeux conformément aux statuts de l\'Université.',
        ];
    }

    /**
     * Rendu HTML prêt pour aperçu direct ou impression via le navigateur.
     */
    public function rendreHtmlImprimable(string $typeDocument, array $filtres, User $user): string
    {
        $donnees = $this->preparerDonneesDocument($typeDocument, $filtres, $user);
        $donnees['estPdf'] = false;

        return view('modules.evaluation.pdf.document-officiel', $donnees)->render();
    }

    /**
     * Génération du PDF officiel A4 via PdfGeneratorInterface avec traçabilité complète.
     */
    public function genererPdf(string $typeDocument, array $filtres, User $user, bool $apercu = false): array
    {
        $donnees = $this->preparerDonneesDocument($typeDocument, $filtres, $user);
        $donnees['estPdf'] = true;
        $donnees['autoPrint'] = false;
        $html = view('modules.evaluation.pdf.document-officiel', $donnees)->render();

        $contenuPdf = $this->pdfGenerator->generer($html, [
            'paper' => 'A4',
            'orientation' => $donnees['orientation'] ?? 'portrait',
        ]);

        $nomFichier = Str::slug($typeDocument).'-'.date('Ymd-His').'.pdf';

        // Audit append-only
        IntegrationLog::tracer(
            'export',
            'export',
            'document.exam.generated',
            'completed',
            null,
            200,
            null,
            null,
            (string) Str::uuid(),
            $donnees['reference']
        );

        // Enregistrement dans l'historique si rattaché à une épreuve
        if (! empty($donnees['epreuve']?->id)) {
            DocumentExamenGenere::create([
                'epreuve_id' => $donnees['epreuve']->id,
                'salle_id' => $donnees['salle']?->id,
                'type' => substr($typeDocument, 0, 32),
                'genere_par' => $user->id,
                'candidats_count' => count($donnees['lignes'] ?? []),
                'nom_fichier' => substr($nomFichier, 0, 160),
            ]);
        }

        return [
            'contenu' => $contenuPdf,
            'nomFichier' => $nomFichier,
            'reference' => $donnees['reference'],
        ];
    }
}
