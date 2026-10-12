<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Models\TypeEvaluation;
use App\Modules\Evaluation\Services\EpreuveService;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Services\DataExchange\DefinitionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5 — Évaluation (§26, §29, §43) : rattrapage lié, types
 * configurables, convocation individuelle, import épreuves.
 */
class EvaluationPhase5Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $adminFaculte;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Matiere $ecue;

    private Group $groupe;

    private Room $salle;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.ph5@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.ph5@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LMI', 'nom' => 'Licence MI',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE01', 'nom' => 'Bases', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->ecue = Matiere::create([
            'code' => 'INF101', 'nom' => 'Informatique', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 4,
        ]);
        $this->groupe = Group::create([
            'code' => 'G1', 'nom' => 'Groupe 1', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $this->salle = Room::create(['code' => 'B12', 'nom' => 'B12', 'capacite' => 40, 'actif' => true]);
    }

    private function creerSessionPrincipale(): SessionExamen
    {
        return SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'S-PRINC-'.random_int(1000, 9999),
            'nom' => 'Principale', 'type' => 'principale',
            'date_debut' => '2027-01-05', 'date_fin' => '2027-01-20',
            'statut' => 'draft', 'created_by' => $this->admin->id,
        ]);
    }

    public function test_rattrapage_exige_origine_principale_meme_annee(): void
    {
        $principale = $this->creerSessionPrincipale();

        // Sans origine : refusé.
        $this->actingAs($this->admin)->post(route('admin.evaluations.sessions.store'), [
            'annee_universitaire_id' => $this->annee->id, 'code' => 'S-RAT-X', 'nom' => 'Rattrapage',
            'type' => 'rattrapage', 'date_debut' => '2027-02-02', 'date_fin' => '2027-02-10',
        ])->assertSessionHasErrors('session_origine_id');

        // Avec origine : créé et lié.
        $this->actingAs($this->admin)->post(route('admin.evaluations.sessions.store'), [
            'annee_universitaire_id' => $this->annee->id, 'session_origine_id' => $principale->id,
            'code' => 'S-RAT-'.random_int(1000, 9999), 'nom' => 'Rattrapage',
            'type' => 'rattrapage', 'date_debut' => '2027-02-02', 'date_fin' => '2027-02-10',
        ])->assertSessionHasNoErrors();

        $rattrapage = SessionExamen::where('type', 'rattrapage')->firstOrFail();
        $this->assertSame($principale->id, (int) $rattrapage->session_origine_id);
        $this->assertSame($principale->id, (int) $rattrapage->origine->id);
        $this->assertTrue($principale->rattrapages()->whereKey($rattrapage->id)->exists());

        // Origine d'une autre année : refusée (année inactive suffit —
        // la garde LOT 2 interdit deux années actives).
        $autreAnnee = AnneeUniversitaire::create([
            'libelle' => '2027-2028', 'date_debut' => '2027-09-01', 'date_fin' => '2028-06-30', 'active' => false,
        ]);
        $this->actingAs($this->admin)->post(route('admin.evaluations.sessions.store'), [
            'annee_universitaire_id' => $autreAnnee->id, 'session_origine_id' => $principale->id,
            'code' => 'S-RAT-Y', 'nom' => 'Rattrapage', 'type' => 'rattrapage',
            'date_debut' => '2028-02-02', 'date_fin' => '2028-02-10',
        ])->assertSessionHasErrors('session_origine_id');
    }

    public function test_types_crud_et_epreuve_validee_contre_referentiel(): void
    {
        // Graine migration : 8 codes.
        $this->assertSame(8, TypeEvaluation::where('actif', true)->count());

        $this->actingAs($this->admin)->post(route('admin.evaluations.types.store'), [
            'code' => 'qcm', 'libelle' => 'QCM',
        ])->assertRedirect();

        $this->actingAs($this->admin)->post(route('admin.evaluations.types.store'), [
            'code' => 'qcm', 'libelle' => 'Doublon',
        ])->assertSessionHasErrors('code');

        $session = $this->creerSessionPrincipale();
        $examen = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen S1', 'type' => 'examen', 'statut' => 'scheduled',
            'responsable_id' => $this->admin->id,
        ]);

        // Type libre inconnu : refusé par la Request.
        $this->actingAs($this->admin)->post(route('admin.evaluations.epreuves.store', $examen), [
            'session_id' => $session->id, 'matiere_id' => $this->ecue->id,
            'date' => '2027-01-06', 'heure_debut' => '08:00', 'heure_fin' => '10:00',
            'type_evaluation' => 'nope',
        ])->assertSessionHasErrors('type_evaluation');

        // Type référencé : accepté.
        $this->actingAs($this->admin)->post(route('admin.evaluations.epreuves.store', $examen), [
            'session_id' => $session->id, 'matiere_id' => $this->ecue->id,
            'date' => '2027-01-06', 'heure_debut' => '08:00', 'heure_fin' => '10:00',
            'type_evaluation' => 'qcm',
        ])->assertRedirect();

        $this->assertSame('qcm', Epreuve::firstOrFail()->type_evaluation);

        // Suppression d'un type utilisé : refusée.
        $type = TypeEvaluation::where('code', 'qcm')->firstOrFail();
        $this->actingAs($this->admin)->delete(route('admin.evaluations.types.destroy', $type))
            ->assertSessionHasErrors('type');
    }

    public function test_convocation_individuelle_pdf_et_idor(): void
    {
        $etudiant = $this->creerEtudiantAvecInscription();
        $epreuve = $this->creerEpreuve();
        $candidat = Candidat::create([
            'epreuve_id' => $epreuve->id, 'etudiant_id' => $etudiant->id, 'statut' => 'present',
        ]);

        $this->actingAs($this->adminFaculte)
            ->get(route('admin.evaluations.candidats.convocation', $candidat))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        // Épreuve d'une autre faculté : 403 (périmètre = épreuve,
        // comme l'émargement collectif).
        $autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $groupeB = Group::create([
            'code' => 'GB1', 'nom' => 'Groupe B1', 'faculte_id' => $autreFaculte->id,
            'niveau' => 'L1', 'actif' => true,
        ]);
        $autre = Etudiant::create([
            'cin' => 'BKPH5009', 'nom' => 'Autre', 'prenom' => 'Ali', 'email' => 'autre@ph5.test',
            'sexe' => 'M', 'faculte_id' => $autreFaculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $epreuveB = app(EpreuveService::class)->creer($epreuve->examen, [
            'session_id' => $epreuve->session_id, 'matiere_id' => $this->ecue->id,
            'groupe_id' => $groupeB->id, 'salle_id' => $this->salle->id,
            'date' => '2027-01-07', 'heure_debut' => '08:00', 'heure_fin' => '10:00',
            'type_evaluation' => 'examen',
        ]);
        $candidatB = Candidat::create([
            'epreuve_id' => $epreuveB->id, 'etudiant_id' => $autre->id, 'statut' => 'present',
        ]);

        $this->actingAs($this->adminFaculte)
            ->get(route('admin.evaluations.candidats.convocation', $candidatB))
            ->assertForbidden();
    }

    public function test_import_epreuves_definition(): void
    {
        $session = $this->creerSessionPrincipale();
        Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id, 'groupe_id' => $this->groupe->id,
            'libelle' => 'Examen S1', 'type' => 'examen', 'statut' => 'scheduled',
            'responsable_id' => $this->admin->id,
        ]);

        $definition = app(DefinitionRegistry::class)->import('epreuves');

        $ligne = [
            'examen' => 'Examen S1', 'session' => $session->code, 'ecue' => 'inf101',
            'date' => '2027-01-06', 'horaire' => '08:00-10:00',
            'salle' => 'B12', 'groupe' => 'G1', 'type_evaluation' => 'examen',
        ];

        $this->assertSame('planifiée : inf101 2027-01-06', $definition->persister($ligne, 'creer', $this->admin->id));
        $this->assertSame('déjà planifiée : inf101 2027-01-06', $definition->persister($ligne, 'creer', $this->admin->id));
        $this->assertSame(1, Epreuve::count());

        // Conflit (même salle, chevauchement) : DomainException, pas de 500.
        $ligne['ecue'] = 'INF101';
        $ligne['horaire'] = '09:00-11:00';

        try {
            app(EpreuveService::class)->creer(
                Examen::where('libelle', 'Examen S1')->firstOrFail(),
                ['session_id' => $session->id, 'matiere_id' => $this->ecue->id,
                    'date' => '2027-01-06', 'heure_debut' => '09:00', 'heure_fin' => '11:00',
                    'salle_id' => $this->salle->id]
            );
            $this->fail('Conflit salle aurait dû être refusé.');
        } catch (\DomainException) {
            $this->assertSame(1, Epreuve::count());
        }
    }

    private function creerEtudiantAvecInscription(): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => 'BKPH5001', 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => 'bkph5001@ph5.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X',
            'ville' => 'Casa', 'code_postal' => '20000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1', 'statut' => 'en_cours', 'type' => 'premiere', 'regime' => 'initiale',
        ]);

        return $etudiant->refresh();
    }

    private function creerEpreuve(): Epreuve
    {
        $session = $this->creerSessionPrincipale();
        $examen = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id, 'groupe_id' => $this->groupe->id,
            'libelle' => 'Examen S1', 'type' => 'examen', 'statut' => 'scheduled',
            'responsable_id' => $this->admin->id,
        ]);

        return app(EpreuveService::class)->creer($examen, [
            'session_id' => $session->id, 'matiere_id' => $this->ecue->id,
            'groupe_id' => $this->groupe->id, 'salle_id' => $this->salle->id,
            'date' => '2027-01-06', 'heure_debut' => '08:00', 'heure_fin' => '10:00',
            'type_evaluation' => 'examen',
        ]);
    }
}
