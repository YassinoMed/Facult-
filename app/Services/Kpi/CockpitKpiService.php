<?php

namespace App\Services\Kpi;

use App\Models\AnneeUniversitaire;
use App\Models\AnomalieQualite;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\Tasks\Models\Task;
use App\Services\Kpi\DTO\DefinitionKpi;
use App\Services\Kpi\DTO\IndicateurKpi;
use App\Services\Kpi\DTO\SnapshotKpi;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Service central de calcul et réconciliation des KPI du Cockpit Scolarité.
 * Assure la cohérence stricte avec les sources de vérité, l'isolation facultaire
 * anti-IDOR, le diagnostic de fraîcheur SLA et la traçabilité des indicateurs.
 */
class CockpitKpiService
{
    public function __construct() {}

    /**
     * Calcule le snapshot complet de l'ensemble des KPI pour un périmètre donné.
     * Met en cache versionné (invalidation automatique dès qu'une table change).
     */
    public function calculerSnapshot(
        ?int $anneeId = null,
        ?int $faculteId = null,
        ?int $programmeId = null,
        ?User $utilisateur = null,
        bool $forcerRecalcul = false,
        int $ttl = 600,
    ): SnapshotKpi {
        $faculteEffective = $this->resoudreFaculte($utilisateur, $faculteId);
        $anneeEffective = $anneeId ?? (int) (AnneeUniversitaire::where('active', true)->value('id') ?? 0);

        $version = $this->versionEmpreinte();
        $cleCache = "cockpit_kpi:{$version}:a{$anneeEffective}:f{$faculteEffective}:p{$programmeId}";

        if (! $forcerRecalcul && Cache::has($cleCache)) {
            $donnees = Cache::get($cleCache);
            if ($donnees instanceof SnapshotKpi) {
                return $donnees;
            }
        }

        $definitions = CatalogueKpi::tous();
        $indicateurs = [];
        $alertes = ['critique' => 0, 'attention' => 0, 'normal' => 0, 'indetermine' => 0];
        $statutGlobal = 'a_jour';

        foreach ($definitions as $code => $definition) {
            $indicateur = $this->calculerIndicateur($definition, $anneeEffective ?: null, $faculteEffective, $programmeId);
            $indicateurs[$code] = $indicateur;
            $alertes[$indicateur->statutAlerte]++;

            if ($indicateur->statutFraicheur === 'perime' && $statutGlobal !== 'critique') {
                $statutGlobal = 'perime';
            }
        }

        $snapshot = new SnapshotKpi(
            version: $version,
            horodatage: now()->toIso8601String(),
            anneeId: $anneeEffective ?: null,
            faculteId: $faculteEffective,
            programmeId: $programmeId,
            indicateurs: $indicateurs,
            syntheseAlertes: $alertes,
            statutFraicheurGlobal: $statutGlobal,
        );

        Cache::put($cleCache, $snapshot, $ttl);

        return $snapshot;
    }

    /**
     * Empreinte MD5 versionnée basée sur les max(updated_at) et count()
     * des sources de vérité principales. Invalidation automatique sans vidage brut.
     */
    public function versionEmpreinte(): string
    {
        $inscriptions = InscriptionAcademique::max('updated_at').InscriptionAcademique::count();
        $etudiants = Etudiant::max('updated_at').Etudiant::count();

        $decisions = class_exists(DecisionDeliberation::class)
            ? DecisionDeliberation::max('updated_at').DecisionDeliberation::count() : '';
        $tasks = class_exists(Task::class)
            ? Task::max('updated_at').Task::count() : '';
        $anomalies = class_exists(AnomalieQualite::class)
            ? AnomalieQualite::max('updated_at').AnomalieQualite::count() : '';
        $demandes = class_exists(OnlineRequest::class)
            ? OnlineRequest::max('updated_at').OnlineRequest::count() : '';
        $epreuves = class_exists(Epreuve::class)
            ? Epreuve::max('updated_at').Epreuve::count() : '';
        $notes = class_exists(NoteEpreuve::class)
            ? NoteEpreuve::max('updated_at').NoteEpreuve::count() : '';

        return md5('kpi_v2|'.VersionKpi::courante()."|{$inscriptions}|{$etudiants}|{$decisions}|{$tasks}|{$anomalies}|{$demandes}|{$epreuves}|{$notes}");
    }

    /**
     * Calcule un indicateur spécifique avec diagnostic de fraîcheur et d'alerte.
     */
    public function calculerIndicateur(
        DefinitionKpi $def,
        ?int $anneeId,
        ?int $faculteId,
        ?int $programmeId
    ): IndicateurKpi {
        $valeur = null;
        $valeurFormatee = 'N/A';
        $derniereMaj = null;
        $explication = null;

        switch ($def->code) {
            case 'effectif_total':
                $q = Etudiant::query()
                    ->when($faculteId !== null, fn ($b) => $b->where('faculte_id', $faculteId))
                    ->when($programmeId !== null, fn ($b) => $b->where('programme_id', $programmeId));
                $valeur = $q->count();
                $valeurFormatee = (string) $valeur;
                $derniereMaj = (clone $q)->max('updated_at');
                break;

            case 'inscriptions_validees':
                $q = $this->queryInscriptions($anneeId, $faculteId, $programmeId)->where('statut', 'valide');
                $valeur = $q->count();
                $valeurFormatee = (string) $valeur;
                $derniereMaj = (clone $q)->max('updated_at');
                break;

            case 'inscriptions_en_attente':
                $q = $this->queryInscriptions($anneeId, $faculteId, $programmeId)
                    ->whereIn('statut', ['en_attente', 'soumise', 'en_cours']);
                $valeur = $q->count();
                $valeurFormatee = (string) $valeur;
                $derniereMaj = (clone $q)->max('updated_at');
                break;

            case 'taux_validation_inscriptions':
                $q = $this->queryInscriptions($anneeId, $faculteId, $programmeId);
                $total = (clone $q)->count();
                $valides = (clone $q)->where('statut', 'valide')->count();
                $derniereMaj = (clone $q)->max('updated_at');

                if ($total === 0) {
                    $valeur = null;
                    $valeurFormatee = 'N/A';
                    $explication = 'Aucune inscription enregistrée dans le périmètre.';
                } else {
                    $valeur = round(($valides / $total) * 100, 1);
                    $valeurFormatee = number_format($valeur, 1, ',', ' ').' %';
                    $explication = "{$valides} validées sur {$total} inscriptions totales.";
                }
                break;

            case 'taux_reussite_deliberations':
                $derniereMaj = null;

                if (! class_exists(DecisionDeliberation::class)) {
                    $valeur = null;
                    $valeurFormatee = 'N/A';
                    $explication = 'Modèle délibération absent.';
                    break;
                }

                $queryDecisions = DecisionDeliberation::where('publie', true)
                    ->when($anneeId !== null, fn ($q) => $q->whereHas('inscriptionAcademique', fn ($i) => $i->where('annee_universitaire_id', $anneeId)))
                    ->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))
                    ->when($programmeId !== null, fn ($q) => $q->whereHas('inscriptionAcademique', fn ($i) => $i->where('programme_id', $programmeId)));

                $derniereMaj = (clone $queryDecisions)->max('updated_at');

                $total = (clone $queryDecisions)->count();
                if ($total === 0) {
                    $valeur = null;
                    $valeurFormatee = 'N/A';
                    $explication = 'Aucune décision de délibération publiée.';
                } else {
                    $admis = (clone $queryDecisions)->whereIn('decision', ['admis', 'admis_compensation', 'admis_conditionnel'])->count();
                    $valeur = round(($admis / $total) * 100, 1);
                    $valeurFormatee = number_format($valeur, 1, ',', ' ').' %';
                    $explication = "{$admis} admis sur {$total} décisions publiées.";
                }
                break;

            case 'taux_validation_credits':
                $queryCredits = $this->queryInscriptions($anneeId, $faculteId, $programmeId);
                $totalCredits = (int) (clone $queryCredits)->sum('credits_total');
                $obtenus = (int) (clone $queryCredits)->sum('credits_obtenus');
                $derniereMaj = (clone $queryCredits)->max('updated_at');

                if ($totalCredits === 0) {
                    $valeur = null;
                    $valeurFormatee = 'N/A';
                    $explication = 'Aucun crédit renseigné dans le périmètre.';
                } else {
                    $valeur = round(($obtenus / $totalCredits) * 100, 1);
                    $valeurFormatee = number_format($valeur, 1, ',', ' ').' %';
                    $explication = "{$obtenus} crédits validés sur {$totalCredits} inscrits.";
                }
                break;

            case 'moyenne_mediane':
                $queryMoyennes = $this->queryInscriptions($anneeId, $faculteId, $programmeId)
                    ->whereNotNull('moyenne_annuelle');
                $moyennes = (clone $queryMoyennes)->pluck('moyenne_annuelle')->map(fn ($m) => (float) $m);
                $derniereMaj = (clone $queryMoyennes)->max('updated_at');

                if ($moyennes->count() < 5) {
                    $valeur = null;
                    $valeurFormatee = 'N/A';
                    $explication = 'Échantillon insuffisant pour une médiane représentative (n = '.$moyennes->count().' < 5).';
                } else {
                    $triees = $moyennes->sort()->values();
                    $effectif = $triees->count();
                    $milieu = intdiv($effectif, 2);
                    $mediane = $effectif % 2 === 1
                        ? (float) $triees->get($milieu)
                        : ((float) $triees->get($milieu - 1) + (float) $triees->get($milieu)) / 2;
                    $valeur = round($mediane, 2);
                    $valeurFormatee = number_format($valeur, 2, ',', ' ').' / 20';
                    $explication = 'Médiane calculée sur '.$moyennes->count().' étudiants.';
                }
                break;

            case 'taches_critiques_ouvertes':
                if (! class_exists(Task::class)) {
                    $valeur = 0;
                    $valeurFormatee = '0';
                    break;
                }
                $q = Task::where('priorite', 'critical')
                    ->whereIn('statut', ['pending', 'in_progress', 'waiting', 'expired'])
                    ->when($faculteId !== null, fn ($b) => $b->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteId)));
                $valeur = $q->count();
                $valeurFormatee = (string) $valeur;
                $derniereMaj = (clone $q)->max('updated_at');
                if ($anneeId !== null || $programmeId !== null) {
                    $explication = 'Compteur transversal : la table tasks ne porte ni année ni programme ; seul le périmètre facultaire est appliqué.';
                }
                break;

            case 'taches_en_retard_sla':
                if (! class_exists(Task::class)) {
                    $valeur = 0;
                    $valeurFormatee = '0';
                    break;
                }
                $q = Task::whereIn('statut', ['pending', 'in_progress', 'waiting'])
                    ->where('echeance_a', '<', now())
                    ->when($faculteId !== null, fn ($b) => $b->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteId)));
                $valeur = $q->count();
                $valeurFormatee = (string) $valeur;
                $derniereMaj = (clone $q)->max('updated_at');
                if ($anneeId !== null || $programmeId !== null) {
                    $explication = 'Compteur transversal : la table tasks ne porte ni année ni programme ; seul le périmètre facultaire est appliqué.';
                }
                break;

            case 'demandes_en_retard':
                if (! class_exists(OnlineRequest::class)) {
                    $valeur = 0;
                    $valeurFormatee = '0';
                    break;
                }
                $q = OnlineRequest::whereIn('status', ['submitted', 'received', 'in_review'])
                    ->when($anneeId !== null, fn ($b) => $b->where('annee_universitaire_id', $anneeId))
                    ->when($faculteId !== null, fn ($b) => $b->where('faculte_id', $faculteId))
                    ->when($programmeId !== null, fn ($b) => $b->whereHas('etudiant', fn ($e) => $e->where('programme_id', $programmeId)));
                $valeur = (clone $q)->get()
                    ->filter(fn ($d) => method_exists($d, 'enRetard') && $d->enRetard())
                    ->count();
                $valeurFormatee = (string) $valeur;
                $derniereMaj = (clone $q)->max('updated_at');
                break;

            case 'anomalies_qualite_ouvertes':
                if (! class_exists(AnomalieQualite::class)) {
                    $valeur = 0;
                    $valeurFormatee = '0';
                    $derniereMaj = null;
                    break;
                }
                $q = AnomalieQualite::whereIn('statut', ['ouverte', 'acquittee', 'en_cours'])
                    ->when($faculteId !== null, fn ($b) => $b->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteId)));
                $valeur = $q->count();
                $valeurFormatee = (string) $valeur;
                $derniereMaj = (clone $q)->max('updated_at');
                break;

            case 'anomalies_critiques':
                if (! class_exists(AnomalieQualite::class)) {
                    $valeur = 0;
                    $valeurFormatee = '0';
                    $derniereMaj = null;
                    break;
                }
                $q = AnomalieQualite::where('gravite', 'critique')
                    ->whereIn('statut', ['ouverte', 'acquittee', 'en_cours'])
                    ->when($faculteId !== null, fn ($b) => $b->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteId)));
                $valeur = $q->count();
                $valeurFormatee = (string) $valeur;
                $derniereMaj = (clone $q)->max('updated_at');
                break;

            case 'epreuves_sans_salle':
                if (! class_exists(Epreuve::class)) {
                    $valeur = 0;
                    $valeurFormatee = '0';
                    $derniereMaj = null;
                    break;
                }
                $q = Epreuve::whereNull('salle_id')->where('statut', '!=', 'cancelled');
                $this->appliquerPerimetreEpreuve($q, $anneeId, $faculteId, $programmeId);
                $valeur = $q->count();
                $valeurFormatee = (string) $valeur;
                $derniereMaj = (clone $q)->max('updated_at');
                break;

            case 'epreuves_sans_surveillant':
                if (! class_exists(Epreuve::class)) {
                    $valeur = 0;
                    $valeurFormatee = '0';
                    $derniereMaj = null;
                    break;
                }
                $q = Epreuve::where('statut', '!=', 'cancelled')
                    ->whereDoesntHave('surveillants', fn ($s) => $s->where('statut', '!=', 'annule'));
                $this->appliquerPerimetreEpreuve($q, $anneeId, $faculteId, $programmeId);
                $valeur = $q->count();
                $valeurFormatee = (string) $valeur;
                $derniereMaj = (clone $q)->max('updated_at');
                break;

            case 'notes_en_attente_validation':
                if (! class_exists(NoteEpreuve::class)) {
                    $valeur = 0;
                    $valeurFormatee = '0';
                    $derniereMaj = null;
                    break;
                }
                $q = NoteEpreuve::where('statut', 'submitted')
                    ->whereHas('epreuve', function ($e) use ($anneeId, $faculteId, $programmeId) {
                        $this->appliquerPerimetreEpreuve($e, $anneeId, $faculteId, $programmeId);
                    });
                $valeur = $q->count();
                $valeurFormatee = (string) $valeur;
                $derniereMaj = (clone $q)->max('updated_at');
                break;

            default:
                $valeur = null;
                $valeurFormatee = 'N/A';
                $explication = 'Indicateur non implémenté.';
        }

        $statutAlerte = $def->evaluerAlerte($valeur);
        $statutFraicheur = $this->evaluerFraicheur($derniereMaj, $def->slaFraicheurMinutes, $valeur !== null);

        return new IndicateurKpi(
            definition: $def,
            valeur: $valeur,
            valeurFormatee: $valeurFormatee,
            statutAlerte: $statutAlerte,
            statutFraicheur: $statutFraicheur,
            derniereMaj: $derniereMaj ? Carbon::parse($derniereMaj)->toIso8601String() : null,
            explication: $explication,
        );
    }

    /**
     * Fournit la requête Eloquent source sous-jacente pour un indicateur (drill-down),
     * en appliquant obligatoirement l'isolation facultaire.
     */
    public function donneesSources(
        string $codeKpi,
        ?int $anneeId = null,
        ?int $faculteId = null,
        ?int $programmeId = null,
        ?User $utilisateur = null
    ): Builder {
        $faculteEffective = $this->resoudreFaculte($utilisateur, $faculteId);
        $anneeEffective = $anneeId ?? (int) (AnneeUniversitaire::where('active', true)->value('id') ?? 0);

        return match ($codeKpi) {
            'effectif_total' => Etudiant::query()
                ->when($faculteEffective !== null, fn ($q) => $q->where('faculte_id', $faculteEffective))
                ->when($programmeId !== null, fn ($q) => $q->where('programme_id', $programmeId)),

            'inscriptions_validees' => $this->queryInscriptions($anneeEffective ?: null, $faculteEffective, $programmeId)
                ->where('statut', 'valide'),

            'inscriptions_en_attente' => $this->queryInscriptions($anneeEffective ?: null, $faculteEffective, $programmeId)
                ->whereIn('statut', ['en_attente', 'soumise', 'en_cours']),

            'taux_validation_inscriptions' => $this->queryInscriptions($anneeEffective ?: null, $faculteEffective, $programmeId),

            'taux_validation_credits' => $this->queryInscriptions($anneeEffective ?: null, $faculteEffective, $programmeId),

            'moyenne_mediane' => $this->queryInscriptions($anneeEffective ?: null, $faculteEffective, $programmeId)
                ->whereNotNull('moyenne_annuelle'),

            'taux_reussite_deliberations' => DecisionDeliberation::where('publie', true)
                ->when($anneeEffective ?: null, fn ($q) => $q->whereHas('inscriptionAcademique', fn ($i) => $i->where('annee_universitaire_id', $anneeEffective)))
                ->when($faculteEffective !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteEffective)))
                ->when($programmeId !== null, fn ($q) => $q->whereHas('inscriptionAcademique', fn ($i) => $i->where('programme_id', $programmeId))),

            'anomalies_qualite_ouvertes' => AnomalieQualite::whereIn('statut', ['ouverte', 'acquittee', 'en_cours'])
                ->when($faculteEffective !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteEffective))),

            'anomalies_critiques' => AnomalieQualite::where('gravite', 'critique')
                ->whereIn('statut', ['ouverte', 'acquittee', 'en_cours'])
                ->when($faculteEffective !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteEffective))),

            'taches_critiques_ouvertes' => Task::where('priorite', 'critical')
                ->whereIn('statut', ['pending', 'in_progress', 'waiting', 'expired'])
                ->when($faculteEffective !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteEffective))),

            'taches_en_retard_sla' => Task::whereIn('statut', ['pending', 'in_progress', 'waiting'])
                ->where('echeance_a', '<', now())
                ->when($faculteEffective !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('faculte_id')->orWhere('faculte_id', $faculteEffective))),

            'demandes_en_retard' => OnlineRequest::whereIn('status', ['submitted', 'received', 'in_review'])
                ->when($anneeEffective ?: null, fn ($q) => $q->where('annee_universitaire_id', $anneeEffective))
                ->when($faculteEffective !== null, fn ($q) => $q->where('faculte_id', $faculteEffective))
                ->when($programmeId !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('programme_id', $programmeId))),

            'epreuves_sans_salle' => tap(
                Epreuve::whereNull('salle_id')->where('statut', '!=', 'cancelled'),
                fn ($q) => $this->appliquerPerimetreEpreuve($q, $anneeEffective ?: null, $faculteEffective, $programmeId)
            ),

            'epreuves_sans_surveillant' => tap(
                Epreuve::where('statut', '!=', 'cancelled')
                    ->whereDoesntHave('surveillants', fn ($s) => $s->where('statut', '!=', 'annule')),
                fn ($q) => $this->appliquerPerimetreEpreuve($q, $anneeEffective ?: null, $faculteEffective, $programmeId)
            ),

            'notes_en_attente_validation' => NoteEpreuve::where('statut', 'submitted')
                ->whereHas('epreuve', fn ($e) => $this->appliquerPerimetreEpreuve($e, $anneeEffective ?: null, $faculteEffective, $programmeId)),

            default => throw new \InvalidArgumentException("Aucune source de drill-down mappée pour l’indicateur « {$codeKpi} »."),
        };
    }

    /**
     * Applique le périmètre année / faculté / programme à une requête d'épreuve.
     */
    private function appliquerPerimetreEpreuve(Builder $query, ?int $anneeId, ?int $faculteId, ?int $programmeId): void
    {
        $query
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->when($faculteId !== null, fn ($q) => $q->pourFaculteId($faculteId))
            ->when($programmeId !== null, fn ($q) => $q->whereHas(
                'matiere.ue.programme',
                fn ($p) => $p->where('programme_id', $programmeId)
            ));
    }

    /**
     * Résout la faculté en appliquant le principe anti-IDOR :
     * si l'utilisateur est restreint, sa faculté est forcée sans exception.
     */
    public function resoudreFaculte(?User $utilisateur, ?int $faculteDemandee): ?int
    {
        if ($utilisateur !== null && $utilisateur->faculteRestreinteId() !== null) {
            return $utilisateur->faculteRestreinteId();
        }

        return $faculteDemandee;
    }

    private function queryInscriptions(?int $anneeId, ?int $faculteId, ?int $programmeId): Builder
    {
        return InscriptionAcademique::query()
            ->when($anneeId !== null, fn ($q) => $q->where('annee_universitaire_id', $anneeId))
            ->when($faculteId !== null, fn ($q) => $q->whereHas('etudiant', fn ($e) => $e->where('faculte_id', $faculteId)))
            ->when($programmeId !== null, fn ($q) => $q->where('programme_id', $programmeId));
    }

    private function evaluerFraicheur(?string $derniereMaj, int $slaMinutes, bool $donneesPresentes): string
    {
        if (! $donneesPresentes || $derniereMaj === null) {
            return 'donnees_insuffisantes';
        }

        $date = Carbon::parse($derniereMaj);
        if ($date->diffInMinutes(now()) > $slaMinutes) {
            return 'perime';
        }

        return 'a_jour';
    }
}
