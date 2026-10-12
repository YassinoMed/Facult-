<?php

namespace Tests\Feature;

use App\Events\DossierDecisionPrise;
use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Services\AffectationAutomatiqueService;
use App\Services\AffectationService;
use App\Services\InscriptionService;
use App\Services\StudentStatusService;
use App\Services\SuiviDossierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Suite de tests complète — LOOP 14 : Affectation académique après validation.
 *
 * Vérifie :
 * 1. Affectation compatible effectuée une seule fois après validation (idempotence).
 * 2. Groupe plein ou incompatible (L1 vs L2, faculté différente) jamais choisi.
 * 3. Exécutions concurrentes / répétées sans double affectation.
 * 4. Simulation sans écriture (aucune modification en base de données).
 * 5. Absence de groupe gérée avec état et message explicites (NOT_ASSIGNED, etc.).
 * 6. Déclenchement automatique après validation officielle du dossier.
 * 7. Restitution sécurisée dans le suivi uniquement après confirmation de l'écriture.
 */
class InscriptionAffectationAcademiqueTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private Faculte $faculte;

    private Faculte $autreFaculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Tunis',
            'code' => 'FST',
            'active' => true,
        ]);

        $this->autreFaculte = Faculte::create([
            'nom' => 'Faculté de Droit de Tunis',
            'code' => 'FDT',
            'active' => true,
        ]);

        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité',
            'email' => 'agent.scol@universite.tn',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);
    }

    private function creerEtudiant(string $cin, string $niveau = 'L1', ?Faculte $faculte = null, string $statut = 'valide'): Etudiant
    {
        $fac = $faculte ?? $this->faculte;

        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Benali',
            'prenom' => 'Kamel',
            'email' => strtolower($cin).'@universite.tn',
            'date_naissance' => '2004-02-10',
            'sexe' => 'M',
            'faculte_id' => $fac->id,
            'niveau' => $niveau,
            'annee_academique' => '2026-2027',
            'adresse' => 'Avenue de la République',
            'ville' => 'Tunis',
            'code_postal' => '1001',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut($statut);

        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => $niveau,
            'statut' => 'en_cours',
        ]);

        return $etudiant->fresh();
    }

    private function creerGroupe(string $code, ?int $capacite = 30, string $niveau = 'L1', ?Faculte $faculte = null): Group
    {
        $fac = $faculte ?? $this->faculte;

        return Group::create([
            'code' => $code,
            'nom' => "Groupe {$code}",
            'faculte_id' => $fac->id,
            'niveau' => $niveau,
            'capacite' => $capacite,
            'actif' => true,
        ]);
    }

    /**
     * Critère 1 : Affectation compatible effectuée une seule fois après validation officielle.
     */
    public function test_affectation_compatible_effectuee_une_seule_fois(): void
    {
        $groupe = $this->creerGroupe('L1-G01', 25, 'L1');
        $etudiant = $this->creerEtudiant('CIN-L1-01', 'L1');
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();

        // Validation officielle de l'inscription
        app(InscriptionService::class)->transiter($inscription, 'valide', $this->scolarite->id);

        $this->assertSame('valide', $inscription->fresh()->statut);

        // Une affectation a été créée et liée au bon groupe
        $affectation = AffectationGroupe::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $this->annee->id)
            ->where('statut', 'active')
            ->first();

        $this->assertNotNull($affectation);
        $this->assertSame($groupe->id, (int) $affectation->groupe_id);
        $this->assertSame($groupe->id, (int) $etudiant->fresh()->groupe_id);

        // Exactement une seule affectation active
        $this->assertSame(1, AffectationGroupe::where('etudiant_id', $etudiant->id)->count());
    }

    /**
     * Critère 2 : Groupe plein ou incompatible (L1 vs L2, faculté différente) JAMAIS choisi.
     */
    public function test_groupe_plein_ou_incompatible_jamais_choisi(): void
    {
        // Groupe L2 dans la même faculté (incompatible niveau)
        $groupeL2 = $this->creerGroupe('L2-G01', 30, 'L2');

        // Groupe L1 d'une AUTRE faculté (incompatible faculté)
        $groupeAutreFac = $this->creerGroupe('L1-AUTRE', 30, 'L1', $this->autreFaculte);

        // Groupe L1 complet (capacité saturée 1/1)
        $groupePlein = $this->creerGroupe('L1-PLEIN', 1, 'L1');
        $occupant = $this->creerEtudiant('CIN-OCCUPANT', 'L1');
        app(InscriptionService::class)->transiter($occupant->inscriptionsAcademiques()->firstOrFail(), 'valide', $this->scolarite->id);

        // Groupe L1 disponible et compatible
        $groupeValide = $this->creerGroupe('L1-LIBRE', 20, 'L1');

        $candidat = $this->creerEtudiant('CIN-CANDIDAT', 'L1');
        app(InscriptionService::class)->transiter($candidat->inscriptionsAcademiques()->firstOrFail(), 'valide', $this->scolarite->id);

        $affectation = AffectationGroupe::where('etudiant_id', $candidat->id)->firstOrFail();

        // Le candidat est affecté au groupe valide libre, JAMAIS au L2, ni autre fac, ni plein
        $this->assertSame($groupeValide->id, (int) $affectation->groupe_id);
        $this->assertNotSame($groupeL2->id, (int) $affectation->groupe_id);
        $this->assertNotSame($groupeAutreFac->id, (int) $affectation->groupe_id);
        $this->assertNotSame($groupePlein->id, (int) $affectation->groupe_id);
    }

    /**
     * Critère 3 : Exécutions répétées / concurrentes sans double affectation (Idempotence).
     */
    public function test_executions_concurrentes_sans_double_affectation(): void
    {
        $this->creerGroupe('L1-IDEMP', 30, 'L1');
        $etudiant = $this->creerEtudiant('CIN-IDEMP', 'L1');
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();

        $service = app(AffectationAutomatiqueService::class);

        app(InscriptionService::class)->transiter($inscription, 'valide', $this->scolarite->id);

        // Réitération manuelle ou concurrence immédiate
        $retour1 = $service->tenter($inscription->fresh(), $this->scolarite->id);
        $retour2 = $service->tenter($inscription->fresh(), $this->scolarite->id);

        $this->assertSame('EXISTING', $retour1['statut']);
        $this->assertSame('EXISTING', $retour2['statut']);
        $this->assertSame(1, AffectationGroupe::where('etudiant_id', $etudiant->id)->count());
    }

    /**
     * Critère 4 : Simulation sans écriture (aucune écriture en base).
     */
    public function test_simulation_sans_ecriture_laisse_la_base_intacte(): void
    {
        $groupe = $this->creerGroupe('L1-SIMU', 25, 'L1');
        $etudiant = $this->creerEtudiant('CIN-SIMU', 'L1');
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();

        $service = app(AffectationAutomatiqueService::class);

        $countAvant = AffectationGroupe::count();
        $this->assertNull($etudiant->groupe_id);

        // Simulation sans écriture
        $simulation = $service->simuler($inscription);

        $this->assertSame('SIMULATED', $simulation['statut']);
        $this->assertSame($groupe->id, $simulation['groupe_id']);
        $this->assertSame($groupe->code, $simulation['groupe_code']);
        $this->assertStringContainsString('serait attribué', $simulation['message']);

        // Aucune écriture persistée
        $this->assertSame($countAvant, AffectationGroupe::count());
        $this->assertNull($etudiant->fresh()->groupe_id);
        $this->assertNull(AffectationGroupe::where('etudiant_id', $etudiant->id)->first());
    }

    /**
     * Critère 5 : Absence de groupe gérée avec état et message explicites.
     */
    public function test_absence_de_groupe_gere_avec_etat_et_message_explicites(): void
    {
        // Seul un groupe L3 existe alors que l'étudiant est L1
        $this->creerGroupe('L3-SEUL', 30, 'L3');

        $etudiant = $this->creerEtudiant('CIN-PAS-DE-GROUPE', 'L1');
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();

        app(InscriptionService::class)->transiter($inscription, 'valide', $this->scolarite->id);

        // L'inscription reste validée
        $this->assertSame('valide', $inscription->fresh()->statut);

        // Aucun groupe n'a été inventé ni attribué
        $this->assertNull(AffectationGroupe::where('etudiant_id', $etudiant->id)->first());
        $this->assertNull($etudiant->fresh()->groupe_id);

        // Appel direct du service pour vérifier le diagnostic explicite
        $resultat = app(AffectationAutomatiqueService::class)->tenter($inscription->fresh(), $this->scolarite->id);
        $this->assertSame('NOT_ASSIGNED', $resultat['statut']);
        $this->assertSame('NO_COMPATIBLE_GROUP', $resultat['raison']);
        $this->assertStringContainsString('Aucun groupe compatible', $resultat['message']);
    }

    /**
     * Critère 6 : Déclenchement automatique via DossierDecisionPrise (validation dossier scolarité).
     */
    public function test_validation_dossier_etudiant_declenche_affectation_automatique(): void
    {
        $groupe = $this->creerGroupe('L1-DECISION', 25, 'L1');
        $etudiant = $this->creerEtudiant('CIN-DECISION', 'L1', null, 'en_attente');

        // Validation du statut étudiant via le service officiel StudentStatusService
        app(StudentStatusService::class)->changer($etudiant, 'valide', $this->scolarite->id, 'Admission approuvée');

        $this->assertSame('valide', $etudiant->fresh()->statut);

        // L'écouteur a déclenché l'affectation automatique
        $affectation = AffectationGroupe::where('etudiant_id', $etudiant->id)
            ->where('statut', 'active')
            ->first();

        $this->assertNotNull($affectation);
        $this->assertSame($groupe->id, (int) $affectation->groupe_id);
        $this->assertSame($groupe->id, (int) $etudiant->fresh()->groupe_id);
    }

    /**
     * Critère 7 : Restitution dans le suivi étudiant uniquement après confirmation d'écriture.
     */
    public function test_groupe_attribue_restitue_dans_le_suivi_uniquement_apres_ecriture(): void
    {
        $groupe = $this->creerGroupe('L1-SUIVI', 25, 'L1');
        $etudiant = $this->creerEtudiant('CIN-SUIVI', 'L1', null, 'en_attente');
        $suiviService = app(SuiviDossierService::class);

        // Dossier en attente : groupe null
        $syntheseInitiale = $suiviService->synthese($etudiant);
        $this->assertNull($syntheseInitiale['groupe']);

        // Dossier validé mais sans groupe encore attribué
        $etudiant->definirStatut('valide');
        $syntheseValideeSansGroupe = $suiviService->synthese($etudiant->fresh());
        $this->assertNotNull($syntheseValideeSansGroupe['groupe']);
        $this->assertSame('en_attente', $syntheseValideeSansGroupe['groupe']['statut']);
        $this->assertNull($syntheseValideeSansGroupe['groupe']['code']);

        // Affectation écrite et confirmée
        app(AffectationService::class)->affecter($etudiant->fresh(), $groupe, $this->annee, $this->scolarite->id, 'affectation test');

        $syntheseApresEcriture = $suiviService->synthese($etudiant->fresh());
        $this->assertNotNull($syntheseApresEcriture['groupe']);
        $this->assertSame('affecte', $syntheseApresEcriture['groupe']['statut']);
        $this->assertSame($groupe->code, $syntheseApresEcriture['groupe']['code']);

        // Vérification dans l'écran de suivi étudiant (HTTP)
        $reponse = $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.suivi'));

        $reponse->assertOk();
        $reponse->assertSee($groupe->code);
        $reponse->assertSee('Affectation confirmée');
    }
}
