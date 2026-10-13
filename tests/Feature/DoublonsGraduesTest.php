<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Services\DataExchange\DataImportService;
use App\Services\DataExchange\Definitions\EtudiantDefinition;
use App\Services\DataExchange\DetecteurDoublonsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * PHASE 5 — Doublons gradués : exact/upsert conservé, conflit signalé
 * (jamais écrasé silencieusement), homonymes jamais fusionnés,
 * similarité → revue seule, non résolu explicite.
 */
class DoublonsGraduesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create([
            'nom' => 'Faculté Doublons', 'code' => 'FDB',
            'description' => 'T', 'active' => true,
        ]);
    }

    private function creerEtudiant(string $cin, string $nom, string $prenom, ?string $naissance = null): Etudiant
    {
        $this->compteur++;

        $etudiant = Etudiant::create([
            'cin' => $cin,
            'num_inscription' => 'MAT-DB-'.$this->compteur,
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => "doublon{$this->compteur}@universite.test",
            'date_naissance' => $naissance,
            'sexe' => 'M',
            'telephone' => '97000'.$this->compteur,
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue X',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2023',
            'password' => 'Secret@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    private function analyser(string $contenu, string $entite = 'etudiants'): array
    {
        $chemin = tempnam(sys_get_temp_dir(), 'doublon').'.csv';
        file_put_contents($chemin, $contenu);
        $fichier = new UploadedFile($chemin, 'doublons.csv', 'text/csv', null, true);

        return app(DataImportService::class)->analyser($fichier, $entite);
    }

    public function test_existant_coherent_reste_mise_a_jour_silencieuse(): void
    {
        $this->creerEtudiant('71000001', 'Ben Ali', 'Mohamed', '2004-01-15');

        ['resultat' => $resultat] = $this->analyser(
            "nom,prenom,cin,date_naissance\nBen Ali,Mohamed,71000001,2004-01-15\n"
        );

        $this->assertSame([], $resultat->erreurs);
        $this->assertCount(1, $resultat->avertissements);
        $this->assertStringContainsString('mise à jour', $resultat->avertissements[0]);
        $this->assertSame([], $resultat->aRevoir);
    }

    public function test_cle_existante_identite_divergente_signale_conflit(): void
    {
        $existant = $this->creerEtudiant('71000002', 'Ben Ali', 'Mohamed', '2004-01-15');

        ['resultat' => $resultat] = $this->analyser(
            "nom,prenom,cin,date_naissance\nTrabelsi,Karim,71000002,2004-01-15\n"
        );

        $this->assertSame([], $resultat->erreurs);
        $this->assertStringContainsString('CONFLIT_IDENTITE', $resultat->avertissements[0]);
        $this->assertStringContainsString("#{$existant->id}", $resultat->avertissements[0]);
        // Aucune CIN/e-mail dans le message (pas de PII).
        $this->assertStringNotContainsString('71000002', $resultat->avertissements[0]);

        $this->assertCount(1, $resultat->aRevoir);
        $this->assertSame('DOUBLONS@1.0', $resultat->aRevoir[0]['regle']);
    }

    public function test_homonymes_distincts_jamais_signales(): void
    {
        $this->creerEtudiant('71000003', 'Ben Ali', 'Mohamed', '2004-01-15');

        ['resultat' => $resultat] = $this->analyser(
            "nom,prenom,cin,date_naissance\nBen Ali,Mohamed,71000004,2005-06-20\n"
        );

        $this->assertSame([], $resultat->erreurs);
        $this->assertSame([], $resultat->avertissements);
        $this->assertSame([], $resultat->aRevoir);
        $this->assertCount(1, $resultat->valides);
    }

    public function test_faute_frappe_propose_revue_potentielle(): void
    {
        $this->creerEtudiant('71000005', 'Ben Ali', 'Mohamed', '2004-01-15');

        ['resultat' => $resultat] = $this->analyser(
            "nom,prenom,date_naissance\nBen Alu,Mohamed,2004-01-15\n"
        );

        $this->assertSame([], $resultat->erreurs);
        $this->assertStringContainsString('DOUBLON_POTENTIEL', $resultat->avertissements[0]);
        $this->assertCount(1, $resultat->aRevoir);
        $this->assertSame('DOUBLONS@1.0', $resultat->aRevoir[0]['regle']);
        // Proposé, pas appliqué : la ligne reste importable telle quelle.
        $this->assertCount(1, $resultat->valides);
    }

    public function test_sans_identifiant_statut_non_resolu(): void
    {
        ['resultat' => $resultat] = $this->analyser("nom,prenom\nInconnu,Personne\n");

        $this->assertSame([], $resultat->erreurs);
        $this->assertStringContainsString('IDENTITE_NON_RESOLUE', $resultat->avertissements[0]);
        $this->assertCount(1, $resultat->aRevoir);
    }

    public function test_entite_non_nominative_sans_bruit(): void
    {
        ['resultat' => $resultat] = $this->analyser("code,nom\nS1,Salle 1\n", 'salles');

        $this->assertSame([], $resultat->erreurs);
        $this->assertSame([], $resultat->avertissements);
        $this->assertSame([], $resultat->aRevoir);
        $this->assertCount(1, $resultat->valides);
    }

    public function test_candidats_similaires_bornes_a_vingt(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->creerEtudiant('DBB'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'Benali', 'Yassine', '2003-03-03');
        }

        $candidats = app(EtudiantDefinition::class)->candidatsSimilaires(
            ['nom' => 'Benali', 'prenom' => 'Yassine', 'date_naissance' => '2003-03-03']
        );

        $this->assertCount(20, $candidats);
    }

    public function test_ligne_sans_identifiant_necrase_jamais_lexistant(): void
    {
        $existant = $this->creerEtudiant('71000007', 'Ben Ali', 'Mohamed', '2004-01-15');

        ['resultat' => $resultat, 'lignes' => $lignes] = app(DataImportService::class)->analyser(
            (function () {
                $chemin = tempnam(sys_get_temp_dir(), 'doublon').'.csv';
                file_put_contents($chemin, "nom,prenom\nAutre,Personne\n");
                $fichier = new UploadedFile($chemin, 'doublons.csv', 'text/csv', null, true);

                return $fichier;
            })(),
            'etudiants'
        );

        $this->assertSame([], $resultat->erreurs);

        // Confirmation : création, pas d'écrasement du premier étudiant.
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent Doublons',
            'email' => 'doublons@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        ['token' => $token] = app(DataImportService::class)->preparer(
            (function () {
                $chemin = tempnam(sys_get_temp_dir(), 'doublon').'.csv';
                file_put_contents($chemin, "nom,prenom\nAutre,Personne\n");
                $fichier = new UploadedFile($chemin, 'doublons.csv', 'text/csv', null, true);

                return $fichier;
            })(),
            'etudiants',
            $agent->id
        );

        app(DataImportService::class)->confirmer($token, 'creer', $agent->id);

        $this->assertSame('Ben Ali', $existant->fresh()->nom);
        $this->assertDatabaseHas('etudiants', ['nom' => 'Autre', 'prenom' => 'Personne']);
    }

    public function test_classification_directe_sans_requete_supplementaire(): void
    {
        $existant = $this->creerEtudiant('71000006', 'Ben Salah', 'Amine', '2002-02-02');

        $detecteur = app(DetecteurDoublonsService::class);
        $definition = app(EtudiantDefinition::class);

        $coherent = $detecteur->classifier(
            ['nom' => 'Ben Salah', 'prenom' => 'Amine', 'date_naissance' => '2002-02-02', 'cin' => '71000006'],
            $definition,
            $existant
        );
        $this->assertSame(DetecteurDoublonsService::MISE_A_JOUR, $coherent['statut']);

        $nouveau = $detecteur->classifier(
            ['nom' => 'Autre', 'prenom' => 'Personne', 'cin' => '71999999'],
            $definition,
            null
        );
        $this->assertSame(DetecteurDoublonsService::NOUVEAU, $nouveau['statut']);
    }
}
