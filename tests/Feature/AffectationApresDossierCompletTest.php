<?php

namespace Tests\Feature;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Schedule\Models\Group;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Services\InscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 14 — preuve de chaîne « dossier complet si cette règle est
 * confirmée » (§cahier : l'affectation automatique ne peut survenir
 * qu'APRÈS validation, et la validation elle-même exige les pièces
 * obligatoires dès que la matrice en impose) :
 *
 * matrice configurée → sans pièce : transiter(valide) REFUSÉ
 * (REGISTRATION_DOCUMENTS_INCOMPLETE) → AUCUN évènement, AUCUNE
 * affectation ; pièces validées → validation → affectation automatique.
 *
 * Complète les Cas 1-11 d'AffectationAutomatiqueApresValidationTest
 * (compatibilité, équilibrage, capacité, idempotence, verrous, absence
 * de groupe) qui restent la référence du moteur.
 */
class AffectationApresDossierCompletTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        // Règle « dossier complet » CONFIRMÉE : la matrice impose une pièce.
        config([
            'inscriptions.documents_requis' => ['piece_identite'],
            'inscriptions.documents_requis_par_type' => ['premiere' => ['piece_identite']],
        ]);

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.chaine@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    public function test_sans_pieces_validation_refusee_donc_aucune_affectation(): void
    {
        $groupe = $this->creerGroupe('L1-CH01');
        $etudiant = $this->creerEtudiant('CHN00001');
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();

        try {
            app(InscriptionService::class)->transiter($inscription, 'valide', $this->scolarite->id);
            $this->fail('La validation aurait dû être refusée : pièces obligatoires incomplètes.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('REGISTRATION_DOCUMENTS_INCOMPLETE', $e->getMessage());
        }

        // Chaîne coupée à la racine : pas de validation, donc ni évènement
        // d'affectation, ni affectation, ni groupe sur l'étudiant.
        $this->assertSame('en_cours', $inscription->fresh()->statut);
        $this->assertSame(0, AffectationGroupe::where('etudiant_id', $etudiant->id)->count());
        $this->assertNull($etudiant->fresh()->groupe_id);
        $this->assertDatabaseMissing('historique_academique', ['action' => 'affectation.auto_assignee']);
    }

    public function test_pieces_valides_puis_validation_puis_affectation_automatique(): void
    {
        $groupe = $this->creerGroupe('L1-CH02');
        $etudiant = $this->creerEtudiant('CHN00002');
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();

        // Pièce exigée déposée ET validée par la scolarité (GED réelle).
        $dossier = StudentDossier::create([
            'etudiant_id' => $etudiant->id,
            'reference' => 'DOS-'.$etudiant->num_inscription,
            'status' => 'open',
        ]);
        StudentDocument::create([
            'student_dossier_id' => $dossier->id,
            'category' => 'identity',
            'document_type' => 'piece_identite',
            'title' => "Pièce d'identité",
            'original_filename' => 'identite.pdf',
            'storage_path' => 'private/dossiers/identite.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 1024,
            'sha256' => hash('sha256', 'piece'),
            'status' => 'validated',
        ]);

        app(InscriptionService::class)->transiter($inscription, 'valide', $this->scolarite->id);

        // Chaîne complète : dossier complet → validation → affectation auto.
        $this->assertSame('valide', $inscription->fresh()->statut);

        $affectation = AffectationGroupe::where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $this->annee->id)
            ->where('statut', 'active')
            ->firstOrFail();

        $this->assertSame($groupe->id, (int) $affectation->groupe_id);
        $this->assertSame($groupe->id, (int) $etudiant->fresh()->groupe_id);
    }

    public function test_matrice_vide_validation_sans_piece_reste_autorisee(): void
    {
        // Règle non confirmée (matrice vide) : transition legacy préservée.
        config(['inscriptions.documents_requis' => [], 'inscriptions.documents_requis_par_type' => []]);

        $groupe = $this->creerGroupe('L1-CH03');
        $etudiant = $this->creerEtudiant('CHN00003');
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();

        app(InscriptionService::class)->transiter($inscription, 'valide', $this->scolarite->id);

        $this->assertSame('valide', $inscription->fresh()->statut);
        $this->assertSame(
            1,
            AffectationGroupe::where('etudiant_id', $etudiant->id)->where('statut', 'active')->count(),
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
            'cin' => $cin, 'nom' => 'Chaine', 'prenom' => 'Sara', 'email' => strtolower($cin).'@chaine.test',
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
