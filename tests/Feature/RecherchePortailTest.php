<?php

namespace Tests\Feature;

use App\Http\Controllers\RecherchePortailController;
use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Announcements\Models\Annonce;
use App\Modules\Enseignement\Models\RessourcePedagogique;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LOOP 18 — Recherche portails : périmètres strictement personnels,
 * résultats bornés, LIKE échappé, aucun champ privé inutile.
 */
class RecherchePortailTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private Matiere $matiere;

    private Group $groupe;

    private Teacher $enseignant;

    private Teacher $etranger;

    private Etudiant $etudiant;

    private Etudiant $etudiantB;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.schedule.active' => true,
            'modules.enseignement.active' => true,
            'modules.online-requests.active' => true,
            'modules.announcements.active' => true,
        ]);
        Storage::fake('private');

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programme = Programme::create([
            'code' => 'L-INFO', 'nom' => 'Licence Info', 'faculte_id' => $this->faculte->id,
            'cycle' => 'licence', 'duree_annees' => 3, 'actif' => true,
        ]);
        $niveau = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE01', 'nom' => 'Fondements', 'credits' => 6, 'coefficient' => 2,
        ]);
        $this->matiere = Matiere::create([
            'code' => 'ALGO101', 'nom' => 'Algorithmique Avancee', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);

        $this->groupe = Group::create([
            'code' => 'G1', 'nom' => 'Groupe 1', 'faculte_id' => $this->faculte->id, 'niveau' => 'L1', 'actif' => true,
        ]);

        $this->enseignant = $this->creerEnseignant($this->faculte);
        $this->etranger = $this->creerEnseignant($this->faculteB);

        $salle = Room::create(['code' => 'B12', 'nom' => 'B12', 'capacite' => 40, 'actif' => true, 'faculte_id' => $this->faculte->id]);
        $planning = Schedule::create([
            'nom' => 'EDT', 'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id, 'programme_id' => $this->programme->id,
            'groupe_id' => $this->groupe->id, 'semestre' => 1, 'statut' => 'publie',
        ]);
        ScheduleSlot::create([
            'schedule_id' => $planning->id, 'matiere_id' => $this->matiere->id,
            'teacher_id' => $this->enseignant->id, 'room_id' => $salle->id,
            'day_of_week' => 'monday', 'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'scheduled',
        ]);

        // LOT 2 : fixtures de non-fuite — un planning brouillon du même
        // groupe (semestre 2) et une séance annulée dans le planning publié.
        $matiereSecrete = Matiere::create([
            'code' => 'SECRET101', 'nom' => 'Séance secrète', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 2, 'credits' => 6, 'coefficient' => 2,
        ]);
        $planningBrouillon = Schedule::create([
            'nom' => 'EDT brouillon', 'annee_universitaire_id' => $this->annee->id,
            'groupe_id' => $this->groupe->id, 'semestre' => 2, 'statut' => 'brouillon',
        ]);
        ScheduleSlot::create([
            'schedule_id' => $planningBrouillon->id, 'matiere_id' => $matiereSecrete->id,
            'teacher_id' => $this->enseignant->id, 'room_id' => $salle->id,
            'day_of_week' => 'tuesday', 'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'scheduled',
        ]);

        $matiereAnnulee = Matiere::create([
            'code' => 'ANNULE101', 'nom' => 'Séance annulée', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 6, 'coefficient' => 2,
        ]);
        ScheduleSlot::create([
            'schedule_id' => $planning->id, 'matiere_id' => $matiereAnnulee->id,
            'teacher_id' => $this->enseignant->id, 'room_id' => $salle->id,
            'day_of_week' => 'wednesday', 'start_time' => '08:00:00', 'end_time' => '10:00:00',
            'type' => 'course', 'status' => 'cancelled',
        ]);

        $this->etudiant = $this->creerEtudiant('08765432', 'kais@etudiant.test', $this->faculte, $this->groupe->id);
        InscriptionAcademique::create([
            'etudiant_id' => $this->etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id, 'niveau_id' => $niveau->id,
            'niveau' => 'L1', 'statut' => 'en_cours', 'type' => 'premiere', 'regime' => 'initiale',
        ]);
        $this->etudiantB = $this->creerEtudiant('12345678', 'sarra@etudiant.test', $this->faculteB, null);

        Storage::disk('private')->put('ressources-pedagogiques/s.pdf', '%PDF-1.4 test');
        RessourcePedagogique::create([
            'teacher_id' => $this->enseignant->id, 'matiere_id' => $this->matiere->id,
            'groupe_id' => null, 'titre' => 'Zebulon Polycope', 'nom_fichier' => 'poly.pdf',
            'chemin' => 'ressources-pedagogiques/s.pdf', 'mime' => 'application/pdf',
            'taille' => 100, 'statut' => 'publie',
        ]);

        $type = RequestType::where('code', 'ATTESTATION_SCOLARITE')->firstOrFail();
        OnlineRequest::create([
            'reference' => 'REQ-ZEB-001', 'etudiant_id' => $this->etudiant->id,
            'request_type_id' => $type->id, 'annee_universitaire_id' => $this->annee->id,
            'objet' => 'Zebulon attestation', 'status' => 'draft',
        ]);

        User::creerCompteBackOffice([
            'name' => 'Admin', 'email' => 'admin.rech@univ.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');

        Annonce::create([
            'titre' => 'Zebulon rentree', 'contenu' => 'Infos', 'statut' => 'published',
            'audience' => 'etudiants', 'faculte_id' => $this->faculte->id,
            'created_by' => 1,
        ]);
    }

    private function creerEnseignant(Faculte $faculte): Teacher
    {
        $departement = Departement::create([
            'code' => 'DEP-'.uniqid(), 'nom' => 'Département', 'faculte_id' => $faculte->id,
        ]);

        return Teacher::create([
            'nom' => 'Nom', 'prenom' => 'Prenom', 'email' => 'ens.'.uniqid().'@univ.test',
            'matricule' => 'ENS-'.uniqid(), 'departement_id' => $departement->id,
            'actif' => true, 'statut' => 'actif', 'password' => 'Motdepasse@123',
        ]);
    }

    private function creerEtudiant(string $cin, string $email, Faculte $faculte, ?int $groupeId): Etudiant
    {
        return Etudiant::create([
            'nom' => 'Zebulon', 'prenom' => 'Kais', 'cin' => $cin, 'email' => $email,
            'telephone' => '98123456', 'date_naissance' => '2004-05-10', 'sexe' => 'M',
            'faculte_id' => $faculte->id, 'groupe_id' => $groupeId,
            'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => '12 rue', 'ville' => 'Tunis', 'code_postal' => '1000',
            'annee_bac' => '2024', 'password' => 'Etudiant@123', 'statut' => 'valide',
            'num_inscription' => 'ETU-'.$cin,
        ]);
    }

    public function test_enseignant_ne_voit_que_son_perimetre_sans_donnee_sensible(): void
    {
        $reponse = $this->withSession(['enseignant_auth_id' => $this->enseignant->id])
            ->get(route('enseignant.recherche', ['q' => 'Zebulon']))
            ->assertOk();

        $reponse->assertSee('Kais Zebulon');
        $reponse->assertDontSee('08765432');
        $reponse->assertDontSee('kais@etudiant.test');
        $reponse->assertSee('Zebulon Polycope');
        // Les demandes étudiantes ne sont jamais exposées côté enseignant.
        $reponse->assertDontSee('REQ-ZEB-001');
    }

    public function test_enseignant_etranger_ne_voit_pas_les_donnees(): void
    {
        // L'enseignant B (autre faculté, aucun créneau) ne voit ni
        // l'étudiant ni le support de l'enseignant A.
        $reponse = $this->withSession(['enseignant_auth_id' => $this->etranger->id])
            ->get(route('enseignant.recherche', ['q' => 'Zebulon']))
            ->assertOk();

        $reponse->assertDontSee('Kais Zebulon');
        $reponse->assertDontSee('Zebulon Polycope');
    }

    public function test_etudiant_ne_voit_que_ses_donnees(): void
    {
        $reponse = $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->get(route('etudiant.recherche', ['q' => 'Zebulon']))
            ->assertOk();

        $reponse->assertSee('Zebulon Polycope');
        $reponse->assertSee('REQ-ZEB-001');
        $reponse->assertSee('Zebulon rentree');
        $reponse->assertDontSee('08765432');
    }

    public function test_etudiant_b_ne_voit_pas_les_donnees_de_a(): void
    {
        $reponse = $this->withSession(['etudiant_auth_id' => $this->etudiantB->id])
            ->get(route('etudiant.recherche', ['q' => 'Zebulon']))
            ->assertOk();

        $reponse->assertDontSee('Zebulon Polycope');
        $reponse->assertDontSee('REQ-ZEB-001');
        $reponse->assertDontSee('Zebulon rentree');
    }

    public function test_recherche_etudiant_ne_voit_pas_les_seances_non_publiees_ou_annulees(): void
    {
        // JSON : le motif n'est pas réaffiché dans le champ de recherche,
        // l'assertion porte donc réellement sur les résultats.
        $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->getJson(route('etudiant.recherche', ['q' => 'ALGO101']))
            ->assertOk()
            ->assertJsonCount(1, 'sections.Mes séances')
            ->assertJsonPath('sections.Mes séances.0.titre', 'ALGO101 · B12');

        // Planning brouillon du même groupe : jamais exposé.
        $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->getJson(route('etudiant.recherche', ['q' => 'SECRET101']))
            ->assertOk()
            ->assertJsonCount(0, 'sections.Mes séances');

        // Séance annulée dans un planning publié : jamais exposée.
        $this->withSession(['etudiant_auth_id' => $this->etudiant->id])
            ->getJson(route('etudiant.recherche', ['q' => 'ANNULE101']))
            ->assertOk()
            ->assertJsonCount(0, 'sections.Mes séances');
    }

    public function test_motif_trop_court_et_caracteres_speciaux(): void
    {
        // 1 caractère : état vide, aucune requête sensible exécutée.
        $this->withSession(['enseignant_auth_id' => $this->enseignant->id])
            ->get(route('enseignant.recherche', ['q' => 'Z']))
            ->assertOk()
            ->assertSee('au moins 2 caractères');

        // Joker SQL échappé : pas d'explosion de résultats.
        $reponse = $this->withSession(['enseignant_auth_id' => $this->enseignant->id])
            ->get(route('enseignant.recherche', ['q' => '%_%']))
            ->assertOk();

        $reponse->assertDontSee('Kais Zebulon');
    }

    public function test_gardes_et_format_json(): void
    {
        $this->get(route('enseignant.recherche', ['q' => 'Zebulon']))
            ->assertRedirect(route('enseignant.connexion.show'));
        $this->get(route('etudiant.recherche', ['q' => 'Zebulon']))
            ->assertRedirect(route('etudiant.connexion.show'));

        $json = $this->withSession(['enseignant_auth_id' => $this->enseignant->id])
            ->getJson(route('enseignant.recherche', ['q' => 'Zebulon']))
            ->assertOk()
            ->json('sections');

        $this->assertArrayHasKey('Étudiants de mes groupes', $json);
    }

    public function test_controleur_borne_les_resultats_a_dix(): void
    {
        $reflet = new \ReflectionClass(RecherchePortailController::class);

        foreach (['etudiantsEnseignant', 'seancesEnseignant', 'epreuvesEnseignant', 'supportsEnseignant', 'supportsEtudiant', 'demandesEtudiant', 'seancesEtudiant'] as $methode) {
            $source = file_get_contents($reflet->getFileName());
            $this->assertStringContainsString('limit(10)', $source, $methode);
        }
    }
}
