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
use Tests\TestCase;

/**
 * LOOP affectation automatique après validation (§1-29) : réutilise le
 * moteur existant, jamais de second moteur, inscription conservée si
 * l'affectation est impossible.
 */
class AffectationAutomatiqueApresValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private Faculte $faculte;

    private Faculte $autreFaculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->autreFaculte = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'description' => 'T', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.auto@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    public function test_cas1_validation_declenche_affectation_compatible(): void
    {
        $groupe = $this->creerGroupe('L2-G01');
        $etudiant = $this->creerEtudiant('AUTO00001');
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();

        app(InscriptionService::class)->transiter($inscription, 'valide', $this->scolarite->id);

        $this->assertSame('valide', $inscription->fresh()->statut);

        $affectation = AffectationGroupe::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $this->annee->id)
            ->where('statut', 'active')->firstOrFail();

        $this->assertSame($groupe->id, (int) $affectation->groupe_id);
        $this->assertSame($groupe->id, (int) $etudiant->fresh()->groupe_id);
        $this->assertDatabaseHas('historique_academique', ['action' => 'affectation.auto_assignee']);
        $this->assertDatabaseHas('notifications', ['etudiant_id' => $etudiant->id, 'type' => 'affectation.groupe']);
    }

    public function test_cas2_plusieurs_groupes_equilibrage_moins_rempli(): void
    {
        $rempli = $this->creerGroupe('L2-G01');
        $leger = $this->creerGroupe('L2-G02');

        for ($i = 1; $i <= 4; $i++) {
            $occ = $this->creerEtudiant('OCC2'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
            $insc = $occ->inscriptionsAcademiques()->firstOrFail();
            app(InscriptionService::class)->transiter($insc, 'valide', $this->scolarite->id);
            AffectationGroupe::where('etudiant_id', $occ->id)->delete();
            $occ->update(['groupe_id' => null]);
            app(AffectationService::class)->affecter($occ->fresh(), $rempli, $this->annee, $this->scolarite->id, 'remplissage');
        }

        $legerOcc = $this->creerEtudiant('OCC20005');
        $inscLeger = $legerOcc->inscriptionsAcademiques()->firstOrFail();
        app(InscriptionService::class)->transiter($inscLeger, 'valide', $this->scolarite->id);
        AffectationGroupe::where('etudiant_id', $legerOcc->id)->delete();
        $legerOcc->update(['groupe_id' => null]);
        app(AffectationService::class)->affecter($legerOcc->fresh(), $leger, $this->annee, $this->scolarite->id, 'remplissage');

        $nouveau = $this->creerEtudiant('AUTO00002');
        app(InscriptionService::class)->transiter($nouveau->inscriptionsAcademiques()->firstOrFail(), 'valide', $this->scolarite->id);

        // Tous les candidats évalués, le moins rempli choisi.
        $this->assertSame($leger->id, (int) AffectationGroupe::where('etudiant_id', $nouveau->id)->firstOrFail()->groupe_id);
    }

    public function test_cas3_premier_groupe_complet_bascule(): void
    {
        $plein = $this->creerGroupe('L2-G01', 1);
        $libre = $this->creerGroupe('L2-G02', 30);

        $premier = $this->creerEtudiant('AUTO00003');
        app(InscriptionService::class)->transiter($premier->inscriptionsAcademiques()->firstOrFail(), 'valide', $this->scolarite->id);
        $this->assertSame($plein->id, (int) AffectationGroupe::where('etudiant_id', $premier->id)->firstOrFail()->groupe_id);

        $second = $this->creerEtudiant('AUTO00004');
        app(InscriptionService::class)->transiter($second->inscriptionsAcademiques()->firstOrFail(), 'valide', $this->scolarite->id);

        $this->assertSame($libre->id, (int) AffectationGroupe::where('etudiant_id', $second->id)->firstOrFail()->groupe_id);
    }

    public function test_cas4_tous_complets_inscription_conservee(): void
    {
        $this->creerGroupe('L2-G01', 1);
        $occupant = $this->creerEtudiant('AUTO00005');
        app(InscriptionService::class)->transiter($occupant->inscriptionsAcademiques()->firstOrFail(), 'valide', $this->scolarite->id);

        $suivant = $this->creerEtudiant('AUTO00006');
        $inscription = $suivant->inscriptionsAcademiques()->firstOrFail();
        app(InscriptionService::class)->transiter($inscription, 'valide', $this->scolarite->id);

        $this->assertSame('valide', $inscription->fresh()->statut);
        $this->assertNull(AffectationGroupe::where('etudiant_id', $suivant->id)->first());
        $this->assertDatabaseHas('historique_academique', ['action' => 'affectation.auto_non_assignee']);
    }

    public function test_cas5_aucun_groupe_compatible(): void
    {
        $this->creerGroupe('L3-G01', 30, 'L3');

        $etudiant = $this->creerEtudiant('AUTO00007');
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();
        app(InscriptionService::class)->transiter($inscription, 'valide', $this->scolarite->id);

        $this->assertSame('valide', $inscription->fresh()->statut);
        $this->assertNull(AffectationGroupe::where('etudiant_id', $etudiant->id)->first());

        $resultat = app(AffectationAutomatiqueService::class)->tenter($inscription->fresh(), $this->scolarite->id);
        $this->assertSame('NOT_ASSIGNED', $resultat['statut']);
        $this->assertSame('NO_COMPATIBLE_GROUP', $resultat['raison']);
    }

    public function test_cas6_deja_affecte_idempotent(): void
    {
        $groupe = $this->creerGroupe('L2-G01');
        $etudiant = $this->creerEtudiant('AUTO00008');

        $manuelle = app(AffectationService::class)->affecter($etudiant, $groupe, $this->annee, $this->scolarite->id, 'manuelle');

        app(InscriptionService::class)->transiter($etudiant->inscriptionsAcademiques()->firstOrFail(), 'valide', $this->scolarite->id);

        $this->assertSame(1, AffectationGroupe::where('etudiant_id', $etudiant->id)->where('statut', 'active')->count());
        $this->assertSame($manuelle->id, AffectationGroupe::where('etudiant_id', $etudiant->id)->firstOrFail()->id);
    }

    public function test_cas7_verrouillee_jamais_deplacee(): void
    {
        $groupeA = $this->creerGroupe('L2-G01');
        $this->creerGroupe('L2-G02');
        $etudiant = $this->creerEtudiant('AUTO00009');

        $affectation = app(AffectationService::class)->affecter($etudiant, $groupeA, $this->annee, $this->scolarite->id, 'manuelle');
        $affectation->update(['verrouille' => true]);

        app(InscriptionService::class)->transiter($etudiant->inscriptionsAcademiques()->firstOrFail(), 'valide', $this->scolarite->id);

        $this->assertSame($groupeA->id, (int) AffectationGroupe::where('etudiant_id', $etudiant->id)->firstOrFail()->groupe_id);
        $this->assertDatabaseHas('historique_academique', ['action' => 'affectation.auto_verrouillee']);
    }

    public function test_cas8_double_validation_une_seule_affectation(): void
    {
        $this->creerGroupe('L2-G01');
        $etudiant = $this->creerEtudiant('AUTO00010');
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();

        app(InscriptionService::class)->transiter($inscription, 'valide', $this->scolarite->id);

        // Rejeu (retry navigateur, double event) : idempotent.
        $resultat = app(AffectationAutomatiqueService::class)->tenter($inscription->fresh(), $this->scolarite->id);
        $this->assertSame('EXISTING', $resultat['statut']);
        $this->assertSame(1, AffectationGroupe::where('etudiant_id', $etudiant->id)->count());
    }

    public function test_cas9_capacite_respectee_sans_surcharge(): void
    {
        $this->creerGroupe('L2-G01', 1);

        $premier = $this->creerEtudiant('AUTO00011');
        $second = $this->creerEtudiant('AUTO00012');

        app(InscriptionService::class)->transiter($premier->inscriptionsAcademiques()->firstOrFail(), 'valide', $this->scolarite->id);
        app(InscriptionService::class)->transiter($second->inscriptionsAcademiques()->firstOrFail(), 'valide', $this->scolarite->id);

        $this->assertSame(1, AffectationGroupe::where('statut', 'active')->count());
        $this->assertNull(AffectationGroupe::where('etudiant_id', $second->id)->first());
        $this->assertSame('valide', $second->inscriptionsAcademiques()->firstOrFail()->fresh()->statut);
    }

    public function test_cas10_faculty_scope_hors_perimetre(): void
    {
        $this->creerGroupe('L2-G01');
        $etudiant = $this->creerEtudiant('AUTO00013');

        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.auto@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->autreFaculte->id,
        ], 'admin_faculte');

        $resultat = app(AffectationAutomatiqueService::class)->tenter(
            $etudiant->inscriptionsAcademiques()->firstOrFail(), $adminFaculte->id, $adminFaculte->faculteRestreinteId()
        );

        $this->assertSame('NOT_ELIGIBLE', $resultat['statut']);
        $this->assertSame('SCOPE_RESTRICTION', $resultat['raison']);
        $this->assertNull(AffectationGroupe::where('etudiant_id', $etudiant->id)->first());

        // HTTP : validation hors périmètre refusée, rien créé.
        $this->actingAs($adminFaculte)->post(route('admin.inscriptions.valider', $etudiant->inscriptionsAcademiques()->firstOrFail()))
            ->assertForbidden();
        $this->assertNull(AffectationGroupe::where('etudiant_id', $etudiant->id)->first());
    }

    public function test_cas11_idor_et_relance_verrouillee(): void
    {
        $groupe = $this->creerGroupe('L2-G01');
        $etudiant = $this->creerEtudiant('AUTO00014');
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();
        app(InscriptionService::class)->transiter($inscription, 'valide', $this->scolarite->id);
        $this->assertSame($groupe->id, (int) AffectationGroupe::where('etudiant_id', $etudiant->id)->firstOrFail()->groupe_id);

        // IDOR : admin d'une autre faculté sur inscription + réaffectation.
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac2', 'email' => 'fac2.auto@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->autreFaculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte)->post(route('admin.inscriptions.reaffecter', $inscription))->assertForbidden();

        // Relance : déjà affecté → EXISTING, une seule ligne.
        $this->actingAs($this->scolarite)->post(route('admin.inscriptions.reaffecter', $inscription))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, AffectationGroupe::where('etudiant_id', $etudiant->id)->count());

        // Panne moteur ne bloque pas l'inscription : étudiant sans niveau.
        $sansNiveau = $this->creerEtudiant('AUTO00015');
        $sansNiveau->update(['niveau' => '']);
        $inscSans = $sansNiveau->inscriptionsAcademiques()->firstOrFail();
        $inscSans->update(['niveau' => '']);
        app(InscriptionService::class)->transiter($inscSans, 'valide', $this->scolarite->id);
        $this->assertSame('valide', $inscSans->fresh()->statut);
        $this->assertNull(AffectationGroupe::where('etudiant_id', $sansNiveau->id)->first());
    }

    private function creerGroupe(string $code, ?int $capacite = 30, string $niveau = 'L1'): Group
    {
        return Group::create([
            'code' => $code, 'nom' => $code, 'faculte_id' => $this->faculte->id,
            'niveau' => $niveau, 'capacite' => $capacite, 'actif' => true,
        ]);
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => strtolower($cin).'@auto.test',
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
