<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Modules\Enseignement\Models\AffectationEnseignement;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\RessourcePedagogique;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests des fonctionnalités LOOP 06 (Supports enseignant) et LOOP 14 (Supports étudiant).
 */
class RessourcesPedagogiquesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Teacher $enseignantA;

    private Teacher $enseignantB;

    private Matiere $matiereA;

    private Matiere $matiereB;

    private Group $groupeA;

    private Group $groupeB;

    private Etudiant $etudiantA;

    private Etudiant $etudiantB;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        config(['modules.enseignement.active' => true]);

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $departement = Departement::create([
            'code' => 'DEP-INFO',
            'nom' => 'Informatique',
            'faculte_id' => $this->faculte->id,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'L-INFO',
            'nom' => 'Licence Informatique',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
        ]);

        $niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
        ]);

        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id,
            'code' => 'S1',
            'nom' => 'Semestre 1',
            'numero' => 1,
            'ordre' => 1,
        ]);

        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id,
            'programme_id' => $this->programme->id,
            'code' => 'UE-INF',
            'nom' => 'Informatique Fondamentale',
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $this->matiereA = Matiere::create([
            'code' => 'ALGO',
            'nom' => 'Algorithmique',
            'ue_id' => $ue->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 3,
            'coefficient' => 1.5,
        ]);

        $this->matiereB = Matiere::create([
            'code' => 'MATHS',
            'nom' => 'Mathématiques',
            'ue_id' => $ue->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 3,
            'coefficient' => 1.5,
        ]);

        $this->groupeA = Group::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'GRP-A',
            'nom' => 'Groupe A',
            'niveau' => 'L1',
            'actif' => true,
        ]);

        $this->groupeB = Group::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'GRP-B',
            'nom' => 'Groupe B',
            'niveau' => 'L1',
            'actif' => true,
        ]);

        $this->enseignantA = Teacher::create([
            'nom' => 'Dupont',
            'prenom' => 'Jean',
            'email' => 'jean.dupont@univ.test',
            'matricule' => 'ENS-001',
            'departement_id' => $departement->id,
            'actif' => true,
            'statut' => 'actif',
            'password' => 'Secret@123',
        ]);

        $this->enseignantB = Teacher::create([
            'nom' => 'Martin',
            'prenom' => 'Claire',
            'email' => 'claire.martin@univ.test',
            'matricule' => 'ENS-002',
            'departement_id' => $departement->id,
            'actif' => true,
            'statut' => 'actif',
            'password' => 'Secret@123',
        ]);

        // Affectation de l'enseignant A à la matière A
        $type = TypeEnseignement::create([
            'code' => 'CM',
            'nom' => 'Cours Magistral',
            'heures_cm' => 1.0,
            'heures_td' => 0.0,
            'heures_tp' => 0.0,
            'actif' => true,
        ]);

        $enseignement = Enseignement::create([
            'code' => 'ENS-ALGO',
            'matiere_id' => $this->matiereA->id,
            'type_id' => $type->id,
            'annee_universitaire_id' => $this->annee->id,
            'semestre_id' => $semestre->id,
            'programme_id' => $this->programme->id,
            'statut' => 'active',
        ]);

        AffectationEnseignement::create([
            'enseignement_id' => $enseignement->id,
            'teacher_id' => $this->enseignantA->id,
            'heures_attribuees' => 24,
            'statut' => 'active',
        ]);

        // Étudiants
        $this->etudiantA = Etudiant::create([
            'cin' => '11223344',
            'nom' => 'Etudiant',
            'prenom' => 'Alpha',
            'email' => 'alpha@etudiant.test',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'groupe_id' => $this->groupeA->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue Test',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2025',
            'password' => 'Etudiant@123',
            'statut' => 'valide',
        ]);

        InscriptionAcademique::create([
            'etudiant_id' => $this->etudiantA->id,
            'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'statut' => 'inscrit',
        ]);

        $this->etudiantB = Etudiant::create([
            'cin' => '55667788',
            'nom' => 'Etudiant',
            'prenom' => 'Beta',
            'email' => 'beta@etudiant.test',
            'sexe' => 'F',
            'faculte_id' => $this->faculte->id,
            'groupe_id' => $this->groupeB->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue Test',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2025',
            'password' => 'Etudiant@123',
            'statut' => 'valide',
        ]);
    }

    private function sessionEnseignant(Teacher $enseignant): static
    {
        return $this->withSession(['enseignant_auth_id' => $enseignant->id]);
    }

    private function sessionEtudiant(Etudiant $etudiant): static
    {
        return $this->withSession(['etudiant_auth_id' => $etudiant->id]);
    }

    public function test_enseignant_peut_creer_un_support_en_brouillon(): void
    {
        $fichier = UploadedFile::fake()->createWithContent('chapitre1.pdf', "%PDF-1.4\nExemple de cours");

        $reponse = $this->sessionEnseignant($this->enseignantA)
            ->post(route('enseignant.ressources.store'), [
                'titre' => 'Chapitre 1 — Introduction Algorithmes',
                'description' => 'Supports du cours 1.',
                'matiere_id' => $this->matiereA->id,
                'groupe_id' => $this->groupeA->id,
                'fichier' => $fichier,
            ]);

        $reponse->assertSessionHasNoErrors();
        $this->assertDatabaseHas('ressources_pedagogiques', [
            'titre' => 'Chapitre 1 — Introduction Algorithmes',
            'teacher_id' => $this->enseignantA->id,
            'matiere_id' => $this->matiereA->id,
            'groupe_id' => $this->groupeA->id,
            'statut' => 'brouillon',
        ]);
    }

    public function test_enseignant_ne_peut_pas_creer_support_pour_matiere_non_affectee(): void
    {
        $fichier = UploadedFile::fake()->createWithContent('chapitre1.pdf', "%PDF-1.4\nTest");

        $reponse = $this->sessionEnseignant($this->enseignantA)
            ->post(route('enseignant.ressources.store'), [
                'titre' => 'Maths pour tous',
                'matiere_id' => $this->matiereB->id, // Matière non affectée à enseignant A
                'fichier' => $fichier,
            ]);

        $reponse->assertStatus(422);
        $this->assertDatabaseMissing('ressources_pedagogiques', ['titre' => 'Maths pour tous']);
    }

    public function test_rejet_fichier_avec_mauvaise_signature_ou_extension_interdite(): void
    {
        // Fausse signature PDF
        $fauxPdf = UploadedFile::fake()->createWithContent('malveillant.pdf', 'Faux contenu sans signature');

        $reponse = $this->sessionEnseignant($this->enseignantA)
            ->post(route('enseignant.ressources.store'), [
                'titre' => 'Support piégé',
                'matiere_id' => $this->matiereA->id,
                'fichier' => $fauxPdf,
            ]);

        $reponse->assertSessionHasErrors(['fichier']);

        // Extension non autorisée
        $exeFichier = UploadedFile::fake()->create('script.exe', 100);

        $reponse2 = $this->sessionEnseignant($this->enseignantA)
            ->post(route('enseignant.ressources.store'), [
                'titre' => 'Script',
                'matiere_id' => $this->matiereA->id,
                'fichier' => $exeFichier,
            ]);

        $reponse2->assertSessionHasErrors(['fichier']);
    }

    public function test_cycle_de_vie_brouillon_publie_archive(): void
    {
        $ressource = RessourcePedagogique::create([
            'teacher_id' => $this->enseignantA->id,
            'matiere_id' => $this->matiereA->id,
            'titre' => 'Polycopié TD',
            'nom_fichier' => 'poly.pdf',
            'chemin' => 'ressources-pedagogiques/poly.pdf',
            'mime' => 'application/pdf',
            'taille' => 1024,
            'statut' => 'brouillon',
        ]);

        Storage::disk('private')->put('ressources-pedagogiques/poly.pdf', "%PDF-1.4\nContenu");

        // Publication
        $this->sessionEnseignant($this->enseignantA)
            ->post(route('enseignant.ressources.publier', $ressource))
            ->assertRedirect();
        $this->assertSame('publie', $ressource->fresh()->statut);

        // Archivage
        $this->sessionEnseignant($this->enseignantA)
            ->post(route('enseignant.ressources.archiver', $ressource))
            ->assertRedirect();
        $this->assertSame('archive', $ressource->fresh()->statut);

        // Repasser en brouillon
        $this->sessionEnseignant($this->enseignantA)
            ->post(route('enseignant.ressources.retirer', $ressource))
            ->assertRedirect();
        $this->assertSame('brouillon', $ressource->fresh()->statut);
    }

    public function test_interdiction_suppression_ressource_publiee(): void
    {
        $ressource = RessourcePedagogique::create([
            'teacher_id' => $this->enseignantA->id,
            'matiere_id' => $this->matiereA->id,
            'titre' => 'Support actif',
            'nom_fichier' => 'actif.pdf',
            'chemin' => 'ressources-pedagogiques/actif.pdf',
            'mime' => 'application/pdf',
            'taille' => 1024,
            'statut' => 'publie',
        ]);

        $this->sessionEnseignant($this->enseignantA)
            ->delete(route('enseignant.ressources.destroy', $ressource))
            ->assertStatus(422);

        $this->assertDatabaseHas('ressources_pedagogiques', ['id' => $ressource->id]);
    }

    public function test_anti_idor_entre_enseignants(): void
    {
        $ressourceA = RessourcePedagogique::create([
            'teacher_id' => $this->enseignantA->id,
            'matiere_id' => $this->matiereA->id,
            'titre' => 'Support secret Enseignant A',
            'nom_fichier' => 'secret.pdf',
            'chemin' => 'ressources-pedagogiques/secret.pdf',
            'mime' => 'application/pdf',
            'taille' => 1024,
            'statut' => 'brouillon',
        ]);

        // Enseignant B tente de voir ou modifier la ressource de A -> 404
        $this->sessionEnseignant($this->enseignantB)
            ->get(route('enseignant.ressources.show', $ressourceA))
            ->assertNotFound();

        $this->sessionEnseignant($this->enseignantB)
            ->post(route('enseignant.ressources.publier', $ressourceA))
            ->assertNotFound();

        $this->sessionEnseignant($this->enseignantB)
            ->delete(route('enseignant.ressources.destroy', $ressourceA))
            ->assertNotFound();
    }

    public function test_etudiant_voit_support_publie_de_son_groupe_et_pas_les_brouillons(): void
    {
        // Support brouillon -> non visible
        $brouillon = RessourcePedagogique::create([
            'teacher_id' => $this->enseignantA->id,
            'matiere_id' => $this->matiereA->id,
            'groupe_id' => $this->groupeA->id,
            'titre' => 'Brouillon non public',
            'nom_fichier' => 'brouillon.pdf',
            'chemin' => 'ressources-pedagogiques/brouillon.pdf',
            'mime' => 'application/pdf',
            'taille' => 1024,
            'statut' => 'brouillon',
        ]);

        // Support publié pour groupe A
        $publieA = RessourcePedagogique::create([
            'teacher_id' => $this->enseignantA->id,
            'matiere_id' => $this->matiereA->id,
            'groupe_id' => $this->groupeA->id,
            'titre' => 'Support TP Groupe A',
            'nom_fichier' => 'tp_a.pdf',
            'chemin' => 'ressources-pedagogiques/tp_a.pdf',
            'mime' => 'application/pdf',
            'taille' => 1024,
            'statut' => 'publie',
        ]);

        // Support publié pour groupe B
        $publieB = RessourcePedagogique::create([
            'teacher_id' => $this->enseignantA->id,
            'matiere_id' => $this->matiereA->id,
            'groupe_id' => $this->groupeB->id,
            'titre' => 'Support TP Groupe B',
            'nom_fichier' => 'tp_b.pdf',
            'chemin' => 'ressources-pedagogiques/tp_b.pdf',
            'mime' => 'application/pdf',
            'taille' => 1024,
            'statut' => 'publie',
        ]);

        Storage::disk('private')->put('ressources-pedagogiques/tp_a.pdf', "%PDF-1.4\nTP A");
        Storage::disk('private')->put('ressources-pedagogiques/tp_b.pdf', "%PDF-1.4\nTP B");

        // Étudiant A consulte ses supports
        $reponse = $this->sessionEtudiant($this->etudiantA)
            ->get(route('etudiant.ressources.index'));

        $reponse->assertOk();
        $reponse->assertSee('Support TP Groupe A');
        $reponse->assertDontSee('Support TP Groupe B');
        $reponse->assertDontSee('Brouillon non public');

        // Étudiant A télécharge son support
        $this->sessionEtudiant($this->etudiantA)
            ->get(route('etudiant.ressources.telecharger', $publieA))
            ->assertOk();

        // Étudiant A tente de télécharger le support du groupe B -> 404
        $this->sessionEtudiant($this->etudiantA)
            ->get(route('etudiant.ressources.telecharger', $publieB))
            ->assertNotFound();
    }
}
