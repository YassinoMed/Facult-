<?php

namespace Tests\Feature\Security;

use App\Livewire\Admin\Campagnes\AssistantCompositionCampagne;
use App\Livewire\Admin\Campagnes\GestionGroupesDestinataires;
use App\Livewire\Admin\Campagnes\SuiviCampagneCourriel;
use App\Livewire\Admin\Campagnes\TableauDeBordCampagnes;
use App\Models\CampagneCourriel;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\GroupeDestinataires;
use App\Models\User;
use App\Modules\Referentiel\Models\Departement;
use App\Modules\Schedule\Models\Teacher;
use App\Services\CampagneCourrielService;
use App\Services\ResolutionDestinatairesService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * LOOP-SECURITY-REMEDIATION-01 — périmètre faculté des campagnes de
 * courriels : un admin_faculte ne peut ni cibler, ni consulter, ni
 * piloter les campagnes et groupes d'une autre faculté, même en forgeant
 * les filtres ou les identifiants.
 */
class CampagnesPerimetreFaculteTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private User $adminA;

    private User $adminGlobal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculteA = Faculte::create(['code' => 'FA', 'nom' => 'Faculté A', 'actif' => true]);
        $this->faculteB = Faculte::create(['code' => 'FB', 'nom' => 'Faculté B', 'actif' => true]);

        $this->adminA = User::creerCompteBackOffice([
            'name' => 'Admin Faculté A',
            'email' => 'admin.fac.a@universite.test',
            'password' => Hash::make('Motdepasse@123'),
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');

        $this->adminGlobal = User::creerCompteBackOffice([
            'name' => 'Admin Global',
            'email' => 'admin.global@universite.test',
            'password' => Hash::make('Motdepasse@123'),
        ], 'admin_scolarite');
    }

    private function creerEtudiant(Faculte $faculte, array $attributs = []): Etudiant
    {
        static $compteur = 4000;
        $compteur++;

        return Etudiant::create(array_merge([
            'cin' => str_pad((string) $compteur, 8, '0', STR_PAD_LEFT),
            'nom' => 'Nom'.$compteur,
            'prenom' => 'Prenom'.$compteur,
            'email' => "etudiant{$compteur}@univ.test",
            'sexe' => 'M',
            'date_naissance' => '2002-01-01',
            'telephone' => '20000000',
            'statut' => 'valide',
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'faculte_id' => $faculte->id,
            'adresse' => '1 rue Test',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2022',
        ], $attributs));
    }

    private function creerEnseignant(Faculte $faculte): Teacher
    {
        $departement = Departement::create([
            'code' => 'DEP-'.uniqid(), 'nom' => 'Département', 'faculte_id' => $faculte->id,
        ]);

        return Teacher::create([
            'nom' => 'Ens', 'prenom' => 'Nom', 'email' => 'ens.'.uniqid().'@univ.test',
            'matricule' => 'ENS-'.uniqid(), 'departement_id' => $departement->id,
            'actif' => true, 'statut' => 'actif', 'password' => 'Motdepasse@123',
        ]);
    }

    private function creerCampagne(Faculte $faculte, string $titre = 'Campagne test'): CampagneCourriel
    {
        return CampagneCourriel::create([
            'titre' => $titre,
            'sujet' => 'Objet',
            'type_ciblage' => CampagneCourriel::CIBLAGE_DYNAMIQUE_ETUDIANTS,
            'statut' => CampagneCourriel::STATUT_BROUILLON,
            'cle_idempotence' => CampagneCourriel::genererCleIdempotence(),
            'filtres' => ['faculte_id' => $faculte->id],
            'auteur_id' => $this->adminA->id,
        ]);
    }

    public function test_resolution_etudiants_ecrase_le_filtre_faculte_forge(): void
    {
        $etudiantA = $this->creerEtudiant($this->faculteA);
        $this->creerEtudiant($this->faculteB);

        $service = app(ResolutionDestinatairesService::class);

        // Filtre forgé sur la faculté B → le périmètre A écrase la valeur.
        $res = $service->resoudre(CampagneCourriel::CIBLAGE_DYNAMIQUE_ETUDIANTS, [
            'filtres' => ['faculte_id' => $this->faculteB->id],
        ], $this->faculteA->id);

        $this->assertSame(1, $res['total_valides']);
        $this->assertSame($etudiantA->id, $res['destinataires'][0]['reference_id']);

        // Sans aucun filtre : toujours borné à la faculté A.
        $res2 = $service->resoudre(CampagneCourriel::CIBLAGE_DYNAMIQUE_ETUDIANTS, [], $this->faculteA->id);
        $this->assertSame(1, $res2['total_valides']);
        $this->assertSame($etudiantA->id, $res2['destinataires'][0]['reference_id']);

        // Sans périmètre (compte global) : les deux facultés restent visibles.
        $res3 = $service->resoudre(CampagneCourriel::CIBLAGE_DYNAMIQUE_ETUDIANTS, []);
        $this->assertSame(2, $res3['total_valides']);
    }

    public function test_resolution_enseignants_bornee_aux_departements_de_la_faculte(): void
    {
        $enseignantA = $this->creerEnseignant($this->faculteA);
        $this->creerEnseignant($this->faculteB);

        $service = app(ResolutionDestinatairesService::class);

        $res = $service->resoudre(CampagneCourriel::CIBLAGE_DYNAMIQUE_ENSEIGNANTS, [
            'filtres' => ['departement_id' => null],
        ], $this->faculteA->id);

        $this->assertSame(1, $res['total_valides']);
        $this->assertSame($enseignantA->id, $res['destinataires'][0]['reference_id']);
    }

    public function test_liste_fixe_ecarte_les_references_hors_perimetre(): void
    {
        $etudiantA = $this->creerEtudiant($this->faculteA);
        $etudiantB = $this->creerEtudiant($this->faculteB);

        $service = app(ResolutionDestinatairesService::class);

        $res = $service->resoudre(CampagneCourriel::CIBLAGE_LISTE_FIXE, [
            'etudiant_ids' => [$etudiantA->id, $etudiantB->id],
            'emails_libres' => [[
                'nom_complet' => 'Contact externe',
                'email' => 'externe@partenaire.test',
            ]],
        ], $this->faculteA->id);

        $this->assertSame(2, $res['total_valides']);
        $this->assertSame($etudiantA->id, $res['destinataires'][0]['reference_id']);
        $this->assertSame('externe@partenaire.test', $res['destinataires'][1]['email']);
    }

    public function test_ciblage_individuel_hors_perimetre_ne_produit_aucun_destinataire(): void
    {
        $etudiantB = $this->creerEtudiant($this->faculteB);

        $service = app(ResolutionDestinatairesService::class);

        $res = $service->resoudre(CampagneCourriel::CIBLAGE_INDIVIDUEL, [
            'type_destinataire' => 'etudiant',
            'reference_id' => $etudiantB->id,
            'email' => '',
        ], $this->faculteA->id);

        $this->assertSame(0, $res['total_valides']);
    }

    public function test_groupe_hors_perimetre_refuse_a_la_resolution(): void
    {
        $groupeB = GroupeDestinataires::create([
            'nom' => 'Groupe B',
            'type' => GroupeDestinataires::TYPE_DYNAMIQUE_ETUDIANTS,
            'audience' => GroupeDestinataires::AUDIENCE_ETUDIANTS,
            'criteres' => ['faculte_id' => $this->faculteB->id],
            'auteur_id' => $this->adminA->id,
        ]);

        $service = app(ResolutionDestinatairesService::class);

        $this->expectException(InvalidArgumentException::class);

        $service->resoudre(CampagneCourriel::CIBLAGE_GROUPE_ENREGISTRE, [
            'groupe_enregistre_id' => $groupeB->id,
        ], $this->faculteA->id);
    }

    public function test_enregistrement_brouillon_marque_la_faculte_du_perimetre(): void
    {
        $campagne = app(CampagneCourrielService::class)->enregistrerBrouillon([
            'titre' => 'Campagne A', 'sujet' => 'Objet',
            'type_ciblage' => CampagneCourriel::CIBLAGE_DYNAMIQUE_ETUDIANTS,
            'filtres' => ['faculte_id' => $this->faculteB->id, 'niveau' => 'L1'],
        ], null, $this->adminA->id, $this->faculteA->id);

        // Le filtre forgé sur la faculté B est remplacé par le périmètre réel.
        $this->assertSame($this->faculteA->id, $campagne->filtres['faculte_id']);
        $this->assertSame('L1', $campagne->filtres['niveau']);
    }

    public function test_operations_de_cycle_de_vie_refusees_hors_perimetre(): void
    {
        $campagneB = $this->creerCampagne($this->faculteB);
        $campagneB->update(['statut' => CampagneCourriel::STATUT_PLANIFIEE, 'planifiee_a' => now()->addDay()]);

        $service = app(CampagneCourrielService::class);

        foreach (['annuler', 'relancerEchecs', 'figerDestinataires', 'planifier', 'envoyer'] as $operation) {
            try {
                match ($operation) {
                    'annuler' => $service->annuler($campagneB, $this->faculteA->id),
                    'relancerEchecs' => $service->relancerEchecs($campagneB, $this->faculteA->id),
                    'figerDestinataires' => $service->figerDestinataires($campagneB, [], $this->faculteA->id),
                    'planifier' => $service->planifier($campagneB, now()->addDays(2), $this->faculteA->id),
                    'envoyer' => $service->envoyer($campagneB, $this->faculteA->id),
                };

                $this->fail("L'opération {$operation} aurait dû être refusée hors périmètre.");
            } catch (DomainException $e) {
                $this->assertSame('Cette campagne est hors de votre périmètre.', $e->getMessage());
            }
        }
    }

    public function test_accueil_http_refuse_la_campagne_hors_perimetre(): void
    {
        $campagneB = $this->creerCampagne($this->faculteB);

        $this->actingAs($this->adminA)
            ->get(route('admin.campagnes-courriel.details', $campagneB))
            ->assertNotFound();

        $this->actingAs($this->adminA)
            ->get(route('admin.campagnes-courriel.composer', $campagneB))
            ->assertNotFound();

        // Suivi Livewire par appel direct : la garde de montage s'applique aussi.
        Livewire::test(SuiviCampagneCourriel::class, ['campagne' => $campagneB])
            ->assertNotFound();

        // La campagne du périmètre reste accessible.
        $campagneA = $this->creerCampagne($this->faculteA);
        Livewire::test(SuiviCampagneCourriel::class, ['campagne' => $campagneA])
            ->assertOk();
    }

    public function test_tableau_de_bord_liste_et_compteurs_bornes_a_sa_faculte(): void
    {
        $campagneA = $this->creerCampagne($this->faculteA, 'Campagne visible A');
        $campagneB = $this->creerCampagne($this->faculteB, 'Campagne invisible B');

        $this->actingAs($this->adminA);

        Livewire::test(TableauDeBordCampagnes::class)
            ->assertOk()
            ->assertSee('Campagne visible A')
            ->assertDontSee('Campagne invisible B');

        // Compteur total : une seule campagne dans le périmètre.
        Livewire::test(TableauDeBordCampagnes::class)
            ->assertViewHas('compteurs', fn (array $compteurs) => $compteurs['total'] === 1);

        // Un compte global voit bien les deux.
        $this->actingAs($this->adminGlobal);
        Livewire::test(TableauDeBordCampagnes::class)
            ->assertSee('Campagne visible A')
            ->assertSee('Campagne invisible B')
            ->assertViewHas('compteurs', fn (array $compteurs) => $compteurs['total'] === 2);

        $this->assertNotSame($campagneA->id, $campagneB->id);
    }

    public function test_groupes_livewire_bornes_a_sa_faculte(): void
    {
        $groupeA = GroupeDestinataires::create([
            'nom' => 'Groupe visible A',
            'type' => GroupeDestinataires::TYPE_DYNAMIQUE_ETUDIANTS,
            'audience' => GroupeDestinataires::AUDIENCE_ETUDIANTS,
            'criteres' => ['faculte_id' => $this->faculteA->id],
            'auteur_id' => $this->adminA->id,
        ]);

        $groupeB = GroupeDestinataires::create([
            'nom' => 'Groupe invisible B',
            'type' => GroupeDestinataires::TYPE_DYNAMIQUE_ETUDIANTS,
            'audience' => GroupeDestinataires::AUDIENCE_ETUDIANTS,
            'criteres' => ['faculte_id' => $this->faculteB->id],
            'auteur_id' => $this->adminA->id,
        ]);

        $this->actingAs($this->adminA);

        Livewire::test(GestionGroupesDestinataires::class)
            ->assertOk()
            ->assertSee('Groupe visible A')
            ->assertDontSee('Groupe invisible B');

        // Édition, archivage, duplication d'un groupe hors périmètre → 404.
        Livewire::test(GestionGroupesDestinataires::class)
            ->call('editerGroupe', $groupeB->id)
            ->assertNotFound();
        Livewire::test(GestionGroupesDestinataires::class)
            ->call('archiverGroupe', $groupeB->id)
            ->assertNotFound();
        Livewire::test(GestionGroupesDestinataires::class)
            ->call('dupliquerGroupe', $groupeB->id)
            ->assertNotFound();

        // Aucune mutation n'a eu lieu.
        $this->assertFalse($groupeB->fresh()->est_archive);
        $this->assertSame(2, GroupeDestinataires::count());

        // Le groupe du périmètre reste accessible.
        Livewire::test(GestionGroupesDestinataires::class)
            ->call('editerGroupe', $groupeA->id)
            ->assertSet('nom', 'Groupe visible A');
    }

    public function test_sauvegarde_groupe_hors_perimetre_refusee(): void
    {
        $groupeB = GroupeDestinataires::create([
            'nom' => 'Groupe B',
            'type' => GroupeDestinataires::TYPE_DYNAMIQUE_ETUDIANTS,
            'audience' => GroupeDestinataires::AUDIENCE_ETUDIANTS,
            'criteres' => ['faculte_id' => $this->faculteB->id],
            'auteur_id' => $this->adminA->id,
        ]);

        $this->actingAs($this->adminA);

        Livewire::test(GestionGroupesDestinataires::class)
            ->set('groupeEnCoursId', $groupeB->id)
            ->set('nom', 'Détournement')
            ->call('sauvegarder')
            ->assertNotFound();

        $this->assertSame('Groupe B', $groupeB->fresh()->nom);
    }

    public function test_assistant_composition_force_la_faculte_du_perimetre(): void
    {
        $this->creerEtudiant($this->faculteA);
        $this->creerEtudiant($this->faculteB);

        $this->actingAs($this->adminA);

        $composant = Livewire::test(AssistantCompositionCampagne::class)
            ->assertOk()
            ->assertSet('filtreFaculteId', $this->faculteA->id);

        // Tentative de forge du filtre : le composant force la faculté du rôle.
        $composant->set('filtreFaculteId', $this->faculteB->id);
        $composant->call('actualiserEstimation');

        // L'estimation ne compte que l'étudiant de la faculté A.
        $composant->assertSet('estimationValides', 1);
    }
}
