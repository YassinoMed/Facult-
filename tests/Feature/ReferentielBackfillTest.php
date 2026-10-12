<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\Matiere;
use App\Models\Note;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\TypeDiplome;
use App\Modules\Referentiel\Models\UniteEnseignement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Commande referentiel:backfill (§24, décision D-C) : DRY-RUN par
 * défaut (aucune écriture), --force --nettoyer ne supprime QUE les
 * doublons matières prouvés (jumeau ECUE exact + zéro référence),
 * idempotence (second passage = no-op).
 */
class ReferentielBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_ne_supprime_rien(): void
    {
        [$doublon, $porteuse, $notee] = $this->fixtures();

        $this->artisan('referentiel:backfill')->assertSuccessful();

        $this->assertDatabaseHas('matieres', ['id' => $doublon->id]);
        $this->assertDatabaseHas('matieres', ['id' => $porteuse->id]);
        $this->assertDatabaseHas('matieres', ['id' => $notee->id]);
    }

    public function test_nettoyer_exige_force(): void
    {
        [$doublon] = $this->fixtures();

        $this->artisan('referentiel:backfill', ['--nettoyer' => true])->assertSuccessful();

        $this->assertDatabaseHas('matieres', ['id' => $doublon->id]);
    }

    public function test_force_supprime_uniquement_les_doublons_prouves(): void
    {
        [$doublon, $porteuse, $notee] = $this->fixtures();

        $this->artisan('referentiel:backfill', ['--force' => true, '--nettoyer' => true])->assertSuccessful();

        // Doublon (jumeau exact, zéro référence) : supprimé.
        $this->assertDatabaseMissing('matieres', ['id' => $doublon->id]);

        // Porteuse de références (jumeau rattaché) + jumeau avec notes : intactes.
        $this->assertDatabaseHas('matieres', ['id' => $porteuse->id]);
        $this->assertDatabaseHas('matieres', ['id' => $notee->id]);

        // Idempotence : second passage = no-op, aucune erreur.
        $this->artisan('referentiel:backfill', ['--force' => true, '--nettoyer' => true])->assertSuccessful();
        $this->assertSame(3, Matiere::count(), 'Porteuse + jumeau noté + jumeau attaché = 3 légitimes.');
    }

    public function test_force_ne_touche_pas_les_niveaux_coherents(): void
    {
        $this->fixtures();
        $diplome = TypeDiplome::create(['code' => 'LIC', 'nom' => 'Licence', 'cycle' => 'licence', 'duree_annees' => 3, 'actif' => true]);
        $programme = Programme::firstOrFail();
        $programme->update(['type_diplome_id' => $diplome->id]);
        // Niveau LIC cohérent (L2 : L1 existe déjà au fixture) : jamais touché
        // (le nettoyage ne vise que les M1/M2 sous cycle licence).
        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L2', 'nom' => 'L2', 'ordre' => 2, 'actif' => true]);

        $this->artisan('referentiel:backfill', ['--force' => true, '--nettoyer' => true])->assertSuccessful();

        // Niveau LIC cohérent : jamais touché (le nettoyage ne vise que
        // les M1/M2 sous cycle licence).
        $this->assertDatabaseHas('niveaux', ['id' => $niveau->id]);
    }

    /**
     * @return array{0: Matiere, 1: Matiere, 2: Matiere}
     */
    private function fixtures(): array
    {
        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'active' => true]);
        $programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'L-BF', 'nom' => 'Licence Backfill',
            'statut' => 'actif', 'actif' => true,
        ]);

        // Porteuse : ECUE rattachée à une UE (références possibles).
        $niveau = Niveau::create(['programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1, 'actif' => true]);
        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1, 'actif' => true,
        ]);
        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $programme->id,
            'code' => 'UE-BF', 'nom' => 'UE', 'credits' => 6, 'actif' => true,
        ]);
        $porteuse = Matiere::create([
            'code' => 'ECUE-BF', 'nom' => 'Algorithmique', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 1, 'credits' => 2,
        ]);

        // Doublon : même nom exact, SANS UE, SANS référence → supprimable.
        $doublon = Matiere::create([
            'code' => 'MAT-BF', 'nom' => 'Algorithmique', 'ue_id' => null,
            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 1, 'credits' => 2,
        ]);

        // Jumeau avec note : protégé par la garde (référence = historique).
        $notee = Matiere::create([
            'code' => 'MAT-NOTED', 'nom' => 'Algorithmique avancée', 'ue_id' => null,
            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 1, 'credits' => 2,
        ]);
        Matiere::create([
            'code' => 'ECUE-NOTED', 'nom' => 'Algorithmique avancée', 'ue_id' => $ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'coefficient' => 1, 'credits' => 2,
        ]);
        $etudiant = Etudiant::create([
            'cin' => 'BF'.random_int(100000, 999999), 'nom' => 'Test', 'prenom' => 'Sara',
            'email' => 'sara.'.random_int(1000, 9999).'@bf.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $faculte->id, 'niveau' => 'L1',
            'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => AnneeUniversitaire::create([
                'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
            ])->id,
            'niveau' => 'L1', 'statut' => 'en_cours',
        ]);
        $semestreInscription = $inscription->semestres()->create(['numero' => 1]);
        Note::create([
            'etudiant_id' => $etudiant->id, 'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestreInscription->id, 'matiere_id' => $notee->id,
            'note' => 12, 'coefficient' => 1, 'credits' => 2, 'session' => 'normale',
        ]);

        return [$doublon, $porteuse, $notee];
    }
}
