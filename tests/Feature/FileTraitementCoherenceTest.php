<?php

namespace Tests\Feature;

use App\Models\DossierCorrection;
use App\Models\DossierVerification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\User;
use App\Services\FileTraitementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase E — Cohérence KPI/liste, priorité des actions, traçabilité répétée.
 */
class FileTraitementCoherenceTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private User $scolarite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.coh@test.tn', 'password' => 'Secret123!',
        ], 'admin_scolarite');
    }

    private function dossier(string $nom, string $statut = 'en_attente'): Etudiant
    {
        static $n = 1;

        $e = Etudiant::create([
            'nom' => $nom, 'prenom' => 'P', 'email' => "c{$n}@test.tn",
            'cin' => sprintf('750000%02d', $n++), 'sexe' => 'M', 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'adr', 'ville' => 'Tunis',
            'code_postal' => '1000', 'annee_bac' => '2025', 'faculte_id' => $this->faculte->id,
        ]);
        if ($statut !== 'en_attente') {
            $e->definirStatut($statut);
        }

        return $e;
    }

    private function marquerComplement(Etudiant $e): void
    {
        DossierCorrection::create([
            'etudiant_id' => $e->id, 'champ' => 'nom', 'ancienne_valeur' => 'x',
            'nouvelle_valeur' => 'y', 'statut' => 'proposee', 'motif' => 'm',
            'propose_par' => $this->scolarite->id,
        ]);
    }

    private function marquerAnomalie(Etudiant $e): void
    {
        DossierVerification::create([
            'etudiant_id' => $e->id, 'verdict' => 'BLOQUANT', 'score' => 40,
            'regles_verifiees' => 10, 'regles_reussies' => 5, 'anomalies_count' => 1,
            'duree_ms' => 10, 'source' => 'web',
        ]);
    }

    private function totalListe(string $action): int
    {
        return $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement', ['action_attendue' => $action]))
            ->viewData('etudiants')->total();
    }

    public function test_kpis_egaux_aux_listes_filtrees_y_compris_cumul(): void
    {
        $this->dossier('Plaine'); // A_INSTRUIRE
        $seulComplement = $this->dossier('SeulComp');
        $this->marquerComplement($seulComplement);
        $seuleAnomalie = $this->dossier('SeuleAno');
        $this->marquerAnomalie($seuleAnomalie);
        $cumul = $this->dossier('Cumul'); // complément + anomalie : UN seul dossier
        $this->marquerComplement($cumul);
        $this->marquerAnomalie($cumul);

        $kpis = app(FileTraitementService::class)->kpis($this->scolarite, $this->faculte->id);

        // Chaque compteur KPI égale le total de la liste filtrée correspondante.
        foreach (FileTraitementService::ACTIONS_ATTENDUES as $action => $libelle) {
            $this->assertSame(
                $this->totalListe($action),
                $kpis['actions'][$action],
                "Incohérence KPI/liste pour {$action}."
            );
        }
        $this->assertSame(1, $kpis['actions'][FileTraitementService::ACTION_A_INSTRUIRE]);
    }

    public function test_kpis_actions_egaux_aux_listes_sous_tous_les_filtres(): void
    {
        $this->dossier('FiltreA'); // en attente, prêt
        $complement = $this->dossier('FiltreB');
        $this->marquerComplement($complement);
        DB::table('etudiants')->where('id', $complement->id)
            ->update(['created_at' => now()->subDays(10)]);

        $anomalie = $this->dossier('FiltreC');
        $this->marquerAnomalie($anomalie);

        $this->dossier('FiltreD', 'valide');
        $this->dossier('FiltreE', 'rejete');

        $cas = [
            ['search' => 'Filtre'],
            ['search' => 'FiltreA'],
            ['statut' => 'en_attente'],
            ['statut' => 'valide'],
            ['statut' => 'rejete'],
            ['categorie' => FileTraitementService::CATEGORIE_READY_FOR_REVIEW],
            ['categorie' => FileTraitementService::CATEGORIE_MISSING_DOCUMENTS],
            ['categorie' => FileTraitementService::CATEGORIE_BLOCKED],
            ['categorie' => FileTraitementService::CATEGORIE_READY_FOR_ASSIGNMENT],
            ['categorie' => FileTraitementService::CATEGORIE_COMPLETED],
            ['depose_depuis' => 7],
        ];

        foreach ($cas as $filtres) {
            $base = $this->actingAs($this->scolarite)
                ->get(route('admin.inscriptions.file-traitement', $filtres))->assertOk();
            $kpis = $base->viewData('kpis');
            $contexte = json_encode($filtres);

            $this->assertSame(
                $base->viewData('etudiants')->total(),
                $kpis['total'],
                'KPI total/liste sous filtres '.$contexte
            );

            foreach (FileTraitementService::ACTIONS_ATTENDUES as $action => $libelle) {
                $liste = $this->actingAs($this->scolarite)
                    ->get(route('admin.inscriptions.file-traitement', array_merge($filtres, [
                        'action_attendue' => $action,
                    ])))->assertOk()->viewData('etudiants')->total();

                $this->assertSame(
                    $liste,
                    $kpis['actions'][$action],
                    "KPI {$action}/liste sous filtres ".$contexte
                );
            }

            // Le compteur « en attente » est le total de la liste avec le
            // même périmètre ; on ne l'ajoute pas à un statut contradictoire.
            if (! isset($filtres['statut'])) {
                $enAttente = $this->actingAs($this->scolarite)
                    ->get(route('admin.inscriptions.file-traitement', array_merge($filtres, [
                        'statut' => 'en_attente',
                    ])))->assertOk()->viewData('etudiants')->total();

                $this->assertSame(
                    $enAttente,
                    $kpis['en_attente'],
                    'KPI en_attente/liste sous filtres '.$contexte
                );
            }
        }
    }

    public function test_correction_approuvee_visible_dans_la_file_sans_bloquer_prematurement(): void
    {
        $etudiant = $this->dossier('Approuvee');

        DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'champ' => 'email',
            'ancienne_valeur' => 'ancien@test.tn',
            'nouvelle_valeur' => 'nouveau@test.tn',
            'statut' => 'approuvee',
            'motif' => 'Correction validée en attente d’application',
            'propose_par' => $this->scolarite->id,
            'decide_par' => $this->scolarite->id,
            'decide_le' => now(),
        ]);

        $reponse = $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.file-traitement'))->assertOk();

        // D-07 : la correction approuvée est visible avec le champ concerné.
        $reponse->assertSee('À appliquer : email');

        // M-03 n'étant pas arbitré, elle n'est pas encore comptée comme
        // complément bloquant : le dossier reste instruisible.
        $this->assertSame(0, $reponse->viewData('kpis')['actions']['COMPLEMENT_ATTENDU']);
    }

    public function test_commentaire_repete_trace_chaque_envoi(): void
    {
        $etudiant = $this->dossier('Repete');
        $route = route('admin.inscriptions.file-traitement.commenter', $etudiant);

        // Comportement actuel documenté : pas d'idempotence serveur (hors
        // périmètre : pas de clés d'idempotence sur web) — chaque envoi
        // est tracé ; le throttle et l'anti-double-submit limitent les doublons.
        $this->actingAs($this->scolarite)->post($route, ['commentaire' => 'Première note de suivi.']);
        $this->actingAs($this->scolarite)->post($route, ['commentaire' => 'Première note de suivi.']);

        $this->assertSame(2, HistoriqueAcademique::where('etudiant_id', $etudiant->id)
            ->where('action', HistoriqueAcademique::ACTION_COMMENTAIRE_INSTRUCTION)->count());
    }

    /**
     * Volumétrie représentative (environnement de test SQLite — mesure
     * indicative, pas une mesure de production).
     */
    public function test_volumetrie_300_dossiers_bornee(): void
    {
        for ($i = 1; $i <= 300; $i++) {
            $e = $this->dossier('Vol'.$i, $i % 3 === 0 ? 'valide' : 'en_attente');
            if ($i % 7 === 0) {
                $this->marquerComplement($e);
            }
            if ($i % 11 === 0) {
                $this->marquerAnomalie($e);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $debut = microtime(true);

        $reponse = $this->actingAs($this->scolarite)->get(route('admin.inscriptions.file-traitement'));

        $dureeMs = (int) ((microtime(true) - $debut) * 1000);
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $reponse->assertOk();
        fwrite(STDERR, "\n[VOLUMETRIE-FILE] 300 dossiers : {$dureeMs} ms, {$requetes} requêtes (SQLite test).\n");

        // Bornes généreuses anti-régression (page de 12 + KPIs).
        $this->assertLessThan(80, $requetes);
        $this->assertLessThan(15000, $dureeMs);
    }
}
