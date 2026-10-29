<?php

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Finances\Models\Facture;
use App\Modules\Finances\Models\Paiement;
use App\Modules\Finances\Models\Remboursement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * LOOP-12 — vérification indépendante des paiements et du rapprochement.
 *
 * Contrôle négatif absent de la suite existante : le tableau de bord
 * financier est scopé par faculté pour les factures, les impayés et les
 * reçus à vérifier, mais le compteur de remboursements était global. Un
 * admin_faculte pouvait donc déduire l'activité de remboursement des
 * autres facultés.
 */
class Loop12FinancesVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private User $adminFaculteA;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set([
            'modules.finances.active' => true,
            'inscriptions-paiements.actif' => true,
        ]);

        $this->faculteA = Faculte::create([
            'nom' => 'Sciences LOOP12', 'code' => 'F12A', 'active' => true,
        ]);
        $this->faculteB = Faculte::create([
            'nom' => 'Droit LOOP12', 'code' => 'F12B', 'active' => true,
        ]);
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->adminFaculteA = User::creerCompteBackOffice([
            'name' => 'Admin Finances A',
            'email' => 'loop12-admin-'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');
    }

    private function creerFacture(Faculte $faculte, string $reference, array $attributs = []): Facture
    {
        $etudiant = Etudiant::create([
            'cin' => 'CIN12'.uniqid(),
            'nom' => 'Nom'.$reference, 'prenom' => 'Prenom',
            'email' => 'loop12-'.$reference.'-'.uniqid().'@example.test', 'sexe' => 'M',
            'faculte_id' => $faculte->id, 'niveau' => 'L1', 'annee_academique' => '2026-2027',
            'adresse' => 'Rue test', 'ville' => 'Dakar', 'code_postal' => '10000', 'annee_bac' => '2020',
        ]);

        return Facture::create($attributs + [
            'reference' => $reference,
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'statut' => 'paid',
            'total_centimes' => 100000,
            'date_echeance' => '2026-12-31',
            'created_by' => null,
        ]);
    }

    private function creerAdminFinancier(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Scolarité Finances LOOP12',
            'email' => 'loop12-fin-'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function creerPaiementConfirme(Facture $facture, string $reference, int $montantCentimes): Paiement
    {
        return Paiement::create([
            'reference' => $reference,
            'facture_id' => $facture->id,
            'montant_centimes' => $montantCentimes,
            'methode' => 'virement',
            'statut' => 'confirmed',
            'confirme_a' => now(),
        ]);
    }

    public function test_admin_faculte_ne_voit_pas_le_compteur_de_remboursements_d_une_autre_faculte(): void
    {
        $factureB = $this->creerFacture($this->faculteB, 'FAC12B');

        $paiementB = Paiement::create([
            'reference' => 'PAY12B',
            'facture_id' => $factureB->id,
            'montant_centimes' => 100000,
            'methode' => 'virement',
            'statut' => 'confirmed',
            'confirme_a' => now(),
        ]);

        Remboursement::create([
            'paiement_id' => $paiementB->id,
            'montant_centimes' => 10000,
            'statut' => 'requested',
            'motif' => 'Demande de remboursement partiel (test).',
            'demande_par' => null,
        ]);

        $reponse = $this->actingAs($this->adminFaculteA)
            ->get(route('admin.finances.index'))
            ->assertOk();

        $reponse->assertViewHas('remboursements', 0);
    }

    public function test_le_tableau_de_bord_agrege_en_sql_et_ne_fuit_pas_hors_faculte(): void
    {
        $factureA1 = $this->creerFacture($this->faculteA, 'AGG-A1', [
            'statut' => 'issued',
            'total_centimes' => 100000,
            'remise_centimes' => 10000,
            'date_echeance' => now()->subDay()->toDateString(),
        ]);
        $this->creerPaiementConfirme($factureA1, 'PAY-AGG-A1', 30000);

        $this->creerFacture($this->faculteA, 'AGG-A2', [
            'statut' => 'paid',
            'total_centimes' => 50000,
        ]);

        $this->creerFacture($this->faculteA, 'AGG-A3', [
            'statut' => 'issued',
            'total_centimes' => 20000,
            'date_echeance' => now()->addMonth()->toDateString(),
        ]);

        $this->creerFacture($this->faculteB, 'AGG-B1', [
            'statut' => 'paid',
            'total_centimes' => 999999,
        ]);

        $reponse = $this->actingAs($this->adminFaculteA)
            ->get(route('admin.finances.index'))
            ->assertOk();

        // A1 : net 90 000, payé 30 000, reste 60 000 (échue).
        // A2 : payée en externe, net 50 000.
        // A3 : net 20 000, échéance future.
        // B1 est hors périmètre.
        $reponse->assertViewHas('total', 160000);
        $reponse->assertViewHas('encaisse', 80000);
        $reponse->assertViewHas('reste', 80000);
        $reponse->assertViewHas('en_retard', 60000);
        $reponse->assertViewHas('impayees', 2);
    }

    public function test_payer_refuse_une_facture_hors_perimetre_meme_si_la_permission_est_accordee(): void
    {
        // Simule une matrice de permissions future qui accorderait
        // `finances.manage` à un compte restreint à une faculté : le
        // contrôle de périmètre du contrôleur doit rester bloquant.
        Gate::before(fn () => true);

        $factureB = $this->creerFacture($this->faculteB, 'PAY-SCOPE-B', [
            'statut' => 'issued',
            'total_centimes' => 100000,
        ]);

        $this->actingAs($this->adminFaculteA)
            ->post(route('admin.finances.payer', $factureB), [
                'montant' => 10,
                'methode' => 'especes',
            ])
            ->assertNotFound();

        $this->assertSame(0, Paiement::count());
    }

    public function test_le_parcours_de_remboursement_est_disponible_et_trace(): void
    {
        $admin = $this->creerAdminFinancier();

        $facture = $this->creerFacture($this->faculteA, 'RMB-A1', [
            'statut' => 'issued',
            'total_centimes' => 100000,
        ]);
        $paiement = $this->creerPaiementConfirme($facture, 'PAY-RMB-A1', 100000);

        $this->actingAs($admin)
            ->post(route('admin.finances.remboursements.store', $paiement), [
                'montant' => 250,
                'motif' => 'Désistement partiel',
            ])
            ->assertRedirect();

        $remboursement = Remboursement::firstOrFail();
        $this->assertSame(25000, $remboursement->montant_centimes);
        $this->assertSame('requested', $remboursement->statut);
        $this->assertSame($admin->id, $remboursement->demande_par);

        $this->actingAs($admin)
            ->get(route('admin.finances.remboursements.index'))
            ->assertOk()
            ->assertSee('RMB-A1')
            ->assertSee('Désistement partiel');

        foreach (['approved', 'processed', 'completed'] as $cible) {
            $this->actingAs($admin)
                ->post(route('admin.finances.remboursements.traiter', $remboursement->fresh()), [
                    'statut' => $cible,
                ])
                ->assertRedirect();
        }

        $this->assertSame('completed', $remboursement->fresh()->statut);
        $this->assertSame($admin->id, $remboursement->fresh()->traite_par);
        $this->assertSame('refunded', $facture->fresh()->statut);
    }

    public function test_la_liste_et_le_traitement_des_remboursements_sont_bornees_a_la_faculte(): void
    {
        Gate::before(fn () => true);

        $factureB = $this->creerFacture($this->faculteB, 'RMB-SCOPE-B', [
            'statut' => 'issued',
            'total_centimes' => 100000,
        ]);
        $paiementB = $this->creerPaiementConfirme($factureB, 'PAY-RMB-SCOPE-B', 100000);

        $remboursementB = Remboursement::create([
            'paiement_id' => $paiementB->id,
            'montant_centimes' => 10000,
            'statut' => 'requested',
            'motif' => 'Demande hors périmètre',
            'demande_par' => null,
        ]);

        $this->actingAs($this->adminFaculteA)
            ->get(route('admin.finances.remboursements.index'))
            ->assertOk()
            ->assertDontSee('RMB-SCOPE-B');

        $this->actingAs($this->adminFaculteA)
            ->post(route('admin.finances.remboursements.traiter', $remboursementB), [
                'statut' => 'approved',
            ])
            ->assertNotFound();

        $this->assertSame('requested', $remboursementB->fresh()->statut);
    }
}
