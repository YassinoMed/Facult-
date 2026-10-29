<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Enseignement\Models\AffectationEnseignement;
use App\Modules\Enseignement\Models\Enseignement;
use App\Modules\Enseignement\Models\TypeEnseignement;
use App\Modules\Evaluation\Models\Candidat;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\EvaluationCompostage;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\NoteEpreuveHistorique;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * LOOP 05 — Saisie des notes côté enseignant (voie strictement anonyme).
 *
 * Éligibilité = ECUE affectée (affectation `active`) ; 404 sinon.
 * Aucune identité étudiante exposée ; mêmes gardes métier que le
 * back-office (candidats, bornes, verrou) ; ni validation, ni
 * correspondance CMP → identité.
 */
class EnseignantNotesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private Teacher $enseignant;

    private Teacher $etranger;

    private Matiere $matiereMienne;

    private Matiere $matiereAutre;

    private Epreuve $epreuveMienne;

    private Epreuve $epreuveAutre;

    private Etudiant $etudiant1;

    private Etudiant $etudiant2;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.evaluation.active' => true]);

        $this->faculteA = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $admin = User::creerCompteBackOffice([
            'name' => 'Admin', 'email' => 'admin.notes@univ.test', 'password' => 'Motdepasse@123',
        ], 'super_admin');

        $prog = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'LGL', 'nom' => 'Licence GL',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niv = Niveau::create(['programme_id' => $prog->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $sem = SemestreLmd::create(['niveau_id' => $niv->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $sem->id, 'programme_id' => $prog->id,
            'code' => 'UE-INF1', 'nom' => 'Fondements', 'credits' => 6, 'coefficient' => 2,
        ]);

        $this->matiereMienne = Matiere::create([
            'code' => 'INFO-101', 'nom' => 'Algorithmique', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 3, 'coefficient' => 1.5,
        ]);
        $this->matiereAutre = Matiere::create([
            'code' => 'INFO-102', 'nom' => 'Architecture', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 3, 'coefficient' => 1.5,
        ]);

        $type = TypeEnseignement::create(['code' => 'CM', 'nom' => 'Cours Magistral', 'actif' => true]);

        $this->enseignant = $this->creerEnseignant($this->faculteA);
        $this->etranger = $this->creerEnseignant($this->faculteA);

        $enseignement = Enseignement::create([
            'code' => 'ENS-ALGO', 'matiere_id' => $this->matiereMienne->id, 'type_id' => $type->id,
            'annee_universitaire_id' => $this->annee->id, 'programme_id' => $prog->id,
            'statut' => 'active', 'cree_par' => $admin->id,
        ]);
        AffectationEnseignement::create([
            'enseignement_id' => $enseignement->id, 'teacher_id' => $this->enseignant->id,
            'role' => 'enseignant', 'volume_heures' => 20, 'statut' => 'active', 'auteur_id' => $admin->id,
        ]);

        $groupe = Group::create([
            'code' => 'GL-G1', 'nom' => 'Groupe 1', 'faculte_id' => $this->faculteA->id, 'niveau' => 'L1', 'actif' => true,
        ]);
        $salle = Room::create(['code' => 'A1', 'nom' => 'A1', 'capacite' => 50, 'actif' => true]);

        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'SESS-P1', 'nom' => 'Principale',
            'type' => 'principale', 'date_debut' => '2027-01-10', 'date_fin' => '2027-01-25', 'statut' => 'open',
        ]);
        $examen = Examen::create([
            'session_id' => $session->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $prog->id, 'libelle' => 'Examens S1', 'statut' => 'scheduled',
        ]);

        $fabriquerEpreuve = fn (Matiere $m, string $suffixe) => Epreuve::create([
            'examen_id' => $examen->id, 'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id, 'matiere_id' => $m->id,
            'groupe_id' => $groupe->id, 'salle_id' => $salle->id,
            'date' => '2027-01-12', 'heure_debut' => '08:30:00', 'heure_fin' => '10:30:00',
            'code' => 'EPR-'.$suffixe, 'coefficient' => 1.5, 'bareme' => 20, 'note_max' => 20,
            'statut' => 'scheduled',
        ]);

        $this->epreuveMienne = $fabriquerEpreuve($this->matiereMienne, 'MOI');
        $this->epreuveAutre = $fabriquerEpreuve($this->matiereAutre, 'AUTRE');

        $this->etudiant1 = $this->creerEtudiant('08765432', 'kais.benali@etudiant.test');
        $this->etudiant2 = $this->creerEtudiant('12345678', 'sarra.trabelsi@etudiant.test');

        Candidat::create(['epreuve_id' => $this->epreuveMienne->id, 'etudiant_id' => $this->etudiant1->id, 'statut' => 'present']);
        Candidat::create(['epreuve_id' => $this->epreuveMienne->id, 'etudiant_id' => $this->etudiant2->id, 'statut' => 'present']);
    }

    public function test_saisie_enseignant_refuse_trois_decimales_puis_bareme_nul(): void
    {
        $this->sessionEnseignant($this->enseignant)
            ->get(route('enseignant.notes.saisie', $this->epreuveMienne))
            ->assertOk();

        $numero = EvaluationCompostage::where('epreuve_id', $this->epreuveMienne->id)->firstOrFail()->numero;

        $this->sessionEnseignant($this->enseignant)
            ->post(route('enseignant.notes.store', $this->epreuveMienne), [
                'lignes' => [['numero' => $numero, 'valeur' => 12.345]],
            ])
            ->assertSessionHasErrors('lignes.0.valeur');

        $this->epreuveMienne->forceFill(['note_max' => 0])->save();

        $this->sessionEnseignant($this->enseignant)
            ->post(route('enseignant.notes.store', $this->epreuveMienne), [
                'lignes' => [['numero' => $numero, 'valeur' => 10]],
            ])
            ->assertSessionHasErrors('lignes.0.valeur');

        $this->assertEquals(0, NoteEpreuve::where('epreuve_id', $this->epreuveMienne->id)->count());
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

    private function creerEtudiant(string $cin, string $email): Etudiant
    {
        return Etudiant::create([
            'nom' => 'Nom-'.$cin, 'prenom' => 'Prenom', 'cin' => $cin, 'email' => $email,
            'telephone' => '98'.substr($cin, 0, 6), 'date_naissance' => '2004-05-10', 'sexe' => 'M',
            'faculte_id' => $this->faculteA->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => '12 rue', 'ville' => 'Tunis', 'code_postal' => '1000',
            'annee_bac' => '2024', 'password' => 'Etudiant@123', 'statut' => 'valide',
            'num_inscription' => 'ETU-'.$cin,
        ]);
    }

    private function sessionEnseignant(Teacher $enseignant): static
    {
        return $this->withSession(['enseignant_auth_id' => $enseignant->id]);
    }

    public function test_index_ne_liste_que_mes_epreuves(): void
    {
        $this->sessionEnseignant($this->enseignant)
            ->get(route('enseignant.notes.index'))
            ->assertOk()
            ->assertSee($this->epreuveMienne->code)
            ->assertDontSee($this->epreuveAutre->code);
    }

    public function test_epreuve_autrui_renvoie_404(): void
    {
        $this->sessionEnseignant($this->enseignant)
            ->get(route('enseignant.notes.saisie', $this->epreuveAutre))
            ->assertNotFound();

        $this->sessionEnseignant($this->etranger)
            ->get(route('enseignant.notes.saisie', $this->epreuveMienne))
            ->assertNotFound();
    }

    public function test_saisie_anonyme_enregistre_sans_exposer_identites(): void
    {
        $compostages = EvaluationCompostage::where('epreuve_id', $this->epreuveMienne->id)->orderBy('numero')->get();
        $this->assertCount(0, $compostages);

        $reponse = $this->sessionEnseignant($this->enseignant)
            ->get(route('enseignant.notes.saisie', $this->epreuveMienne))
            ->assertOk();

        // Numéros générés à l'ouverture, aucune identité en clair.
        $reponse->assertSee('CMP-');
        $reponse->assertDontSee($this->etudiant1->nom.$this->etudiant1->cin);
        $reponse->assertDontSee($this->etudiant1->email);
        $reponse->assertDontSee($this->etudiant1->cin);

        $numeros = EvaluationCompostage::where('epreuve_id', $this->epreuveMienne->id)->orderBy('numero')->pluck('numero')->all();
        $this->assertCount(2, $numeros);

        $this->sessionEnseignant($this->enseignant)
            ->post(route('enseignant.notes.store', $this->epreuveMienne), [
                'lignes' => [
                    ['numero' => $numeros[0], 'valeur' => 14.5],
                    ['numero' => $numeros[1], 'valeur' => 9],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertEquals(2, NoteEpreuve::where('epreuve_id', $this->epreuveMienne->id)->count());
        // Auteur portail : aucun compte back-office imputé, mais historique tracé.
        $this->assertTrue(NoteEpreuve::where('epreuve_id', $this->epreuveMienne->id)->whereNull('saisi_par')->exists());
        $note = NoteEpreuve::where('epreuve_id', $this->epreuveMienne->id)->first();
        $historique = NoteEpreuveHistorique::where('evaluation_note_id', $note->id)->first();
        $this->assertNotNull($historique);
        $this->assertStringContainsString('Enseignant', (string) $historique->motif);
    }

    public function test_saisie_refuse_note_hors_bornes_et_epreuve_verrouillee(): void
    {
        $this->sessionEnseignant($this->enseignant)
            ->get(route('enseignant.notes.saisie', $this->epreuveMienne))
            ->assertOk();

        $numero = EvaluationCompostage::where('epreuve_id', $this->epreuveMienne->id)->firstOrFail()->numero;

        $this->sessionEnseignant($this->enseignant)
            ->post(route('enseignant.notes.store', $this->epreuveMienne), [
                'lignes' => [['numero' => $numero, 'valeur' => 25]],
            ])
            ->assertSessionHasErrors();

        $this->assertEquals(0, NoteEpreuve::where('epreuve_id', $this->epreuveMienne->id)->count());

        $this->epreuveMienne->update(['statut' => 'locked']);

        $this->sessionEnseignant($this->enseignant)
            ->post(route('enseignant.notes.store', $this->epreuveMienne), [
                'lignes' => [['numero' => $numero, 'valeur' => 12]],
            ])
            ->assertSessionHasErrors(['epreuve']);

        $this->sessionEnseignant($this->enseignant)
            ->get(route('enseignant.notes.saisie', $this->epreuveMienne))
            ->assertOk()
            ->assertSee('Verrouill');
    }

    public function test_import_csv_anonyme_et_rejet_colonne_identifiante(): void
    {
        $this->sessionEnseignant($this->enseignant)
            ->get(route('enseignant.notes.saisie', $this->epreuveMienne))
            ->assertOk();

        $numeros = EvaluationCompostage::where('epreuve_id', $this->epreuveMienne->id)->orderBy('numero')->pluck('numero')->all();

        $csv = "numero,note\n{$numeros[0]},15\n{$numeros[1]},11.5\n";
        $fichier = UploadedFile::fake()->createWithContent('notes.csv', $csv);

        $this->sessionEnseignant($this->enseignant)
            ->post(route('enseignant.notes.importer', $this->epreuveMienne), ['fichier' => $fichier])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertEquals(2, NoteEpreuve::where('epreuve_id', $this->epreuveMienne->id)->count());

        // Test délimiteur alternatif point-virgule (;)
        $csvPv = "numero;note\n{$numeros[0]};16\n{$numeros[1]};13\n";
        $fichierPv = UploadedFile::fake()->createWithContent('notes_pv.csv', $csvPv);

        $this->sessionEnseignant($this->enseignant)
            ->post(route('enseignant.notes.importer', $this->epreuveMienne), ['fichier' => $fichierPv])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $csvPiege = "numero,note,nom\n{$numeros[0]},15,Dupont\n";
        $piege = UploadedFile::fake()->createWithContent('piege.csv', $csvPiege);

        $this->sessionEnseignant($this->enseignant)
            ->post(route('enseignant.notes.importer', $this->epreuveMienne), ['fichier' => $piege])
            ->assertSessionHasErrors(['fichier']);
    }

    public function test_export_anonyme_sans_identite_et_idor(): void
    {
        $this->sessionEnseignant($this->enseignant)
            ->get(route('enseignant.notes.saisie', $this->epreuveMienne))
            ->assertOk();

        $reponse = $this->sessionEnseignant($this->enseignant)
            ->get(route('enseignant.notes.exporter', $this->epreuveMienne))
            ->assertOk();

        $contenu = $reponse->streamedContent();
        $this->assertStringContainsString('compostage', $contenu);
        $this->assertStringNotContainsString($this->etudiant1->email, $contenu);
        $this->assertStringNotContainsString($this->etudiant1->cin, $contenu);

        $this->sessionEnseignant($this->etranger)
            ->get(route('enseignant.notes.exporter', $this->epreuveMienne))
            ->assertNotFound();

        // Aucune route de correspondance CMP → identité côté enseignant.
        $this->assertFalse(Route::has('enseignant.notes.correspondance'));
    }

    public function test_garde_exige_authentification(): void
    {
        $this->get(route('enseignant.notes.index'))
            ->assertRedirect(route('enseignant.connexion.show'));
        $this->get(route('enseignant.notes.saisie', $this->epreuveMienne))
            ->assertRedirect(route('enseignant.connexion.show'));
    }
}
