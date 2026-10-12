<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\InscriptionPedagogique;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\Evaluation\Services\SessionPreparationService;
use App\Modules\Referentiel\Models\Maquette;
use App\Modules\Referentiel\Models\MaquetteUe;
use App\Modules\Referentiel\Models\MaquetteUeEcue;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Referentiel\Services\MaquetteService;
use App\Modules\Schedule\Models\Group;
use App\Services\InscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wiring des contextes ECUE de plan (Vague 3, audit 08/10/2026) :
 * le plan actif fait foi — inscription pédagogique et génération
 * d'épreuves respectent les occurrences `maquette_ue_ecue` (repli
 * documenté sur `matieres.ue_id` si le plan ne définit aucune ECUE
 * pour l'UE). La matière canonique n'est jamais mutée.
 */
class MaquettePlanEcuesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AnneeUniversitaire $annee;

    private Programme $programme;

    private UniteEnseignement $ue;

    private Matiere $ecueIn;

    private Matiere $ecueOut;

    private Maquette $maquette;

    private MaquetteUe $ligneUe;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.referentiel.active' => true,
            'modules.evaluation.active' => true,
            'modules.schedule.active' => true,
        ]);

        $faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.plan@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $faculte->id, 'code' => 'L-PLAN', 'nom' => 'Licence PL',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $niveau = Niveau::create(['programme_id' => $this->programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1]);
        $semestre = SemestreLmd::create(['niveau_id' => $niveau->id, 'code' => 'S1', 'nom' => 'S1', 'numero' => 1, 'ordre' => 1]);
        $this->ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id, 'programme_id' => $this->programme->id,
            'code' => 'UE-PLAN', 'nom' => 'UE plan', 'credits' => 12, 'coefficient' => 2,
        ]);

        $this->ecueIn = Matiere::create([
            'code' => 'PL-101', 'nom' => 'Dans le plan', 'ue_id' => $this->ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 4, 'coefficient' => 1, 'actif' => true,
        ]);
        $this->ecueOut = Matiere::create([
            'code' => 'PL-102', 'nom' => 'Hors plan', 'ue_id' => $this->ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 4, 'coefficient' => 1, 'actif' => true,
        ]);

        $this->maquette = Maquette::create([
            'programme_id' => $this->programme->id, 'annee_universitaire_id' => $this->annee->id,
            'version' => 1, 'statut' => 'draft', 'auteur_id' => $this->admin->id,
        ]);
        $this->maquette->unitesEnseignement()->attach($this->ue->id, ['semestre_id' => $semestre->id]);
        $this->ligneUe = MaquetteUe::where('maquette_id', $this->maquette->id)->firstOrFail();
    }

    private function definirPlan(array $matieres): void
    {
        app(MaquetteService::class)->definirEcues($this->maquette, $matieres, $this->admin->id);
    }

    /**
     * Les consommateurs (inscription pédagogique, génération d'épreuves)
     * exigent une maquette ACTIVE : activation directe du fixture (le
     * workflow complet est couvert par MaquetteVersioningTest).
     */
    private function activer(): void
    {
        $this->maquette->update(['statut' => 'active']);
    }

    public function test_inscrire_ecue_refusee_hors_plan(): void
    {
        // Le plan actif ne retient que PL-101 parmi les 2 ECUE de l'UE.
        $this->definirPlan([[
            'maquette_ue_id' => $this->ligneUe->id,
            'matiere_id' => $this->ecueIn->id,
            'coefficient' => 2,
            'credits' => 5,
        ]]);
        $this->activer();

        $inscription = $this->creerInscription();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('hors du plan');
        app(InscriptionService::class)->inscrireEcue($inscription, $this->ecueOut, $this->admin->id);
    }

    public function test_inscrire_ecue_du_plan_acceptee(): void
    {
        $this->definirPlan([[
            'maquette_ue_id' => $this->ligneUe->id,
            'matiere_id' => $this->ecueIn->id,
        ]]);
        $this->activer();

        $inscription = $this->creerInscription();

        $pedago = app(InscriptionService::class)->inscrireEcue($inscription, $this->ecueIn, $this->admin->id);

        $this->assertInstanceOf(InscriptionPedagogique::class, $pedago);
        $this->assertDatabaseHas('inscriptions_pedagogiques', [
            'inscription_id' => $inscription->id, 'matiere_id' => $this->ecueIn->id, 'statut' => 'inscrit',
        ]);
    }

    public function test_repli_attachee_si_plan_sans_occurrences(): void
    {
        // Aucune occurrence pour l'UE : repli documenté sur ue_id — les
        // deux ECUE rattachées restent inscriptibles.
        $this->activer();
        $inscription = $this->creerInscription();

        app(InscriptionService::class)->inscrireEcue($inscription, $this->ecueOut, $this->admin->id);

        $this->assertDatabaseHas('inscriptions_pedagogiques', [
            'inscription_id' => $inscription->id, 'matiere_id' => $this->ecueOut->id,
        ]);
    }

    public function test_generation_epreuves_utilise_les_occurrences_du_plan(): void
    {
        $this->definirPlan([[
            'maquette_ue_id' => $this->ligneUe->id,
            'matiere_id' => $this->ecueIn->id,
        ]]);
        $this->activer();

        Group::create([
            'code' => 'G-PLAN', 'nom' => 'Groupe', 'faculte_id' => $this->programme->faculte_id,
            'programme_id' => $this->programme->id, 'niveau' => 'L1', 'actif' => true,
        ]);
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'SES-PLAN', 'nom' => 'Session',
            'type' => 'principale', 'date_debut' => '2027-01-05', 'date_fin' => '2027-01-30',
            'statut' => 'draft', 'created_by' => $this->admin->id,
        ]);

        $rapport = app(SessionPreparationService::class)
            ->genererEpreuves($session, $this->programme->id, 'L1', '2027-01-06');

        // 1 ECUE du plan × 1 groupe = 1 épreuve (PL-102 exclue du plan).
        $this->assertSame(1, $rapport['creees']);
        $this->assertSame(1, Epreuve::where('session_id', $session->id)->where('matiere_id', $this->ecueIn->id)->count());
        $this->assertSame(0, Epreuve::where('session_id', $session->id)->where('matiere_id', $this->ecueOut->id)->count());
    }

    public function test_definir_ecues_refuse_ecue_hors_ue(): void
    {
        $autreUe = UniteEnseignement::create([
            'semestre_id' => $this->ue->semestre_id, 'programme_id' => $this->programme->id,
            'code' => 'UE-AUTRE', 'nom' => 'Autre UE', 'credits' => 6,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('hors de l\'UE');
        $this->definirPlan([[
            'maquette_ue_id' => MaquetteUe::create([
                'maquette_id' => $this->maquette->id, 'ue_id' => $autreUe->id,
                'semestre_id' => $this->ue->semestre_id,
            ])->id,
            'matiere_id' => $this->ecueIn->id,
        ]]);
    }

    public function test_definir_ecues_refusee_sur_maquette_verrouillee(): void
    {
        $this->maquette->update(['statut' => 'approved']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('verrouillée');
        $this->definirPlan([[
            'maquette_ue_id' => $this->ligneUe->id,
            'matiere_id' => $this->ecueIn->id,
        ]]);
    }

    public function test_contextes_ne_mutent_jamais_la_canonique(): void
    {
        $this->definirPlan([[
            'maquette_ue_id' => $this->ligneUe->id,
            'matiere_id' => $this->ecueIn->id,
            'coefficient' => 9,
            'credits' => 12,
        ]]);

        $contexte = MaquetteUeEcue::where('maquette_ue_id', $this->ligneUe->id)->firstOrFail();
        $this->assertEquals(9, (float) $contexte->coefficient);
        $this->assertSame(12, $contexte->credits);

        // La matière canonique est intacte (historique protégé).
        $this->assertEquals(1, (float) $this->ecueIn->fresh()->coefficient);
        $this->assertSame(4, $this->ecueIn->fresh()->credits);
    }

    public function test_nouvelle_version_copie_les_occurrences_sans_toucher_l_originale(): void
    {
        $this->definirPlan([[
            'maquette_ue_id' => $this->ligneUe->id,
            'matiere_id' => $this->ecueIn->id,
            'coefficient' => 3,
            'regime' => 'optionnelle',
            'groupe_choix' => 'OPT-S1',
        ]]);

        $v2 = app(MaquetteService::class)->nouvelleVersion($this->maquette, $this->annee->id, $this->admin->id, 'test');

        $contexteV2 = MaquetteUeEcue::whereHas('maquetteUe', fn ($q) => $q->where('maquette_id', $v2->id))->firstOrFail();
        $this->assertSame($this->ecueIn->id, $contexteV2->matiere_id);
        $this->assertEquals(3, (float) $contexteV2->coefficient);
        $this->assertSame('optionnelle', $contexteV2->regime);
        $this->assertSame('OPT-S1', $contexteV2->groupe_choix);

        // L'originale garde son contexte, la copie est une ligne distincte.
        $this->assertSame(1, MaquetteUeEcue::where('maquette_ue_id', $this->ligneUe->id)->count());
    }

    public function test_http_definir_contextes_ecues(): void
    {
        $cle = $this->ligneUe->id.'-'.$this->ecueIn->id;

        $reponse = $this->actingAs($this->admin)->put(route('admin.referentiel.maquettes.ecues', $this->maquette), [
            'ecues' => [$cle => [
                'maquette_ue_id' => $this->ligneUe->id,
                'matiere_id' => $this->ecueIn->id,
                'coefficient' => 2,
                'credits' => 5,
                'regime' => 'optionnelle',
                'groupe_choix' => 'OPT-S1',
            ]],
        ]);

        $reponse->assertRedirect();
        $this->assertDatabaseHas('maquette_ue_ecue', [
            'maquette_ue_id' => $this->ligneUe->id,
            'matiere_id' => $this->ecueIn->id,
            'regime' => 'optionnelle',
            'groupe_choix' => 'OPT-S1',
        ]);

        // Maquette verrouillée : refus propre (session errors), pas de 500.
        $this->maquette->update(['statut' => 'approved']);
        $this->actingAs($this->admin)->put(route('admin.referentiel.maquettes.ecues', $this->maquette), [
            'ecues' => [$cle => ['maquette_ue_id' => $this->ligneUe->id, 'matiere_id' => $this->ecueIn->id]],
        ])->assertRedirect()->assertSessionHasErrors('maquette');
    }

    public function test_http_contextes_isoles_par_faculte(): void
    {
        $adminEtranger = User::creerCompteBackOffice([
            'name' => 'Admin B', 'email' => 'b.plan@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'T', 'active' => true])->id,
        ], 'admin_faculte');

        $this->actingAs($adminEtranger)->put(route('admin.referentiel.maquettes.ecues', $this->maquette), [
            'ecues' => [],
        ])->assertForbidden();

        $this->assertSame(0, MaquetteUeEcue::where('maquette_ue_id', $this->ligneUe->id)->count());
    }

    public function test_vue_show_rend_les_contextes_brouillon_et_verrouille(): void
    {
        // Brouillon : tableau + formulaire d'édition des contextes.
        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.referentiel.maquettes.show', $this->maquette));
        $reponse->assertOk();
        $reponse->assertSee('ECUE du plan');
        $reponse->assertSee('Enregistrer les contextes ECUE');
        $reponse->assertSee('PL-101');

        // Verrouillée : contextes affichés en figé, sans formulaire.
        $this->maquette->update(['statut' => 'approved']);

        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.referentiel.maquettes.show', $this->maquette));
        $reponse->assertOk();
        $reponse->assertSee('Contextes figés (approved)');
        $reponse->assertDontSee('Enregistrer les contextes ECUE');
    }

    public function test_groupe_choix_maximum_applique(): void
    {
        // Groupe « OPT-2PARMI3 » : 3 ECUE optionnelles, max 2.
        $troisieme = Matiere::create([
            'code' => 'PL-103', 'nom' => 'Troisième option', 'ue_id' => $this->ue->id,
            'niveau' => 'L1', 'semestre' => 1, 'credits' => 4, 'coefficient' => 1, 'actif' => true,
        ]);
        $this->definirPlan([
            ['maquette_ue_id' => $this->ligneUe->id, 'matiere_id' => $this->ecueIn->id,
                'regime' => 'optionnelle', 'groupe_choix' => 'OPT-2PARMI3', 'conditions' => ['max_choix' => 2, 'min_choix' => 2]],
            ['maquette_ue_id' => $this->ligneUe->id, 'matiere_id' => $this->ecueOut->id,
                'regime' => 'optionnelle', 'groupe_choix' => 'OPT-2PARMI3', 'conditions' => ['max_choix' => 2, 'min_choix' => 2]],
            ['maquette_ue_id' => $this->ligneUe->id, 'matiere_id' => $troisieme->id,
                'regime' => 'optionnelle', 'groupe_choix' => 'OPT-2PARMI3', 'conditions' => ['max_choix' => 2, 'min_choix' => 2]],
        ]);
        $this->activer();

        $inscription = $this->creerInscription();
        $service = app(InscriptionService::class);

        // 2 parmi 3 : acceptées.
        $service->inscrireEcue($inscription, $this->ecueIn, $this->admin->id);
        $service->inscrireEcue($inscription, $this->ecueOut, $this->admin->id);

        // La 3e dépasse le maximum : refusée, aucune écriture.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('maximum');
        $service->inscrireEcue($inscription, $troisieme, $this->admin->id);
    }

    public function test_groupe_choix_minimum_signale_en_masse(): void
    {
        $this->definirPlan([
            ['maquette_ue_id' => $this->ligneUe->id, 'matiere_id' => $this->ecueIn->id,
                'regime' => 'optionnelle', 'groupe_choix' => 'OPT-MIN2', 'conditions' => ['min_choix' => 2]],
            ['maquette_ue_id' => $this->ligneUe->id, 'matiere_id' => $this->ecueOut->id,
                'regime' => 'optionnelle', 'groupe_choix' => 'OPT-MIN2', 'conditions' => ['min_choix' => 2]],
        ]);
        $this->activer();

        $inscription = $this->creerInscription();
        $service = app(InscriptionService::class);

        // Choix partiel (1 sur 2 requis) : inscrit, mais signalé.
        $rapport = $service->inscrireEcues($inscription, [$this->ecueIn->id], $this->admin->id);
        $this->assertSame(1, $rapport['crees']);
        $this->assertNotEmpty($rapport['avertissements']);
        $this->assertStringContainsString('OPT-MIN2', $rapport['avertissements'][0]);
        $this->assertStringContainsString('2 matière(s) requise(s)', $rapport['avertissements'][0]);

        // Choix complété : plus d'avertissement.
        $rapportComplet = $service->inscrireEcues($inscription, [$this->ecueOut->id], $this->admin->id);
        $this->assertSame(1, $rapportComplet['crees']);
        $this->assertSame([], $rapportComplet['avertissements']);
    }

    public function test_sans_bornes_conditions_groupe_permissif(): void
    {
        // Groupe sans bornes explicites : aucune contrainte (permissif),
        // le régime/groupe restent des étiquettes exploitables.
        $this->definirPlan([
            ['maquette_ue_id' => $this->ligneUe->id, 'matiere_id' => $this->ecueIn->id,
                'regime' => 'optionnelle', 'groupe_choix' => 'OPT-LIBRE'],
        ]);
        $this->activer();

        $inscription = $this->creerInscription();
        $pedago = app(InscriptionService::class)->inscrireEcue($inscription, $this->ecueIn, $this->admin->id);

        $this->assertInstanceOf(InscriptionPedagogique::class, $pedago);
    }

    private function creerInscription(): InscriptionAcademique
    {
        $etudiant = Etudiant::create([
            'cin' => 'PL'.random_int(100000, 999999), 'nom' => 'Plan', 'prenom' => 'Sara',
            'email' => 'sara.'.random_int(1000, 9999).'@plan.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $this->programme->faculte_id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        return InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1', 'statut' => 'en_cours', 'type' => 'premiere', 'regime' => 'initiale',
        ]);
    }

    // ── MASTER LOOP 6 — contrat « absence de maquette » ─────────

    public function test_inscrire_ecue_refusee_sans_maquette_active(): void
    {
        // Maquette en brouillon (jamais active) : refus explicite et
        // exploitable, jamais de silently-dégradé.
        $inscription = $this->creerInscription();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Aucune maquette active');
        app(InscriptionService::class)->inscrireEcue($inscription, $this->ecueIn, $this->admin->id);
    }

    public function test_generation_epreuves_refusee_sans_maquette_active(): void
    {
        Group::create([
            'code' => 'G-PLAN', 'nom' => 'Groupe', 'faculte_id' => $this->programme->faculte_id,
            'programme_id' => $this->programme->id, 'niveau' => 'L1', 'actif' => true,
        ]);
        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id, 'code' => 'SES-SANS-PLAN', 'nom' => 'Session',
            'type' => 'principale', 'date_debut' => '2027-01-05', 'date_fin' => '2027-01-30',
            'statut' => 'draft', 'created_by' => $this->admin->id,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Aucune maquette active');
        app(SessionPreparationService::class)
            ->genererEpreuves($session, $this->programme->id, 'L1', '2027-01-06');
    }
}
