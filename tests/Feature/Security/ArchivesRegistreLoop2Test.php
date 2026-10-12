<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * LOOP 2 — Registre et recherche : filtre programme exposé, combinaisons,
 * valeurs invalides, pagination avec filtres conservés, tri déterministe,
 * masquage CIN, état vide.
 */
class ArchivesRegistreLoop2Test extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30', 'active' => true,
        ]);
        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LIC', 'nom' => 'Licence',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
    }

    private function dossier(string $nom, array $options = []): DossierAcademique
    {
        $etudiant = Etudiant::create(array_merge([
            'cin' => 'BK'.random_int(100000, 999999),
            'nom' => $nom, 'prenom' => 'Test',
            'email' => uniqid('r2.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ], $options['etudiant'] ?? []));

        if (isset($options['programme_id'])) {
            InscriptionAcademique::create([
                'etudiant_id' => $etudiant->id, 'niveau' => 'L1',
                'annee_universitaire_id' => $this->annee->id, 'statut' => 'en_cours',
                'programme_id' => $options['programme_id'],
            ]);
        }

        return DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-'.strtoupper(substr(md5(uniqid('', true)), 0, 8)),
            'date_archivage' => now()->toDateString(),
            'statut' => $options['statut'] ?? 'actif',
        ]);
    }

    public function test_filtre_programme_expose_et_fonctionnel(): void
    {
        $this->scolarite('super_admin');
        $this->dossier('AvecProg', ['programme_id' => $this->programme->id]);
        $this->dossier('SansProg');

        $reponse = $this->get(route('admin.archives.index', ['programme_id' => $this->programme->id]))
            ->assertOk();
        $reponse->assertSee('AvecProg');
        $reponse->assertDontSee('SansProg');
        $reponse->assertSee('name="programme_id"', false);
    }

    public function test_combinaison_search_statut(): void
    {
        $this->scolarite('super_admin');
        $this->dossier('Cible', ['statut' => 'clos']);
        $this->dossier('CibleX', ['statut' => 'actif']);

        $reponse = $this->get(route('admin.archives.index', ['search' => 'Cible', 'statut' => 'clos']))
            ->assertOk();
        // La recherche englobe les deux noms : le statut clos ne garde que le dossier clos.
        $reponse->assertDontSee('CibleX');
    }

    public function test_valeurs_invalides_sans_erreur(): void
    {
        $this->scolarite('super_admin');
        $this->dossier('Stable');

        $this->get(route('admin.archives.index', [
            'faculte_id' => 999999, 'annee_universitaire_id' => 'abc',
            'niveau' => 'Z9', 'statut' => 'inexistant', 'programme_id' => 999999,
        ]))->assertOk()->assertSee('Aucun dossier');
    }

    public function test_pagination_conserve_les_filtres(): void
    {
        $this->scolarite('super_admin');
        for ($i = 0; $i < 14; $i++) {
            $this->dossier('Pagine'.$i);
        }

        $reponse = $this->get(route('admin.archives.index', ['search' => 'Pagine']))->assertOk();
        $this->assertStringContainsString('search=Pagine', $reponse->getContent());
    }

    public function test_tri_deterministe_homonymes(): void
    {
        $this->scolarite('super_admin');
        $premier = $this->dossier('Homonyme');
        $second = $this->dossier('Homonyme');

        $reponse = $this->get(route('admin.archives.index', ['search' => 'Homonyme']))->assertOk();
        $contenu = $reponse->getContent();
        // Tie-break id croissant : ordre stable entre deux pages et deux requêtes.
        $this->assertTrue(strpos($contenu, $premier->matricule) < strpos($contenu, $second->matricule));
    }

    public function test_cin_complete_absente_du_registre(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->dossier('Masque')->etudiant;

        $reponse = $this->get(route('admin.archives.index'))->assertOk();
        $reponse->assertSee($etudiant->cin_masquee);
        $reponse->assertDontSee($etudiant->cin);
    }
}
