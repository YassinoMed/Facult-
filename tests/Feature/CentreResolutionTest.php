<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Services\InscriptionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Centre de résolution : gate verdict BLOQUANT, file des bloquées,
 * correction justifiée + re-vérification, sécurité, concurrence.
 */
class CentreResolutionTest extends TestCase
{
    use RefreshDatabase;

    private User $scolarite;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.ctr@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    public function test_verdict_bloquant_bloque_validation(): void
    {
        $etudiant = $this->creerEtudiant('CTR00001', ['nom' => '']);
        $inscription = $this->inscrire($etudiant);

        try {
            app(InscriptionService::class)->transiter($inscription, 'valide', $this->scolarite->id);
            $this->fail('Validation aveugle aurait dû être refusée.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('IDENTITE_COMPLETE', $e->getMessage());
        }

        $this->assertSame('en_cours', $inscription->fresh()->statut);
    }

    public function test_centre_liste_bloquees_et_fiche(): void
    {
        $etudiant = $this->creerEtudiant('CTR00002', ['nom' => '']);
        $this->inscrire($etudiant);

        $this->actingAs($this->scolarite)->post(route('admin.verification.verifier', $etudiant))->assertRedirect();

        $contenu = (string) $this->actingAs($this->scolarite)
            ->get(route('admin.verification.index'))->assertOk()->getContent();
        $this->assertStringContainsString($etudiant->num_inscription, $contenu);

        $fiche = (string) $this->actingAs($this->scolarite)
            ->get(route('admin.verification.show', $etudiant))->assertOk()->getContent();
        $this->assertStringContainsString('BLOQUANT', $fiche);
        $this->assertStringContainsString('IDENTITE_COMPLETE', $fiche);
    }

    public function test_correction_justifiee_reverifiee(): void
    {
        $etudiant = $this->creerEtudiant('CTR00003');
        $inscription = $this->inscrire($etudiant, 'L1');

        // Motif obligatoire.
        $this->actingAs($this->scolarite)->post(route('admin.inscriptions.corriger', $inscription), [
            'niveau_id' => 999999, 'motif' => '',
        ])->assertSessionHasErrors('motif');

        // Correction sans changement de niveau : verdict stable, audit présent.
        $this->actingAs($this->scolarite)->post(route('admin.inscriptions.corriger', $inscription), [
            'motif' => 'Vérification administrative de routine du dossier.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('historique_academique', [
            'entite' => 'inscription', 'entite_id' => $inscription->id, 'action' => 'correction_inscription',
        ]);
    }

    public function test_correction_inscription_validee_refusee(): void
    {
        $etudiant = $this->creerEtudiant('CTR00004');
        $inscription = $this->inscrire($etudiant, 'L1');
        app(InscriptionService::class)->transiter($inscription, 'valide', $this->scolarite->id);

        $this->actingAs($this->scolarite)->post(route('admin.inscriptions.corriger', $inscription->fresh()), [
            'motif' => 'Tentative de modification après validation officielle.',
        ])->assertStatus(422);
    }

    public function test_securite_non_autorise_et_cross_faculte(): void
    {
        $agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.ctr@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $etudiant = $this->creerEtudiant('CTR00005');
        $inscription = $this->inscrire($etudiant, 'L1');

        // Agent scolarité : corrections courantes autorisées (gradient RBAC).
        $this->actingAs($agent)->post(route('admin.inscriptions.corriger', $inscription), [
            'motif' => 'Correction courante par agent habilité de scolarité.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $autre = Faculte::create(['nom' => 'FLSH', 'code' => 'FLSH', 'description' => 'T', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.ctr@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autre->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte)->post(route('admin.inscriptions.corriger', $inscription), [
            'motif' => 'Tentative hors périmètre faculté de rattachement.',
        ])->assertForbidden();

        $this->actingAs($adminFaculte)->get(route('admin.verification.show', $etudiant))->assertForbidden();
    }

    public function test_moteur_lecture_seule_et_concurrence(): void
    {
        $etudiant = $this->creerEtudiant('CTR00006');
        $inscription = $this->inscrire($etudiant, 'L1');

        $avant = [
            'etudiants' => Etudiant::count(),
            'inscriptions' => InscriptionAcademique::count(),
        ];

        $this->actingAs($this->scolarite)->post(route('admin.verification.verifier', $etudiant))->assertRedirect();

        // Le moteur n'écrit que le run append-only, jamais le métier.
        $this->assertSame($avant['etudiants'], Etudiant::count());
        $this->assertSame($avant['inscriptions'], InscriptionAcademique::count());

        // Deux corrections séquentielles : pas de lost update.
        foreach (['Première justification administrative complète.', 'Seconde justification administrative complète.'] as $motif) {
            $this->actingAs($this->scolarite)->post(route('admin.inscriptions.corriger', $inscription), [
                'motif' => $motif,
            ])->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertSame(2, HistoriqueAcademique::where('action', 'correction_inscription')
            ->where('entite_id', $inscription->id)->count());
    }

    /** @param array<string, mixed> $attributs */
    private function creerEtudiant(string $cin, array $attributs = []): Etudiant
    {
        $etudiant = Etudiant::create(array_merge([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara', 'email' => strtolower($cin).'@ctr.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ], $attributs));
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    private function inscrire(Etudiant $etudiant, string $niveau = 'L1'): InscriptionAcademique
    {
        return $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id, 'niveau' => $niveau,
            'statut' => 'en_cours', 'type' => 'premiere',
        ]);
    }
}
