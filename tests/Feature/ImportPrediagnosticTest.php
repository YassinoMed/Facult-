<?php

namespace Tests\Feature;

use App\Models\ImportJob;
use App\Models\ImportReport;
use App\Services\DataExchange\DataImportService;
use App\Services\DataExchange\ImportPrediagnosticService;
use App\Services\Io\IoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * PHASE 2 — Prédiagnostic et mapping intelligent : schéma lu sans
 * écriture, en-têtes accentués reconnus, inconnues signalées avec
 * suggestion (jamais appliquée), refus propres, empreinte etVolume.
 */
class ImportPrediagnosticTest extends TestCase
{
    use RefreshDatabase;

    private function fichier(string $contenu, string $nom = 'prediagnostic.csv'): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'predi').'_'.$nom;
        file_put_contents($chemin, $contenu);

        return new UploadedFile($chemin, $nom, 'text/csv', null, true);
    }

    public function test_entetes_accentues_reconnus(): void
    {
        $diagnostic = app(ImportPrediagnosticService::class)->diagnostiquer(
            $this->fichier("Nom,Prénom\nDupont,Jean\n"),
            'etudiants'
        );

        $this->assertNull($diagnostic['refus']);
        $this->assertSame([], $diagnostic['manquantes']);

        $parBrut = array_column($diagnostic['mapping'], null, 'brut');
        $this->assertSame('canonique', $parBrut['prenom']['statut']);
        $this->assertSame('prenom', $parBrut['prenom']['champ']);
        $this->assertTrue($diagnostic['pret']);
    }

    public function test_analyseur_accepte_les_entetes_accentues(): void
    {
        try {
            ['resultat' => $resultat] = app(DataImportService::class)->analyser(
                $this->fichier("Nom,Prénom\nDupont,Jean\n"),
                'etudiants'
            );
        } catch (IoException $e) {
            $this->fail('En-têtes accentués refusés : '.$e->getMessage());
        }

        foreach ($resultat->erreurs as $erreur) {
            $this->assertStringNotContainsString('colonnes manquantes', $erreur['message']);
        }
    }

    public function test_entete_inconnue_signalee_avec_suggestion_jamais_appliquee(): void
    {
        $diagnostic = app(ImportPrediagnosticService::class)->diagnostiquer(
            $this->fichier("nom,premon\nDupont,Jean\n"),
            'etudiants'
        );

        $parBrut = array_column($diagnostic['mapping'], null, 'brut');
        $this->assertSame('inconnue', $parBrut['premon']['statut']);
        $this->assertNull($parBrut['premon']['champ']);
        $this->assertSame('prenom', $parBrut['premon']['suggestion']);
        $this->assertContains('premon', $diagnostic['inconnues']);
        // Colonne requise absente + blocage explicite, pas d'exception.
        $this->assertContains('prenom', $diagnostic['manquantes']);
        $this->assertFalse($diagnostic['pret']);
        $this->assertNotEmpty($diagnostic['blocages']);
    }

    public function test_conflit_ambigu_signale_sans_appliquer(): void
    {
        $diagnostic = app(ImportPrediagnosticService::class)->diagnostiquer(
            $this->fichier("code_enseignement,enseignement\nENS-1,ENS-2\n"),
            'affectations-groupes'
        );

        $this->assertNotEmpty($diagnostic['conflits']);
        $this->assertFalse($diagnostic['pret']);
    }

    public function test_volume_excessif_bloque_proprement(): void
    {
        $lignes = ['nom,prenom'];

        for ($i = 0; $i <= DataImportService::LIGNES_MAX; $i++) {
            $lignes[] = "Etudiant{$i},Prenom{$i}";
        }

        $diagnostic = app(ImportPrediagnosticService::class)->diagnostiquer(
            $this->fichier(implode("\n", $lignes)),
            'etudiants'
        );

        $this->assertNull($diagnostic['refus']);
        $this->assertTrue($diagnostic['volume']['depasse_lignes']);
        $this->assertFalse($diagnostic['pret']);
    }

    public function test_refus_propres_sans_exception(): void
    {
        $service = app(ImportPrediagnosticService::class);

        $inconnue = $service->diagnostiquer($this->fichier("a,b\n1,2\n"), 'entite-inexistante');
        $this->assertSame('Entité « entite-inexistante » non importable.', $inconnue['refus']);

        $xls = $service->diagnostiquer($this->fichier("a,b\n1,2\n", 'fichier.xls'), 'etudiants');
        $this->assertStringContainsString('ODS', $xls['refus'] ?? '');

        $vide = $service->diagnostiquer($this->fichier(''), 'etudiants');
        $this->assertNotNull($vide['refus']);
    }

    public function test_empreinte_et_fichier_deja_traite(): void
    {
        $service = app(ImportPrediagnosticService::class);
        $contenu = "nom,prenom\nDupont,Jean\n";

        $premier = $service->diagnostiquer($this->fichier($contenu), 'etudiants');

        $this->assertNotNull($premier['empreinte']);
        $this->assertFalse($premier['deja_traite']);

        $auteur = \App\Models\User::creerCompteBackOffice([
            'name' => 'Agent Prédiagnostic',
            'email' => 'prediagnostic@universite.test',
            'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        ImportReport::create([
            'user_id' => $auteur->id,
            'integration' => 'web',
            'direction' => 'import',
            'type' => 'data.etudiants',
            'format' => 'csv',
            'filename' => 'prediagnostic.csv',
            'file_hash' => $premier['empreinte'],
            'status' => 'completed',
            'total_rows' => 1,
        ]);

        $second = $service->diagnostiquer($this->fichier($contenu), 'etudiants');

        $this->assertTrue($second['deja_traite']);
    }

    public function test_prediagnostic_sans_aucune_ecriture(): void
    {
        $avant = [
            'rapports' => ImportReport::count(),
            'jobs' => ImportJob::count(),
        ];

        $service = app(ImportPrediagnosticService::class);
        $service->diagnostiquer($this->fichier("nom,prenom\nDupont,Jean\n"), 'etudiants');
        $service->diagnostiquer($this->fichier("nom,premon\nDupont,Jean\n"), 'etudiants');
        $service->diagnostiquer($this->fichier(''), 'etudiants');

        $this->assertSame($avant['rapports'], ImportReport::count());
        $this->assertSame($avant['jobs'], ImportJob::count());
    }
}
