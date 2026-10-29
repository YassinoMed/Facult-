<?php

namespace App\Modules\Evaluation\Services;

use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Surveillance;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;

/**
 * Orchestrateur Enterprise pour la surveillance des examens et les groupes (Menus 5 & 8).
 *
 * Centralise :
 *  - L'audit des ratios réglementaires d'encadrement (besoinActifs vs surveillants effectifs)
 *  - La détection des épreuves sous-surveillées ou sans encadrement
 *  - La détection des conflits horaires et double affectation d'enseignants
 *  - L'audit des cohortes et groupes d'examen (capacités, surcharges, conformité)
 *  - La garantie zéro écriture sur les requêtes de consultation
 */
class ExamSurveillanceGroupesOrchestratorService
{
    public function __construct(
        private SurveillanceConstraintResolver $constraintResolver,
        private SurveillanceService $surveillanceService,
    ) {}

    /**
     * Audit complet de la surveillance des épreuves pour une session ou faculté donnée.
     *
     * @return array{
     *     kpis: array{
     *         epreuves_totales: int,
     *         epreuves_conformes: int,
     *         epreuves_sous_surveillees: int,
     *         epreuves_sans_surveillant: int,
     *         surveillances_actives: int,
     *         surveillances_confirmees: int,
     *         surveillances_en_attente: int,
     *         remplacements_effectues: int,
     *         enseignants_mobilises: int,
     *         taux_couverture: float
     *     },
     *     epreuves_auditees: array<int, array{
     *         epreuve_id: int,
     *         code: string,
     *         matiere: string,
     *         date: string,
     *         horaire: string,
     *         salle: string,
     *         candidats_count: int,
     *         besoin_surveillants: int,
     *         surveillants_affectes: int,
     *         statut_encadrement: string,
     *         manque_surveillants: int
     *     }>
     * }
     */
    public function auditerSurveillance(?int $sessionId = null, ?int $faculteId = null, ?string $dateFiltre = null): array
    {
        $epreuves = Epreuve::query()
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->when($sessionId !== null, fn ($q) => $q->where('session_id', $sessionId))
            ->when($dateFiltre !== null && $dateFiltre !== '', fn ($q) => $q->whereDate('date', $dateFiltre))
            ->where('statut', '!=', 'cancelled')
            ->with(['matiere', 'salle', 'session', 'surveillants' => fn ($q) => $q->whereNotIn('statut', ['annule', 'remplace'])])
            ->withCount('candidats')
            ->orderBy('date')
            ->orderBy('heure_debut')
            ->get();

        $epreuvesTotales = $epreuves->count();
        $epreuvesConformes = 0;
        $epreuvesSousSurveillees = 0;
        $epreuvesSansSurveillant = 0;

        $epreuvesAuditees = [];

        foreach ($epreuves as $epreuve) {
            $contraintes = $epreuve->session !== null
                ? $this->constraintResolver->resoudrePourSession($epreuve->session, $faculteId)
                : $this->constraintResolver->resoudre($faculteId, $epreuve->annee_universitaire_id, null);

            $nbCandidats = (int) $epreuve->candidats_count;
            $nbSalles = 1;

            $besoin = $contraintes->besoinActifs($nbCandidats, $nbSalles);
            $affectes = $epreuve->surveillants->count();

            if ($affectes === 0) {
                $statutEncadrement = 'sans_surveillant';
                $epreuvesSansSurveillant++;
            } elseif ($affectes < $besoin) {
                $statutEncadrement = 'sous_surveillee';
                $epreuvesSousSurveillees++;
            } else {
                $statutEncadrement = 'conforme';
                $epreuvesConformes++;
            }

            $epreuvesAuditees[] = [
                'epreuve_id' => $epreuve->id,
                'code' => $epreuve->code,
                'matiere' => $epreuve->matiere?->nom ?? ($epreuve->matiere?->libelle_complet ?? 'Matière #'.$epreuve->matiere_id),
                'date' => $epreuve->date?->format('d/m/Y') ?? 'Non planifiée',
                'horaire' => substr((string) $epreuve->heure_debut, 0, 5).' → '.substr((string) $epreuve->heure_fin, 0, 5),
                'salle' => $epreuve->salle?->code ?? 'Non assignée',
                'candidats_count' => $nbCandidats,
                'besoin_surveillants' => $besoin,
                'surveillants_affectes' => $affectes,
                'statut_encadrement' => $statutEncadrement,
                'manque_surveillants' => max(0, $besoin - $affectes),
            ];
        }

        // Statistiques globales de surveillance
        $surveillancesQuery = Surveillance::query()
            ->when($faculteId !== null, fn ($q) => $q->whereHas('epreuve', fn ($sq) => $sq->pourFaculteId($faculteId)))
            ->when($sessionId !== null, fn ($q) => $q->whereHas('epreuve', fn ($sq) => $sq->where('session_id', $sessionId)));

        $surveillancesActives = (clone $surveillancesQuery)
            ->whereIn('statut', ['assigne', 'confirme'])
            ->count();

        $surveillancesConfirmees = (clone $surveillancesQuery)
            ->where('statut', 'confirme')
            ->count();

        $surveillancesEnAttente = (clone $surveillancesQuery)
            ->where('statut', 'assigne')
            ->count();

        $remplacementsEffectues = (clone $surveillancesQuery)
            ->where('statut', 'remplace')
            ->count();

        $enseignantsMobilises = (clone $surveillancesQuery)
            ->whereIn('statut', ['assigne', 'confirme'])
            ->whereNotNull('teacher_id')
            ->distinct('teacher_id')
            ->count('teacher_id');

        $tauxCouverture = $epreuvesTotales > 0
            ? round(($epreuvesConformes / $epreuvesTotales) * 100, 1)
            : 100.0;

        return [
            'kpis' => [
                'epreuves_totales' => $epreuvesTotales,
                'epreuves_conformes' => $epreuvesConformes,
                'epreuves_sous_surveillees' => $epreuvesSousSurveillees,
                'epreuves_sans_surveillant' => $epreuvesSansSurveillant,
                'surveillances_actives' => $surveillancesActives,
                'surveillances_confirmees' => $surveillancesConfirmees,
                'surveillances_en_attente' => $surveillancesEnAttente,
                'remplacements_effectues' => $remplacementsEffectues,
                'enseignants_mobilises' => $enseignantsMobilises,
                'taux_couverture' => $tauxCouverture,
            ],
            'epreuves_auditees' => $epreuvesAuditees,
        ];
    }

    /**
     * Détecte les conflits de surveillance pour un enseignant sur une épreuve cible.
     *
     * @return array<int, string> Liste des conflits identifiés
     */
    public function detecterConflitsEnseignant(Epreuve $epreuve, int $teacherId): array
    {
        $conflits = [];
        $enseignant = Teacher::find($teacherId);

        if (! $enseignant) {
            return ['Enseignant introuvable.'];
        }

        if (! $enseignant->actif) {
            $conflits[] = "L'enseignant {$enseignant->nomComplet()} est actuellement inactif.";
        }

        // 1. Déjà affecté à cette même épreuve
        $dejaSurEpreuve = $epreuve->surveillants()
            ->where('teacher_id', $teacherId)
            ->whereNotIn('statut', ['annule', 'remplace'])
            ->exists();

        if ($dejaSurEpreuve) {
            $conflits[] = "L'enseignant est déjà affecté à cette épreuve.";
        }

        // 2. Conflit horaire avec une autre épreuve le même jour
        $heureDebut = substr((string) $epreuve->heure_debut, 0, 5);
        $heureFin = substr((string) $epreuve->heure_fin, 0, 5);

        $autreEpreuveChevauchante = Epreuve::query()
            ->whereDate('date', $epreuve->date->format('Y-m-d'))
            ->where('id', '!=', $epreuve->id)
            ->where('statut', '!=', 'cancelled')
            ->whereHas('surveillants', fn ($q) => $q->where('teacher_id', $teacherId)->whereNotIn('statut', ['annule', 'remplace']))
            ->with('matiere')
            ->get()
            ->first(fn (Epreuve $autre) => $heureDebut < substr((string) $autre->heure_fin, 0, 5)
                && substr((string) $autre->heure_debut, 0, 5) < $heureFin);

        if ($autreEpreuveChevauchante !== null) {
            $plage = substr((string) $autreEpreuveChevauchante->heure_debut, 0, 5).' → '.substr((string) $autreEpreuveChevauchante->heure_fin, 0, 5);
            $conflits[] = "Conflit d'horaire : déjà affecté sur l'épreuve {$autreEpreuveChevauchante->code} ({$autreEpreuveChevauchante->matiere?->nom}, {$plage}).";
        }

        // 3. Conflit avec un cours régulier (ScheduleSlot)
        $jourSemaine = strtolower($epreuve->date->format('l'));
        $slotConflit = ScheduleSlot::query()
            ->where('teacher_id', $teacherId)
            ->where('day_of_week', $jourSemaine)
            ->where('status', '!=', 'cancelled')
            ->with('matiere')
            ->get()
            ->first(fn ($s) => $s->chevauche((string) $epreuve->heure_debut, (string) $epreuve->heure_fin));

        if ($slotConflit !== null) {
            $conflits[] = "Conflit avec cours régulier programmé : « {$slotConflit->matiere?->nom} » ({$slotConflit->start_time} - {$slotConflit->end_time}).";
        }

        return $conflits;
    }

    /**
     * Audit de cohérence et de capacité des cohortes et groupes d'examen.
     *
     * @return array{
     *     total_groupes: int,
     *     effectif_total: int,
     *     capacite_totale: int,
     *     groupes_sans_capacite: int,
     *     groupes_surcharges: int,
     *     taux_occupation_moyen: float
     * }
     */
    public function auditerGroupes(?int $faculteId = null, ?string $recherche = null): array
    {
        $groupes = Group::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->when($recherche !== null && $recherche !== '', fn ($q) => $q->where(fn ($sq) => $sq->where('code', 'like', "%{$recherche}%")->orWhere('nom', 'like', "%{$recherche}%")))
            ->withCount('etudiants')
            ->get();

        $totalGroupes = $groupes->count();
        $effectifTotal = $groupes->sum('etudiants_count');
        $capaciteTotale = $groupes->sum(fn ($g) => max(0, (int) ($g->capacite ?? 0)));

        $groupesSansCapacite = $groupes->filter(fn ($g) => empty($g->capacite) || (int) $g->capacite <= 0)->count();
        $groupesSurcharges = $groupes->filter(fn ($g) => ! empty($g->capacite) && (int) $g->capacite > 0 && $g->etudiants_count > (int) $g->capacite)->count();

        $tauxOccupationMoyen = $capaciteTotale > 0
            ? round(($effectifTotal / $capaciteTotale) * 100, 1)
            : 0.0;

        return [
            'total_groupes' => $totalGroupes,
            'effectif_total' => $effectifTotal,
            'capacite_totale' => $capaciteTotale,
            'groupes_sans_capacite' => $groupesSansCapacite,
            'groupes_surcharges' => $groupesSurcharges,
            'taux_occupation_moyen' => $tauxOccupationMoyen,
        ];
    }

    /**
     * Audit complet des amphithéâtres et des cohortes d'examen selon le Mode B (Décision D-04).
     *
     * Applique la Décision D-04 :
     * - Capacité utile examen = 50% de la capacité physique (1 siège sur 2 / anti-fraude).
     * - Capacité nulle ou indéterminée = bloquante.
     * - Redécoupage virtuel des groupes sources en cohortes d'amphithéâtre optimisées.
     *
     * @return array{
     *     kpis: array{
     *         total_amphis: int,
     *         capacite_brute_amphis: int,
     *         capacite_utile_d04: int,
     *         cohortes_estimees: int,
     *         amphis_bloquants: int,
     *         taux_occupation_moyen_d04: float,
     *         ratio_espacement: float
     *     },
     *     amphitheatres: array<int, array{
     *         id: int,
     *         code: string,
     *         nom: string,
     *         batiment: string,
     *         etage: string,
     *         capacite_nominale: int,
     *         capacite_utile_d04: int,
     *         est_bloquante: bool,
     *         statut_d04: string,
     *         accessible_pmr: bool
     *     }>,
     *     cohortes_mode_b: array<int, array{
     *         code_cohorte: string,
     *         amphi_nom: string,
     *         amphi_code: string,
     *         capacite_nominale: int,
     *         capacite_utile_d04: int,
     *         effectif_convoque: int,
     *         groupes_sources: string,
     *         taux_remplissage: float,
     *         statut: string
     *     }>
     * }
     */
    public function auditerModeBAmphitheatres(?int $faculteId = null, ?string $recherche = null): array
    {
        $amphisQuery = Room::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->where(function ($q) {
                $q->where('type_salle', 'amphitheatre')
                    ->orWhere('nom', 'like', '%amphi%')
                    ->orWhere('code', 'like', '%amphi%')
                    ->orWhere('capacite', '>=', 50);
            })
            ->when($recherche !== null && $recherche !== '', function ($q) use ($recherche) {
                $q->where(fn ($sq) => $sq->where('code', 'like', "%{$recherche}%")->orWhere('nom', 'like', "%{$recherche}%"));
            })
            ->with('batimentRelation')
            ->orderByDesc('capacite');

        $amphis = $amphisQuery->get();

        $amphitheatres = [];
        $capaciteBruteTotale = 0;
        $capaciteUtileD04Totale = 0;
        $amphisBloquants = 0;

        foreach ($amphis as $amphi) {
            $capNominale = max(0, (int) ($amphi->capacite ?? 0));
            // Décision D-04 : ratio 50% anti-fraude (1 place sur 2)
            $capUtileD04 = (int) floor($capNominale * 0.50);
            $estBloquante = ($capNominale <= 0);

            if ($estBloquante) {
                $amphisBloquants++;
            } else {
                $capaciteBruteTotale += $capNominale;
                $capaciteUtileD04Totale += $capUtileD04;
            }

            $amphitheatres[] = [
                'id' => $amphi->id,
                'code' => $amphi->code,
                'nom' => $amphi->nom,
                'batiment' => $amphi->batimentRelation?->nom ?? $amphi->batiment ?? 'Campus Principal',
                'etage' => $amphi->etage ?? 'RDC',
                'capacite_nominale' => $capNominale,
                'capacite_utile_d04' => $capUtileD04,
                'est_bloquante' => $estBloquante,
                'statut_d04' => $estBloquante ? 'Capacité nulle bloquante' : 'Conforme D-04 (50%)',
                'accessible_pmr' => (bool) $amphi->accessible_pmr,
            ];
        }

        // Constitution algorithmique des Cohortes Spécifiques Mode B (Amphithéâtre)
        // en regroupant les groupes TD sources vers les jauges utiles D-04
        $groupesSources = Group::query()
            ->when($faculteId !== null, fn ($q) => $q->where('faculte_id', $faculteId))
            ->withCount('etudiants')
            ->orderBy('code')
            ->get();

        $cohortesModeB = [];
        $amphisDisponibles = collect($amphitheatres)->filter(fn ($a) => ! $a['est_bloquante'] && $a['capacite_utile_d04'] > 0)->values();
        $groupesPool = $groupesSources->all();

        $indexAmphi = 0;
        $totalAmphisValides = $amphisDisponibles->count();

        if ($totalAmphisValides > 0 && ! empty($groupesPool)) {
            while (! empty($groupesPool) && $indexAmphi < $totalAmphisValides) {
                $amphiCible = $amphisDisponibles[$indexAmphi];
                $capUtileMax = $amphiCible['capacite_utile_d04'];
                $cumulEffectif = 0;
                $groupesInclus = [];

                while (! empty($groupesPool)) {
                    $candidatGroupe = $groupesPool[0];
                    $effGroupe = max(1, (int) $candidatGroupe->etudiants_count);

                    if ($cumulEffectif + $effGroupe <= $capUtileMax || empty($groupesInclus)) {
                        $groupeChoisi = array_shift($groupesPool);
                        $groupesInclus[] = $groupeChoisi->code;
                        $cumulEffectif += $effGroupe;
                    } else {
                        break;
                    }
                }

                $tauxRemplissage = $capUtileMax > 0 ? round(($cumulEffectif / $capUtileMax) * 100, 1) : 0.0;
                $numCohorte = sprintf('%02d', $indexAmphi + 1);

                $cohortesModeB[] = [
                    'code_cohorte' => 'COH-D04-'.$amphiCible['code'].'-'.$numCohorte,
                    'amphi_nom' => $amphiCible['nom'],
                    'amphi_code' => $amphiCible['code'],
                    'capacite_nominale' => $amphiCible['capacite_nominale'],
                    'capacite_utile_d04' => $capUtileMax,
                    'effectif_convoque' => $cumulEffectif,
                    'groupes_sources' => implode(' + ', $groupesInclus),
                    'taux_remplissage' => $tauxRemplissage,
                    'statut' => $tauxRemplissage > 100 ? 'Surcharge D-04' : ($tauxRemplissage >= 80 ? 'Optimale' : 'Équilibrée'),
                ];

                $indexAmphi++;
            }
        }

        // Taux moyen d'occupation utile sur les amphis
        $totalEffectifCohortes = collect($cohortesModeB)->sum('effectif_convoque');
        $tauxMoyenD04 = $capaciteUtileD04Totale > 0
            ? round(($totalEffectifCohortes / $capaciteUtileD04Totale) * 100, 1)
            : 0.0;

        return [
            'kpis' => [
                'total_amphis' => count($amphitheatres),
                'capacite_brute_amphis' => $capaciteBruteTotale,
                'capacite_utile_d04' => $capaciteUtileD04Totale,
                'cohortes_estimees' => count($cohortesModeB),
                'amphis_bloquants' => $amphisBloquants,
                'taux_occupation_moyen_d04' => $tauxMoyenD04,
                'ratio_espacement' => 0.50,
            ],
            'amphitheatres' => $amphitheatres,
            'cohortes_mode_b' => $cohortesModeB,
        ];
    }
}
