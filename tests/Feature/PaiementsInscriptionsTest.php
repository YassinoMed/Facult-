<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\Finances\Models\DossierPaiement;
use App\Modules\Finances\Models\Facture;
use App\Modules\Finances\Models\RecuPaiement;
use App\Modules\Finances\Models\Tarif;
use App\Modules\Finances\Services\DossierPaiementService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * LOOP-UNIREGISTRE-INSCRIPTIONS-PAIEMENTS-01.
 *
 * Preuves attendues :
 *  - un dossier de paiement par inscription annuelle, pour L1 → M2 ;
 *  - les reçus ne sont jamais réutilisés entre inscriptions/années ;
 *  - un dépôt n'est jamais un paiement confirmé ;
 *  - validation/refus tracés, refus motivé, historique conservé ;
 *  - isolation étudiant et faculté, permissions minimales ;
 *  - validation profonde du fichier et anti-doublon.
 */
class PaiementsInscriptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $adminFaculte;

    private Faculte $faculte;

    private Faculte $autreFaculte;

    private AnneeUniversitaire $annee;

    private AnneeUniversitaire $anneeSuivante;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.finances.active' => true,
            'inscriptions-paiements.actif' => true,
        ]);

        Storage::fake('private');

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences', 'code' => 'FSPAY', 'description' => 'Test', 'active' => true,
        ]);
        $this->autreFaculte = Faculte::create([
            'nom' => 'Faculté de Médecine', 'code' => 'FMPAY', 'description' => 'Test', 'active' => true,
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité Centrale', 'email' => 'sco.pay@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->adminFaculte = User::creerCompteBackOffice([
            'name' => 'Scolarité Faculté', 'email' => 'fac.pay@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->faculte->id,
        ], 'admin_faculte');

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->anneeSuivante = AnneeUniversitaire::create([
            'libelle' => '2027-2028', 'date_debut' => '2027-09-01', 'date_fin' => '2028-06-30', 'active' => false,
        ]);
    }

    public function test_dossier_unique_par_inscription_pour_tous_les_niveaux(): void
    {
        $niveaux = ['L1', 'L2', 'L3', 'M1', 'M2'];

        foreach ($niveaux as $index => $niveau) {
            $etudiant = $this->creerEtudiant('PAYNIV0'.$index, $this->faculte, $niveau);
            $inscription = $this->creerInscription($etudiant, $this->annee, $niveau);

            $this->assertNotNull($inscription->dossierPaiement, "Dossier absent pour {$niveau}.");
            $this->assertSame(DossierPaiement::NON_PAYE, $inscription->dossierPaiement->statut);
        }

        $this->assertDatabaseCount('dossiers_paiement', 5);

        $inscription = InscriptionAcademique::firstOrFail();

        $this->expectException(UniqueConstraintViolationException::class);
        DossierPaiement::create([
            'inscription_academique_id' => $inscription->id,
            'statut' => DossierPaiement::NON_PAYE,
        ]);
    }

    public function test_inscriptions_annuelles_successives_ont_des_dossiers_distincts_et_recus_non_reutilises(): void
    {
        $this->creerTarif('TARIF-A1', 100000, $this->annee);
        $this->creerTarif('TARIF-A2', 120000, $this->anneeSuivante);

        $etudiant = $this->creerEtudiant('PAYANN01');
        $premiere = $this->creerInscription($etudiant, $this->annee);

        $contenu = $this->contenuPdf('annee-1');

        $this->deposer($etudiant, $premiere, [
            'recu' => $this->fichierPdf('recu-a1.pdf', $contenu),
            'reference_paiement' => 'REF-A1',
        ])->assertRedirect(route('etudiant.paiements.inscription', $premiere));

        $this->prendre($premiere->dossierPaiement);
        $this->valider($premiere->dossierPaiement)->assertRedirect();

        $this->assertTrue((bool) $premiere->fresh()->paye);

        $seconde = $this->creerInscription($etudiant, $this->anneeSuivante);

        $this->assertNotSame($premiere->dossierPaiement->id, $seconde->dossierPaiement->id);
        $this->assertSame(DossierPaiement::NON_PAYE, $seconde->dossierPaiement->statut);
        $this->assertSame(0, $seconde->dossierPaiement->recus()->count());
        $this->assertFalse((bool) $seconde->fresh()->paye);

        // Même fichier réutilisé pour la nouvelle année : refusé.
        $this->deposer($etudiant, $seconde, [
            'recu' => $this->fichierPdf('recu-a2.pdf', $contenu),
            'reference_paiement' => 'REF-A2',
            'montant' => '1200.00',
        ])->assertSessionHasErrors('recu');

        $this->assertSame(0, $seconde->dossierPaiement->recus()->count());

        // Fichier distinct : accepté, l'ancien reçu reste validé.
        $this->deposer($etudiant, $seconde, [
            'recu' => $this->fichierPdf('recu-a2.pdf', $this->contenuPdf('annee-2')),
            'reference_paiement' => 'REF-A2',
            'montant' => '1200.00',
        ])->assertRedirect();

        $this->assertSame(DossierPaiement::RECU_DEPOSE, $seconde->dossierPaiement->fresh()->statut);
        $this->assertSame(RecuPaiement::VALIDE, $premiere->dossierPaiement->fresh()->recus()->first()->statut);
        $this->assertFalse((bool) $seconde->fresh()->paye);
    }

    public function test_montant_attendu_priorise_facture_puis_tarif_specifique_sans_valeur_inventee(): void
    {
        $etudiant = $this->creerEtudiant('PAYMNT01');
        $inscription = $this->creerInscription($etudiant, $this->annee);

        $service = app(DossierPaiementService::class);

        // Aucun tarif, aucune facture : null, jamais un montant inventé.
        $montant = $service->montantAttendu($inscription);
        $this->assertNull($montant['centimes']);
        $this->assertSame('non_configure', $montant['source']);

        $this->creerTarif('TARIF-GLOBAL', 100000, $this->annee);
        $this->creerTarif('TARIF-L1', 150000, $this->annee, 'L1');

        $montant = $service->montantAttendu($inscription);
        $this->assertSame(150000, $montant['centimes']);
        $this->assertSame('tarif', $montant['source']);
        $this->assertCount(1, $montant['details']);

        // Une facture non annulée est la source la plus précise.
        Facture::create([
            'reference' => 'FAC-PAY-001',
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'inscription_academique_id' => $inscription->id,
            'statut' => 'issued',
            'total_centimes' => 250000,
            'remise_centimes' => 50000,
            'date_echeance' => '2026-12-31',
            'created_by' => $this->admin->id,
        ]);

        $montant = $service->montantAttendu($inscription);
        $this->assertSame(200000, $montant['centimes']);
        $this->assertSame('facture', $montant['source']);
        $this->assertSame('2026-12-31', $montant['echeance']->format('Y-m-d'));

        // Facture annulée ignorée : retour au tarif officiel.
        Facture::where('reference', 'FAC-PAY-001')->update(['statut' => 'cancelled']);
        $this->assertSame(150000, $service->montantAttendu($inscription)['centimes']);
    }

    public function test_depot_recu_prive_ne_confirme_jamais_le_paiement(): void
    {
        $this->creerTarif('TARIF-DEPOT', 100000, $this->annee);

        $etudiant = $this->creerEtudiant('PAYDEP01');
        $inscription = $this->creerInscription($etudiant, $this->annee);

        $this->deposer($etudiant, $inscription, [
            'reference_paiement' => 'REF-DEPOT-1',
            'montant' => '1000.00',
        ])->assertRedirect();

        $dossier = $inscription->dossierPaiement->fresh();
        $recu = $dossier->recus()->firstOrFail();

        $this->assertSame(DossierPaiement::RECU_DEPOSE, $dossier->statut);
        $this->assertSame(RecuPaiement::RECU_DEPOSE, $recu->statut);
        $this->assertFalse((bool) $inscription->fresh()->paye);

        $this->assertSame(100000, $recu->montant_attendu_centimes);
        $this->assertSame('tarif', $recu->source_montant);
        $this->assertSame(100000, $recu->montant_declare_centimes);
        $this->assertSame('REF-DEPOT-1', $recu->reference_paiement);
        $this->assertSame('2026-10-01', $recu->date_paiement->format('Y-m-d'));
        $this->assertSame(64, strlen($recu->sha256));

        $this->assertStringStartsWith(
            'paiements-inscriptions/2026-2027/'.$etudiant->id.'/recus-paiement/',
            $recu->storage_path
        );
        $this->assertTrue(Storage::disk('private')->exists($recu->storage_path));
        $this->assertSame([], Storage::disk('private')->files('student-dossiers'));
    }

    public function test_etudiant_ne_peut_pas_acceder_aux_inscriptions_ou_recus_d_un_autre(): void
    {
        $etudiantA = $this->creerEtudiant('PAYISO01');
        $etudiantB = $this->creerEtudiant('PAYISO02');
        $inscriptionA = $this->creerInscription($etudiantA, $this->annee);
        $this->creerInscription($etudiantB, $this->annee);

        $this->deposer($etudiantA, $inscriptionA, ['reference_paiement' => 'REF-ISO-1'])->assertRedirect();

        $recuA = $inscriptionA->dossierPaiement->recus()->firstOrFail();

        $this->withSession(['etudiant_auth_id' => $etudiantB->id])
            ->get(route('etudiant.paiements.inscription', $inscriptionA))
            ->assertNotFound();

        $this->withSession(['etudiant_auth_id' => $etudiantB->id])
            ->post(route('etudiant.paiements.recus.store', $inscriptionA), [
                'recu' => $this->fichierPdf('intrus.pdf', $this->contenuPdf('intrus')),
                'montant' => '1000.00',
                'reference_paiement' => 'REF-INTRUS',
                'date_paiement' => '2026-10-01',
                'engagement' => '1',
            ])->assertNotFound();

        $this->withSession(['etudiant_auth_id' => $etudiantB->id])
            ->get(route('etudiant.paiements.recus.telecharger', [$inscriptionA, $recuA]))
            ->assertNotFound();

        $this->assertSame(1, $inscriptionA->dossierPaiement->recus()->count());
    }

    public function test_file_admin_est_protegee_par_permission_et_faculte(): void
    {
        $this->creerTarif('TARIF-ADM', 100000, $this->annee);

        $etudiantA = $this->creerEtudiant('PAYADM01', $this->faculte);
        $etudiantB = $this->creerEtudiant('PAYADM02', $this->autreFaculte);
        $inscriptionA = $this->creerInscription($etudiantA, $this->annee);
        $inscriptionB = $this->creerInscription($etudiantB, $this->annee);

        $this->deposer($etudiantA, $inscriptionA, ['reference_paiement' => 'REF-ADM-A'])->assertRedirect();
        $this->deposer($etudiantB, $inscriptionB, ['reference_paiement' => 'REF-ADM-B'])->assertRedirect();

        $agent = User::creerCompteBackOffice([
            'name' => 'Agent sans finance', 'email' => 'agent.pay@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        $this->assertFalse($agent->hasPermission('finances.view'));
        $this->actingAs($agent)->get(route('admin.finances.paiements.index'))->assertForbidden();

        $this->assertTrue($this->adminFaculte->hasPermission('finances.receipts.verify'));
        $this->assertFalse($this->adminFaculte->hasPermission('finances.confirm'));

        $reponse = $this->actingAs($this->adminFaculte)
            ->get(route('admin.finances.paiements.index', ['statut' => 'tous']))
            ->assertOk();

        $lignes = $reponse->viewData('lignes');
        $this->assertCount(1, $lignes);
        $this->assertSame($inscriptionA->dossierPaiement->id, $lignes->first()['dossier']->id);

        $this->actingAs($this->adminFaculte)
            ->get(route('admin.finances.paiements.show', $inscriptionB->dossierPaiement))
            ->assertForbidden();

        $this->actingAs($this->adminFaculte)
            ->post(route('admin.finances.paiements.prendre', $inscriptionB->dossierPaiement))
            ->assertForbidden();

        // Périmètre autorisé : l'admin faculté peut prendre en charge et valider.
        $this->actingAs($this->adminFaculte)
            ->post(route('admin.finances.paiements.prendre', $inscriptionA->dossierPaiement))
            ->assertRedirect();

        $this->actingAs($this->adminFaculte)
            ->post(route('admin.finances.paiements.valider', $inscriptionA->dossierPaiement))
            ->assertRedirect();

        $this->assertSame(DossierPaiement::VALIDE, $inscriptionA->dossierPaiement->fresh()->statut);
        $this->assertTrue((bool) $inscriptionA->fresh()->paye);
    }

    public function test_file_administrative_ne_fait_pas_de_requete_par_dossier(): void
    {
        $this->creerTarif('TARIF-FILE', 100000, $this->annee);

        for ($i = 1; $i <= 10; $i++) {
            $etudiant = $this->creerEtudiant('FILE'.$i);
            $this->creerInscription($etudiant, $this->annee);
        }

        $requetes = 0;
        DB::listen(function () use (&$requetes): void {
            $requetes++;
        });

        $this->actingAs($this->admin)
            ->get(route('admin.finances.paiements.index', ['statut' => 'tous']))
            ->assertOk();

        // Les montants attendus sont chargés par lot (factures + tarifs),
        // jamais deux requêtes supplémentaires par dossier. Mesuré : 16
        // requêtes pour 10 dossiers (dont layout, pagination et filtres).
        $this->assertLessThan(20, $requetes, "File administrative : {$requetes} requêtes.");
    }

    public function test_validation_exige_prise_en_charge_et_ne_peut_pas_etre_doublee(): void
    {
        $this->creerTarif('TARIF-VAL', 100000, $this->annee);

        $etudiant = $this->creerEtudiant('PAYVAL01');
        $inscription = $this->creerInscription($etudiant, $this->annee);
        $this->deposer($etudiant, $inscription, ['reference_paiement' => 'REF-VAL-1'])->assertRedirect();

        $dossier = $inscription->dossierPaiement->fresh();

        // Validation directe sans prise en charge : refusée.
        $this->actingAs($this->admin)
            ->post(route('admin.finances.paiements.valider', $dossier))
            ->assertSessionHasErrors('paiement');

        $this->assertSame(DossierPaiement::RECU_DEPOSE, $dossier->fresh()->statut);
        $this->assertFalse((bool) $inscription->fresh()->paye);

        $this->prendre($dossier);
        $this->valider($dossier)->assertRedirect();

        $this->assertSame(DossierPaiement::VALIDE, $dossier->fresh()->statut);
        $this->assertTrue((bool) $inscription->fresh()->paye);
        $this->assertSame(1, RecuPaiement::where('dossier_paiement_id', $dossier->id)->where('est_valide', true)->count());

        // Double validation : refusée, aucun second reçu validé.
        $this->actingAs($this->admin)
            ->post(route('admin.finances.paiements.valider', $dossier))
            ->assertSessionHasErrors('paiement');

        $this->assertSame(1, RecuPaiement::where('dossier_paiement_id', $dossier->id)->where('est_valide', true)->count());
    }

    public function test_validation_avec_ecart_de_montant_exige_un_commentaire(): void
    {
        $this->creerTarif('TARIF-ECART', 100000, $this->annee);

        $etudiant = $this->creerEtudiant('PAYECR01');
        $inscription = $this->creerInscription($etudiant, $this->annee);

        $this->deposer($etudiant, $inscription, [
            'reference_paiement' => 'REF-ECART-1',
            'montant' => '900.00',
        ])->assertRedirect();

        $dossier = $inscription->dossierPaiement->fresh();
        $this->prendre($dossier);

        $this->actingAs($this->admin)
            ->post(route('admin.finances.paiements.valider', $dossier))
            ->assertSessionHasErrors('paiement');

        $this->assertSame(DossierPaiement::EN_VERIFICATION, $dossier->fresh()->statut);
        $this->assertFalse((bool) $inscription->fresh()->paye);

        $this->actingAs($this->admin)
            ->post(route('admin.finances.paiements.valider', $dossier), [
                'commentaire' => 'Écart de 100 DH justifié par un versement partiel documenté.',
            ])
            ->assertRedirect();

        $recu = $dossier->fresh()->recus()->firstOrFail();
        $this->assertSame(RecuPaiement::VALIDE, $recu->statut);
        $this->assertStringContainsString('versement partiel', (string) $recu->commentaire);
        $this->assertTrue((bool) $inscription->fresh()->paye);
    }

    public function test_validation_sans_montant_attendu_exige_un_commentaire(): void
    {
        // Aucun tarif ni facture : le montant attendu reste null. Valider
        // sans référence officielle exige une justification humaine tracée.
        $etudiant = $this->creerEtudiant('PAYNC01');
        $inscription = $this->creerInscription($etudiant, $this->annee);

        $this->deposer($etudiant, $inscription, [
            'reference_paiement' => 'REF-NC-1',
            'montant' => '1000.00',
        ])->assertRedirect();

        $dossier = $inscription->dossierPaiement->fresh();
        $this->prendre($dossier);

        $this->actingAs($this->admin)
            ->post(route('admin.finances.paiements.valider', $dossier))
            ->assertSessionHasErrors('paiement');

        $this->assertSame(DossierPaiement::EN_VERIFICATION, $dossier->fresh()->statut);
        $this->assertFalse((bool) $inscription->fresh()->paye);

        $this->actingAs($this->admin)
            ->post(route('admin.finances.paiements.valider', $dossier), [
                'commentaire' => 'Aucun tarif paramétré : reçu original vérifié manuellement par la scolarité.',
            ])
            ->assertRedirect();

        $recu = $dossier->fresh()->recus()->firstOrFail();
        $this->assertNull($recu->montant_attendu_centimes);
        $this->assertSame(RecuPaiement::VALIDE, $recu->statut);
        $this->assertStringContainsString('vérifié manuellement', (string) $recu->commentaire);
        $this->assertTrue((bool) $inscription->fresh()->paye);
    }

    public function test_refus_conserve_motif_et_permet_un_nouveau_recu_versionne(): void
    {
        $this->creerTarif('TARIF-REFUS', 100000, $this->annee);

        $etudiant = $this->creerEtudiant('PAYREF01');
        $inscription = $this->creerInscription($etudiant, $this->annee);

        $this->deposer($etudiant, $inscription, [
            'reference_paiement' => 'REF-REFUS-1',
            'date_paiement' => '2026-10-01',
        ])->assertRedirect();

        $dossier = $inscription->dossierPaiement->fresh();
        $this->prendre($dossier);

        $motif = 'Reçu illisible : montant et référence absents.';

        $this->actingAs($this->admin)
            ->post(route('admin.finances.paiements.refuser', $dossier), ['motif' => $motif])
            ->assertRedirect();

        $dossier->refresh();
        $premier = $dossier->recus()->where('version', 1)->firstOrFail();

        $this->assertSame(DossierPaiement::REFUSE, $dossier->statut);
        $this->assertSame(RecuPaiement::REFUSE, $premier->statut);
        $this->assertSame($motif, $premier->motif_refus);
        $this->assertFalse((bool) $inscription->fresh()->paye);

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.paiements.inscription', $inscription))
            ->assertOk()
            ->assertSee($motif);

        // Nouveau reçu : version 2, l'ancien reste intact. Même référence,
        // même date et même montant que le reçu refusé : il s'agit du même
        // paiement avec un document corrigé. Le doublon n'est interdit
        // qu'entre inscriptions distinctes, pas dans l'historique du dossier.
        $this->deposer($etudiant, $inscription, [
            'recu' => $this->fichierPdf('recu-v2.pdf', $this->contenuPdf('refus-v2')),
            'reference_paiement' => 'REF-REFUS-1',
            'date_paiement' => '2026-10-01',
        ])->assertRedirect();

        $dossier->refresh();
        $this->assertSame(DossierPaiement::RECU_DEPOSE, $dossier->statut);
        $this->assertSame(2, $dossier->recus()->count());
        $this->assertSame(RecuPaiement::REFUSE, $dossier->recus()->where('version', 1)->firstOrFail()->statut);

        $deuxieme = $dossier->recus()->where('version', 2)->firstOrFail();
        $this->assertSame(RecuPaiement::RECU_DEPOSE, $deuxieme->statut);

        $this->prendre($dossier);
        $this->valider($dossier)->assertRedirect();

        $this->assertSame(DossierPaiement::VALIDE, $dossier->fresh()->statut);
        $this->assertSame(RecuPaiement::VALIDE, $deuxieme->fresh()->statut);
        $this->assertSame(RecuPaiement::REFUSE, $premier->fresh()->statut);
        $this->assertSame($motif, $premier->fresh()->motif_refus);
        $this->assertTrue((bool) $inscription->fresh()->paye);
    }

    public function test_deux_refus_successifs_conservent_tout_l_historique(): void
    {
        $this->creerTarif('TARIF-DEUX-REFUS', 100000, $this->annee);

        $etudiant = $this->creerEtudiant('PAYREF02');
        $inscription = $this->creerInscription($etudiant, $this->annee);

        $motifs = [
            'Premier refus : reçu illisible et référence absente.',
            'Deuxième refus : montant déclaré incohérent avec le reçu.',
        ];

        foreach ([1, 2] as $version) {
            $this->deposer($etudiant, $inscription, [
                'recu' => $this->fichierPdf('recu-refus-'.$version.'.pdf'),
                'reference_paiement' => 'REF-DEUX-REFUS-'.$version,
                'date_paiement' => '2026-10-0'.$version,
            ])->assertRedirect();

            $dossier = $inscription->dossierPaiement->fresh();
            $this->prendre($dossier);

            $this->actingAs($this->admin)
                ->post(route('admin.finances.paiements.refuser', $dossier), [
                    'motif' => $motifs[$version - 1],
                ])
                ->assertRedirect();
        }

        $dossier = $inscription->dossierPaiement->fresh();
        $this->assertSame(DossierPaiement::REFUSE, $dossier->statut);
        $this->assertSame(2, $dossier->recus()->count());

        foreach ($dossier->recus()->reorder()->orderBy('version')->get() as $index => $recu) {
            $this->assertSame($index + 1, $recu->version);
            $this->assertSame(RecuPaiement::REFUSE, $recu->statut);
            $this->assertSame($motifs[$index], $recu->motif_refus);
            $this->assertNull($recu->est_valide);
        }

        // Un troisième reçu reste possible après deux refus, puis validable.
        $this->deposer($etudiant, $inscription, [
            'recu' => $this->fichierPdf('recu-refus-3.pdf'),
            'reference_paiement' => 'REF-DEUX-REFUS-3',
            'date_paiement' => '2026-10-03',
        ])->assertRedirect();

        $dossier->refresh();
        $this->prendre($dossier);
        $this->valider($dossier)->assertRedirect();

        $this->assertSame(3, $dossier->fresh()->recus()->count());
        $this->assertSame(2, $dossier->fresh()->recus()->where('statut', RecuPaiement::REFUSE)->count());
        $this->assertSame(1, $dossier->fresh()->recus()->where('statut', RecuPaiement::VALIDE)->count());
        $this->assertTrue((bool) $inscription->fresh()->paye);
    }

    public function test_un_seul_recu_en_attente_par_dossier(): void
    {
        $etudiant = $this->creerEtudiant('PAYATT01');
        $inscription = $this->creerInscription($etudiant, $this->annee);

        $this->deposer($etudiant, $inscription, ['reference_paiement' => 'REF-ATT-1'])->assertRedirect();

        $this->deposer($etudiant, $inscription, [
            'recu' => $this->fichierPdf('recu-2.pdf', $this->contenuPdf('attente-2')),
            'reference_paiement' => 'REF-ATT-2',
        ])->assertSessionHasErrors('recu');

        $dossier = $inscription->dossierPaiement->fresh();
        $this->assertSame(1, $dossier->recus()->count());

        $this->prendre($dossier);

        $this->deposer($etudiant, $inscription, [
            'recu' => $this->fichierPdf('recu-3.pdf', $this->contenuPdf('attente-3')),
            'reference_paiement' => 'REF-ATT-3',
        ])->assertSessionHasErrors('recu');

        $this->assertSame(1, $dossier->recus()->count());
    }

    public function test_fichier_identique_ou_meme_reference_date_montant_est_refuse(): void
    {
        $etudiantA = $this->creerEtudiant('PAYDUP01');
        $etudiantB = $this->creerEtudiant('PAYDUP02');
        $inscriptionA = $this->creerInscription($etudiantA, $this->annee);
        $inscriptionB = $this->creerInscription($etudiantB, $this->annee);

        $contenu = $this->contenuPdf('doublon');

        $this->deposer($etudiantA, $inscriptionA, [
            'recu' => $this->fichierPdf('recu-a.pdf', $contenu),
            'reference_paiement' => 'REF-DUP-1',
            'date_paiement' => '2026-10-01',
            'montant' => '1000.00',
        ])->assertRedirect();

        // Même fichier, autre inscription : refusé.
        $this->deposer($etudiantB, $inscriptionB, [
            'recu' => $this->fichierPdf('recu-b.pdf', $contenu),
            'reference_paiement' => 'REF-DUP-2',
            'date_paiement' => '2026-10-01',
            'montant' => '1000.00',
        ])->assertSessionHasErrors('recu');

        // Fichier différent mais même référence/date/montant : refusé.
        $this->deposer($etudiantB, $inscriptionB, [
            'recu' => $this->fichierPdf('recu-b2.pdf', $this->contenuPdf('doublon-2')),
            'reference_paiement' => 'REF-DUP-1',
            'date_paiement' => '2026-10-01',
            'montant' => '1000.00',
        ])->assertSessionHasErrors('recu');

        $this->assertSame(0, $inscriptionB->dossierPaiement->recus()->count());
        $this->assertSame(1, $inscriptionA->dossierPaiement->recus()->count());

        // Fichier + référence distincts : accepté.
        $this->deposer($etudiantB, $inscriptionB, [
            'recu' => $this->fichierPdf('recu-b3.pdf', $this->contenuPdf('doublon-3')),
            'reference_paiement' => 'REF-DUP-3',
            'date_paiement' => '2026-10-02',
            'montant' => '1000.00',
        ])->assertRedirect();

        $this->assertSame(1, $inscriptionB->dossierPaiement->fresh()->recus()->count());
    }

    public function test_mime_reel_et_signature_sont_verifies_meme_si_extension_trompeuse(): void
    {
        $etudiant = $this->creerEtudiant('PAYMIM01');
        $inscription = $this->creerInscription($etudiant, $this->annee);

        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

        // Extension .pdf mais contenu JPEG : refusé par la validation HTTP.
        $this->deposer($etudiant, $inscription, [
            'recu' => UploadedFile::fake()->createWithContent('recu.pdf', $jpeg),
            'reference_paiement' => 'REF-MIME-1',
        ])->assertSessionHasErrors('recu');

        $this->assertSame(0, $inscription->dossierPaiement->recus()->count());
        $this->assertSame([], Storage::disk('private')->allFiles());

        // Appel direct du service : la validation profonde refuse aussi.
        try {
            app(DossierPaiementService::class)->deposerRecu(
                $inscription,
                $etudiant,
                UploadedFile::fake()->createWithContent('recu.pdf', $jpeg),
                [
                    'montant' => '1000.00',
                    'reference_paiement' => 'REF-MIME-2',
                    'date_paiement' => '2026-10-01',
                ],
            );
            $this->fail('Un contenu JPEG avec extension PDF aurait dû être refusé.');
        } catch (RuntimeException $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'signature manquante')
                    || str_contains($e->getMessage(), 'Contenu incohérent'),
                'Le service devait refuser le faux PDF, message reçu : '.$e->getMessage()
            );
        }

        $this->assertSame(0, $inscription->dossierPaiement->recus()->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_recu_est_consultable_par_ayant_droit_et_jamais_hors_perimetre(): void
    {
        $etudiant = $this->creerEtudiant('PAYCON01');
        $autre = $this->creerEtudiant('PAYCON02');
        $inscription = $this->creerInscription($etudiant, $this->annee);
        $autreInscription = $this->creerInscription($autre, $this->annee);

        $this->deposer($etudiant, $inscription, ['reference_paiement' => 'REF-CON-1'])->assertRedirect();

        $dossier = $inscription->dossierPaiement->fresh();
        $recu = $dossier->recus()->firstOrFail();

        $this->actingAs($this->admin)
            ->get(route('admin.finances.paiements.recu.apercu', [$dossier, $recu]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff');

        $this->actingAs($this->admin)
            ->get(route('admin.finances.paiements.recu.telecharger', [$dossier, $recu]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->admin)
            ->get(route('admin.finances.paiements.show', $dossier))
            ->assertOk()
            ->assertSee($recu->reference_paiement);

        $this->assertDatabaseHas('historique_academique', [
            'action' => 'paiement.recu_telecharge',
            'entite' => 'recu_paiement',
            'entite_id' => $recu->id,
        ]);

        // Un reçu ne peut pas être lu via le dossier d'une autre inscription.
        $this->actingAs($this->admin)
            ->get(route('admin.finances.paiements.recu.telecharger', [
                $autreInscription->dossierPaiement,
                $recu,
            ]))
            ->assertNotFound();

        // Étudiant propriétaire : autorisé ; autre étudiant : 404.
        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.paiements.recus.telecharger', [$inscription, $recu]))
            ->assertOk();

        $this->withSession(['etudiant_auth_id' => $autre->id])
            ->get(route('etudiant.paiements.recus.telecharger', [$inscription, $recu]))
            ->assertNotFound();

        // Admin d'une autre faculté : interdit.
        $this->actingAs($this->adminFaculte)
            ->get(route('admin.finances.paiements.recu.telecharger', [$dossier, $recu]))
            ->assertOk(); // même faculté

        $adminAutre = User::creerCompteBackOffice([
            'name' => 'Faculté B', 'email' => 'facb.pay@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->autreFaculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminAutre)
            ->get(route('admin.finances.paiements.recu.telecharger', [$dossier, $recu]))
            ->assertForbidden();
    }

    public function test_commande_rattrapage_cree_les_dossiers_manquants_sans_toucher_aux_historiques(): void
    {
        $etudiant = $this->creerEtudiant('PAYBKF01');

        $legacyEnCours = InscriptionAcademique::withoutEvents(fn () => $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L2',
            'statut' => 'en_cours',
        ]));

        $legacyValide = InscriptionAcademique::withoutEvents(fn () => $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->anneeSuivante->id,
            'niveau' => 'L3',
            'statut' => 'valide',
        ]));

        $this->assertNull($legacyEnCours->dossierPaiement);
        $this->assertNull($legacyValide->dossierPaiement);
        $this->assertDatabaseCount('dossiers_paiement', 0);

        $this->artisan('paiements:initialiser-dossiers', ['--dry-run' => true])
            ->expectsOutputToContain('seraient créés')
            ->assertSuccessful();

        $this->assertDatabaseCount('dossiers_paiement', 0);

        // Par défaut : seules les inscriptions en_cours.
        $this->artisan('paiements:initialiser-dossiers')->assertSuccessful();
        $this->assertNotNull($legacyEnCours->fresh()->dossierPaiement);
        $this->assertNull($legacyValide->fresh()->dossierPaiement);
        $this->assertDatabaseCount('dossiers_paiement', 1);

        // Idempotence.
        $this->artisan('paiements:initialiser-dossiers')->assertSuccessful();
        $this->assertDatabaseCount('dossiers_paiement', 1);

        // --tout : rattrapage historique explicitement demandé.
        $this->artisan('paiements:initialiser-dossiers', ['--tout' => true])->assertSuccessful();
        $this->assertNotNull($legacyValide->fresh()->dossierPaiement);
        $this->assertDatabaseCount('dossiers_paiement', 2);
    }

    public function test_module_ou_parcours_desactive_renvoie_404_et_ne_cree_aucun_dossier(): void
    {
        $etudiant = $this->creerEtudiant('PAYFLG01');

        config(['modules.finances.active' => false]);

        $inscription = $this->creerInscription($etudiant, $this->annee);
        $this->assertNull($inscription->dossierPaiement);

        $this->artisan('paiements:initialiser-dossiers')
            ->expectsOutputToContain('module Finances est désactivé')
            ->assertSuccessful();
        $this->assertDatabaseCount('dossiers_paiement', 0);

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.paiements.index'))
            ->assertNotFound();

        config(['modules.finances.active' => true, 'inscriptions-paiements.actif' => false]);

        $inscription2 = $this->creerInscription($etudiant, $this->anneeSuivante);
        $this->assertNull($inscription2->dossierPaiement);

        $this->artisan('paiements:initialiser-dossiers')
            ->expectsOutputToContain('parcours de paiement est désactivé')
            ->assertSuccessful();
        $this->assertDatabaseCount('dossiers_paiement', 0);

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.paiements.index'))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('admin.finances.paiements.index'))
            ->assertNotFound();
    }

    public function test_creation_admin_avec_paye_manuel_reste_intacte_apres_depot(): void
    {
        $etudiant = $this->creerEtudiant('PAYLEG01');

        $inscription = $this->creerInscription($etudiant, $this->annee, 'L1', ['paye' => true]);

        $this->assertTrue((bool) $inscription->fresh()->paye);
        $this->assertSame(DossierPaiement::NON_PAYE, $inscription->dossierPaiement->statut);

        $this->deposer($etudiant, $inscription, ['reference_paiement' => 'REF-LEG-1'])->assertRedirect();

        $this->assertTrue((bool) $inscription->fresh()->paye);
        $this->assertSame(DossierPaiement::RECU_DEPOSE, $inscription->dossierPaiement->fresh()->statut);
    }

    public function test_etudiant_voit_son_parcours_et_l_historique_sur_ses_pages(): void
    {
        $this->creerTarif('TARIF-UI', 100000, $this->annee);

        $etudiant = $this->creerEtudiant('PAYUI01');
        $inscription = $this->creerInscription($etudiant, $this->annee);

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.paiements.index'))
            ->assertOk()
            ->assertSee('2026-2027')
            ->assertSee('Non payé');

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.paiements.inscription', $inscription))
            ->assertOk()
            ->assertSee('Déposer mon reçu')
            ->assertSee('1 000,00 DH')
            ->assertSee('Aucun paiement confirmé');

        $this->deposer($etudiant, $inscription, ['reference_paiement' => 'REF-UI-1'])->assertRedirect();

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.paiements.inscription', $inscription))
            ->assertOk()
            ->assertSee('Reçu en cours de vérification')
            ->assertSee('REF-UI-1')
            ->assertSee('v1');
    }

    /**
     * LOT 3 : le tableau de bord et les deux pages finances ne mélangent
     * jamais facturation historique et reçus d'inscription.
     */
    public function test_dashboard_et_pages_finances_distinguent_recus_et_facturation(): void
    {
        $this->creerTarif('TARIF-DASH', 50000, $this->annee);
        $etudiant = $this->creerEtudiant('PAYDASH1');
        $inscription = $this->creerInscription($etudiant, $this->annee);

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.dashboard'))
            ->assertOk()
            ->assertSee('Frais d’inscription')
            ->assertSee('Paiement à effectuer')
            ->assertSee('500,00 DH');

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.paiements.index'))
            ->assertOk()
            ->assertSee('Mes finances (facturation historique)');

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.finances.index'))
            ->assertOk()
            ->assertSee('Facturation historique')
            ->assertSee('Ouvrir Mes paiements d’inscription');

        // Dépôt : le widget affiche une vérification, jamais une confirmation.
        $dossier = $inscription->dossierPaiement;
        $dossier->update(['statut' => DossierPaiement::RECU_DEPOSE]);
        RecuPaiement::create([
            'dossier_paiement_id' => $dossier->id,
            'inscription_academique_id' => $inscription->id,
            'annee_universitaire_id' => $this->annee->id,
            'version' => 1,
            'storage_path' => 'private/recus/dashboard.pdf',
            'original_filename' => 'recu-dashboard.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 12,
            'sha256' => hash('sha256', 'dashboard-recu'),
            'montant_attendu_centimes' => 50000,
            'source_montant' => 'tarif',
            'montant_declare_centimes' => 50000,
            'reference_paiement' => 'REF-DASH',
            'date_paiement' => '2026-10-01',
            'statut' => RecuPaiement::RECU_DEPOSE,
            'depose_le' => now(),
        ]);

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.dashboard'))
            ->assertOk()
            ->assertSee('Reçu en attente de vérification')
            ->assertDontSee('Paiement confirmé');

        // Validation : seule la décision de scolarité confirme le paiement.
        $inscription->update(['paye' => true]);
        $dossier->update(['statut' => DossierPaiement::VALIDE]);

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.dashboard'))
            ->assertOk()
            ->assertSee('Paiement confirmé');
    }

    /**
     * LOT 3 : lorsque le module finances est inactif, le widget et son
     * lien ne doivent pas apparaître (aucun lien mort).
     */
    public function test_widget_finances_absent_quand_le_module_est_inactif(): void
    {
        config(['modules.finances.active' => false]);

        $etudiant = $this->creerEtudiant('PAYOFF1');

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.dashboard'))
            ->assertOk()
            ->assertDontSee('Frais d’inscription')
            ->assertDontSee('Gérer mon paiement d’inscription');
    }

    private function creerEtudiant(
        string $cin,
        ?Faculte $faculte = null,
        string $niveau = 'L1',
    ): Etudiant {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Benali',
            'prenom' => 'Sara',
            'email' => strtolower($cin).'@pay.test',
            'date_naissance' => '2004-01-01',
            'sexe' => 'F',
            'faculte_id' => ($faculte ?? $this->faculte)->id,
            'niveau' => $niveau,
            'annee_academique' => '2026-2027',
            'adresse' => 'Rue de test',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    /**
     * @param  array<string, mixed>  $attributs
     */
    private function creerInscription(
        Etudiant $etudiant,
        AnneeUniversitaire $annee,
        string $niveau = 'L1',
        array $attributs = [],
    ): InscriptionAcademique {
        $inscription = $etudiant->inscriptionsAcademiques()->create(array_merge([
            'annee_universitaire_id' => $annee->id,
            'niveau' => $niveau,
            'statut' => 'en_cours',
        ], $attributs));

        $inscription->load('dossierPaiement');

        return $inscription;
    }

    private function creerTarif(
        string $code,
        int $montantCentimes,
        AnneeUniversitaire $annee,
        ?string $niveau = null,
    ): Tarif {
        return Tarif::create([
            'code' => $code,
            'libelle' => 'Frais '.$code,
            'montant_centimes' => $montantCentimes,
            'annee_universitaire_id' => $annee->id,
            'niveau' => $niveau,
            'type_frais' => 'inscription',
            'actif' => true,
        ]);
    }

    private function contenuPdf(string $suffixe): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n% ".$suffixe."\n%%EOF\n";
    }

    private function fichierPdf(string $nom = 'recu.pdf', ?string $contenu = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $nom,
            $contenu ?? $this->contenuPdf(uniqid('pdf-', true))
        );
    }

    /**
     * @param  array<string, mixed>  $remplacements
     */
    private function deposer(Etudiant $etudiant, InscriptionAcademique $inscription, array $remplacements = [])
    {
        $donnees = array_merge([
            'recu' => $this->fichierPdf(),
            'montant' => '1000.00',
            'reference_paiement' => 'REF-'.uniqid(),
            'date_paiement' => '2026-10-01',
            'engagement' => '1',
        ], $remplacements);

        return $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->post(route('etudiant.paiements.recus.store', $inscription), $donnees);
    }

    private function prendre(DossierPaiement $dossier)
    {
        return $this->actingAs($this->admin)
            ->post(route('admin.finances.paiements.prendre', $dossier));
    }

    private function valider(DossierPaiement $dossier, ?string $commentaire = null)
    {
        return $this->actingAs($this->admin)
            ->post(route('admin.finances.paiements.valider', $dossier), array_filter([
                'commentaire' => $commentaire,
            ]));
    }
}
