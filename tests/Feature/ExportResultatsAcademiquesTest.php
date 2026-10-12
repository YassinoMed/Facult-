<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Services\Exports\ListeEtudiantsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PHASE 7 — Colonnes moyenne/crédits/décision LUES sur l'inscription
 * officielle de l'année affichée (source : délibérations), vides si
 * absentes (jamais calculées ici), sans N+1.
 */
class ExportResultatsAcademiquesTest extends TestCase
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
            'name' => 'Scolarité', 'email' => 'sco.res@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    private function creerEtudiant(string $cin, ?array $resultats = null, ?string $anneeLibelle = '2026-2027'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@res.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => $anneeLibelle, 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        if ($resultats !== null) {
            $etudiant->inscriptionsAcademiques()->create(array_merge([
                'annee_universitaire_id' => $this->annee->id, 'niveau' => 'L1', 'statut' => 'valide',
            ], $resultats));
        }

        return $etudiant;
    }

    private function jeu(array $filtres = []): array
    {
        $service = app(ListeEtudiantsService::class);

        return $service->jeuDonnees($service->requete($filtres, $this->admin), $this->admin);
    }

    public function test_resultats_officiels_alimentes(): void
    {
        $this->creerEtudiant('RES00001', [
            'moyenne_annuelle' => 14.50, 'credits_obtenus' => 30, 'credits_total' => 30, 'decision' => 'Admis',
        ]);

        $jeu = $this->jeu();
        $ligne = $jeu['lignes'][0];

        $this->assertStringContainsString('14.5', (string) $ligne['moyenne']);
        $this->assertSame(30, (int) $ligne['credits']);
        $this->assertSame('Admis', $ligne['decision']);
    }

    public function test_sans_inscription_colonnes_vides_jamais_inventees(): void
    {
        $this->creerEtudiant('RES00002', null);

        $ligne = $this->jeu()['lignes'][0];

        $this->assertSame('', $ligne['moyenne']);
        $this->assertSame('', $ligne['credits']);
        $this->assertSame('', $ligne['decision']);
    }

    public function test_inscription_de_l_annee_affichee_fait_foi(): void
    {
        $ancienne = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => false,
        ]);

        $etudiant = $this->creerEtudiant('RES00003', [
            'moyenne_annuelle' => 16.00, 'credits_obtenus' => 30, 'decision' => 'Admis',
        ]);
        $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $ancienne->id, 'niveau' => 'L1', 'statut' => 'valide',
            'moyenne_annuelle' => 9.00, 'credits_obtenus' => 12, 'decision' => 'Ajourné',
        ]);

        $ligne = $this->jeu()['lignes'][0];

        $this->assertStringContainsString('16', (string) $ligne['moyenne']);
        $this->assertSame('Admis', $ligne['decision']);
    }

    public function test_export_csv_contient_les_resultats(): void
    {
        $this->creerEtudiant('RES00004', [
            'moyenne_annuelle' => 13.25, 'credits_obtenus' => 24, 'decision' => 'Admis',
        ]);

        $reponse = $this->actingAs($this->admin)->get(route('admin.listes.exporter', ['format' => 'csv']));
        $reponse->assertOk();

        $contenu = (string) $reponse->streamedContent();
        $this->assertStringContainsString('13.25', $contenu);
        $this->assertStringContainsString('Admis', $contenu);
    }

    public function test_aucun_chargement_paresseux_par_ligne(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->creerEtudiant('RES0001'.$i, [
                'moyenne_annuelle' => 10 + $i, 'credits_obtenus' => 30, 'decision' => 'Admis',
            ]);
        }

        DB::enableQueryLog();
        $this->jeu();
        $requetes = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $paresseuses = array_filter($requetes, fn ($q) => (bool) preg_match(
            '/from "inscriptions_academiques" where.*"etudiant_id" = \?/i', (string) $q
        ));

        $this->assertSame([], array_values($paresseuses));
    }
}
