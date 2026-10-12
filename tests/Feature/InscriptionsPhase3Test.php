<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Services\InscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActAsApiClient;
use Tests\TestCase;

/**
 * Phase 2/3 — maturité : identité masquée API, idempotence, workflow
 * verrouillé, transfert aligné, attestation, RBAC + Faculty Scope + IDOR.
 */
class InscriptionsPhase3Test extends TestCase
{
    use ActAsApiClient;
    use RefreshDatabase;

    private Faculte $faculte;

    private Faculte $autreFaculte;

    private User $scolarite;

    private User $adminFaculte;

    private AnneeUniversitaire $annee;

    private AnneeUniversitaire $anneeSuivante;

    private Programme $programme;

    private Niveau $niveau;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->autreFaculte = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true]);
        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.ph3@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.ph3@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => true,
        ]);
        $this->anneeSuivante = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => false,
        ]);
        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LMI', 'nom' => 'Licence MI',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->niveau = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
    }

    public function test_api_masque_cin_complete_sans_archives_view(): void
    {
        $etudiant = $this->creerEtudiant('BKPH3001');

        [$client, $token] = $this->clientApi(['students:read']);

        $reponse = $this->getApi(route('api.v1.students.show', $etudiant), $token)->assertOk();

        $this->assertSame('BK***001', $reponse->json('data.cin_masquee'));
        $this->assertArrayNotHasKey('cin', $reponse->json('data'));
    }

    public function test_double_reinscription_ne_cree_qu_une_ligne(): void
    {
        $etudiant = $this->creerEtudiant('BKPH3002');
        $service = app(InscriptionService::class);

        $service->reinscrire($etudiant, $this->anneeSuivante, $this->scolarite->id);

        try {
            $service->reinscrire($etudiant, $this->anneeSuivante, $this->scolarite->id);
            $this->fail('La seconde réinscription aurait dû être refusée.');
        } catch (\DomainException) {
            // Attendu : 422 métier, pas de doublon.
        }

        $this->assertSame(1, $etudiant->inscriptionsAcademiques()
            ->where('annee_universitaire_id', $this->anneeSuivante->id)->count());
    }

    public function test_transition_interdite_refusee_et_audit_trace(): void
    {
        $etudiant = $this->creerEtudiant('BKPH3003');
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();
        $service = app(InscriptionService::class);

        $service->transiter($inscription, 'valide', $this->scolarite->id, 'Dossier complet.');

        $this->assertSame('valide', $inscription->fresh()->statut);
        $this->assertTrue(HistoriqueAcademique::where('action', HistoriqueAcademique::ACTION_MODIFICATION_INSCRIPTION)
            ->where('entite_id', $inscription->id)->exists());

        try {
            $service->transiter($inscription->fresh(), 'en_cours', $this->scolarite->id);
            $this->fail('Transition verrouillée aurait dû être refusée.');
        } catch (\DomainException) {
            $this->assertSame('valide', $inscription->fresh()->statut);
        }
    }

    public function test_transfert_aligne_faculte_et_conserve_historique(): void
    {
        $etudiant = $this->creerEtudiant('BKPH3004');
        $cible = Programme::create([
            'faculte_id' => $this->autreFaculte->id, 'code' => 'LMPC', 'nom' => 'Licence MPC',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);

        $this->actingAs($this->scolarite)->post(route('admin.etudiants.transferer', $etudiant), [
            'programme_id' => $cible->id, 'motif' => 'Réorientation officielle.',
        ])->assertRedirect();

        $etudiant->refresh();
        $this->assertSame($this->autreFaculte->id, (int) $etudiant->faculte_id);
        $this->assertSame($cible->id, (int) $etudiant->programme_id);
        $this->assertTrue($etudiant->situations()->where('type', 'transfert')->exists());
        $this->assertTrue(HistoriqueAcademique::where('action', HistoriqueAcademique::ACTION_MODIFICATION_INSCRIPTION)
            ->where('entite_id', $etudiant->id)->exists());
        $this->assertSame(1, $etudiant->inscriptionsAcademiques()->count());
    }

    public function test_attestation_pdf_et_idor_faculte(): void
    {
        $etudiant = $this->creerEtudiant('BKPH3005');
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();

        $reponse = $this->actingAs($this->scolarite)
            ->get(route('admin.inscriptions.attestation', $inscription))
            ->assertRedirect();

        // Phase 16 : émission via le registre puis téléchargement signé.
        $this->actingAs($this->scolarite)->get($reponse->headers->get('Location'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $etudiantB = $this->creerEtudiant('BKPH3006');
        $etudiantB->update(['faculte_id' => $this->autreFaculte->id]);
        $inscriptionB = $etudiantB->inscriptionsAcademiques()->firstOrFail();

        $this->actingAs($this->adminFaculte)
            ->get(route('admin.inscriptions.attestation', $inscriptionB))
            ->assertForbidden();
    }

    public function test_api_registrations_filtres_et_perimetre(): void
    {
        $etudiant = $this->creerEtudiant('BKPH3007');
        $inscription = $etudiant->inscriptionsAcademiques()->firstOrFail();

        [$client, $token] = $this->clientApi(['archives:read']);

        $this->getApi(route('api.v1.registrations.index', ['statut' => 'en_cours']), $token)
            ->assertOk()
            ->assertJsonFragment(['id' => $inscription->id]);

        $this->getApi(route('api.v1.registrations.index', ['statut' => 'valide']), $token)
            ->assertOk()
            ->assertJsonMissing(['id' => $inscription->id]);
    }

    private function creerEtudiant(string $cin): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@ph3.test',
            'date_naissance' => '2004-01-01', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id, 'niveau_id' => $this->niveau->id,
            'niveau' => 'L1', 'annee_academique' => '2025-2026', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id, 'niveau_id' => $this->niveau->id,
            'niveau' => 'L1', 'statut' => 'en_cours', 'type' => 'premiere', 'regime' => 'initiale',
        ]);

        return $etudiant->refresh();
    }
}
