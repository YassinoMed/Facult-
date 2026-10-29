<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\AffectationAutomatiqueService;
use App\Services\DataExchange\Definitions\InscriptionAcademiqueDefinition;
use App\Services\DataExchange\Definitions\ProgressionDefinition;
use App\Services\InscriptionService;
use App\Services\Io\InscriptionsAcademiquesImporter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * LOOP-INSCRIPTION-UNIQUE-ANNUELLE-01 — règle métier : au plus UNE
 * inscription académique par couple (étudiant, année universitaire).
 *
 * Couvre : garde centralisée (`InscriptionService`), refus de la
 * deuxième inscription avec message explicite sans donnée personnelle,
 * autorisation l'année suivante et pour d'autres étudiants, statuts et
 * suppressions (aucune logique sur cette table), contrainte UNIQUE RÉELLE
 * en base (index + insertion brute refusée), import legacy, régularisation
 * idempotente, affectation automatique idempotente, imports DataExchange
 * (upsert sans doublon).
 */
class UniciteInscriptionAnnuelleTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private AnneeUniversitaire $annee;

    private AnneeUniversitaire $anneeSuivante;

    protected function setUp(): void
    {
        parent::setUp();

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-07-15', 'active' => true,
        ]);
        $this->anneeSuivante = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-07-15', 'active' => false,
        ]);
    }

    // ── Règle centrale (service métier) ─────────────────────────────

    public function test_premiere_inscription_autorisee_deuxieme_refusee(): void
    {
        $etudiant = $this->etudiantAvecDossier();
        $service = app(InscriptionService::class);

        $premiere = $service->creerInscriptionAnnuelle($etudiant->id, $this->annee->id, ['niveau' => 'L1']);

        $this->assertSame('en_cours', $premiere->fresh()->statut);

        try {
            $service->creerInscriptionAnnuelle($etudiant->id, $this->annee->id, ['niveau' => 'L1']);
            $this->fail('Le refus métier attendu (unicité annuelle) n’a pas été levé.');
        } catch (\DomainException $e) {
            $this->assertSame(InscriptionService::MESSAGE_INSCRIPTION_ANNUELLE_EXISTANTE, $e->getMessage());
            // Message utilisateur explicite SANS donnée personnelle.
            $this->assertStringNotContainsString((string) $etudiant->nom, $e->getMessage());
            $this->assertStringNotContainsString((string) $etudiant->cin, $e->getMessage());
            $this->assertStringNotContainsString((string) $etudiant->num_inscription, $e->getMessage());
        }

        $this->assertSame(1, InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $this->annee->id)->count());
    }

    public function test_etudiants_differents_meme_annee_autorises(): void
    {
        $service = app(InscriptionService::class);

        $a = $this->etudiantAvecDossier();
        $b = $this->etudiantAvecDossier();

        $service->creerInscriptionAnnuelle($a->id, $this->annee->id, ['niveau' => 'L1']);
        $service->creerInscriptionAnnuelle($b->id, $this->annee->id, ['niveau' => 'L1']);

        $this->assertSame(2, InscriptionAcademique::where('annee_universitaire_id', $this->annee->id)->count());
    }

    public function test_meme_etudiant_annee_suivante_autorisee(): void
    {
        $etudiant = $this->etudiantAvecDossier();
        $service = app(InscriptionService::class);

        $service->creerInscriptionAnnuelle($etudiant->id, $this->annee->id, ['niveau' => 'L1']);

        $deuxieme = $service->reinscrire($etudiant, $this->anneeSuivante, null);

        $this->assertSame('reinscription', $deuxieme->type);
        $this->assertSame($this->anneeSuivante->id, (int) $deuxieme->annee_universitaire_id);

        // Réinscription rejouée pour la même année : refus métier unique.
        try {
            $service->reinscrire($etudiant, $this->anneeSuivante, null);
            $this->fail('La réinscription en doublon aurait dû être refusée.');
        } catch (\DomainException $e) {
            $this->assertSame(InscriptionService::MESSAGE_INSCRIPTION_ANNUELLE_EXISTANTE, $e->getMessage());
        }

        $this->assertSame(2, $etudiant->inscriptionsAcademiques()->count());
    }

    public function test_statuts_abandon_et_ajourne_ne_neutralisent_pas_la_regle(): void
    {
        $service = app(InscriptionService::class);

        foreach (['abandon', 'ajourne'] as $statut) {
            $etudiant = $this->etudiantAvecDossier();
            $service->creerInscriptionAnnuelle($etudiant->id, $this->annee->id, ['niveau' => 'L1', 'statut' => $statut]);

            $refuse = false;
            try {
                $service->creerInscriptionAnnuelle($etudiant->id, $this->annee->id, ['niveau' => 'L1']);
            } catch (\DomainException) {
                $refuse = true;
            }

            $this->assertTrue($refuse, "Statut {$statut} : la deuxième inscription doit rester refusée.");
            $this->assertSame(1, InscriptionAcademique::where('etudiant_id', $etudiant->id)->count());
        }
    }

    public function test_suppression_physique_libere_la_paire_aucune_suppression_logique(): void
    {
        // Schéma réel : AUCUN `deleted_at` sur inscriptions_academiques —
        // la contrainte UNIQUE simple est donc la forme adaptée (une
        // « suppression » y est physique et libère la paire).
        $this->assertFalse(
            Schema::hasColumn('inscriptions_academiques', 'deleted_at'),
            'Aucune suppression logique attendue sur inscriptions_academiques.'
        );

        $etudiant = $this->etudiantAvecDossier();
        $service = app(InscriptionService::class);

        $inscription = $service->creerInscriptionAnnuelle($etudiant->id, $this->annee->id, ['niveau' => 'L1']);
        $inscription->delete();

        $nouvelle = $service->creerInscriptionAnnuelle($etudiant->id, $this->annee->id, ['niveau' => 'L1']);

        $this->assertNotSame($inscription->id, $nouvelle->id);
        $this->assertSame(1, InscriptionAcademique::where('etudiant_id', $etudiant->id)->count());
    }

    // ── Contrainte RÉELLE en base ───────────────────────────────────

    public function test_contrainte_unique_presente_et_infranchissable_en_base(): void
    {
        $index = collect(Schema::getIndexes('inscriptions_academiques'))->first(function (array $index): bool {
            if (! ($index['unique'] ?? false)) {
                return false;
            }
            $colonnes = $index['columns'] ?? [];
            sort($colonnes);

            return $colonnes === ['annee_universitaire_id', 'etudiant_id'];
        });

        $this->assertNotNull(
            $index,
            'Index UNIQUE (etudiant_id, annee_universitaire_id) attendu sur inscriptions_academiques.'
        );

        $etudiant = $this->etudiantAvecDossier();
        $ligne = [
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('inscriptions_academiques')->insert($ligne);

        // Filet final : même en contournant le service, la base refuse la
        // deuxième ligne — c'est cette garantie qui rend la concurrence sûre.
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('inscriptions_academiques')->insert($ligne);
    }

    // ── Formulaire admin (HTTP) ─────────────────────────────────────

    public function test_http_admin_refuse_le_doublon_avec_message_explicite(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-UNIQ-1',
            'date_archivage' => now()->toDateString(),
        ]);

        $this->post(route('admin.archives.inscriptions.store', $dossier), [
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
        ])->assertRedirect();

        $this->post(route('admin.archives.inscriptions.store', $dossier), [
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L2',
        ])->assertSessionHasErrors(['annee_universitaire_id' => InscriptionService::MESSAGE_INSCRIPTION_ANNUELLE_EXISTANTE]);

        $this->assertDatabaseCount('inscriptions_academiques', 1);

        // L'année suivante reste possible depuis le même formulaire.
        $this->post(route('admin.archives.inscriptions.store', $dossier), [
            'annee_universitaire_id' => $this->anneeSuivante->id,
            'niveau' => 'L2',
        ])->assertRedirect();

        $this->assertDatabaseCount('inscriptions_academiques', 2);
    }

    // ── Import legacy ───────────────────────────────────────────────

    public function test_import_legacy_ignore_le_doublon(): void
    {
        $etudiant = $this->etudiantAvecDossier();
        DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-IMPU-1',
            'date_archivage' => now()->toDateString(),
        ]);

        $lignes = [[
            'matricule' => 'MAT-IMPU-1',
            'annee_universitaire' => '2025-2026',
            'niveau' => 'L1',
            'groupe' => 'G1',
            'statut' => 'valide',
            'observation' => '',
        ]];

        $importer = app(InscriptionsAcademiquesImporter::class);

        $premier = $importer->importer($lignes, 'creer');
        $this->assertSame(1, $premier['importes']);

        // Réimport : le doublon est détecté et ignoré, jamais dupliqué.
        $deuxieme = $importer->importer($lignes, 'creer');
        $this->assertSame(0, $deuxieme['importes']);
        $this->assertSame(1, $deuxieme['ignores']);

        $this->assertSame(1, InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $this->annee->id)->count());
        $this->assertSame(2, DB::table('semestres')->count());
    }

    // ── Régularisation en masse ─────────────────────────────────────

    public function test_regularisation_idempotente(): void
    {
        $etudiant = $this->etudiantAvecDossier();
        $service = app(InscriptionService::class);

        $premier = $service->regulariserInscriptionsManquantes($this->annee->id);
        $this->assertSame(1, $premier['total_candidats']);
        $this->assertSame(1, $premier['crees']);
        $this->assertSame([], $premier['erreurs']);

        // Deuxième exécution : plus aucun candidat, aucun doublon créé.
        $deuxieme = $service->regulariserInscriptionsManquantes($this->annee->id);
        $this->assertSame(0, $deuxieme['total_candidats']);
        $this->assertSame(0, $deuxieme['crees']);

        $this->assertSame(1, InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $this->annee->id)->count());
    }

    // ── Workflow : affectation automatique ──────────────────────────

    public function test_affectation_automatique_n_ecrit_qu_une_inscription(): void
    {
        $etudiant = $this->etudiantAvecDossier();
        $service = app(AffectationAutomatiqueService::class);

        // Provisionnement déclenché deux fois (double événement, retry…) :
        // une seule inscription, jamais de doublon ni d'erreur technique.
        $service->tenterPourEtudiant($etudiant);
        $service->tenterPourEtudiant($etudiant);

        $this->assertSame(1, InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $this->annee->id)->count());
    }

    // ── Imports DataExchange ────────────────────────────────────────

    public function test_data_exchange_inscriptions_upsert_sans_doublon(): void
    {
        $etudiant = $this->etudiantAvecDossier();
        $definition = app(InscriptionAcademiqueDefinition::class);

        $ligne = [
            'ligne' => 2,
            'matricule' => $etudiant->num_inscription,
            'annee_universitaire' => '2025-2026',
            'filiere' => null,
            'niveau' => 'L1',
            'groupe' => null,
            'statut' => 'valide',
            'observation' => null,
        ];

        $definition->persister($ligne, 'creer', null);
        $this->assertSame(1, InscriptionAcademique::where('etudiant_id', $etudiant->id)->count());

        $resultat = $definition->persister($ligne, 'creer', null);

        $this->assertStringContainsString('mise à jour', $resultat);
        $this->assertSame(1, InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $this->annee->id)->count());
    }

    public function test_data_exchange_progression_upsert_sans_doublon(): void
    {
        $etudiant = $this->etudiantAvecDossier();
        $programme = Programme::create([
            'faculte_id' => $etudiant->faculte_id, 'code' => 'LINFO', 'nom' => 'Licence Informatique',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'Licence 1', 'ordre' => 1]);
        $l2 = Niveau::create(['programme_id' => $programme->id, 'code' => 'L2', 'nom' => 'Licence 2', 'ordre' => 2]);

        $definition = app(ProgressionDefinition::class);
        $ligne = [
            'etudiant_matricule' => $etudiant->num_inscription,
            'annee_source' => '2025-2026',
            'niveau_source' => 'L1',
            'decision' => 'admis',
            'niveau_cible' => 'L2',
            'annee_cible' => '2026-2027',
        ];

        $definition->persister($ligne, 'upsert', null);
        $definition->persister($ligne, 'upsert', null);

        $inscriptions = InscriptionAcademique::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $this->anneeSuivante->id)->get();

        $this->assertCount(1, $inscriptions);
        $this->assertSame($l2->id, (int) $inscriptions->first()->niveau_id);
    }
}
