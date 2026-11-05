<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\User;
use App\Services\EcheanceDerogationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOOP 146 / Phases Q135 à Q146 — Tests HTTP de gestion administrative des dérogations et échéances.
 */
class AdminDerogationGestionHttpTest extends TestCase
{
    use RefreshDatabase;

    private EcheanceDerogationService $service;

    private User $admin;

    private Etudiant $etudiant;

    private string $demandeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(EcheanceDerogationService::class);

        config([
            'loop146.derogations.mecanisme_identifie' => false,
            'loop146.derogations.autorites_competentes' => [],
            'loop146.derogations.canaux_relance' => [],
            'loop146.derogations.voies_reexamen' => [],
            'loop146.derogations.voies_recours' => [],
        ]);

        $faculte = Faculte::create([
            'code' => 'FST',
            'nom' => 'Faculté des Sciences et Techniques',
        ]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Agent Scolarite',
            'email' => 'agent.scol@universite.ac.ma',
            'password' => 'Admin@123',
            'telephone' => '0600000002',
            'faculte_id' => $faculte->id,
            'actif' => true,
        ], 'super_admin');

        $this->etudiant = Etudiant::create([
            'cin' => '81460031',
            'nom' => 'Tazi',
            'prenom' => 'Kenza',
            'email' => 'kenza.tazi@universite.ac.ma',
            'date_naissance' => '2004-05-18',
            'sexe' => 'F',
            'faculte_id' => $faculte->id,
            'niveau' => 'L2',
            'annee_academique' => '2026-2027',
            'adresse' => 'Rabat',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2023',
            'password' => 'Etudiant@123',
        ]);
        $this->etudiant->definirStatut('valide');

        $depot = $this->service->deposerDemandeDerogation(
            $this->etudiant,
            'Demande dérogation examen',
            'Motif circonstancié avec certificat médical légalisé.'
        );
        $this->demandeId = $depot['demande_id'];
    }

    public function test_admin_peut_consulter_index_et_rechercher(): void
    {
        $reponse = $this->actingAs($this->admin)->get(route('admin.derogations.index'));

        $reponse->assertOk();
        $reponse->assertSee('Tazi');
        $reponse->assertSee($this->etudiant->cin);
    }

    public function test_admin_peut_consulter_fiche_show(): void
    {
        $reponse = $this->actingAs($this->admin)->get(route('admin.derogations.show', $this->etudiant));

        $reponse->assertOk();
        $reponse->assertSee($this->demandeId);
        $reponse->assertSee('Motif initial');
    }

    public function test_q135_q136_admin_constate_depassement_echeance(): void
    {
        $dateInitiale = Carbon::now()->subDays(4)->format('Y-m-d\TH:i');

        $reponse = $this->actingAs($this->admin)->post(
            route('admin.derogations.constater-depassement', $this->etudiant),
            [
                'type_echeance' => 'depot_justificatif',
                'echeance_initiale' => $dateInitiale,
                'reference_reglementaire' => 'Reglement-Art-15',
            ]
        );

        $reponse->assertRedirect(route('admin.derogations.show', $this->etudiant));
        $reponse->assertSessionHas('success');

        $audit = HistoriqueAcademique::where('etudiant_id', $this->etudiant->id)
            ->where('action', 'constat_depassement_echeance')
            ->firstOrFail();

        $valeurs = json_decode((string) $audit->nouvelle_valeur, true);
        $this->assertTrue($valeurs['depasse']);
        $this->assertFalse($valeurs['consequences_appliquees']);
    }

    public function test_q138_admin_statuer_fail_closed_puis_succes_si_autorite_reconnue(): void
    {
        // 1. Fail closed si autorité non configurée
        $reponse = $this->actingAs($this->admin)->post(
            route('admin.derogations.statuer', $this->etudiant),
            [
                'demande_id' => $this->demandeId,
                'decision' => 'accordee',
                'autorite_qualite' => 'Doyen',
                'reference_acte_officiel' => 'DEC-2026-001',
            ]
        );

        $reponse->assertRedirect();
        $reponse->assertSessionHasErrors(['decision']);

        // 2. Succès si autorité et mécanisme officiellement établis
        config([
            'loop146.derogations.mecanisme_identifie' => true,
            'loop146.derogations.autorites_competentes' => ['Doyen'],
        ]);

        $reponseOk = $this->actingAs($this->admin)->post(
            route('admin.derogations.statuer', $this->etudiant),
            [
                'demande_id' => $this->demandeId,
                'decision' => 'accordee',
                'autorite_qualite' => 'Doyen',
                'reference_acte_officiel' => 'DEC-2026-001',
            ]
        );

        $reponseOk->assertRedirect(route('admin.derogations.show', $this->etudiant));
        $reponseOk->assertSessionHas('success');

        $audit = HistoriqueAcademique::where('etudiant_id', $this->etudiant->id)
            ->where('action', 'decision_derogation')
            ->firstOrFail();

        $valeurs = json_decode((string) $audit->nouvelle_valeur, true);
        $this->assertSame('accordee', $valeurs['decision']);
        $this->assertSame('DEC-2026-001', $valeurs['reference_acte']);
    }

    public function test_q139_admin_relance_fail_closed_puis_succes(): void
    {
        // Fail closed si canal non reconnu
        $reponse = $this->actingAs($this->admin)->post(
            route('admin.derogations.relancer', $this->etudiant),
            [
                'demande_id' => $this->demandeId,
                'canal_relance' => 'Canal_Inconnu',
            ]
        );

        $reponse->assertRedirect();
        $reponse->assertSessionHasErrors(['canal_relance']);

        // Succès avec canal officiel
        config(['loop146.derogations.canaux_relance' => ['Courrier_Officiel_Rectorat']]);

        $reponseOk = $this->actingAs($this->admin)->post(
            route('admin.derogations.relancer', $this->etudiant),
            [
                'demande_id' => $this->demandeId,
                'canal_relance' => 'Courrier_Officiel_Rectorat',
                'reference_preuve_relance' => 'REL-2026-01',
            ]
        );

        $reponseOk->assertRedirect(route('admin.derogations.show', $this->etudiant));
        $reponseOk->assertSessionHas('success');
    }

    public function test_q141_admin_informer_attente_prolongee(): void
    {
        $reponse = $this->actingAs($this->admin)->post(
            route('admin.derogations.informer-attente', $this->etudiant),
            [
                'demande_id' => $this->demandeId,
                'demarches' => "Transmission au secrétariat\nAttente d'avis de commission",
                'reponse_recue' => 0,
            ]
        );

        $reponse->assertRedirect(route('admin.derogations.show', $this->etudiant));
        $reponse->assertSessionHas('success');

        $audit = HistoriqueAcademique::where('etudiant_id', $this->etudiant->id)
            ->where('action', 'information_attente_prolongee')
            ->firstOrFail();

        $valeurs = json_decode((string) $audit->nouvelle_valeur, true);
        $this->assertNull($valeurs['delai_promis']);
        $this->assertFalse($valeurs['decision_prejugee']);
    }

    public function test_q143_admin_transmettre_reexamen_fail_closed_puis_succes(): void
    {
        // Fail-closed si voie non configurée
        $reponse = $this->actingAs($this->admin)->post(
            route('admin.derogations.transmettre-reexamen', $this->etudiant),
            [
                'demande_id' => $this->demandeId,
                'voie_transmission' => 'Voie_Inconnue',
                'reference_transmission' => 'TRX-001',
            ]
        );

        $reponse->assertRedirect();
        $reponse->assertSessionHasErrors(['voie_transmission']);

        // Succès si voie officielle configurée
        config(['loop146.derogations.voies_reexamen' => ['Commission_Recours_Facultaire']]);

        $reponseOk = $this->actingAs($this->admin)->post(
            route('admin.derogations.transmettre-reexamen', $this->etudiant),
            [
                'demande_id' => $this->demandeId,
                'voie_transmission' => 'Commission_Recours_Facultaire',
                'reference_transmission' => 'TRX-001',
            ]
        );

        $reponseOk->assertRedirect(route('admin.derogations.show', $this->etudiant));
        $reponseOk->assertSessionHas('success');
    }

    public function test_q144_admin_enregistrer_decision_insuffisamment_motivee(): void
    {
        $texte = 'La commission confirme sa position antérieure.';

        $reponse = $this->actingAs($this->admin)->post(
            route('admin.derogations.decision-insuffisamment-motivee', $this->etudiant),
            [
                'demande_id' => $this->demandeId,
                'decision_texte' => $texte,
                'motifs_fournis' => 'Motif succinct.',
                'reference_decision' => 'DEC-REEX-01',
            ]
        );

        $reponse->assertRedirect(route('admin.derogations.show', $this->etudiant));
        $reponse->assertSessionHas('success');

        $audit = HistoriqueAcademique::where('etudiant_id', $this->etudiant->id)
            ->where('action', 'decision_insuffisamment_motivee')
            ->firstOrFail();

        $valeurs = json_decode((string) $audit->nouvelle_valeur, true);
        $this->assertSame($texte, $valeurs['decision_texte']);
        $this->assertFalse($valeurs['motifs_completes']);
    }

    public function test_q145_admin_enregistrer_decision_ambigue(): void
    {
        $contenu = 'Procès-verbal : le dossier est pris en considération.';

        $reponse = $this->actingAs($this->admin)->post(
            route('admin.derogations.decision-ambigue', $this->etudiant),
            [
                'demande_id' => $this->demandeId,
                'contenu_decision' => $contenu,
                'reference_acte' => 'PV-2026-09',
            ]
        );

        $reponse->assertRedirect(route('admin.derogations.show', $this->etudiant));
        $reponse->assertSessionHas('success');

        $audit = HistoriqueAcademique::where('etudiant_id', $this->etudiant->id)
            ->where('action', 'decision_reexamen_ambigue')
            ->firstOrFail();

        $valeurs = json_decode((string) $audit->nouvelle_valeur, true);
        $this->assertFalse($valeurs['acceptation_presumee']);
        $this->assertFalse($valeurs['refus_presume']);
    }

    public function test_q146_admin_appliquer_clarification_officielle(): void
    {
        $reponse = $this->actingAs($this->admin)->post(
            route('admin.derogations.appliquer-clarification', $this->etudiant),
            [
                'demande_id' => $this->demandeId,
                'issue_clarifiee' => 'refus',
                'reference_clarification' => 'CLAR-2026-99',
                'auteur_clarification' => 'Recteur',
            ]
        );

        $reponse->assertRedirect(route('admin.derogations.show', $this->etudiant));
        $reponse->assertSessionHas('success');

        $audit = HistoriqueAcademique::where('etudiant_id', $this->etudiant->id)
            ->where('action', 'clarification_decision_enregistree')
            ->firstOrFail();

        $valeurs = json_decode((string) $audit->nouvelle_valeur, true);
        $this->assertSame('clarification_enregistree', $valeurs['statut_apres']);
        $this->assertFalse($valeurs['option_q146_confirmee']);
        $this->assertFalse($valeurs['decision_finale_appliquee']);
        $this->assertFalse($valeurs['mutations_automatiques_effectuees']);
        $this->assertTrue($valeurs['decision_initiale_ambigue_preservee']);

        // Q146 reste sans statut final : la consultation consolidée ne
        // présume ni accord ni refus après la clarification officielle.
        $consultation = $this->service->consulterDossierDerogations($this->etudiant);
        $demande = collect($consultation['demandes'])
            ->firstWhere('demande_id', $this->demandeId);

        $this->assertNotNull($demande);
        $this->assertSame('clarification_enregistree', $demande['statut']);
        $this->assertNull($demande['statut_final']);
        $this->assertFalse($demande['decision_finale_appliquee']);
        $this->assertTrue($demande['clarification_recue']);
    }

    public function test_admin_avec_seule_permission_de_lecture_ne_peut_pas_muter_une_derogation(): void
    {
        $lecteur = User::creerCompteBackOffice([
            'name' => 'Agent Consultation',
            'email' => 'agent.consultation@universite.ac.ma',
            'password' => 'Admin@123',
            'telephone' => '0600000003',
            'faculte_id' => $this->etudiant->faculte_id,
            'actif' => true,
        ], 'consultation');

        // La permission demandes.view suffit pour lire l'écran...
        $this->actingAs($lecteur)
            ->get(route('admin.derogations.show', $this->etudiant))
            ->assertOk();

        // ...mais demandes.process est exigée pour toute mutation.
        $reponse = $this->actingAs($lecteur)->post(
            route('admin.derogations.appliquer-clarification', $this->etudiant),
            [
                'demande_id' => $this->demandeId,
                'issue_clarifiee' => 'refus',
                'reference_clarification' => 'CLAR-2026-100',
                'auteur_clarification' => 'Recteur',
            ]
        );

        $reponse->assertForbidden();

        $this->assertDatabaseMissing('historique_academique', [
            'etudiant_id' => $this->etudiant->id,
            'action' => 'clarification_decision_enregistree',
        ]);
    }
}
