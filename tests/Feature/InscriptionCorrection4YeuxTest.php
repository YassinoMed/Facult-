<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\DossierCorrection;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActAsScolarite;
use Tests\TestCase;

/**
 * B8 (LOT 1) : corrections d'inscription (niveau/programme/type)
 * à QUATRE YEUX — proposition → approbation par un second agent →
 * application. Jamais d'écriture directe de la cible.
 * Un motif seul (trace d'audit, sans modification) reste direct.
 */
class InscriptionCorrection4YeuxTest extends TestCase
{
    use ActAsScolarite, RefreshDatabase;

    private Etudiant $etudiant;

    private InscriptionAcademique $inscription;

    private Programme $programmeBachelier;

    private Programme $programmeMaster;

    private Niveau $l1;

    private Niveau $m1;

    private User $proposeur;

    private User $approbateur;

    protected function setUp(): void
    {
        parent::setUp();

        $faculte = Faculte::create(['nom' => 'FA', 'code' => 'FA', 'description' => 'T', 'active' => true]);
        $annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->programmeBachelier = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'LI', 'nom' => 'Licence Info',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->l1 = Niveau::create(['programme_id' => $this->programmeBachelier->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $this->programmeMaster = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'MI', 'nom' => 'Master Info',
            'duree_annees' => 2, 'semestres_nombre' => 4, 'credits_requis' => 120,
        ]);
        $this->m1 = Niveau::create(['programme_id' => $this->programmeMaster->id, 'code' => 'M1', 'nom' => 'M1', 'ordre' => 1]);

        $this->etudiant = Etudiant::create([
            'cin' => 'BK'.random_int(100000, 999999), 'nom' => 'Test', 'prenom' => 'Quatre',
            'email' => uniqid('q4.', true).'@example.com', 'sexe' => 'F',
            'faculte_id' => $faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => '1 rue', 'ville' => 'Sfax', 'code_postal' => '3000',
            'annee_bac' => '2024', 'password' => 'motdepasse1',
        ]);
        $this->inscription = InscriptionAcademique::create([
            'etudiant_id' => $this->etudiant->id, 'annee_universitaire_id' => $annee->id,
            'programme_id' => $this->programmeBachelier->id, 'niveau_id' => $this->l1->id,
            'niveau' => 'L1', 'filiere' => 'Info', 'statut' => 'en_cours', 'type' => 'premiere',
        ]);

        $this->proposeur = $this->scolarite('admin_scolarite');
        $this->approbateur = User::creerCompteBackOffice([
            'name' => 'Second', 'email' => 'second.'.uniqid().'@scolarite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function proposerChangement(): TestResponse
    {
        return $this->actingAs($this->proposeur)->post(route('admin.inscriptions.corriger', $this->inscription), [
            'programme_id' => $this->programmeMaster->id, 'niveau_id' => $this->m1->id,
            'motif' => 'Réorientation vers le Master après accord de la commission.',
        ]);
    }

    public function test_changement_propose_jamais_applique_directement(): void
    {
        $this->proposerChangement()->assertRedirect()->assertSessionHasNoErrors();

        $correction = DossierCorrection::where('inscription_academique_id', $this->inscription->id)->firstOrFail();
        $this->assertSame('proposee', $correction->statut);
        $this->assertSame(DossierCorrection::CHAMP_INSCRIPTION, $correction->champ);

        // L'écriture n'a PAS eu lieu : c'est la preuve du quatre-yeux.
        $actualise = $this->inscription->fresh();
        $this->assertSame((int) $this->programmeBachelier->id, (int) $actualise->programme_id);
        $this->assertSame((int) $this->l1->id, (int) $actualise->niveau_id);

        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $this->etudiant->id, 'action' => 'correction_proposee',
        ]);
    }

    public function test_approbation_par_le_proposeur_refusee(): void
    {
        $this->proposerChangement()->assertRedirect();
        $correction = DossierCorrection::where('inscription_academique_id', $this->inscription->id)->firstOrFail();

        // 4-eyes : le proposeur ne peut pas approuver sa propre correction.
        $this->actingAs($this->proposeur)->post(route('admin.verification.decider', $correction), [
            'decision' => 'approuver',
        ])->assertForbidden();

        $this->assertSame('proposee', $correction->fresh()->statut);
    }

    public function test_workflow_complet_proposer_approuver_appliquer(): void
    {
        $this->proposerChangement()->assertRedirect();
        $correction = DossierCorrection::where('inscription_academique_id', $this->inscription->id)->firstOrFail();

        $this->actingAs($this->approbateur)->post(route('admin.verification.decider', $correction), [
            'decision' => 'approuver',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('approuvee', $correction->fresh()->statut);

        $this->actingAs($this->approbateur)->post(route('admin.verification.decider', $correction), [
            'decision' => 'appliquer',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $actualise = $this->inscription->fresh();
        $this->assertSame((int) $this->programmeMaster->id, (int) $actualise->programme_id);
        $this->assertSame((int) $this->m1->id, (int) $actualise->niveau_id);
        $this->assertSame('appliquee', $correction->fresh()->statut);
        $this->assertNotNull($correction->fresh()->appliquee_le);

        $this->assertSame(1, HistoriqueAcademique::where('entite', 'inscription')
            ->where('entite_id', $this->inscription->id)
            ->where('action', 'correction_inscription')->count());
    }

    public function test_rejet_bloque_l_application(): void
    {
        $this->proposerChangement()->assertRedirect();
        $correction = DossierCorrection::where('inscription_academique_id', $this->inscription->id)->firstOrFail();

        $this->actingAs($this->approbateur)->post(route('admin.verification.decider', $correction), [
            'decision' => 'rejeter', 'motif' => 'Incohérent avec la commission.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->approbateur)->post(route('admin.verification.decider', $correction), [
            'decision' => 'appliquer',
        ])->assertSessionHasErrors('correction');

        $this->assertSame((int) $this->programmeBachelier->id, (int) $this->inscription->fresh()->programme_id);
    }

    public function test_drift_entre_proposition_et_application_refuse(): void
    {
        $this->proposerChangement()->assertRedirect();
        $correction = DossierCorrection::where('inscription_academique_id', $this->inscription->id)->firstOrFail();

        $this->actingAs($this->approbateur)->post(route('admin.verification.decider', $correction), [
            'decision' => 'approuver',
        ])->assertRedirect();

        // L'inscription a bougé entre proposition et application.
        $this->inscription->forceFill(['type' => 'transfert'])->save();

        $this->actingAs($this->approbateur)->post(route('admin.verification.decider', $correction), [
            'decision' => 'appliquer',
        ])->assertSessionHasErrors('correction');

        $this->assertSame('approuvee', $correction->fresh()->statut);
    }

    public function test_idempotence_double_proposition(): void
    {
        $this->proposerChangement()->assertRedirect();
        $this->proposerChangement()->assertSessionHasErrors('correction');

        $this->assertSame(1, DossierCorrection::where('inscription_academique_id', $this->inscription->id)->count());
    }

    public function test_isolation_faculte_proposition_refusee(): void
    {
        $autreFaculte = Faculte::create(['nom' => 'FB', 'code' => 'FB', 'description' => 'T', 'active' => true]);
        $adminB = User::creerCompteBackOffice([
            'name' => 'Fac B', 'email' => uniqid('fb.', true).'@scolarite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminB)->post(route('admin.inscriptions.corriger', $this->inscription), [
            'programme_id' => $this->programmeMaster->id,
            'motif' => 'Tentative hors périmètre de rattachement complet.',
        ])->assertForbidden();

        $this->assertSame(0, DossierCorrection::where('champ', DossierCorrection::CHAMP_INSCRIPTION)->count());
    }
}
