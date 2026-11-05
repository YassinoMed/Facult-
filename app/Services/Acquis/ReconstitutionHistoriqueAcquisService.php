<?php

namespace App\Services\Acquis;

use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\Note;
use App\Modules\Evaluation\Models\DecisionDeliberation;

/**
 * RECONSTITUTION ET MIGRATION DES ACQUIS HISTORIQUES (LOOP 26).
 *
 * Reconstitue la trajectoire académique d'un étudiant à travers ses différentes
 * années universitaires, sessions et décisions souveraines de jury.
 * Respect strict de l'idempotence, zéro écrasement des notes historiques.
 */
class ReconstitutionHistoriqueAcquisService
{
    public function __construct(
        private readonly MoteurDecisionAcademiqueService $moteurDecision,
        private readonly HistoriqueTentativesService $tentativesService,
    ) {}

    /**
     * Reconstitue l'ensemble du parcours historique d'un étudiant de manière chronologique.
     *
     * @return array{
     *     etudiant_id: int,
     *     annees_analysees: int,
     *     chronologie: array<int, array>,
     *     synthese_acquis: array,
     *     anomalies_detectees: array<int, string>,
     *     dossiers_incomplets: array<int, string>,
     *     reconstitution_idempotente: bool
     * }
     */
    public function reconstituerParcoursHistorique(Etudiant $etudiant): array
    {
        $inscriptions = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->with(['anneeUniversitaire', 'programmeRef', 'semestres'])
            ->get()
            ->sortBy(function (InscriptionAcademique $insc) {
                return $insc->anneeUniversitaire?->date_debut?->timestamp ?? $insc->id;
            })
            ->values();

        $chronologie = [];
        $anomalies = [];
        $dossiersIncomplets = [];
        $creditsCumules = 0;
        $matieresCapitaliseesHistorique = [];

        foreach ($inscriptions as $index => $inscription) {
            $anneeLibelle = $inscription->anneeUniversitaire?->libelle ?? "Année-{$inscription->id}";

            // 1. Recherche des délibérations de jury associées
            $deliberations = DecisionDeliberation::where('etudiant_id', $etudiant->id)
                ->where('inscription_academique_id', $inscription->id)
                ->get();

            $decisionJury = $deliberations->first();
            $deliberationPubliee = (bool) ($decisionJury?->publie ?? false);

            // 2. Recherche des notes associées à cette inscription
            $notes = Note::where('etudiant_id', $etudiant->id)
                ->where('inscription_academique_id', $inscription->id)
                ->with(['matiere', 'semestre'])
                ->get();

            // CAS PARTICULIER A : Notes sans PV formel de délibération
            if ($notes->isNotEmpty() && ! $deliberationPubliee) {
                $anomalies[] = [
                    'type' => 'NOTE_SANS_PV',
                    'message' => "Année {$anneeLibelle} : {$notes->count()} notes enregistrées mais absence de PV de délibération officiel publié.",
                    'annee' => $anneeLibelle,
                    'notes_count' => $notes->count(),
                ];
            }

            // CAS PARTICULIER B : PV officiel présent mais détail des notes absent
            if ($deliberationPubliee && $notes->isEmpty()) {
                $dossiersIncomplets[] = [
                    'type' => 'PV_SANS_NOTE',
                    'message' => "Année {$anneeLibelle} : Décision de jury enregistrée ({$decisionJury->decision}) mais aucune note individuelle présente en base.",
                    'annee' => $anneeLibelle,
                    'decision' => $decisionJury->decision,
                ];
            }

            // 3. Calcul de la décision pour cette étape chronologique
            $bilanEtape = $this->moteurDecision->determinerDecision($etudiant, $inscription);

            // Cumul sécurisé des matières validées
            foreach ($bilanEtape['elements_conserves'] as $elem) {
                $matieresCapitaliseesHistorique[$elem['id']] = [
                    'code' => $elem['code'],
                    'nom' => $elem['nom'],
                    'credits' => $elem['credits'],
                    'annee_obtention' => $anneeLibelle,
                    'note_retenue' => $elem['note'] ?? null,
                ];
            }

            $chronologie[] = [
                'ordre' => $index + 1,
                'inscription_id' => $inscription->id,
                'annee' => $anneeLibelle,
                'programme' => $inscription->programmeRef?->nom ?? $inscription->filiere,
                'niveau' => $inscription->niveau,
                'deliberation_officielle' => $deliberationPubliee ? ($decisionJury->decision ?? 'NON_DEFINIE') : 'PROVISOIRE_NON_PUBLIEE',
                'moyenne_officielle' => $decisionJury?->moyenne,
                'credits_acquis_annee' => $bilanEtape['credits']['capitalises'],
                'elements_a_reprendre_count' => count($bilanEtape['elements_a_reprendre']),
                'anomalies_annee' => $bilanEtape['anomalies'],
            ];
        }

        $totalCreditsConservables = array_sum(array_column($matieresCapitaliseesHistorique, 'credits'));

        return [
            'etudiant_id' => $etudiant->id,
            'annees_analysees' => $inscriptions->count(),
            'chronologie' => $chronologie,
            'synthese_acquis' => [
                'total_matieres_capitalisees' => count($matieresCapitaliseesHistorique),
                'total_credits_conserves' => $totalCreditsConservables,
                'matieres_detaillees' => array_values($matieresCapitaliseesHistorique),
            ],
            'anomalies_detectees' => $anomalies,
            'dossiers_incomplets' => $dossiersIncomplets,
            'reconstitution_idempotente' => true,
        ];
    }

    /**
     * Alias compatible pour la reconstitution historique des acquis.
     *
     * @return array<string, mixed>
     */
    public function reconstituerHistorique(Etudiant $etudiant): array
    {
        $rapport = $this->reconstituerParcoursHistorique($etudiant);
        $rapport['succes'] = true;
        $rapport['tentatives_par_annee'] = $rapport['chronologie'];

        return $rapport;
    }

    /**
     * Sauvegarde la reconstitution historique dans le journal immuable d'audit.
     */
    public function auditerReconstitution(Etudiant $etudiant, int $acteurId, string $motif = 'Reconstitution chronologique des acquis'): void
    {
        $reconstitution = $this->reconstituerParcoursHistorique($etudiant);

        HistoriqueAcademique::create([
            'etudiant_id' => $etudiant->id,
            'utilisateur_id' => $acteurId,
            'action' => 'reconstitution_acquis_historiques',
            'entite' => 'etudiants',
            'entite_id' => $etudiant->id,
            'ancienne_valeur' => null,
            'nouvelle_valeur' => json_encode([
                'motif' => $motif,
                'annees_reconstituees' => $reconstitution['annees_analysees'],
                'total_credits' => $reconstitution['synthese_acquis']['total_credits_conserves'],
                'anomalies_count' => count($reconstitution['anomalies_detectees']),
                'dossiers_incomplets_count' => count($reconstitution['dossiers_incomplets']),
            ], JSON_UNESCAPED_UNICODE),
            'source_systeme' => 'moteur_reconstitution_loop26',
        ]);
    }
}
