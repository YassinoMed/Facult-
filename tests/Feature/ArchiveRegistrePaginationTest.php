<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Registre : robustesse recherche/filtres/pagination (LOOP 2).
 * Complète ArchiveTest (recherche, filtre statut, jokers LIKE) et
 * ArchiveIsolationFaculteTest (périmètre) — aucune redondance.
 */
class ArchiveRegistrePaginationTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private function creerDossier(string $matricule, string $nom, string $statut = 'actif', ?int $anneeId = null): DossierAcademique
    {
        $etudiant = $this->etudiantAvecDossier();
        $etudiant->update(['nom' => $nom, 'prenom' => 'Test']);

        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => $matricule,
            'date_archivage' => now()->toDateString(),
            'statut' => $statut,
        ]);

        if ($anneeId !== null) {
            InscriptionAcademique::create([
                'etudiant_id' => $etudiant->id,
                'annee_universitaire_id' => $anneeId,
                'niveau' => 'L1',
            ]);
        }

        return $dossier;
    }

    public function test_pagination_stable_sur_homonymes_sans_chevauchement_ni_perte(): void
    {
        $this->scolarite('super_admin');

        // 15 dossiers au MÊME nom : sans tie-break déterministe, MySQL peut
        // renvoyer des lignes en double ou en perdre entre deux pages.
        $idsCrees = [];
        for ($i = 1; $i <= 15; $i++) {
            $idsCrees[] = $this->creerDossier(sprintf('MAT-HM-%03d', $i), 'HomonymeMeme')->id;
        }

        $page1 = $this->get(route('admin.archives.index', ['search' => 'HomonymeMeme']))->assertOk();
        $page2 = $this->get(route('admin.archives.index', ['search' => 'HomonymeMeme', 'page' => 2]))->assertOk();

        preg_match_all('/MAT-HM-\d{3}/', $page1->getContent(), $m1);
        preg_match_all('/MAT-HM-\d{3}/', $page2->getContent(), $m2);

        $page1Ids = array_unique($m1[0]);
        $page2Ids = array_unique($m2[0]);

        $this->assertCount(12, $page1Ids, 'Page 1 : 12 dossiers attendus');
        $this->assertCount(3, $page2Ids, 'Page 2 : reliquat de 3 attendu');
        $this->assertEmpty(array_intersect($page1Ids, $page2Ids), 'Aucun doublon entre pages');
        $this->assertCount(15, array_merge($page1Ids, $page2Ids), 'Aucun dossier perdu');
    }

    public function test_combinaison_recherche_statut_annee_isole_un_seul_dossier(): void
    {
        $this->scolarite('super_admin');

        $annee = AnneeUniversitaire::create([
            'libelle' => '2024-2025', 'date_debut' => '2024-09-01', 'date_fin' => '2025-07-15',
        ]);

        // Cible : nom commun + clos + inscrit sur l'année.
        $this->creerDossier('MAT-CIBLE-1', 'Combinaison', 'clos', (int) $annee->id);
        // Leurres : même nom actif, ou clos sans inscription sur l'année.
        $this->creerDossier('MAT-LEURRE-1', 'Combinaison', 'actif', (int) $annee->id);
        $this->creerDossier('MAT-LEURRE-2', 'Combinaison', 'clos');

        $this->get(route('admin.archives.index', [
            'search' => 'Combinaison',
            'statut' => 'clos',
            'annee_universitaire_id' => $annee->id,
        ]))
            ->assertOk()
            ->assertSee('MAT-CIBLE-1')
            ->assertDontSee('MAT-LEURRE-1')
            ->assertDontSee('MAT-LEURRE-2');
    }

    public function test_valeurs_de_filtres_invalides_sans_erreur_ni_donnee(): void
    {
        $this->scolarite('super_admin');
        $this->creerDossier('MAT-VISIBLE-1', 'DossierValide');

        // Valeurs hors whitelist : liste vide, jamais d'erreur serveur.
        $this->get(route('admin.archives.index', ['statut' => 'INEXISTANT']))
            ->assertOk()->assertDontSee('MAT-VISIBLE-1');
        $this->get(route('admin.archives.index', ['niveau' => 'ZZ']))
            ->assertOk()->assertDontSee('MAT-VISIBLE-1');
        $this->get(route('admin.archives.index', ['annee_universitaire_id' => 999999]))
            ->assertOk()->assertDontSee('MAT-VISIBLE-1');
    }

    public function test_filtres_conserves_dans_les_liens_de_pagination(): void
    {
        $this->scolarite('super_admin');

        for ($i = 1; $i <= 13; $i++) {
            $this->creerDossier(sprintf('MAT-PG-%03d', $i), 'PaginationGardee');
        }

        $reponse = $this->get(route('admin.archives.index', ['search' => 'PaginationGardee', 'statut' => 'actif']))
            ->assertOk();

        // Les liens de pagination doivent porter les filtres (withQueryString).
        $this->assertStringContainsString('search=PaginationGardee', $reponse->getContent());
        $this->assertStringContainsString('statut=actif', $reponse->getContent());
        $this->assertStringContainsString('page=2', $reponse->getContent());
    }
}
