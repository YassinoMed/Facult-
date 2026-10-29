<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierVerification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\RegistrationPeriod;
use App\Models\User;
use App\Modules\Attendance\Models\Justification;
use App\Modules\Attendance\Models\Presence;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\TaskGenerationService;
use App\Modules\Tasks\Services\TaskService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP-UNIREGISTRE-CENTRE-TRAVAIL-LOT-A-01
 * Tests de la génération et synchronisation idempotente des tâches d'inscription.
 */
class TaskGenerationInscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Niveau $niveau;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.tasks.active' => true,
            'modules.attendance.active' => true,
            'modules.online-requests.active' => true,
        ]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'description' => 'Faculté pilote',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'L-INFO',
            'nom' => 'Licence Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'actif' => true,
        ]);

        $this->niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L1',
            'nom' => 'Première Année Licence',
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité',
            'email' => 'agent.tasks@fst.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_scolarite');
    }

    private function creerEtudiant(string $cin = '08765432', string $statut = 'en_attente'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Ben Salah',
            'prenom' => 'Amine',
            'email' => "etudiant.{$cin}@fst.test",
            'date_naissance' => '2004-05-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Tunis',
            'code_postal' => '1060',
            'annee_bac' => '2023',
            'password' => 'Secret@123',
        ]);

        $etudiant->definirStatut($statut);

        return $etudiant;
    }

    private function creerInscription(Etudiant $etudiant, AnneeUniversitaire $annee, string $statut = 'en_cours', ?Carbon $dateCreation = null): InscriptionAcademique
    {
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $annee->id,
            'programme_id' => $this->programme->id,
            'niveau_id' => $this->niveau->id,
            'niveau' => 'L1',
            'filiere' => 'Informatique',
            'statut' => $statut,
            'type' => 'premiere',
        ]);

        if ($dateCreation !== null) {
            $inscription->created_at = $dateCreation;
            $inscription->saveQuietly();
        }

        return $inscription;
    }

    /** 1. Création d'une tâche pour une inscription éligible au traitement. */
    public function test_creation_tache_pour_inscription_eligible(): void
    {
        $etudiant = $this->creerEtudiant('01111111');
        $inscription = $this->creerInscription($etudiant, $this->annee, 'en_cours');

        $service = app(TaskGenerationService::class);
        $cles = $service->synchroniserInscriptions();

        $cleAttendue = "inscription:{$inscription->id}";
        $this->assertContains($cleAttendue, $cles);

        $this->assertDatabaseHas('tasks', [
            'cle_unique' => $cleAttendue,
            'source' => 'inscription',
            'statut' => 'pending',
            'faculte_id' => $this->faculte->id,
        ]);

        $tache = Task::where('cle_unique', $cleAttendue)->first();
        $this->assertNotNull($tache);
        $this->assertStringContainsString($etudiant->nom_complet, $tache->titre);
        $this->assertSame($inscription->id, $tache->data['inscription_id']);
        $this->assertSame($etudiant->id, $tache->data['etudiant_id']);
    }

    /** 2. Clé unique conforme à `inscription:{id}`. */
    public function test_format_cle_unique_conforme_inscription_id(): void
    {
        $etudiant = $this->creerEtudiant('02222222');
        $inscription = $this->creerInscription($etudiant, $this->annee, 'en_cours');

        app(TaskGenerationService::class)->synchroniserInscriptions();

        $tache = Task::where('source', 'inscription')->first();
        $this->assertNotNull($tache);
        $this->assertSame("inscription:{$inscription->id}", $tache->cle_unique);
        $this->assertLessThanOrEqual(120, strlen($tache->cle_unique));
    }

    /** 3. Absence de doublon après synchronisation répétée (Idempotence). */
    public function test_absence_de_doublon_apres_synchronisation_repetee(): void
    {
        $etudiant = $this->creerEtudiant('03333333');
        $inscription = $this->creerInscription($etudiant, $this->annee, 'en_cours');

        $service = app(TaskGenerationService::class);

        // Premier passage
        $service->synchroniserInscriptions();
        $this->assertSame(1, Task::where('cle_unique', "inscription:{$inscription->id}")->count());

        // Deuxième passage (relance identique)
        $service->synchroniserInscriptions();
        $this->assertSame(1, Task::where('cle_unique', "inscription:{$inscription->id}")->count());

        // Troisième passage via la méthode globale generer()
        $service->generer();
        $this->assertSame(1, Task::where('cle_unique', "inscription:{$inscription->id}")->count());
    }

    /** 4. Séparation des tâches de deux inscriptions historiques du même étudiant. */
    public function test_separation_des_taches_pour_inscriptions_historiques_du_meme_etudiant(): void
    {
        $anneePrecedente = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => false,
        ]);

        $etudiant = $this->creerEtudiant('04444444');

        // Deux inscriptions distinctes sur deux années distinctes pour le même étudiant
        $inscription1 = $this->creerInscription($etudiant, $anneePrecedente, 'en_cours');
        $inscription2 = $this->creerInscription($etudiant, $this->annee, 'en_cours');

        $this->assertNotSame($inscription1->id, $inscription2->id);

        app(TaskGenerationService::class)->synchroniserInscriptions();

        // Vérification de la présence de 2 tâches indépendantes en base
        $this->assertSame(2, Task::where('source', 'inscription')->count());
        $this->assertDatabaseHas('tasks', ['cle_unique' => "inscription:{$inscription1->id}"]);
        $this->assertDatabaseHas('tasks', ['cle_unique' => "inscription:{$inscription2->id}"]);

        // Chaque tâche référence précisément son inscription correspondante
        $t1 = Task::where('cle_unique', "inscription:{$inscription1->id}")->first();
        $t2 = Task::where('cle_unique', "inscription:{$inscription2->id}")->first();
        $this->assertSame($anneePrecedente->id, $t1->data['annee_universitaire_id']);
        $this->assertSame($this->annee->id, $t2->data['annee_universitaire_id']);
    }

    /** 5. Absence de tâche pour une inscription déjà validée et clôture automatique. */
    public function test_absence_tache_pour_inscription_validee_et_cloture_automatique(): void
    {
        $etudiant = $this->creerEtudiant('05555555', 'valide');
        $inscriptionValide = $this->creerInscription($etudiant, $this->annee, 'valide');

        $service = app(TaskGenerationService::class);
        $cles = $service->synchroniserInscriptions();

        // Aucune tâche n'est créée pour une inscription déjà validée
        $this->assertNotContains("inscription:{$inscriptionValide->id}", $cles);
        $this->assertDatabaseMissing('tasks', ['cle_unique' => "inscription:{$inscriptionValide->id}"]);

        // Scénario de clôture automatique : un dossier en_cours devient valide
        $etudiant2 = $this->creerEtudiant('05555556', 'en_attente');
        $inscription2 = $this->creerInscription($etudiant2, $this->annee, 'en_cours');

        $service->generer();
        $this->assertDatabaseHas('tasks', [
            'cle_unique' => "inscription:{$inscription2->id}",
            'statut' => 'pending',
        ]);

        // L'inscription est validée administrativement
        $inscription2->update(['statut' => 'valide']);
        $etudiant2->definirStatut('valide');

        // La prochaine génération clôture automatiquement la tâche résolue
        $compteurs = $service->generer();
        $this->assertGreaterThanOrEqual(1, $compteurs['cloturees']);

        $tacheCloturee = Task::where('cle_unique', "inscription:{$inscription2->id}")->first();
        $this->assertSame('completed', $tacheCloturee->statut);
        $this->assertNotNull($tacheCloturee->terminee_a);
    }

    /** 6. Calcul des priorités selon les règles documentées. */
    public function test_calcul_des_priorites_selon_regles_documentees(): void
    {
        $service = app(TaskGenerationService::class);

        // Cas A : Normal (< 48h, sans blocage, sans échéance proche)
        $eNormal = $this->creerEtudiant('06000001');
        $iNormal = $this->creerInscription($eNormal, $this->annee, 'en_cours', now()->subHours(10));

        $service->synchroniserInscriptions();
        $tNormal = Task::where('cle_unique', "inscription:{$iNormal->id}")->first();
        $this->assertSame('normal', $tNormal->priorite);
        $this->assertFalse($tNormal->data['est_en_souffrance']);

        // Cas B : Souffrance (attente >= 48h mais < 7j) -> reste normal avec indicateur
        $eSouffrance = $this->creerEtudiant('06000002');
        $iSouffrance = $this->creerInscription($eSouffrance, $this->annee, 'en_cours', now()->subHours(50));

        $service->synchroniserInscriptions();
        $tSouffrance = Task::where('cle_unique', "inscription:{$iSouffrance->id}")->first();
        $this->assertSame('normal', $tSouffrance->priorite);
        $this->assertTrue($tSouffrance->data['est_en_souffrance']);
        $this->assertGreaterThanOrEqual(48, $tSouffrance->data['heures_attente']);

        // Cas C : High par ancienneté >= 7 jours
        $eAncien = $this->creerEtudiant('06000003');
        $iAncien = $this->creerInscription($eAncien, $this->annee, 'en_cours', now()->subDays(8));

        $service->synchroniserInscriptions();
        $tAncien = Task::where('cle_unique', "inscription:{$iAncien->id}")->first();
        $this->assertSame('high', $tAncien->priorite);

        // Cas D : High par blocage métier confirmé (verdict BLOQUANT)
        $eBloque = $this->creerEtudiant('06000004');
        $iBloque = $this->creerInscription($eBloque, $this->annee, 'en_cours', now()->subHours(5));
        DossierVerification::create([
            'etudiant_id' => $eBloque->id,
            'verdict' => 'BLOQUANT',
            'score' => 40,
            'contexte' => 'test',
            'regles_echouees' => ['documents.cin_manquant'],
        ]);

        $service->synchroniserInscriptions();
        $tBloque = Task::where('cle_unique', "inscription:{$iBloque->id}")->first();
        $this->assertSame('high', $tBloque->priorite);
        $this->assertTrue($tBloque->data['bloque']);

        // Cas E : Critical par période officielle expirant dans < 48h
        $eUrgent = $this->creerEtudiant('06000005');
        $iUrgent = $this->creerInscription($eUrgent, $this->annee, 'en_cours', now()->subHours(2));

        RegistrationPeriod::create([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
            'statut' => 'active',
            'type_periode' => 'normale',
            'opened_at' => now()->subDays(10),
            'closed_at' => now()->addHours(24), // Expire dans 24h (< 48h)
        ]);

        $service->synchroniserInscriptions();
        $tUrgent = Task::where('cle_unique', "inscription:{$iUrgent->id}")->first();
        $this->assertSame('critical', $tUrgent->priorite);
        $this->assertNotNull($tUrgent->echeance_a);
    }

    /** 7. Conservation du cycle de vie d'une tâche existante (ne pas rouvrir). */
    public function test_conservation_cycle_de_vie_tache_existante_non_active(): void
    {
        $etudiant = $this->creerEtudiant('07777777');
        $inscription = $this->creerInscription($etudiant, $this->annee, 'en_cours');

        $service = app(TaskGenerationService::class);
        $service->synchroniserInscriptions();

        $tache = Task::where('cle_unique', "inscription:{$inscription->id}")->first();
        $this->assertSame('pending', $tache->statut);

        // Un agent clôture ou annule manuellement la tâche
        app(TaskService::class)->transiter($tache, 'cancelled', $this->admin->id, 'Traitée en direct');
        $this->assertSame('cancelled', $tache->fresh()->statut);

        // Nouvelle génération : la tâche ne doit PAS être rouverte implicitement
        $service->synchroniserInscriptions();
        $this->assertSame('cancelled', $tache->fresh()->statut);
    }

    /** 8. Résilience face aux collisions de clé unique (concurrence). */
    public function test_resilience_collision_cle_unique(): void
    {
        $taskService = app(TaskService::class);
        $cle = 'test:collision:unique:123';

        // Premier appel direct
        $t1 = $taskService->synchroniser($cle, [
            'titre' => 'Tâche 1',
            'source' => 'test',
            'priorite' => 'normal',
        ]);
        $this->assertNotNull($t1);

        // Deuxième appel avec les mêmes attributs
        $t2 = $taskService->synchroniser($cle, [
            'titre' => 'Tâche 1 maj',
            'source' => 'test',
            'priorite' => 'high',
        ]);

        $this->assertSame($t1->id, $t2->id);
        $this->assertSame('high', $t2->priorite);
        $this->assertSame(1, Task::where('cle_unique', $cle)->count());
    }

    /** 9. Non-régression des autres tâches générées par TaskGenerationService. */
    public function test_non_regression_des_autres_emetteurs_de_taches(): void
    {
        $etudiant = $this->creerEtudiant('09999999', 'valide');
        $presence = Presence::create([
            'etudiant_id' => $etudiant->id,
            'date' => '2026-10-06',
            'seance' => '08:00',
            'statut' => 'absent',
        ]);
        $justification = Justification::create([
            'presence_id' => $presence->id,
            'etudiant_id' => $etudiant->id,
            'motif' => 'Absence médicale justifiée',
            'statut' => 'pending',
        ]);

        $compteurs = app(TaskGenerationService::class)->generer();

        $this->assertArrayHasKey('inscriptions', $compteurs);
        $this->assertArrayHasKey('justifications', $compteurs);
        $this->assertArrayHasKey('sans_groupe', $compteurs);
        $this->assertSame(1, $compteurs['justifications']);
        $this->assertDatabaseHas('tasks', ['cle_unique' => "justification:{$justification->id}"]);
    }

    /** 10. Une période d'inscription déjà fermée dans le passé n'est pas qualifiée de 'critical'. */
    public function test_periode_fermee_dans_le_passe_non_critique(): void
    {
        RegistrationPeriod::create([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
            'statut' => 'active',
            'type_periode' => 'normale',
            'opened_at' => now()->subDays(30),
            'closed_at' => now()->subHours(5), // Fermée depuis 5h
        ]);

        $etudiant = $this->creerEtudiant('05555555');
        $inscription = $this->creerInscription($etudiant, $this->annee, 'en_cours', now()->subDays(1));

        app(TaskGenerationService::class)->synchroniserInscriptions();

        $tache = Task::where('cle_unique', "inscription:{$inscription->id}")->first();
        $this->assertNotNull($tache);
        // Ne doit PAS être critical (<48h dans le futur), mais normal (car <7 jours et pas d'anomalie bloquante)
        $this->assertSame('normal', $tache->priorite);
    }

    /** 11. cloturerResolues avec faculteId préserve strictement les tâches des autres facultés. */
    public function test_cloturer_resolues_isole_strictement_par_faculte(): void
    {
        $autreFaculte = Faculte::create([
            'code' => 'FSB',
            'nom' => 'Faculté des Sciences de Bizerte',
            'actif' => true,
        ]);

        $etudiantF1 = $this->creerEtudiant('01111111'); // Faculte $this->faculte
        $etudiantF2 = Etudiant::create([
            'cin' => '02222222',
            'nom' => 'Ben Salah',
            'prenom' => 'Sarra',
            'email' => 'sarra.fsb@fst.test',
            'date_naissance' => '2004-03-15',
            'sexe' => 'F',
            'faculte_id' => $autreFaculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus Universitaire',
            'ville' => 'Bizerte',
            'code_postal' => '7000',
            'annee_bac' => '2023',
            'password' => 'Secret@123',
        ]);
        $etudiantF2->definirStatut('en_attente');

        $inscr1 = $this->creerInscription($etudiantF1, $this->annee, 'en_cours');
        $inscr2 = $this->creerInscription($etudiantF2, $this->annee, 'en_cours');

        $service = app(TaskGenerationService::class);
        $taskService = app(TaskService::class);

        // Synchronisation globale initiale
        $service->synchroniserInscriptions();

        $tache1 = Task::where('cle_unique', "inscription:{$inscr1->id}")->first();
        $tache2 = Task::where('cle_unique', "inscription:{$inscr2->id}")->first();
        $this->assertSame('pending', $tache1->statut);
        $this->assertSame('pending', $tache2->statut);

        // Validation de l'inscription F1 uniquement
        $inscr1->update(['statut' => 'valide']);

        // Clôture ciblée sur F1 avec liste vide (toutes résolues pour F1)
        $cloturees = $taskService->cloturerResolues('inscription', [], $this->faculte->id);

        $this->assertSame(1, $cloturees);
        $this->assertSame('completed', $tache1->fresh()->statut);
        // Tache 2 de l'autre faculté doit rester active (pending) !
        $this->assertSame('pending', $tache2->fresh()->statut);
    }
}
