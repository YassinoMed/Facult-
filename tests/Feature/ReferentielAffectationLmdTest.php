<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Domaine;
use App\Modules\Referentiel\Models\Mention;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\Specialite;
use App\Modules\Schedule\Models\Group;
use App\Services\Affectations\MassAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Référentiel LMD ↔ affectation (LOOP 2, sans migration) : la chaîne
 * Domaine→Mention→Specialite→Programme→Niveau existe et l'affectation
 * la respecte via faculte+programme+niveau (contrainte forte).
 */
class ReferentielAffectationLmdTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Bench', 'email' => 'lmd@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    /**
     * @return array{domaineA: Domaine, mentionA: Mention, specialiteA: Specialite, programmeA: Programme, niveauA: Niveau}
     */
    private function chaineLmd(string $suffixe): array
    {
        $domaine = Domaine::create([
            'faculte_id' => $this->faculte->id, 'code' => 'DOM-'.$suffixe, 'nom' => 'Domaine '.$suffixe, 'actif' => true,
        ]);
        $mention = Mention::create([
            'domaine_id' => $domaine->id, 'code' => 'MEN-'.$suffixe, 'nom' => 'Mention '.$suffixe, 'actif' => true,
        ]);
        $specialite = Specialite::create([
            'mention_id' => $mention->id, 'code' => 'SPE-'.$suffixe, 'nom' => 'Spécialité '.$suffixe, 'actif' => true,
        ]);
        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'specialite_id' => $specialite->id,
            'code' => 'PROG-'.$suffixe, 'nom' => 'Programme '.$suffixe,
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create([
            'programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1,
        ]);

        return [
            'domaineA' => $domaine, 'mentionA' => $mention, 'specialiteA' => $specialite,
            'programmeA' => $programme, 'niveauA' => $niveau,
        ];
    }

    private function creerEtudiant(string $cin, Programme $programme, string $niveau = 'L1'): Etudiant
    {
        static $compteur = 800;
        $compteur++;

        $etudiant = Etudiant::create([
            'cin' => $cin, 'num_inscription' => 'INS-L'.$compteur,
            'nom' => 'Lmd', 'prenom' => 'Test'.$compteur, 'email' => "lmd{$compteur}@universite.test",
            'date_naissance' => '2004-01-15', 'sexe' => 'M', 'telephone' => '98000111',
            'faculte_id' => $this->faculte->id, 'programme_id' => $programme->id,
            'niveau' => $niveau, 'annee_academique' => '2026-2027',
            'adresse' => 'Rue X', 'ville' => 'Tunis', 'code_postal' => '1000',
            'annee_bac' => '2023', 'password' => 'Secret@123',
        ]);
        $etudiant->definirStatut('valide');
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => $niveau, 'statut' => 'valide',
        ]);

        return $etudiant;
    }

    private function creerGroupe(string $code, Programme $programme, string $niveau = 'L1'): Group
    {
        return Group::create([
            'code' => $code, 'nom' => $code, 'faculte_id' => $this->faculte->id,
            'programme_id' => $programme->id, 'niveau' => $niveau, 'capacite' => 30, 'actif' => true,
        ]);
    }

    public function test_hierarchie_domaine_mention_specialite_programme_niveau(): void
    {
        $chaine = $this->chaineLmd('A');

        $this->assertTrue($chaine['programmeA']->specialite->is($chaine['specialiteA']));
        $this->assertTrue($chaine['specialiteA']->mention->is($chaine['mentionA']));
        $this->assertTrue($chaine['mentionA']->domaine->is($chaine['domaineA']));
        $this->assertTrue($chaine['niveauA']->programme->is($chaine['programmeA']));
        $this->assertTrue($chaine['domaineA']->mentions->contains($chaine['mentionA']));
    }

    public function test_mention_differente_jamais_affectee_automatiquement(): void
    {
        $chaineA = $this->chaineLmd('A');
        $chaineB = $this->chaineLmd('B');
        $this->creerEtudiant('LMD00001', $chaineA['programmeA']);
        $this->creerGroupe('G-MENB-01', $chaineB['programmeA']);

        $analyse = app(MassAssignmentService::class)->analyser([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
        ], $this->admin);

        $this->assertSame(0, $analyse['assigned_count']);
        $this->assertSame('AUCUN_GROUPE_COMPATIBLE', $analyse['anomalies'][0]['code']);
    }

    public function test_meme_mention_meme_programme_affecte(): void
    {
        $chaine = $this->chaineLmd('C');
        $etudiant = $this->creerEtudiant('LMD00002', $chaine['programmeA']);
        $groupe = $this->creerGroupe('G-MENC-01', $chaine['programmeA']);

        $analyse = app(MassAssignmentService::class)->analyser([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
            'etudiant_ids' => [$etudiant->id],
        ], $this->admin);

        $this->assertSame(1, $analyse['assigned_count']);
        $this->assertSame($groupe->id, $analyse['propositions'][0]['proposed_group_id']);
    }

    public function test_niveau_string_reste_contrainte_absolue(): void
    {
        $chaine = $this->chaineLmd('D');
        $this->creerEtudiant('LMD00003', $chaine['programmeA'], 'L1');
        $this->creerGroupe('G-MEND-L2', $chaine['programmeA'], 'L2');

        $analyse = app(MassAssignmentService::class)->analyser([
            'annee_universitaire_id' => $this->annee->id,
            'faculte_id' => $this->faculte->id,
        ], $this->admin);

        $this->assertSame(0, $analyse['assigned_count']);
        $this->assertSame('AUCUN_GROUPE_COMPATIBLE', $analyse['anomalies'][0]['code']);
    }

    public function test_homonymes_inter_mentions_distincts(): void
    {
        $chaineA = $this->chaineLmd('E');
        $chaineB = $this->chaineLmd('F');
        $premier = $this->creerEtudiant('LMD00004', $chaineA['programmeA']);
        $second = $this->creerEtudiant('LMD00005', $chaineB['programmeA']);
        $premier->update(['nom' => 'Ben Ali', 'prenom' => 'Mohamed']);
        $second->update(['nom' => 'Ben Ali', 'prenom' => 'Mohamed']);

        $this->assertNotSame($premier->id, $second->id);
        $this->assertSame(2, Etudiant::where('nom', 'Ben Ali')->where('prenom', 'Mohamed')->count());
    }
}
