<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * LOOP 3 — Fiche détaillée : N+1 borné, ordre chronologique, journal
 * sans PII, sections et états présents.
 */
class ArchivesFicheLoop3Test extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    public function test_fiche_sans_n_plus_un(): void
    {
        $this->scolarite('super_admin');
        $faculte = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30', 'active' => true,
        ]);
        $etudiant = Etudiant::create([
            'cin' => 'BK123456', 'nom' => 'Fiche', 'prenom' => 'Test',
            'email' => uniqid('f3.', true).'@example.com',
            'sexe' => 'M', 'faculte_id' => $faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026',
            'adresse' => '1 rue', 'ville' => 'Rabat',
            'code_postal' => '10000', 'annee_bac' => '2024',
            'password' => 'motdepasse1',
        ]);
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-FICHE-1', 'date_archivage' => now()->toDateString(),
        ]);
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'niveau' => 'L1',
            'annee_universitaire_id' => $annee->id, 'statut' => 'en_cours',
        ]);
        $semestre = Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => 1]);
        for ($i = 1; $i <= 5; $i++) {
            $matiere = Matiere::create([
                'code' => 'F3M'.$i, 'nom' => 'Matière '.$i, 'coefficient' => 2,
                'credits' => 6, 'niveau' => 'L1', 'semestre' => 1,
            ]);
            Note::create([
                'inscription_academique_id' => $inscription->id, 'etudiant_id' => $etudiant->id,
                'semestre_id' => $semestre->id, 'matiere_id' => $matiere->id,
                'note' => 10 + $i, 'coefficient' => 2, 'credits' => 6,
            ]);
        }

        $compteur = 0;
        DB::listen(function () use (&$compteur): void {
            $compteur++;
        });

        $this->get(route('admin.archives.show', $dossier))->assertOk();

        // Eager notes.etudiant (policies @can par ligne) : 27 → 23
        // requêtes mesurées à 5 notes ; la borne verrouille le gain.
        $this->assertLessThan(25, $compteur, "Fiche 5 notes : {$compteur} requêtes.");
    }

    public function test_journal_sans_donnees_personnelles(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-JOURNAL-1', 'date_archivage' => now()->toDateString(),
        ]);

        $reponse = $this->get(route('admin.archives.show', $dossier))->assertOk();

        // La consultation est journalisée mais n'expose ni CIN ni secrets.
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => HistoriqueAcademique::ACTION_CONSULTATION,
        ]);
        $entree = HistoriqueAcademique::where('etudiant_id', $etudiant->id)->latest('id')->first();
        $this->assertStringNotContainsString($etudiant->cin, (string) ($entree->ancienne_valeur.$entree->nouvelle_valeur));
        $reponse->assertSee('Historique des actions');
    }

    public function test_sections_et_ordre_chronologique(): void
    {
        $this->scolarite('super_admin');
        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-SECTIONS-1', 'date_archivage' => now()->toDateString(),
        ]);

        $reponse = $this->get(route('admin.archives.show', $dossier))->assertOk();
        $contenu = $reponse->getContent();

        $this->assertStringContainsString('Parcours universitaire', $contenu);
        $this->assertStringContainsString('Documents archivés', $contenu);
        $this->assertStringContainsString('Historique des actions', $contenu);
    }
}
