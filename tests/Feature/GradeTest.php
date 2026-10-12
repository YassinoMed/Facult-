<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierAcademique;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\Note;
use App\Models\Semestre;
use App\Services\AcademicCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * Notes académiques — données critiques :
 * bornes 0-20, unicité par session, cohérence matière/semestre,
 * verrouillage (locked_at), recalcul automatique, journalisation.
 */
class GradeTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private array $contexte;

    private function contexte(): array
    {
        if (isset($this->contexte)) {
            return $this->contexte;
        }

        $etudiant = $this->etudiantAvecDossier();
        $dossier = DossierAcademique::create([
            'etudiant_id' => $etudiant->id,
            'matricule' => 'MAT-GRD-1',
            'date_archivage' => now()->toDateString(),
        ]);
        $inscription = InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => AnneeUniversitaire::create([
                'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-07-15',
            ])->id,
            'niveau' => 'L1',
        ]);
        $semestre = Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => 1]);
        $matiere = Matiere::create([
            'code' => 'GRD101', 'nom' => 'Matière de test', 'coefficient' => 2, 'credits' => 4, 'niveau' => 'L1', 'semestre' => 1,
        ]);

        return $this->contexte = compact('etudiant', 'dossier', 'inscription', 'semestre', 'matiere');
    }

    public function test_saisie_valide_cree_la_note_et_recalcul_les_moyennes(): void
    {
        $this->scolarite('agent_scolarite');
        $c = $this->contexte();

        $this->post(route('admin.archives.notes.store', $c['inscription']), [
            'semestre_numero' => 1,
            'matiere_id' => $c['matiere']->id,
            'note' => 15.25,
            'session' => 'normale',
        ])->assertRedirect();

        $note = Note::first();
        $this->assertEquals(15.25, (float) $note->note);

        // Coefficient et crédits dérivés de la MATIÈRE, jamais du client
        $this->assertEquals(2, (float) $note->coefficient);
        $this->assertEquals(4, $note->credits);

        $c['inscription']->refresh();
        $this->assertEquals(15.25, (float) $c['inscription']->moyenne_annuelle);
        $this->assertEquals(4, $c['inscription']->credits_obtenus);
    }

    public function test_mass_assignment_les_champs_critiques_sont_ignores(): void
    {
        $this->scolarite('agent_scolarite');
        $c = $this->contexte();

        // Tentative d'injection : coefficient falsifié + etudiant_id d'un autre étudiant
        $this->post(route('admin.archives.notes.store', $c['inscription']), [
            'semestre_numero' => 1,
            'matiere_id' => $c['matiere']->id,
            'note' => 10,
            'coefficient' => 99,       // ignoré : dérivé de la matière
            'credits' => 60,           // ignoré : dérivé de la matière
            'etudiant_id' => 424242,   // ignoré : dérivé de l'inscription
            'session' => 'normale',
        ]);

        $note = Note::first();
        $this->assertEquals($c['etudiant']->id, $note->etudiant_id);
        $this->assertEquals(2, (float) $note->coefficient);
        $this->assertEquals(4, $note->credits);
    }

    public function test_bornes_0_et_20_renforcees(): void
    {
        $this->scolarite('super_admin');
        $c = $this->contexte();

        $this->post(route('admin.archives.notes.store', $c['inscription']), [
            'semestre_numero' => 1, 'matiere_id' => $c['matiere']->id, 'note' => 21, 'session' => 'normale',
        ])->assertSessionHasErrors('note');

        $this->post(route('admin.archives.notes.store', $c['inscription']), [
            'semestre_numero' => 1, 'matiere_id' => $c['matiere']->id, 'note' => -1, 'session' => 'normale',
        ])->assertSessionHasErrors('note');

        $this->assertDatabaseCount('notes', 0);
    }

    public function test_unicite_par_semestre_matiere_session(): void
    {
        $this->scolarite('super_admin');
        $c = $this->contexte();

        $this->post(route('admin.archives.notes.store', $c['inscription']), [
            'semestre_numero' => 1, 'matiere_id' => $c['matiere']->id, 'note' => 12, 'session' => 'normale',
        ]);

        $this->post(route('admin.archives.notes.store', $c['inscription']), [
            'semestre_numero' => 1, 'matiere_id' => $c['matiere']->id, 'note' => 14, 'session' => 'normale',
        ])->assertSessionHasErrors('matiere_id');

        // La session de rattrapage reste autorisée pour la même matière
        $this->post(route('admin.archives.notes.store', $c['inscription']), [
            'semestre_numero' => 1, 'matiere_id' => $c['matiere']->id, 'note' => 16, 'session' => 'rattrapage',
        ])->assertRedirect();

        $this->assertDatabaseCount('notes', 2);
    }

    public function test_coherence_matiere_niveau_et_semestre_renforcee(): void
    {
        $this->scolarite('super_admin');
        $c = $this->contexte();

        // Matière de M1 : ne correspond pas au niveau L1 de l'inscription
        $matiereM1 = Matiere::create([
            'code' => 'GRD501', 'nom' => 'Matière M1', 'coefficient' => 2, 'credits' => 4, 'niveau' => 'M1', 'semestre' => 1,
        ]);

        $this->post(route('admin.archives.notes.store', $c['inscription']), [
            'semestre_numero' => 1, 'matiere_id' => $matiereM1->id, 'note' => 12, 'session' => 'normale',
        ])->assertSessionHasErrors('matiere_id');

        // Matière L1 S2 : ne correspond pas au semestre S1 visé
        $matiereS2 = Matiere::create([
            'code' => 'GRD102', 'nom' => 'Matière S2', 'coefficient' => 2, 'credits' => 4, 'niveau' => 'L1', 'semestre' => 2,
        ]);

        $this->post(route('admin.archives.notes.store', $c['inscription']), [
            'semestre_numero' => 1, 'matiere_id' => $matiereS2->id, 'note' => 12, 'session' => 'normale',
        ])->assertSessionHasErrors('matiere_id');

        $this->assertDatabaseCount('notes', 0);
    }

    public function test_note_verrouillee_immodifiable_par_un_agent(): void
    {
        $agent = $this->scolarite('agent_scolarite');
        $c = $this->contexte();

        $note = Note::create([
            'etudiant_id' => $c['etudiant']->id, 'inscription_academique_id' => $c['inscription']->id,
            'semestre_id' => $c['semestre']->id, 'matiere_id' => $c['matiere']->id,
            'note' => 12, 'coefficient' => 2, 'credits' => 4, 'session' => 'normale',
            'locked_at' => now(), // verrouillée par un rôle supérieur
        ]);

        $this->patch(route('admin.archives.notes.update', $note), [
            'note' => 18, 'raison' => 'Correction non autorisée',
        ])->assertForbidden();

        $this->assertDatabaseHas('notes', ['id' => $note->id, 'note' => 12]);
    }

    public function test_note_verrouillee_modifiable_par_admin_scolarite_avec_motif(): void
    {
        $this->scolarite('admin_scolarite');
        $c = $this->contexte();

        $note = Note::create([
            'etudiant_id' => $c['etudiant']->id, 'inscription_academique_id' => $c['inscription']->id,
            'semestre_id' => $c['semestre']->id, 'matiere_id' => $c['matiere']->id,
            'note' => 12, 'coefficient' => 2, 'credits' => 4, 'session' => 'normale',
            'locked_at' => now(),
        ]);

        $this->patch(route('admin.archives.notes.update', $note), [
            'note' => 14, 'raison' => 'Rectification après commission',
        ])->assertRedirect();

        $this->assertDatabaseHas('notes', ['id' => $note->id, 'note' => 14]);

        // Journal : ancienne et nouvelle valeur tracées
        $entree = HistoriqueAcademique::where('action', HistoriqueAcademique::ACTION_MODIFICATION_NOTE)->first();
        $this->assertNotNull($entree);
        $this->assertStringContainsString('12', (string) $entree->ancienne_valeur);
        $this->assertStringContainsString('14', (string) $entree->nouvelle_valeur);
        $this->assertStringContainsString('Rectification après commission', (string) $entree->nouvelle_valeur);
    }

    public function test_agent_ne_peut_pas_verrouiller_une_note(): void
    {
        $this->scolarite('agent_scolarite');
        $c = $this->contexte();

        $note = Note::create([
            'etudiant_id' => $c['etudiant']->id, 'inscription_academique_id' => $c['inscription']->id,
            'semestre_id' => $c['semestre']->id, 'matiere_id' => $c['matiere']->id,
            'note' => 12, 'coefficient' => 2, 'credits' => 4, 'session' => 'normale',
        ]);

        $this->patch(route('admin.archives.notes.lock', $note))->assertForbidden();
        $this->assertDatabaseHas('notes', ['id' => $note->id, 'locked_at' => null]);
    }

    public function test_admin_verrouille_puis_deverrouille_avec_journalisation(): void
    {
        $this->scolarite('admin_scolarite');
        $c = $this->contexte();

        $note = Note::create([
            'etudiant_id' => $c['etudiant']->id, 'inscription_academique_id' => $c['inscription']->id,
            'semestre_id' => $c['semestre']->id, 'matiere_id' => $c['matiere']->id,
            'note' => 12, 'coefficient' => 2, 'credits' => 4, 'session' => 'normale',
        ]);

        $this->patch(route('admin.archives.notes.lock', $note))->assertRedirect();
        $this->assertNotNull($note->fresh()->locked_at);

        $this->patch(route('admin.archives.notes.lock', $note))->assertRedirect();
        $this->assertNull($note->fresh()->locked_at);

        $this->assertSame(2, HistoriqueAcademique::where('action', HistoriqueAcademique::ACTION_VERROUILLAGE_NOTE)->count());
    }

    public function test_suppression_de_note_recalcul_les_moyennes(): void
    {
        $this->scolarite('admin_scolarite');
        $c = $this->contexte();

        $note = Note::create([
            'etudiant_id' => $c['etudiant']->id, 'inscription_academique_id' => $c['inscription']->id,
            'semestre_id' => $c['semestre']->id, 'matiere_id' => $c['matiere']->id,
            'note' => 12, 'coefficient' => 2, 'credits' => 4, 'session' => 'normale',
        ]);

        app(AcademicCalculationService::class)->recalculerInscription($c['inscription']);
        $c['inscription']->refresh();
        $this->assertEquals(12, (float) $c['inscription']->moyenne_annuelle);

        $this->delete(route('admin.archives.notes.destroy', $note))->assertRedirect();

        $c['inscription']->refresh();
        $this->assertNull($c['inscription']->moyenne_annuelle);
        $this->assertSame(0, $c['inscription']->credits_obtenus);
        $this->assertDatabaseHas('historique_academique', [
            'action' => HistoriqueAcademique::ACTION_SUPPRESSION_NOTE,
        ]);
    }
}
