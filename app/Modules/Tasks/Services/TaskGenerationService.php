<?php

namespace App\Modules\Tasks\Services;

use App\Models\DossierCorrection;
use App\Models\DossierVerification;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\RegistrationPeriod;
use App\Modules\Attendance\Models\Justification;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Finances\Models\Facture;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\Tasks\Models\Task;
use App\Services\PeriodeInscriptionService;

/**
 * Génération idempotente (§9.5-9.6) : les émetteurs existants
 * (SLA, justifications, délibérations, épreuves, inscriptions)
 * deviennent des tâches actionnables. `cle_unique` = une source = une tâche active.
 */
class TaskGenerationService
{
    public function __construct(private TaskService $taches) {}

    /** @return array<string, int> */
    public function generer(): array
    {
        $compteurs = [
            'inscriptions' => 0,
            'demandes' => 0,
            'justifications' => 0,
            'deliberations' => 0,
            'epreuves' => 0,
            'sans_groupe' => 0,
            'factures' => 0,
        ];

        $clesInscriptions = $this->synchroniserInscriptions();
        $compteurs['inscriptions'] = count($clesInscriptions);

        $clesSansGroupe = $this->synchroniserSansGroupe();
        $compteurs['sans_groupe'] = count($clesSansGroupe);

        $clesFactures = $this->synchroniserFactures();
        $compteurs['factures'] = count($clesFactures);

        $clesDemandes = [];
        foreach ($this->demandesEnRetard() as $demande) {
            $cle = "demande_sla:{$demande->id}";
            $clesDemandes[] = $cle;
            $this->taches->synchroniser($cle, [
                'titre' => "Demande {$demande->reference} en retard",
                'description' => "{$demande->type->name} — délai dépassé.",
                'source' => 'demande_sla',
                'priorite' => 'critical',
                'echeance_a' => $demande->echeance(),
                'lien' => route('admin.demandes.show', $demande),
                'faculte_id' => $demande->faculte_id,
                'assigne_a' => $demande->assigned_to,
            ]);
            $compteurs['demandes']++;
        }

        $clesJustifications = [];
        foreach ($this->justificationsEnAttente() as $justification) {
            $cle = "justification:{$justification->id}";
            $clesJustifications[] = $cle;
            $this->taches->synchroniser($cle, [
                'titre' => "Justification à vérifier — {$justification->etudiant->nom_complet}",
                'description' => mb_substr($justification->motif, 0, 200),
                'source' => 'justification',
                'priorite' => 'high',
                'lien' => route('admin.justifications.index'),
                'faculte_id' => $justification->etudiant->faculte_id,
            ]);
            $compteurs['justifications']++;
        }

        $clesDeliberations = [];
        foreach ($this->deliberationsBloquees() as $deliberation) {
            $cle = "deliberation:{$deliberation->id}";
            $clesDeliberations[] = $cle;
            $this->taches->synchroniser($cle, [
                'titre' => 'Délibération bloquée par des anomalies',
                'description' => count($deliberation->anomaliesBloquantes()).' anomalie(s) bloquante(s).',
                'source' => 'deliberation',
                'priorite' => 'high',
                'lien' => route('admin.evaluations.deliberations.show', $deliberation),
            ]);
            $compteurs['deliberations']++;
        }

        $clesEpreuves = [];
        foreach ($this->epreuvesSansSalle() as $epreuve) {
            $cle = "epreuve_salle:{$epreuve->id}";
            $clesEpreuves[] = $cle;
            $this->taches->synchroniser($cle, [
                'titre' => "Épreuve sans salle — {$epreuve->matiere->code}",
                'description' => "Le {$epreuve->date->format('d/m/Y')} à ".substr((string) $epreuve->heure_debut, 0, 5).'.',
                'source' => 'epreuve_salle',
                'priorite' => 'normal',
                'echeance_a' => $epreuve->date,
                'lien' => route('admin.evaluations.epreuves.show', $epreuve),
            ]);
            $compteurs['epreuves']++;
        }

        $compteurs['cloturees'] = $this->taches->cloturerResolues('inscription', $clesInscriptions)
            + $this->taches->cloturerResolues('demande_sla', $clesDemandes)
            + $this->taches->cloturerResolues('justification', $clesJustifications)
            + $this->taches->cloturerResolues('deliberation', $clesDeliberations)
            + $this->taches->cloturerResolues('epreuve_salle', $clesEpreuves)
            + $this->taches->cloturerResolues('sans_groupe', $clesSansGroupe)
            + $this->taches->cloturerResolues('facture_impayee', $clesFactures);

        return $compteurs;
    }

    /**
     * Inscriptions académiques nécessitant un contrôle / instruction administrative.
     * Idempotent : cle_unique = "inscription:{$inscription->id}".
     *
     * @return array<int, string>
     */
    public function synchroniserInscriptions(?int $faculteId = null, bool $cloturerResolues = false): array
    {
        $cles = [];

        $query = InscriptionAcademique::with(['etudiant', 'anneeUniversitaire', 'programmeRef'])
            ->where('statut', 'en_cours')
            ->whereHas('etudiant', function ($q) use ($faculteId) {
                $q->where('statut', 'en_attente');
                if ($faculteId !== null) {
                    $q->where('faculte_id', $faculteId);
                }
            });

        $inscriptions = $query->get();

        if ($inscriptions->isEmpty()) {
            if ($cloturerResolues) {
                $this->taches->cloturerResolues('inscription', [], $faculteId);
            }

            return [];
        }

        $etudiantIds = $inscriptions->pluck('etudiant_id')->filter()->unique()->all();

        // 1. Compléments ouverts en attente (DossierCorrection)
        $idsAvecComplement = ! empty($etudiantIds)
            ? DossierCorrection::whereIn('etudiant_id', $etudiantIds)
                ->where('statut', 'proposee')
                ->pluck('etudiant_id')
                ->flip()
                ->all()
            : [];

        // 2. Derniers verdicts des vérifications (DossierVerification)
        $derniersRuns = ! empty($etudiantIds)
            ? DossierVerification::whereIn('etudiant_id', $etudiantIds)
                ->whereIn('id', function ($sub) use ($etudiantIds) {
                    $sub->selectRaw('MAX(id)')
                        ->from('dossier_verifications')
                        ->whereIn('etudiant_id', $etudiantIds)
                        ->groupBy('etudiant_id');
                })
                ->get(['etudiant_id', 'verdict', 'regles_echouees'])
                ->keyBy('etudiant_id')
            : collect();

        $maintenant = now();

        foreach ($inscriptions as $inscription) {
            $etudiant = $inscription->etudiant;
            if ($etudiant === null) {
                continue;
            }

            $cle = "inscription:{$inscription->id}";

            // Respect du cycle de vie existant : ne jamais rouvrir implicitement
            // une tâche déjà clôturée, annulée ou expirée par un gestionnaire.
            $tacheExistante = Task::where('cle_unique', $cle)->first();
            if ($tacheExistante !== null && ! $tacheExistante->estActive()) {
                $cles[] = $cle;

                continue;
            }

            // Calcul de priorité selon les règles documentées :
            // 1. 'critical' : urgence réelle liée à une période officielle expirant dans < 48h (futur strictly <= 48h)
            $echeance = null;
            $estCritique = false;

            if ($inscription->anneeUniversitaire !== null) {
                try {
                    $periodeInfo = app(PeriodeInscriptionService::class)->evaluer(
                        $etudiant, $inscription->anneeUniversitaire, $inscription->type ?? 'premiere'
                    );

                    if (! empty($periodeInfo['periode_id'])) {
                        $periodeModel = RegistrationPeriod::find($periodeInfo['periode_id']);
                        $echeance = $periodeModel?->closed_at;
                        if ($echeance !== null && $echeance->greaterThan($maintenant) && $echeance->lessThanOrEqualTo($maintenant->copy()->addHours(48))) {
                            $estCritique = true;
                        }
                    }
                } catch (\Throwable) {
                    // Fail-soft : ne jamais bloquer la synchronisation sur une erreur de période
                }
            }

            // 2. 'high' : blocage métier confirmé OU attente >= 7 jours
            $joursAttente = $inscription->created_at ? (int) $inscription->created_at->diffInDays($maintenant) : 0;
            $heuresAttente = $inscription->created_at ? (int) $inscription->created_at->diffInHours($maintenant) : 0;
            $estEnSouffrance = $heuresAttente >= 48;

            $run = $derniersRuns->get($etudiant->id);
            $aBlocage = ($run?->verdict === 'BLOQUANT') || isset($idsAvecComplement[$etudiant->id]);

            if ($estCritique) {
                $priorite = 'critical';
            } elseif ($aBlocage || $joursAttente >= 7) {
                $priorite = 'high';
            } else {
                $priorite = 'normal';
            }

            $titre = "Contrôle inscription — {$etudiant->nom_complet}";
            $details = array_filter([
                $etudiant->matricule_ou_cin,
                $inscription->niveau ?? $etudiant->niveau,
                $inscription->programmeRef?->nom ?? $etudiant->filiere,
                $estEnSouffrance ? "En attente depuis {$heuresAttente}h" : null,
                $aBlocage ? 'Blocage détecté' : null,
            ]);
            $description = implode(' · ', $details);

            $lien = route('admin.inscriptions.file-traitement', [
                'search' => $etudiant->cin ?: $etudiant->nom,
            ]);

            $this->taches->synchroniser($cle, [
                'titre' => $titre,
                'description' => mb_substr($description, 0, 255),
                'source' => 'inscription',
                'priorite' => $priorite,
                'echeance_a' => $echeance,
                'lien' => $lien,
                'data' => [
                    'inscription_id' => $inscription->id,
                    'etudiant_id' => $etudiant->id,
                    'annee_universitaire_id' => $inscription->annee_universitaire_id,
                    'est_en_souffrance' => $estEnSouffrance,
                    'heures_attente' => $heuresAttente,
                    'jours_attente' => $joursAttente,
                    'bloque' => $aBlocage,
                ],
                'faculte_id' => $etudiant->faculte_id,
            ]);

            $cles[] = $cle;
        }

        if ($cloturerResolues) {
            $this->taches->cloturerResolues('inscription', $cles, $faculteId);
        }

        return $cles;
    }

    /**
     * Factures impayées échues : bascule `overdue` + tâche de relance.
     * Idempotent, module finances optionnel.
     *
     * @return array<int, string>
     */
    private function synchroniserFactures(): array
    {
        if (! config('modules.finances.active') || ! class_exists(Facture::class)) {
            return [];
        }

        $cles = [];

        $factures = Facture::with('etudiant')
            ->whereIn('statut', ['issued', 'partially_paid'])
            ->whereNotNull('date_echeance')
            ->whereDate('date_echeance', '<', now()->format('Y-m-d'))
            ->cursor();

        foreach ($factures as $facture) {
            if ($facture->resteCentimes() === 0) {
                continue;
            }

            if ($facture->peutTransiterVers('overdue')) {
                $facture->update(['statut' => 'overdue']);
            }

            $cle = "facture_impayee:{$facture->id}";
            $cles[] = $cle;
            $this->taches->synchroniser($cle, [
                'titre' => "Relancer {$facture->reference} — ".Facture::formater($facture->resteCentimes()),
                'description' => $facture->etudiant?->nom_complet,
                'source' => 'facture_impayee',
                'priorite' => 'high',
                'echeance_a' => $facture->date_echeance,
                'lien' => route('admin.finances.factures.show', $facture),
                'faculte_id' => $facture->etudiant?->faculte_id,
            ]);
        }

        return $cles;
    }

    /**
     * Étudiants validés sans groupe : dossier → tâche → affectation →
     * planning → notification (§13 exemple 1).
     *
     * @return array<int, string>
     */
    private function synchroniserSansGroupe(): array
    {
        $cles = [];

        foreach (Etudiant::where('statut', 'valide')->whereNull('groupe_id')->cursor() as $etudiant) {
            $cle = "sans_groupe:{$etudiant->id}";
            $cles[] = $cle;
            $this->taches->synchroniser($cle, [
                'titre' => "Affecter {$etudiant->nom_complet} à un groupe",
                'description' => 'Sans groupe : planning indisponible.',
                'source' => 'sans_groupe',
                'priorite' => 'high',
                'lien' => route('admin.etudiants.show', $etudiant),
                'faculte_id' => $etudiant->faculte_id,
            ]);
        }

        return $cles;
    }

    /** @return iterable<OnlineRequest> */
    private function demandesEnRetard(): iterable
    {
        if (! config('modules.online-requests.active')) {
            return [];
        }

        return OnlineRequest::with(['type', 'etudiant'])
            ->whereIn('status', ['submitted', 'received', 'in_review'])
            ->cursor()
            ->filter(fn (OnlineRequest $d) => $d->enRetard());
    }

    /** @return iterable<Justification> */
    private function justificationsEnAttente(): iterable
    {
        if (! config('modules.attendance.active')) {
            return [];
        }

        return Justification::with('etudiant')
            ->where('statut', 'pending')
            ->cursor();
    }

    /** @return iterable<mixed> */
    private function deliberationsBloquees(): iterable
    {
        if (! config('modules.evaluation.active') || ! class_exists(Deliberation::class)) {
            return [];
        }

        return Deliberation::whereIn('statut', ['prepared', 'in_review'])
            ->cursor()
            ->filter(fn ($d) => $d->anomaliesBloquantes() !== []);
    }

    /** @return iterable<mixed> */
    private function epreuvesSansSalle(): iterable
    {
        if (! config('modules.evaluation.active') || ! class_exists(Epreuve::class)) {
            return [];
        }

        return Epreuve::with('matiere')
            ->whereNull('salle_id')
            ->where('statut', '!=', 'cancelled')
            ->whereDate('date', '>=', now()->format('Y-m-d'))
            ->whereDate('date', '<=', now()->addDays(14)->format('Y-m-d'))
            ->cursor();
    }
}
