<?php

namespace Tests\Feature;

use App\Models\AffectationGroupe;
use App\Models\AnneeUniversitaire;
use App\Models\AssignmentItem;
use App\Models\AssignmentSimulation;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Schedule;
use App\Modules\Schedule\Models\ScheduleSlot;
use App\Services\Affectations\AssignmentConfirmationService;
use App\Services\Affectations\AssignmentConstraintEngine;
use App\Services\Affectations\AssignmentEligibilityResolver;
use App\Services\Affectations\AssignmentSimulationService;
use App\Services\AffectationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Refonte affectation automatique : éligibilité, contraintes, simulation
 * sans écriture, équilibrage, swap, confirmation par groupe,
 * obsolescence, idempotence, concurrence, sécurité, cas limites.
 */
class AssignmentAutomatiqueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'AA1', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.aa@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
    }

    private function groupe(string $code, ?int $capacite = 30, array $attrs = []): Group
    {
        return Group::create(array_merge([
            'code' => $code, 'nom' => "Groupe {$code}", 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'capacite' => $capacite, 'actif' => true,
        ], $attrs));
    }

    private function etudiant(string $cin, array $attrs = [], bool $avecInscription = true): Etudiant
    {
        static $i = 0;
        $i++;

        $etudiant = Etudiant::create(array_merge([
            'cin' => $cin, 'nom' => 'Nom'.$i, 'prenom' => 'Prenom'.$i,
            'email' => strtolower($cin).'@aa.test', 'date_naissance' => '2004-01-01',
            'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X',
            'ville' => 'Tunis', 'code_postal' => '1000', 'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ], $attrs));
        $etudiant->definirStatut('valide');

        if ($avecInscription) {
            InscriptionAcademique::create([
                'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $this->annee->id,
                'niveau' => 'L1', 'statut' => 'en_cours', 'type' => 'premiere', 'regime' => 'initiale',
            ]);
        }

        return $etudiant;
    }

    /** @return array{annee_id: int, groupe_ids: array<int>, etudiant_ids: array<int>} */
    private function params(array $groupes, array $etudiants): array
    {
        return [
            'annee_id' => $this->annee->id,
            'groupe_ids' => array_map(fn (Group $g) => $g->id, $groupes),
            'etudiant_ids' => array_map(fn (Etudiant $e) => $e->id, $etudiants),
        ];
    }

    // ── Éligibilité ──────────────────────────────────────────────

    public function test_eligibilite_sans_inscription_suspendu_statut(): void
    {
        $sansInscription = $this->etudiant('AAE00001', [], false);
        $suspendu = $this->etudiant('AAE00002');
        $suspendu->situations()->create([
            'type' => 'suspension', 'motif' => 'x', 'date_effet' => now()->subDay()->toDateString(),
        ]);
        $ok = $this->etudiant('AAE00003');

        $eligibles = app(AssignmentEligibilityResolver::class)->eligibles(
            [$sansInscription->id, $suspendu->id, $ok->id], $this->annee
        );

        $this->assertSame([$ok->id], $eligibles->map(fn (Etudiant $e) => $e->id)->all());
        $this->assertNotNull(app(AssignmentEligibilityResolver::class)->motifIneligibilite($sansInscription, $this->annee));
        $this->assertNull(app(AssignmentEligibilityResolver::class)->motifIneligibilite($ok, $this->annee));
    }

    public function test_verrouille_exclu_avec_motif(): void
    {
        $groupe = $this->groupe('GA');
        $etudiant = $this->etudiant('AAE00010');
        AffectationGroupe::create([
            'etudiant_id' => $etudiant->id, 'groupe_id' => $groupe->id,
            'annee_universitaire_id' => $this->annee->id, 'date_debut' => now()->toDateString(),
            'statut' => 'active', 'verrouille' => true, 'motif' => 'jury',
        ]);

        $this->assertStringContainsString(
            'verrouillée',
            (string) app(AssignmentEligibilityResolver::class)->motifIneligibilite($etudiant->fresh(), $this->annee)
        );
    }

    // ── Contraintes ──────────────────────────────────────────────

    public function test_contraintes_programme_capacite_scope(): void
    {
        $g1 = $this->groupe('G1', 1);
        $etudiant = $this->etudiant('AAC00001');

        // Capacité simulée épuisée → CAPACITY.
        $diag = app(AssignmentConstraintEngine::class)->admissibles(
            $etudiant, collect([$g1]), $this->annee, [$g1->id => 0], null
        );
        $this->assertFalse($diag[$g1->id]['admissible']);
        $this->assertSame('CAPACITY', $diag[$g1->id]['conflits'][0]['type']);

        // Scope étranger → FACULTY_SCOPE.
        $diag = app(AssignmentConstraintEngine::class)->admissibles(
            $etudiant, collect([$g1]), $this->annee, [$g1->id => 5], 999999
        );
        $this->assertSame('FACULTY_SCOPE', $diag[$g1->id]['conflits'][0]['type']);

        // Cas nominal → admissible.
        $diag = app(AssignmentConstraintEngine::class)->admissibles(
            $etudiant, collect([$g1]), $this->annee, [$g1->id => 5], null
        );
        $this->assertTrue($diag[$g1->id]['admissible']);
    }

    public function test_conflit_horaire_detecte(): void
    {
        $g1 = $this->groupe('G1');
        $g2 = $this->groupe('G2');
        $etudiant = $this->etudiant('AAH00001');
        app(AffectationService::class)->affecter($etudiant, $g1, $this->annee, $this->admin->id, 'init');

        // Créneaux qui se chevauchent (lundi 08:00-10:00 vs 09:00-11:00).
        $matiere = Matiere::create([
            'code' => 'AAH-INF', 'nom' => 'Info', 'niveau' => 'L1', 'semestre' => 1,
            'credits' => 6, 'coefficient' => 2,
        ]);

        foreach ([$g1, $g2] as $i => $groupe) {
            $planning = Schedule::create([
                'annee_universitaire_id' => $this->annee->id, 'semestre' => 1,
                'groupe_id' => $groupe->id, 'nom' => 'P'.$groupe->code, 'actif' => true,
            ]);
            ScheduleSlot::create([
                'schedule_id' => $planning->id, 'matiere_id' => $matiere->id, 'day_of_week' => 'monday',
                'start_time' => $i === 0 ? '08:00' : '09:00', 'end_time' => $i === 0 ? '10:00' : '11:00',
            ]);
        }

        $diag = app(AssignmentConstraintEngine::class)->admissibles(
            $etudiant->fresh(), collect([$g2]), $this->annee, [$g2->id => 5], null
        );

        $this->assertFalse($diag[$g2->id]['admissible']);
        $this->assertSame('SCHEDULE', $diag[$g2->id]['conflits'][0]['type']);
    }

    // ── Simulation ───────────────────────────────────────────────

    public function test_simulation_sans_ecriture_et_equilibree(): void
    {
        $groupes = [$this->groupe('G1'), $this->groupe('G2'), $this->groupe('G3')];
        $etudiants = [];

        for ($i = 1; $i <= 90; $i++) {
            $etudiants[] = $this->etudiant('AAEQ'.str_pad((string) $i, 5, '0', STR_PAD_LEFT));
        }

        $avant = AffectationGroupe::count();

        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params($groupes, $etudiants), $this->admin->id, null
        );

        // Aucune écriture métier pendant la simulation.
        $this->assertSame($avant, AffectationGroupe::count());
        $this->assertSame('simulee', $simulation->statut);
        $this->assertSame(90, $simulation->affectes);
        $this->assertSame(0, $simulation->non_affectes);
        $this->assertSame(0, $simulation->metriques['ecart_max']);
        $this->assertSame(30, $simulation->metriques['minimum']);
        $this->assertSame(30, $simulation->metriques['maximum']);
    }

    public function test_contraints_d_abord_et_determinisme(): void
    {
        // G1 niveau L2 (seul admissible pour l'étudiant L2).
        $g1 = $this->groupe('G1', 30, ['niveau' => 'L2']);
        $g2 = $this->groupe('G2', 30);
        $contraint = $this->etudiant('AAD00001', ['niveau' => 'L2']);
        $flexibles = [$this->etudiant('AAD00002'), $this->etudiant('AAD00003')];

        $tous = array_merge([$contraint], $flexibles);
        $service = app(AssignmentSimulationService::class);

        $s1 = $service->simuler($this->params([$g1, $g2], $tous), $this->admin->id, null);
        $s2 = $service->simuler($this->params([$g1, $g2], $tous), $this->admin->id, null);

        $carte = fn (AssignmentSimulation $s) => AssignmentItem::where('simulation_id', $s->id)->orderBy('etudiant_id')->pluck('groupe_propose_id', 'etudiant_id')->all();

        // Déterministe : deux simulations identiques.
        $this->assertSame($carte($s1), $carte($s2));
        // Le plus contraint obtient son unique groupe.
        $this->assertSame($g1->id, $carte($s1)[$contraint->id]);
    }

    public function test_capacite_insuffisante_non_affectes_expliques(): void
    {
        $groupes = [$this->groupe('G1', 20), $this->groupe('G2', 20)];
        $etudiants = [];

        for ($i = 1; $i <= 50; $i++) {
            $etudiants[] = $this->etudiant('AAI'.str_pad((string) $i, 5, '0', STR_PAD_LEFT));
        }

        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params($groupes, $etudiants), $this->admin->id, null
        );

        $this->assertSame(40, $simulation->affectes);
        $this->assertSame(10, $simulation->non_affectes);

        $item = AssignmentItem::where('simulation_id', $simulation->id)->where('statut', 'non_affecte')->firstOrFail();
        $this->assertNotEmpty($item->conflits);
    }

    public function test_cas_complexe_100_etudiants(): void
    {
        $g1 = $this->groupe('G1', 30, ['niveau' => 'L2']);
        $programmeP2 = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'P2AA', 'nom' => 'Prog 2',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $g2 = $this->groupe('G2', 30, ['programme_id' => $programmeP2->id]);
        $g3 = $this->groupe('G3', 25);
        $g4 = $this->groupe('G4', 25);

        $etudiants = [];

        // 10 fortement contraints : niveau L2 → G1 uniquement.
        for ($i = 1; $i <= 10; $i++) {
            $etudiants[] = $this->etudiant('AAX1'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), ['niveau' => 'L2']);
        }

        // 15 contraints programme P2 → G2/G3/G4 (jamais G1).
        for ($i = 1; $i <= 15; $i++) {
            $etudiants[] = $this->etudiant('AAX2'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), ['programme_id' => $programmeP2->id]);
        }

        // 65 flexibles (L1, sans programme) → G2/G3/G4.
        for ($i = 1; $i <= 65; $i++) {
            $etudiants[] = $this->etudiant('AAX3'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        }

        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params([$g1, $g2, $g3, $g4], $etudiants), $this->admin->id, null
        );

        // Les 10 contraints L2 sont protégés sur G1.
        $surG1 = AssignmentItem::where('simulation_id', $simulation->id)->where('groupe_propose_id', $g1->id)->count();
        $this->assertSame(10, $surG1);
        $this->assertGreaterThanOrEqual(85, $simulation->affectes);
    }

    // ── Confirmation ───────────────────────────────────────────

    public function test_confirmation_par_groupe_idempotente(): void
    {
        $g1 = $this->groupe('G1', 30);
        $g2 = $this->groupe('G2', 30);
        $etudiants = [$this->etudiant('AAF00001'), $this->etudiant('AAF00002')];

        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params([$g1, $g2], $etudiants), $this->admin->id, null
        );

        $service = app(AssignmentConfirmationService::class);
        $proposeG1 = AssignmentItem::where('simulation_id', $simulation->id)->where('groupe_propose_id', $g1->id)->count();

        $r1 = $service->confirmerGroupe($simulation->fresh(), $g1->id, $this->admin->id);
        $this->assertSame($proposeG1, $r1['confirmees']);

        // Double confirmation : 0 écriture.
        $total = AffectationGroupe::count();
        $r2 = $service->confirmerGroupe($simulation->fresh(), $g1->id, $this->admin->id);
        $this->assertSame(0, $r2['confirmees']);
        $this->assertSame($total, AffectationGroupe::count());
        $this->assertSame('partiellement_confirmee', $simulation->fresh()->statut);
    }

    public function test_simulation_obsolete_detectee(): void
    {
        $g1 = $this->groupe('G1', 30);
        $etudiants = [$this->etudiant('AAO00001')];

        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params([$g1], $etudiants), $this->admin->id, null
        );

        // Donnée changée après simulation : capacité modifiée.
        $g1->update(['capacite' => 25]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('relancer la simulation');

        app(AssignmentConfirmationService::class)->confirmerGroupe($simulation->fresh(), $g1->id, $this->admin->id);
    }

    public function test_modification_manuelle_validee(): void
    {
        $g1 = $this->groupe('G1', 30);
        $g2 = $this->groupe('G2', 30);
        $etudiants = [$this->etudiant('AAM00001')];

        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params([$g1, $g2], $etudiants), $this->admin->id, null
        );

        $item = AssignmentItem::where('simulation_id', $simulation->id)->firstOrFail();
        $autre = $item->groupe_propose_id === $g1->id ? $g2 : $g1;

        $modifie = app(AssignmentConfirmationService::class)->modifier($item, $autre->id, $this->admin->id);
        $this->assertSame('modifie', $modifie->statut);
        $this->assertSame($autre->id, (int) $modifie->groupe_propose_id);
    }

    public function test_verrouille_bloque_sans_exception(): void
    {
        $g1 = $this->groupe('G1', 30);
        $etudiant = $this->etudiant('AAV00001');
        app(AffectationService::class)->affecter($etudiant, $g1, $this->annee, $this->admin->id, 'init');
        AffectationGroupe::where('etudiant_id', $etudiant->id)->update(['verrouille' => true]);

        $g2 = $this->groupe('G2', 30);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('verrouillée');

        app(AffectationService::class)->affecter($etudiant, $g2, $this->annee, $this->admin->id, 'transfert');
    }

    // ── Sécurité ───────────────────────────────────────────────

    public function test_idor_faculte_et_concurrence_double_confirm(): void
    {
        $g1 = $this->groupe('G1', 30);
        $etudiants = [$this->etudiant('AAS00001')];

        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params([$g1], $etudiants), $this->admin->id, null
        );

        $autreFaculte = Faculte::create(['nom' => 'FLSH', 'code' => 'AA2', 'description' => 'T', 'active' => true]);
        $adminFaculte = User::creerCompteBackOffice([
            'name' => 'Fac', 'email' => 'fac.aa@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $autreFaculte->id,
        ], 'admin_faculte');

        $this->actingAs($adminFaculte)->get(route('admin.affectations.automatique.show', $simulation))->assertNotFound();

        // Concurrence : deux confirmations du même groupe, une seule écrit.
        $service = app(AssignmentConfirmationService::class);
        $service->confirmerGroupe($simulation->fresh(), $g1->id, $this->admin->id);
        $total = AffectationGroupe::count();
        $service->confirmerGroupe($simulation->fresh(), $g1->id, $this->admin->id);
        $this->assertSame($total, AffectationGroupe::count());
    }

    public function test_rbac_confirmation_refusee_sans_droit(): void
    {
        $g1 = $this->groupe('G1', 30);
        $etudiants = [$this->etudiant('AAR00001')];

        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params([$g1], $etudiants), $this->admin->id, null
        );

        $agent = User::creerCompteBackOffice([
            'name' => 'Consult', 'email' => 'consult.aa@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');

        // consultation : archives.view mais pas archives.update.
        $this->actingAs($agent)
            ->post(route('admin.affectations.automatique.confirmer', [$simulation, $g1]))
            ->assertForbidden();
    }

    // ── Cas limites ────────────────────────────────────────────

    public function test_zero_groupe_422_et_capacite_zero(): void
    {
        $etudiants = [$this->etudiant('AAZ00001')];

        $this->actingAs($this->admin)->post(route('admin.affectations.automatique.simuler'), [
            'annee_universitaire_id' => $this->annee->id, 'groupe_ids' => [],
        ])->assertSessionHasErrors('groupe_ids');

        $g0 = $this->groupe('G0', 0);
        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params([$g0], $etudiants), $this->admin->id, null
        );

        $this->assertSame(0, $simulation->affectes);
        $this->assertSame(1, $simulation->non_affectes);
    }

    public function test_formulaire_pre_rempli_non_affectes(): void
    {
        $g1 = $this->groupe('G1', 30);
        $libre = $this->etudiant('AAP00001');
        $pris = $this->etudiant('AAP00002');
        app(AffectationService::class)->affecter($pris, $g1, $this->annee, $this->admin->id, 'init');

        $reponse = $this->actingAs($this->admin)->get(route('admin.affectations.automatique.parametres'))->assertOk();
        $contenu = (string) $reponse->getContent();

        preg_match('/<textarea[^>]*>(.*?)<\/textarea>/s', $contenu, $m);
        $ids = array_values(array_filter(array_map('trim', explode("\n", trim($m[1] ?? '')))));

        $this->assertContains((string) $libre->id, $ids);
        $this->assertNotContains((string) $pris->id, $ids);
    }

    public function test_simuler_ids_colles_prioritaires(): void
    {
        $g1 = $this->groupe('G1', 30);
        $garde = $this->etudiant('AAQ00001');
        $ignore = $this->etudiant('AAQ00002');

        $reponse = $this->actingAs($this->admin)->post(route('admin.affectations.automatique.simuler'), [
            'annee_universitaire_id' => $this->annee->id,
            'groupe_ids' => [$g1->id],
            'etudiant_ids_text' => "{$garde->id}\n{$ignore->id}\n{$garde->id}",
        ])->assertRedirect();

        $simulation = AssignmentSimulation::latest('id')->firstOrFail();
        $this->assertSame(2, $simulation->candidats);
        $this->assertSame(2, $simulation->affectes);
    }

    public function test_get_simuler_redirige_formulaire(): void
    {
        // Refresh ou accès direct sur une URL de simulation POST-only :
        // retour au formulaire avec message, jamais 405.
        $this->actingAs($this->admin)->get('/admin/affectations/auto/simuler')
            ->assertRedirect(route('admin.affectations.auto'));

        $this->actingAs($this->admin)->get('/admin/affectations/simuler')
            ->assertRedirect(route('admin.affectations.masse'));
    }

    public function test_annee_defaut_plus_inscriptions_actives(): void
    {
        // L'année récente sans inscriptions ne doit pas être présélectionnée.
        $ancienne = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => false,
        ]);
        $etudiant = $this->etudiant('AAY00001');
        InscriptionAcademique::where('etudiant_id', $etudiant->id)->update(['annee_universitaire_id' => $ancienne->id]);

        $contenu = (string) $this->actingAs($this->admin)
            ->get(route('admin.affectations.automatique.parametres'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<option value="'.$ancienne->id.'" selected/',
            $contenu
        );
    }

    public function test_prefiltre_signature_parite_controleur(): void
    {
        $moteur = app(AssignmentConstraintEngine::class);
        $gOk = $this->groupe('GPOK', 30);
        $gNiveau = $this->groupe('GPNIV', 30, ['niveau' => 'L9']);
        $gInactif = $this->groupe('GPINA', 30, ['actif' => false]);
        $etudiant = $this->etudiant('AAPAR001');
        $groupes = collect([$gOk, $gNiveau, $gInactif]);
        $places = [$gOk->id => 5, $gNiveau->id => 5, $gInactif->id => 5];

        $prefiltre = $moteur->prefiltreSignature($etudiant, $groupes);
        $complets = $moteur->admissibles($etudiant, $groupes, $this->annee, $places, null);

        // Verdict identique : exclus du pré-filtre ⟺ inadmissibles.
        foreach ($groupes as $groupe) {
            $exclu = isset($prefiltre['diagnostiques'][$groupe->id]);
            $this->assertSame(! $complets[$groupe->id]['admissible'], $exclu, "Divergence sur {$groupe->code}");
        }

        $this->assertSame('LEVEL_MISMATCH', $prefiltre['diagnostiques'][$gNiveau->id]['contraintes'][0]['code']);
        $this->assertSame('GROUP_INACTIVE', $prefiltre['diagnostiques'][$gInactif->id]['contraintes'][0]['code']);

        // Léger vs complet : mêmes ensembles d'admissibles.
        $leger = $moteur->admissiblesLeger($etudiant, $groupes, $this->annee, $places, null);
        $attendus = array_keys(array_filter($complets, fn (array $d) => $d['admissible']));
        sort($attendus);
        $this->assertSame($attendus, array_keys(array_filter($leger)));
    }

    public function test_ineligibles_colles_apparaissent_motives(): void
    {
        $g1 = $this->groupe('G1', 30);
        $ok = $this->etudiant('AAX00001');
        $sansInscription = $this->etudiant('AAX00002', [], false);

        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params([$g1], [$ok, $sansInscription]), $this->admin->id, null
        );

        // Aucun candidat ne disparaît : l'inéligible est tracé et motivé.
        $this->assertSame(2, $simulation->candidats);
        $item = AssignmentItem::where('simulation_id', $simulation->id)
            ->where('etudiant_id', $sansInscription->id)->firstOrFail();
        $this->assertSame('non_affecte', $item->statut);
        $this->assertStringContainsString('inscription', (string) $item->raison);

        $contenu = (string) $this->actingAs($this->admin)
            ->get(route('admin.affectations.automatique.show', $simulation))->assertOk()->getContent();
        $this->assertStringContainsString('(ré)inscrire', $contenu);
    }

    public function test_confirmation_par_tranches_avec_reprise(): void
    {
        $g1 = $this->groupe('G1', 30);
        $etudiants = [$this->etudiant('AAT00001'), $this->etudiant('AAT00002'), $this->etudiant('AAT00003')];

        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params([$g1], $etudiants), $this->admin->id, null
        );

        $service = app(AssignmentConfirmationService::class);

        // Tranche de 2 : 1 restante, groupe non marqué confirmé.
        $r1 = $service->confirmerGroupe($simulation->fresh(), $g1->id, $this->admin->id, 2);
        $this->assertSame(2, $r1['confirmees']);
        $this->assertSame(1, $r1['restantes']);
        $this->assertFalse($r1['termine']);
        $this->assertFalse($simulation->fresh()->groupeConfirme($g1->id));

        // Reprise : termine et marque le groupe confirmé.
        $r2 = $service->confirmerGroupe($simulation->fresh(), $g1->id, $this->admin->id, 2);
        $this->assertSame(1, $r2['confirmees']);
        $this->assertTrue($r2['termine']);
        $this->assertTrue($simulation->fresh()->groupeConfirme($g1->id));

        // HTTP : bouton « Continuer » proposé quand il reste des items.
        $g2 = $this->groupe('G2', 30);
        $etudiants2 = [$this->etudiant('AAT00004'), $this->etudiant('AAT00005')];
        $simulation2 = app(AssignmentSimulationService::class)->simuler(
            $this->params([$g2], $etudiants2), $this->admin->id, null
        );
        // Forcer artificiellement une tranche partielle via limite service déjà testée ;
        // ici on vérifie juste que la page show mentionne le bouton Confirmer.
        $contenu = (string) $this->actingAs($this->admin)
            ->get(route('admin.affectations.automatique.show', $simulation2))->assertOk()->getContent();
        $this->assertStringContainsString('Confirmer ce groupe', $contenu);
    }

    public function test_matrice_candidats_complete_par_etudiant(): void
    {
        // Test 1 (§28) : 5 compatibles → 5 entrées compatibles + sélection.
        $groupes = [];
        $etudiants = [];

        for ($i = 1; $i <= 5; $i++) {
            $groupes[] = $this->groupe('GM'.$i, 30);
        }

        for ($i = 1; $i <= 3; $i++) {
            $etudiants[] = $this->etudiant('AAC'.str_pad((string) $i, 5, '0', STR_PAD_LEFT));
        }

        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params($groupes, $etudiants), $this->admin->id, null
        );

        $item = AssignmentItem::where('simulation_id', $simulation->id)
            ->where('etudiant_id', $etudiants[0]->id)->firstOrFail();

        $compatibles = array_values(array_filter($item->candidats, fn (array $c) => $c['compatible']));
        $this->assertCount(5, $compatibles);
        $this->assertSame(5, (int) $item->flexibilite);
        $this->assertContains($item->groupe_propose_id, array_column($compatibles, 'groupe_id'));
    }

    public function test_choix_equilibrage_parmi_candidats(): void
    {
        // Test 5 (§28) : le moins rempli parmi les compatibles est choisi.
        $rempli = $this->groupe('GE1', 30);
        $vide1 = $this->groupe('GE2', 30);
        $vide2 = $this->groupe('GE3', 30);

        for ($i = 1; $i <= 5; $i++) {
            app(AffectationService::class)->affecter(
                $this->etudiant('AAR'.str_pad((string) $i, 5, '0', STR_PAD_LEFT)),
                $rempli, $this->annee, $this->admin->id, 'remplissage'
            );
        }

        $nouveau = $this->etudiant('AAR00006');
        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params([$rempli, $vide1, $vide2], [$nouveau]), $this->admin->id, null
        );

        $item = AssignmentItem::where('simulation_id', $simulation->id)->firstOrFail();
        $this->assertContains($item->groupe_propose_id, [$vide1->id, $vide2->id]);
    }

    public function test_groupe_complet_pendant_simulation_exclu(): void
    {
        // Test 8 (§28), seed fixe donc ordre déterministe : G1 (cap 1)
        // pris par le premier traité, exclu CAPACITY pour les suivants.
        $g1 = $this->groupe('GF1', 1);
        $g2 = $this->groupe('GF2', 30);
        $e1 = $this->etudiant('AAF00001');
        $e2 = $this->etudiant('AAF00002');
        $e3 = $this->etudiant('AAF00003');

        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params([$g1, $g2], [$e1, $e2, $e3]) + ['seed' => 'TEST8'], $this->admin->id, null
        );

        $parCin = AssignmentItem::where('simulation_id', $simulation->id)->with('etudiant:id,cin')->get()->keyBy(fn ($i) => $i->etudiant->cin);

        $this->assertSame($g1->id, (int) $parCin['AAF00001']->groupe_propose_id);

        foreach (['AAF00002', 'AAF00003'] as $cin) {
            $this->assertSame($g2->id, (int) $parCin[$cin]->groupe_propose_id);
            $entreeG1 = collect($parCin[$cin]->candidats)->firstWhere('groupe_id', $g1->id);
            $this->assertFalse((bool) $entreeG1['compatible']);
            $this->assertSame('CAPACITY', $entreeG1['type']);
        }
    }

    public function test_fsegs_tous_groupes_compatibles(): void
    {
        // Test 9 (§29) : Gestion L1 → 38 compatibles, jamais le 1er d'office.
        $groupes = [];

        for ($i = 1; $i <= 38; $i++) {
            $groupes[] = $this->groupe('GL1-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 30);
        }

        $etudiant = $this->etudiant('AAFL1001');
        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params($groupes, [$etudiant]), $this->admin->id, null
        );

        $item = AssignmentItem::where('simulation_id', $simulation->id)->firstOrFail();
        $this->assertCount(38, array_filter($item->candidats, fn (array $c) => $c['compatible']));
    }

    public function test_modification_hors_candidats_refusee(): void
    {        // §24 : une affectation manuelle hors candidate_groups est refusée.
        $g1 = $this->groupe('GV1', 30);
        $gIncompatible = $this->groupe('GV9', 30, ['niveau' => 'L9']);
        $etudiant = $this->etudiant('AAV00001');

        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params([$g1], [$etudiant]), $this->admin->id, null
        );

        $item = AssignmentItem::where('simulation_id', $simulation->id)->firstOrFail();

        $this->expectException(\DomainException::class);
        app(AssignmentConfirmationService::class)->modifier($item, $gIncompatible->id, $this->admin->id);
    }

    public function test_vue_matrice_et_filtre_flexibilite(): void
    {
        $groupes = [$this->groupe('GH1', 30), $this->groupe('GH2', 30)];
        $etudiant = $this->etudiant('AAH00001');
        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params($groupes, [$etudiant]), $this->admin->id, null
        );

        $contenu = (string) $this->actingAs($this->admin)
            ->get(route('admin.affectations.automatique.show', $simulation))->assertOk()->getContent();

        $this->assertStringContainsString('groupes possibles', $contenu);
        $this->assertStringContainsString('1 seul groupe possible', $contenu);
        $this->assertStringContainsString('proposé', $contenu);

        // Filtre flexibilité : 2 compatibles → visible en « 4+ » ? non ; en « 2-3 » oui.
        $this->actingAs($this->admin)
            ->get(route('admin.affectations.automatique.show', [$simulation, 'flex' => '2-3']))->assertOk();
        $this->assertSame(1, AssignmentItem::where('simulation_id', $simulation->id)->whereBetween('flexibilite', [2, 3])->count());
    }

    public function test_cas_1_dix_etudiants_deux_groupes_moitie(): void
    {
        $groupes = [$this->groupe('GC1', 10), $this->groupe('GC2', 10)];
        $etudiants = [];

        for ($i = 1; $i <= 10; $i++) {
            $etudiants[] = $this->etudiant('AAC1'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        }

        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params($groupes, $etudiants), $this->admin->id, null
        );

        $repartition = AssignmentItem::where('simulation_id', $simulation->id)
            ->whereNotNull('groupe_propose_id')->get()->groupBy('groupe_propose_id');

        $this->assertSame([5, 5], $repartition->map->count()->sort()->values()->all());
    }

    public function test_cas_3_capacite_insuffisante_groupe_supp(): void
    {
        $groupes = [$this->groupe('GC1', 10), $this->groupe('GC2', 10)];
        $etudiants = [];

        for ($i = 1; $i <= 25; $i++) {
            $etudiants[] = $this->etudiant('AAC3'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        }

        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params($groupes, $etudiants), $this->admin->id, null
        );

        $this->assertSame(20, $simulation->affectes);
        $this->assertSame(1, $simulation->metriques['groupes_supplementaires']);
        $this->assertSame(10, $simulation->metriques['capacite_recommandee']);
        $this->assertSame(5, $simulation->metriques['manque']);
    }

    public function test_cas_4_cent_etudiants_equilibre(): void
    {
        $groupes = [$this->groupe('GQ1', 30), $this->groupe('GQ2', 30), $this->groupe('GQ3', 25), $this->groupe('GQ4', 25)];
        $etudiants = [];

        for ($i = 1; $i <= 100; $i++) {
            $etudiants[] = $this->etudiant('AAQ4'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        }

        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params($groupes, $etudiants), $this->admin->id, null
        );

        $this->assertSame(100, $simulation->affectes);
        $this->assertLessThanOrEqual(2, $simulation->metriques['ecart_max']);
    }

    public function test_cas_12_meme_seed_meme_resultat(): void
    {
        $groupes = [$this->groupe('GS1', 30), $this->groupe('GS2', 30)];
        $etudiants = [];

        for ($i = 1; $i <= 10; $i++) {
            $etudiants[] = $this->etudiant('AAS1'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        }

        $params = $this->params($groupes, $etudiants) + ['seed' => 'SEED-FIXE'];
        $service = app(AssignmentSimulationService::class);
        $s1 = $service->simuler($params, $this->admin->id, null);
        $s2 = $service->simuler($params, $this->admin->id, null);

        $carte = fn ($s) => AssignmentItem::where('simulation_id', $s->id)->orderBy('etudiant_id')->pluck('groupe_propose_id', 'etudiant_id')->all();
        $this->assertSame($carte($s1), $carte($s2));
    }

    public function test_cas_13_seeds_differents_memes_contraintes(): void
    {
        $groupes = [$this->groupe('GS1', 30), $this->groupe('GS2', 30), $this->groupe('GS3', 30)];
        $etudiants = [];

        for ($i = 1; $i <= 30; $i++) {
            $etudiants[] = $this->etudiant('AAS2'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        }

        $service = app(AssignmentSimulationService::class);
        $base = $this->params($groupes, $etudiants);
        $resultats = [];

        for ($k = 0; $k < 5; $k++) {
            $s = $service->simuler($base + ['seed' => 'SEED-'.$k], $this->admin->id, null);
            $this->assertSame(30, $s->affectes);
            $resultats[] = json_encode(AssignmentItem::where('simulation_id', $s->id)->orderBy('etudiant_id')->pluck('groupe_propose_id')->all());
        }

        // Au moins 2 répartitions distinctes sur 5 seeds, toutes valides.
        $this->assertGreaterThan(1, count(array_unique($resultats)));
    }

    public function test_cas_15_deux_admins_une_seule_ecriture(): void
    {
        $groupe = $this->groupe('GK1', 30);
        $etudiants = [$this->etudiant('AAK00001'), $this->etudiant('AAK00002')];
        $simulation = app(AssignmentSimulationService::class)->simuler(
            $this->params([$groupe], $etudiants), $this->admin->id, null
        );

        $admin2 = User::creerCompteBackOffice([
            'name' => 'Scolarité 2', 'email' => 'sco2.phauto@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $service = app(AssignmentConfirmationService::class);
        $service->confirmerGroupe($simulation->fresh(), $groupe->id, $this->admin->id);
        $total = AffectationGroupe::count();
        $service->confirmerGroupe($simulation->fresh(), $groupe->id, $admin2->id);

        $this->assertSame($total, AffectationGroupe::count());
        $this->assertTrue($simulation->fresh()->groupeConfirme($groupe->id));
    }

    public function test_regenerer_meme_params_nouveau_seed(): void
    {
        $groupe = $this->groupe('GR1', 30);
        $etudiant = $this->etudiant('AAR00001');
        $service = app(AssignmentSimulationService::class);
        $s1 = $service->simuler($this->params([$groupe], [$etudiant]), $this->admin->id, null);
        $s2 = $service->regenerer($s1, $this->admin->id, null);

        $this->assertNotSame($s1->id, $s2->id);
        $this->assertSame($s1->id, (int) $s2->simulation_origine_id);
        $this->assertNotSame($s1->seed, $s2->seed);
    }

    public function test_strategie_mixite_genre_repartition_equilibree(): void
    {
        $g1 = $this->groupe('GMIX1', 20);
        $g2 = $this->groupe('GMIX2', 20);

        $etudiants = [];
        // 10 filles
        for ($i = 1; $i <= 10; $i++) {
            $etudiants[] = $this->etudiant('AAFMIX'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), ['sexe' => 'F']);
        }
        // 10 garçons
        for ($i = 1; $i <= 10; $i++) {
            $etudiants[] = $this->etudiant('AAMMIX'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), ['sexe' => 'M']);
        }

        $service = app(AssignmentSimulationService::class);
        $simulation = $service->simuler(
            $this->params([$g1, $g2], $etudiants) + ['strategie' => 'mixite_genre'],
            $this->admin->id,
            null
        );

        $this->assertSame(20, $simulation->affectes);
        $this->assertSame('mixite_genre', $simulation->metriques['strategie']);

        $genresG1 = $simulation->metriques['genres_par_groupe'][$g1->id];
        $genresG2 = $simulation->metriques['genres_par_groupe'][$g2->id];

        // Parité parfaite : 5 filles et 5 garçons dans chaque groupe
        $this->assertSame(5, $genresG1['femmes']);
        $this->assertSame(5, $genresG1['hommes']);
        $this->assertSame(5, $genresG2['femmes']);
        $this->assertSame(5, $genresG2['hommes']);
    }

    public function test_strategie_redoublants_homogenes(): void
    {
        $g1 = $this->groupe('GRED1', 20);
        $g2 = $this->groupe('GRED2', 20);

        $etudiants = [];
        // 6 redoublants (reinscription)
        for ($i = 1; $i <= 6; $i++) {
            $e = $this->etudiant('AARED'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), [], false);
            InscriptionAcademique::create([
                'etudiant_id' => $e->id,
                'annee_universitaire_id' => $this->annee->id,
                'niveau' => 'L1',
                'statut' => 'en_cours',
                'type' => 'reinscription',
                'regime' => 'initiale',
            ]);
            $etudiants[] = $e;
        }

        // 14 nouveaux (premiere)
        for ($i = 1; $i <= 14; $i++) {
            $etudiants[] = $this->etudiant('AANOUV'.str_pad((string) $i, 3, '0', STR_PAD_LEFT));
        }

        $service = app(AssignmentSimulationService::class);
        $simulation = $service->simuler(
            $this->params([$g1, $g2], $etudiants) + ['strategie' => 'redoublants_homogenes'],
            $this->admin->id,
            null
        );

        $this->assertSame(20, $simulation->affectes);
        $this->assertSame('redoublants_homogenes', $simulation->metriques['strategie']);

        $redsG1 = $simulation->metriques['redoublants_par_groupe'][$g1->id];
        $redsG2 = $simulation->metriques['redoublants_par_groupe'][$g2->id];

        // Distribution homogène : exactement 3 redoublants par groupe
        $this->assertSame(3, $redsG1['redoublants']);
        $this->assertSame(3, $redsG2['redoublants']);
    }

    public function test_strategie_alphabetique(): void
    {
        $g1 = $this->groupe('GALPH1', 10);
        $g2 = $this->groupe('GALPH2', 10);

        $noms = ['Abidi', 'Ben Salem', 'Dridi', 'Jlassi', 'Trabelsi', 'Zitouni'];
        $etudiants = [];

        foreach ($noms as $idx => $nom) {
            $etudiants[] = $this->etudiant('AALP000'.$idx, ['nom' => $nom, 'prenom' => 'Prenom'.$idx]);
        }

        $service = app(AssignmentSimulationService::class);
        $simulation = $service->simuler(
            $this->params([$g1, $g2], $etudiants) + ['strategie' => 'alphabetique'],
            $this->admin->id,
            null
        );

        $this->assertSame(6, $simulation->affectes);
        $this->assertSame('alphabetique', $simulation->metriques['strategie']);

        $items = AssignmentItem::where('simulation_id', $simulation->id)->get();
        foreach ($items as $item) {
            $this->assertStringContainsString('alphabétique', $item->raison);
        }
    }

    public function test_controller_parametres_vue_et_filtres_lmd(): void
    {
        $g = $this->groupe('GCTL1', 25);
        $e1 = $this->etudiant('ACTL0001');

        $reponse = $this->actingAs($this->admin)->get(route('admin.affectations.automatique.parametres'));
        $reponse->assertOk();
        $reponse->assertViewHas(['programmes', 'specialites', 'niveaux', 'regimes', 'strategies']);

        $postReponse = $this->actingAs($this->admin)->post(route('admin.affectations.automatique.simuler'), [
            'annee_universitaire_id' => $this->annee->id,
            'groupe_ids' => [$g->id],
            'regime' => 'initiale',
            'strategie' => 'mixite_genre',
            'etudiant_ids_text' => (string) $e1->id,
        ]);

        $postReponse->assertRedirect();
        $simulation = AssignmentSimulation::latest('id')->first();
        $this->assertNotNull($simulation);
        $this->assertSame('mixite_genre', $simulation->metriques['strategie'] ?? null);
    }
}
