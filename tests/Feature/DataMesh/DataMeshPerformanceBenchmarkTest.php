<?php

namespace Tests\Feature\DataMesh;

use App\DataMesh\Catalogue;
use App\DataMesh\Outbox\OutboxService;
use App\DataMesh\Qualite\ControlesDonnees;
use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Mesures de performance et observabilité reproductibles (§12 du prompt).
 */
class DataMeshPerformanceBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private User $admin;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Niveau $niveau;

    private Group $groupe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'Faculté des Sciences', 'code' => 'FST', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Admin Test',
            'email' => 'admin.bench@fst.univ.tn',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'nom' => 'Licence Informatique',
            'code' => 'L-INFO',
            'faculte_id' => $this->faculte->id,
            'actif' => true,
        ]);

        $this->niveau = Niveau::create([
            'nom' => 'L1',
            'code' => 'L1',
            'programme_id' => $this->programme->id,
            'actif' => true,
        ]);

        $this->groupe = Group::create([
            'code' => 'L1-G1',
            'nom' => 'Groupe 1',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'capacite' => 40,
            'actif' => true,
        ]);

        // Création de 20 étudiants avec inscriptions et affectations
        for ($i = 1; $i <= 20; $i++) {
            $cin = '0800'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $etudiant = Etudiant::create([
                'cin' => $cin,
                'nom' => 'Etudiant'.$i,
                'prenom' => 'Prenom'.$i,
                'email' => "etu{$i}@fst.univ.tn",
                'date_naissance' => '2005-01-01',
                'sexe' => 'M',
                'faculte_id' => $this->faculte->id,
                'programme_id' => $this->programme->id,
                'niveau_id' => $this->niveau->id,
                'niveau' => 'L1',
                'annee_academique' => '2026-2027',
                'adresse' => 'Campus',
                'ville' => 'Tunis',
                'code_postal' => '1000',
                'annee_bac' => '2023',
                'password' => 'Pass@123',
            ]);
            $etudiant->definirStatut('valide');

            InscriptionAcademique::create([
                'etudiant_id' => $etudiant->id,
                'annee_universitaire_id' => $this->annee->id,
                'programme_id' => $this->programme->id,
                'niveau_id' => $this->niveau->id,
                'niveau' => 'L1',
                'statut' => 'valide',
            ]);

            AffectationGroupe::create([
                'etudiant_id' => $etudiant->id,
                'groupe_id' => $this->groupe->id,
                'annee_universitaire_id' => $this->annee->id,
                'date_debut' => '2026-09-01',
                'statut' => 'active',
            ]);
        }
    }

    public function test_mesure_performance_et_prevention_n_plus_un(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        // 1. Mesure sur inscriptions_validees
        $debut = microtime(true);
        $prodInscriptions = Catalogue::produit('inscriptions_validees');
        $donneesInscriptions = $prodInscriptions->donnees(['utilisateur' => $this->admin]);
        $dureeInscriptionsMs = (microtime(true) - $debut) * 1000;
        $requetesInscriptions = count(DB::getQueryLog());

        $this->assertSame(20, $donneesInscriptions['total']);
        // Grâce au eager loading with(['etudiant', 'anneeUniversitaire', 'programmeRef']),
        // le nombre de requêtes SQL est fixé (O(1)) et ne dépend pas du nombre d'étudiants (anti N+1).
        // Borne 6 (lot 02) : 1 COUNT de pagination + 1 page + 3 eager + 1 référentiel facultés.
        // La requête de comptage est indexée et bornée, documentée dans CONTRACTS.md §5.
        $this->assertLessThanOrEqual(6, $requetesInscriptions, "Prévention N+1 : max 6 requêtes attendues pour 20 étudiants, obtenu : {$requetesInscriptions}");

        // Invariance au volume : 5 ou 20 lignes par page → même nombre de requêtes (pas de N+1 caché).
        DB::flushQueryLog();
        $prodInscriptions->donnees(['utilisateur' => $this->admin, 'page' => 1, 'par_page' => 5]);
        $requetesReduites = count(DB::getQueryLog());
        $this->assertSame($requetesInscriptions, $requetesReduites, 'Le nombre de requêtes doit être indépendant du volume paginé.');

        // 2. Mesure sur affectations_groupes
        DB::flushQueryLog();
        $debut = microtime(true);
        $prodAffectations = Catalogue::produit('affectations_groupes');
        $donneesAffectations = $prodAffectations->donnees(['utilisateur' => $this->admin]);
        $dureeAffectationsMs = (microtime(true) - $debut) * 1000;
        $requetesAffectations = count(DB::getQueryLog());

        $this->assertSame(20, $donneesAffectations['total']);
        $this->assertLessThanOrEqual(4, $requetesAffectations, "Prévention N+1 affectations : obtenu {$requetesAffectations}");

        // 3. Mesure du coût des contrôles de qualité
        DB::flushQueryLog();
        $debut = microtime(true);
        $moteur = new ControlesDonnees;
        $rapportQualite = $moteur->controler($this->admin, $this->faculte->id, $this->annee->id);
        $dureeQualiteMs = (microtime(true) - $debut) * 1000;
        $requetesQualite = count(DB::getQueryLog());

        $this->assertCount(8, $rapportQualite['resultats']);
        $this->assertLessThan(200, $dureeQualiteMs, "Le contrôle complet de 8 règles doit s'exécuter en moins de 200 ms, obtenu : {$dureeQualiteMs} ms");

        // 4. Mesure du temps de propagation Outbox
        $outbox = new OutboxService;
        $debut = microtime(true);
        for ($i = 0; $i < 10; $i++) {
            $outbox->enregistrer(
                'bench.event',
                'etudiant',
                $i + 1,
                ['indice' => $i],
                "bench_dedup_{$i}"
            );
        }
        $outbox->publierEnAttente();
        $dureeOutboxMs = (microtime(true) - $debut) * 1000;

        $this->assertLessThan(100, $dureeOutboxMs, 'Expédition de 10 messages outbox en moins de 100 ms');

        // Affichage structuré des mesures pour le rapport
        $mesures = [
            'inscriptions_validees' => [
                'requetes_sql' => $requetesInscriptions,
                'duree_ms' => round($dureeInscriptionsMs, 2),
                'volume_donnees' => $donneesInscriptions['total'],
            ],
            'affectations_groupes' => [
                'requetes_sql' => $requetesAffectations,
                'duree_ms' => round($dureeAffectationsMs, 2),
                'volume_donnees' => $donneesAffectations['total'],
            ],
            'controles_qualite' => [
                'requetes_sql' => $requetesQualite,
                'duree_ms' => round($dureeQualiteMs, 2),
                'nb_regles' => count($rapportQualite['resultats']),
            ],
            'propagation_outbox' => [
                'messages' => 10,
                'duree_ms' => round($dureeOutboxMs, 2),
            ],
        ];

        $this->assertIsArray($mesures);
    }
}
