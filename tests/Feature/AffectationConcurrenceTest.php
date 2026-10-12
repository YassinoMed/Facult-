<?php

namespace Tests\Feature;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Services\AffectationAutomatiqueService;
use App\Services\AffectationService;
use App\Services\InscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LOOP 14 — critère « Exécutions concurrentes sans double affectation » :
 * l'étudiant est la RESSOURCE SÉRIALISANTE du moteur d'affectation.
 *
 * Faille corrigée : `affecter()`/`retirer()` verrouillaient le groupe et
 * la ligne d'affectation existante, mais un SELECT FOR UPDATE sur une
 * ligne ABSENTE ne sérialise rien (gap lock InnoDB → double INSERT
 * possible en prod multi-worker : deux workers passent tous deux le
 * contrôle « existante » puis insèrent chacun). Le verrou de ligne
 * ÉTUDIANT force le second worker à attendre le commit du premier.
 *
 * Preuves : invariant portable (jamais deux affectations actives, quel
 * que soit l'entrelacement séquentiel) + preuve FOR UPDATE sur MySQL
 * (skip SQLite, pattern AttendanceRisquesP1Test).
 */
class AffectationConcurrenceTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.concurrent@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    // Invariant central, portable : jamais deux affectations actives pour
    // le même (étudiant, année) — quel que soit l'entrelacement testé.
    private function assertInvariantUneActive(Etudiant $etudiant): void
    {
        $this->assertSame(
            1,
            AffectationGroupe::where('etudiant_id', $etudiant->id)
                ->where('annee_universitaire_id', $this->annee->id)
                ->where('statut', 'active')
                ->count(),
            'Deux affectations actives détectées : invariant violé.',
        );
    }

    public function test_double_tenter_une_seule_affectation_active(): void
    {
        $groupe = $this->creerGroupe('L1-CC01');
        $etudiant = $this->creerEtudiant('CON00001');
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();

        app(InscriptionService::class)->transiter($inscription, 'valide', $this->scolarite->id);

        // Replay « concurrent » (worker + relance manuelle) : idempotence.
        $resultat = app(AffectationAutomatiqueService::class)
            ->tenter($inscription->fresh(), $this->scolarite->id);

        $this->assertSame('EXISTING', $resultat['statut']);
        $this->assertInvariantUneActive($etudiant);
        $this->assertSame(1, AffectationGroupe::where('etudiant_id', $etudiant->id)->count());
    }

    public function test_transfert_concurrent_ne_laisse_jamais_deux_actives(): void
    {
        $groupeA = $this->creerGroupe('L1-CC02A');
        $groupeB = $this->creerGroupe('L1-CC02B');
        $etudiant = $this->creerEtudiant('CON00002');

        $this->affecterDirect($etudiant, $groupeA);
        $this->affecterDirect($etudiant, $groupeB);

        $this->assertInvariantUneActive($etudiant);

        $active = AffectationGroupe::where('etudiant_id', $etudiant->id)
            ->where('statut', 'active')->firstOrFail();
        $this->assertSame($groupeB->id, (int) $active->groupe_id);

        $cloturee = AffectationGroupe::where('etudiant_id', $etudiant->id)
            ->where('statut', 'cloturee')->firstOrFail();
        $this->assertSame($groupeA->id, (int) $cloturee->groupe_id);
    }

    public function test_retrait_puis_affectation_ne_laisse_jamais_deux_actives(): void
    {
        $groupeA = $this->creerGroupe('L1-CC03A');
        $groupeB = $this->creerGroupe('L1-CC03B');
        $etudiant = $this->creerEtudiant('CON00003');

        $this->affecterDirect($etudiant, $groupeA);

        $active = AffectationGroupe::where('etudiant_id', $etudiant->id)
            ->where('statut', 'active')->firstOrFail();

        app(AffectationService::class)->retirer($active, $this->scolarite->id, 'Correction administrative.');

        $this->assertSame(0, AffectationGroupe::where('etudiant_id', $etudiant->id)
            ->where('statut', 'active')->count());

        $this->affecterDirect($etudiant, $groupeB);

        $this->assertInvariantUneActive($etudiant);
        $this->assertSame($groupeB->id, (int) $etudiant->fresh()->groupe_id);
    }

    public function test_affecter_verrouille_la_ligne_etudiant(): void
    {
        // SQLite ne supporte pas la sémantique FOR UPDATE : preuve exigée
        // sur MySQL, comportement couvert partout par les invariants
        // portables ci-dessus (pattern AttendanceRisquesP1Test).
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Verrou ligne FOR UPDATE vérifiable uniquement sur MySQL (driver courant : '.DB::getDriverName().').');
        }

        $groupe = $this->creerGroupe('L1-CC04');
        $etudiant = $this->creerEtudiant('CON00004');

        $verrousEtudiant = 0;
        $ordreEtudiantAvantGroupe = null;
        DB::listen(function ($requete) use (&$verrousEtudiant, &$ordreEtudiantAvantGroupe) {
            $sql = strtolower($requete->sql);

            if (str_contains($sql, 'for update') && str_contains($sql, 'etudiants')) {
                $verrousEtudiant++;
                $ordreEtudiantAvantGroupe ??= 'etudiant';
            } elseif (str_contains($sql, 'for update') && str_contains($sql, 'groups')
                && $ordreEtudiantAvantGroupe === null) {
                $ordreEtudiantAvantGroupe = 'groupe_d_abord';
            }
        });

        $this->affecterDirect($etudiant, $groupe);

        $this->assertGreaterThanOrEqual(1, $verrousEtudiant,
            'Aucun SELECT ... FOR UPDATE sur la ligne étudiant : double affectation possible en prod multi-worker.');
        $this->assertSame('etudiant', $ordreEtudiantAvantGroupe,
            'Le verrou étudiant doit être pris AVANT le verrou groupe (ordre anti-deadlock).');
    }

    private function affecterDirect(Etudiant $etudiant, Group $groupe): void
    {
        app(AffectationService::class)->affecter(
            $etudiant->fresh(), $groupe->fresh(), $this->annee, $this->scolarite->id, 'Test concurrence.',
        );
    }

    private function creerGroupe(string $code): Group
    {
        return Group::create([
            'code' => $code, 'nom' => $code, 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => 30, 'actif' => true,
        ]);
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Concurrent', 'prenom' => 'Sara', 'email' => strtolower($cin).'@concurrence.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'en_cours',
        ]);

        return $etudiant;
    }
}
