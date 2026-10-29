<?php

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Note;
use App\Models\User;
use App\Modules\Attendance\Models\Presence;
use App\Modules\PreventionDecrochage\Models\AlerteDecrochage;
use App\Modules\PreventionDecrochage\Models\RegleDecrochage;
use App\Modules\Referentiel\Models\Programme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Lot 15 — Prévention du décrochage : tests du cahier.
 *
 * Couverture : catalogue de règles expliquées et approuvées (brouillon ne
 * scanne pas, approbation exige une justification écrite, désactivation),
 * scan présences (absents/retards, excuse NON comptée, étudiant sans
 * présence = sans données, jamais un signal), scan notes (chaîne
 * note → semestre → inscription, seuil strict, année via inscription),
 * idempotence du re-scan, flux humain (examen, clôture tracée, rétention
 * non inventée), mesures d'accompagnement séparées des résultats
 * officiels, isolation facultaire (IDOR), permissions, module désactivé,
 * non-écriture des données officielles, performance synthétique.
 *
 * Données 100% synthétiques — aucune donnée réelle d'étudiant.
 */
class PreventionDecrochageTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private AnneeUniversitaire $anneeSuivante;

    private Programme $programmeA;

    private Programme $programmeB;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('modules.prevention-decrochage.active', true);

        $this->faculteA = Faculte::create(['nom' => 'Sciences Test', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->faculteB = Faculte::create(['nom' => 'Droit Test', 'code' => 'FDEG', 'description' => 'T', 'active' => true]);
        $this->annee = AnneeUniversitaire::create(['libelle' => '2024-2025', 'date_debut' => '2024-09-01', 'date_fin' => '2025-07-15']);
        $this->anneeSuivante = AnneeUniversitaire::create(['libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-07-15']);
        $this->programmeA = Programme::create([
            'faculte_id' => $this->faculteA->id, 'code' => 'LIC-A', 'nom' => 'Licence A',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $this->programmeB = Programme::create([
            'faculte_id' => $this->faculteB->id, 'code' => 'LIC-B', 'nom' => 'Licence B',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
    }

    /* ── Données de synthèse ─────────────────────────────────────── */

    private function creerEtudiant(Faculte $faculte): Etudiant
    {
        return Etudiant::create([
            'cin' => 'CIN-'.uniqid(), 'nom' => 'Nom'.uniqid(), 'prenom' => 'Pre',
            'email' => 'etu-'.uniqid().'@example.test', 'sexe' => 'M',
            'faculte_id' => $faculte->id, 'niveau' => 'L1', 'annee_academique' => '2024-2025',
            'adresse' => 'Rue test', 'ville' => 'Dakar', 'code_postal' => '10000', 'annee_bac' => '2020',
        ]);
    }

    private function presence(Etudiant $etudiant, string $statut, string $date): Presence
    {
        return Presence::create([
            'etudiant_id' => $etudiant->id, 'date' => $date, 'seance' => 'S1',
            'statut' => $statut, 'motif' => $statut === 'absent' ? null : 'test',
        ]);
    }

    /** Chaîne note → semestre → inscription (faculté/année confirmées). */
    private function note(Etudiant $etudiant, float $note, AnneeUniversitaire $annee, Programme $programme): Note
    {
        // UNIQUE (etudiant_id, annee_universitaire_id) : réutilise l'inscription.
        $inscription = InscriptionAcademique::query()
            ->where('etudiant_id', $etudiant->id)
            ->where('annee_universitaire_id', $annee->id)
            ->first() ?? InscriptionAcademique::create([
                'etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $annee->id,
                'programme_id' => $programme->id, 'niveau' => 'L1', 'statut' => 'valide',
            ]);
        // UNIQUE (semester, numero) : réutilise le semestre 1 existant.
        $semestre = DB::table('semestres')->where('inscription_academique_id', $inscription->id)->where('numero', 1)->value('id')
            ?? DB::table('semestres')->insertGetId([
                'inscription_academique_id' => $inscription->id, 'numero' => 1,
                'credits_obtenus' => 0, 'credits_total' => 30,
            ]);
        // UNIQUE (semestre, matiere, session) : matière unique par note.
        $matiereId = DB::table('matieres')->insertGetId([
            'code' => 'MAT-'.uniqid(), 'nom' => 'Matiere Test', 'niveau' => 'L1', 'semestre' => 1,
        ]);

        return Note::create([
            'etudiant_id' => $etudiant->id, 'inscription_academique_id' => $inscription->id,
            'semestre_id' => $semestre, 'matiere_id' => $matiereId,
            'note' => $note, 'coefficient' => 1, 'credits' => 6, 'session' => 'normale',
        ]);
    }

    /**
     * Règle du catalogue pour tests (raison explicite écrite).
     * $seuil = seuil de DÉCLENCHEMENT ; $seuilMesure = seuil de la mesure
     * sous-jacente (notes : note < seuil_mesure compte).
     */
    private function regle(string $type, string $mesure, float $seuil, string $statut = 'approuvee', ?float $seuilMesure = null): RegleDecrochage
    {
        return RegleDecrochage::create([
            'code' => 'REG-'.uniqid(), 'nom' => 'Regle Test', 'type' => $type,
            'mesure' => $mesure, 'seuil' => $seuil, 'seuil_mesure' => $seuilMesure,
            'statut' => $statut, 'version' => 1,
            'raison_explicable' => 'Justification écrite du conseil pédagogique pour ce test.',
        ]);
    }

    /* ── Utilisateurs ────────────────────────────────────────────── */

    private function adminScolarite(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Admin Scolarite', 'email' => 'scol-'.uniqid().'@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
    }

    private function adminFaculte(Faculte $faculte): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Admin Faculte', 'email' => 'adminfac-'.uniqid().'@universite.test',
            'password' => 'Motdepasse@123', 'faculte_id' => $faculte->id,
        ], 'admin_faculte');
    }

    private function consultateur(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Consultation', 'email' => 'consult-'.uniqid().'@universite.test', 'password' => 'Motdepasse@123',
        ], 'consultation');
    }

    private function agentScolarite(): User
    {
        return User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent-'.uniqid().'@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');
    }

    /* ── Appels ──────────────────────────────────────────────────── */

    private function rescan(User $utilisateur, array $donnees = []): TestResponse
    {
        return $this->actingAs($utilisateur)->postJson(route('admin.prevention-decrochage.detecter'), $donnees);
    }

    private function indexAlertes(User $utilisateur, array $parametres = []): TestResponse
    {
        return $this->actingAs($utilisateur)->getJson(route('admin.prevention-decrochage.alertes.index', $parametres));
    }

    /* ── Catalogue de règles ─────────────────────────────────────── */

    public function test_creation_regle_refusee_sans_decrochage_regles(): void
    {
        $reponse = $this->actingAs($this->consultateur())->postJson(route('admin.prevention-decrochage.regles.creer'), [
            'code' => 'REG-1', 'nom' => 'R', 'type' => 'presences', 'mesure' => 'absents',
            'seuil' => 3, 'raison_explicable' => 'Une justification test ecrite ici.',
        ]);
        $reponse->assertStatus(403); // consultation : lecture seule (decrochage.view)
    }

    public function test_regle_creee_est_en_brouillon_et_ne_scanne_pas(): void
    {
        $reponse = $this->actingAs($this->adminScolarite())->postJson(route('admin.prevention-decrochage.regles.creer'), [
            'code' => 'REG-BROUILLON', 'nom' => 'Regle brouillon', 'type' => 'presences',
            'mesure' => 'absents', 'seuil' => 3, 'raison_explicable' => 'Justification ecrite du cahier test.',
        ]);
        $reponse->assertStatus(201);
        $this->assertSame('brouillon', $reponse->json('regle.statut'));

        // Un brouillon n'est JAMAIS exécuté par le scan.
        $this->rescan($this->adminScolarite())->assertOk()->assertJson(['regles_executees' => 0]);
        $this->assertSame(0, AlerteDecrochage::count());
    }

    public function test_approbation_exige_justification_ecrite(): void
    {
        $regle = RegleDecrochage::create([
            'code' => 'REG-COURTE', 'nom' => 'R', 'type' => 'presences', 'mesure' => 'absents',
            'seuil' => 3, 'statut' => 'brouillon', 'version' => 1, 'raison_explicable' => 'court',
        ]);

        $reponse = $this->actingAs($this->adminScolarite())->postJson(route('admin.prevention-decrochage.regles.approuver', ['id' => $regle->id]));
        $reponse->assertStatus(422);
        $this->assertSame('brouillon', $regle->refresh()->statut);
    }

    public function test_approbation_tracee_avec_auteur_et_date(): void
    {
        $regle = $this->regle('presences', 'absents', 2, 'brouillon');
        $admin = $this->adminScolarite();

        $reponse = $this->actingAs($admin)->postJson(route('admin.prevention-decrochage.regles.approuver', ['id' => $regle->id]));
        $reponse->assertOk();

        $regle->refresh();
        $this->assertSame('approuvee', $regle->statut);
        $this->assertSame($admin->id, (int) $regle->approuve_par);
        $this->assertNotNull($regle->approuve_le);
    }

    public function test_regle_approuvee_ne_peut_pas_etre_re_approuvee(): void
    {
        $regle = $this->regle('presences', 'absents', 2);
        $this->actingAs($this->adminScolarite())
            ->postJson(route('admin.prevention-decrochage.regles.approuver', ['id' => $regle->id]))
            ->assertStatus(422);
    }

    public function test_desactiver_arrete_la_generation_d_alertes(): void
    {
        $regle = $this->regle('presences', 'absents', 1);
        $etudiant = $this->creerEtudiant($this->faculteA);
        $this->presence($etudiant, 'absent', '2024-10-02');
        $this->presence($etudiant, 'absent', '2024-10-09');

        $this->rescan($this->adminScolarite())->assertOk()->assertJson(['alertes_creees' => 1]);
        $this->assertSame(1, AlerteDecrochage::count());

        $this->actingAs($this->adminScolarite())
            ->postJson(route('admin.prevention-decrochage.regles.desactiver', ['id' => $regle->id]))
            ->assertOk();

        $this->rescan($this->adminScolarite())->assertOk()->assertJson(['regles_executees' => 0]);
        $this->assertSame(1, AlerteDecrochage::count()); // aucune alerte nouvelle
    }

    /* ── Scan présences ─────────────────────────────────────────── */

    public function test_absents_atteignant_seuil_cree_alerte_avec_preuve_datee(): void
    {
        $this->regle('presences', 'absents', 2);
        $etudiant = $this->creerEtudiant($this->faculteA);
        $this->presence($etudiant, 'absent', '2024-10-02');
        $this->presence($etudiant, 'absent', '2024-10-09');

        $this->rescan($this->adminScolarite())->assertOk()->assertJson(['alertes_creees' => 1]);

        $alerte = AlerteDecrochage::sole();
        $this->assertSame('generee', $alerte->statut);
        $this->assertSame(2.0, (float) $alerte->valeur);
        $this->assertSame('presences', $alerte->preuve['source']);
        $this->assertCount(2, $alerte->preuve['elements']); // éléments datés et sourcés
        // Tri desc explicite sur les dates des preuves.
        $this->assertSame(
            ['2024-10-09', '2024-10-02'],
            collect($alerte->preuve['elements'])->pluck('date')->all()
        );
    }

    public function test_excuse_justifiee_n_est_pas_comptee_comme_absent(): void
    {
        $this->regle('presences', 'absents', 1);
        $etudiant = $this->creerEtudiant($this->faculteA);
        $this->presence($etudiant, 'excuse', '2024-10-02');
        $this->presence($etudiant, 'excuse', '2024-10-09');

        $this->rescan($this->adminScolarite())->assertOk()->assertJson(['alertes_creees' => 0]);
    }

    public function test_etudiant_sans_presence_ne_genere_jamais_d_alerte(): void
    {
        // Règle d'or : donnée absente ≠ signal négatif.
        $this->regle('presences', 'absents', 1);
        $sansDonnees = $this->creerEtudiant($this->faculteA); // aucune présence
        $etudiantDonne = $this->creerEtudiant($this->faculteA); // 1 absent
        $this->presence($etudiantDonne, 'absent', '2024-10-02');

        $this->rescan($this->adminScolarite())->assertOk()->assertJson(['alertes_creees' => 1]);

        $alerte = AlerteDecrochage::sole();
        $this->assertSame($etudiantDonne->id, $alerte->etudiant_id);
        $this->assertNotSame($sansDonnees->id, $alerte->etudiant_id);
    }

    public function test_mesure_retards_compte_les_retards(): void
    {
        $this->regle('presences', 'retards', 2);
        $etudiant = $this->creerEtudiant($this->faculteA);
        $this->presence($etudiant, 'retard', '2024-10-02');
        $this->presence($etudiant, 'retard', '2024-10-09');
        $this->presence($etudiant, 'absent', '2024-10-16'); // pas la mesure visée

        $this->rescan($this->adminScolarite())->assertOk()->assertJson(['alertes_creees' => 1]);
        $this->assertSame(2.0, (float) AlerteDecrochage::sole()->valeur);
    }

    public function test_plage_d_annee_borne_les_presences(): void
    {
        $this->regle('presences', 'absents', 1);
        $etudiant = $this->creerEtudiant($this->faculteA);
        $this->presence($etudiant, 'absent', '2024-10-02'); // dans 2024-2025
        $this->presence($etudiant, 'absent', '2025-11-02'); // dans 2025-2026

        $resume = $this->rescan($this->adminScolarite(), ['annee_universitaire_id' => $this->annee->id])->assertOk()->json();
        $this->assertSame(1, $resume['alertes_creees']);

        // Année suivante : le triplet (règle, étudiant, année) change.
        $resume2 = $this->rescan($this->adminScolarite(), ['annee_universitaire_id' => $this->anneeSuivante->id])->assertOk()->json();
        $this->assertSame(1, $resume2['alertes_creees']);
        $this->assertSame(2, AlerteDecrochage::count());
    }

    /* ── Scan notes ─────────────────────────────────────────────── */

    public function test_notes_sous_seuil_cree_alerte_avec_chaine_confirmee(): void
    {
        $this->regle('notes', 'notes_sous_seuil', 1, 'approuvee', 10);
        $etudiant = $this->creerEtudiant($this->faculteA);
        $note = $this->note($etudiant, 7.5, $this->annee, $this->programmeA);
        $this->note($etudiant, 14.0, $this->annee, $this->programmeA); // au-dessus : ignoré

        $this->rescan($this->adminScolarite())->assertOk()->assertJson(['alertes_creees' => 1]);

        $alerte = AlerteDecrochage::sole();
        $this->assertSame(1.0, (float) $alerte->valeur);
        $this->assertSame('notes', $alerte->preuve['source']);
        $this->assertSame($note->id, (int) $alerte->preuve['elements'][0]['note_id']);
        // Scan global (sans année) : l'alerte n'est PAS datée d'une année
        // universitaire arbitraire (jamais d'invention de périmètre).
        $this->assertNull($alerte->annee_universitaire_id);
    }

    public function test_note_egale_au_seuil_ne_signale_pas(): void
    {
        $this->regle('notes', 'notes_sous_seuil', 1, 'approuvee', 10);
        $etudiant = $this->creerEtudiant($this->faculteA);
        $this->note($etudiant, 10.0, $this->annee, $this->programmeA); // == seuil : comparaison stricte
        $this->note($etudiant, 12.5, $this->annee, $this->programmeA);

        $this->rescan($this->adminScolarite())->assertOk()->assertJson(['alertes_creees' => 0]);
    }

    public function test_scan_notes_borne_par_l_annee_de_l_inscription(): void
    {
        $this->regle('notes', 'notes_sous_seuil', 1, 'approuvee', 10);
        $etudiant = $this->creerEtudiant($this->faculteA);
        $this->note($etudiant, 6.0, $this->annee, $this->programmeA); // seulement 2024-2025

        $resume = $this->rescan($this->adminScolarite(), ['annee_universitaire_id' => $this->anneeSuivante->id])->assertOk()->json();
        $this->assertSame(0, $resume['alertes_creees']);
        $this->assertSame(0, AlerteDecrochage::count());

        $resume2 = $this->rescan($this->adminScolarite(), ['annee_universitaire_id' => $this->annee->id])->assertOk()->json();
        $this->assertSame(1, $resume2['alertes_creees']);
        // L'alerte porte l'année du périmètre demandé (datée, jamais inventée).
        $this->assertSame($this->annee->id, (int) AlerteDecrochage::sole()->annee_universitaire_id);
    }

    public function test_etudiant_sans_notes_ne_genere_jamais_d_alerte(): void
    {
        $this->regle('notes', 'notes_sous_seuil', 1, 'approuvee', 10);
        $this->creerEtudiant($this->faculteA); // aucune note → sans données

        $resume = $this->rescan($this->adminScolarite())->assertOk()->json();
        $this->assertSame(0, $resume['alertes_creees']);
        $this->assertSame(1, $resume['regles_sans_donnees']);
        $this->assertSame(0, AlerteDecrochage::count());
    }

    /* ── Idempotence + flux humain ──────────────────────────────── */

    public function test_rescan_sans_doublon_et_alerte_fermee_renaissant(): void
    {
        $this->regle('presences', 'absents', 1);
        $etudiant = $this->creerEtudiant($this->faculteA);
        $this->presence($etudiant, 'absent', '2024-10-02');

        $this->rescan($this->adminScolarite())->assertOk()->assertJson(['alertes_creees' => 1]);
        $this->rescan($this->adminScolarite())->assertOk()->assertJson(['alertes_creees' => 0, 'doublons_ignores' => 1]);
        $this->assertSame(1, AlerteDecrochage::count());

        $alerte = AlerteDecrochage::sole();
        $this->actingAs($this->adminScolarite())
            ->postJson(route('admin.prevention-decrochage.alertes.cloturer', ['id' => $alerte->id]), ['motif_cloture' => 'Etudiant contacte, situation reglee.'])
            ->assertOk();
        $this->assertSame('cloturee', $alerte->refresh()->statut);

        // Une alerte FERMÉE peut renaître au scan suivant (trace complète).
        $this->rescan($this->adminScolarite())->assertOk()->assertJson(['alertes_creees' => 1]);
        $this->assertSame(2, AlerteDecrochage::count());
    }

    public function test_examiner_marque_un_flux_humain_trace(): void
    {
        $this->regle('presences', 'absents', 1);
        $etudiant = $this->creerEtudiant($this->faculteA);
        $this->presence($etudiant, 'absent', '2024-10-02');
        $this->rescan($this->adminScolarite());
        $alerte = AlerteDecrochage::sole();
        $agent = $this->adminScolarite();

        $this->actingAs($agent)
            ->postJson(route('admin.prevention-decrochage.alertes.examiner', ['id' => $alerte->id]), ['commentaire_examen' => 'Consultation de dossier en cours.'])
            ->assertOk();

        $alerte->refresh();
        $this->assertSame('examinee', $alerte->statut);
        $this->assertSame($agent->id, (int) $alerte->examen_par);
        $this->assertNotNull($alerte->examine_le);

        // Deuxième examen : transition invalide refusée.
        $this->actingAs($agent)
            ->postJson(route('admin.prevention-decrochage.alertes.examiner', ['id' => $alerte->id]))
            ->assertStatus(422);
    }

    public function test_cloturer_exige_un_motif_et_ne_jamais_inventer_retention(): void
    {
        $this->regle('presences', 'absents', 1);
        $etudiant = $this->creerEtudiant($this->faculteA);
        $this->presence($etudiant, 'absent', '2024-10-02');
        $this->rescan($this->adminScolarite());
        $alerte = AlerteDecrochage::sole();

        $this->actingAs($this->adminScolarite())
            ->postJson(route('admin.prevention-decrochage.alertes.cloturer', ['id' => $alerte->id]), [])
            ->assertStatus(422); // motif obligatoire : clôture tracée

        $this->actingAs($this->adminScolarite())
            ->postJson(route('admin.prevention-decrochage.alertes.cloturer', ['id' => $alerte->id]), ['motif_cloture' => 'Etudiant accompagne, dossier verrouille.'])
            ->assertOk();

        $alerte->refresh();
        $this->assertSame('cloturee', $alerte->statut);
        $this->assertNull($alerte->retention_a); // politique NON confirmée : reste NULL
    }

    public function test_suivi_accompagnement_repose_dans_la_table_module(): void
    {
        $this->regle('presences', 'absents', 1);
        $etudiant = $this->creerEtudiant($this->faculteA);
        $this->presence($etudiant, 'absent', '2024-10-02');
        $this->rescan($this->adminScolarite());
        $alerte = AlerteDecrochage::sole();

        $this->actingAs($this->adminScolarite())
            ->postJson(route('admin.prevention-decrochage.alertes.suivis.creer', ['id' => $alerte->id]), ['mesure' => 'Entretien de suivi planifie.'])
            ->assertStatus(201);

        $this->actingAs($this->adminScolarite())
            ->getJson(route('admin.prevention-decrochage.alertes.suivis.index', ['id' => $alerte->id]))
            ->assertOk()->assertJsonCount(1, 'suivis'); // dossier distinct des résultats officiels
    }

    /* ── Isolation facultaire, module, données officielles ────────── */

    public function test_admin_faculte_bloque_sur_alerte_hors_perimetre(): void
    {
        $this->regle('presences', 'absents', 1);
        $etudiantA = $this->creerEtudiant($this->faculteA);
        $this->presence($etudiantA, 'absent', '2024-10-02');
        $this->rescan($this->adminScolarite());
        $alerte = AlerteDecrochage::sole();

        $adminB = $this->adminFaculte($this->faculteB);
        $this->actingAs($adminB)
            ->postJson(route('admin.prevention-decrochage.alertes.examiner', ['id' => $alerte->id]))
            ->assertStatus(403);
        $this->actingAs($adminB)
            ->postJson(route('admin.prevention-decrochage.alertes.cloturer', ['id' => $alerte->id]), ['motif_cloture' => 'x'])
            ->assertStatus(403);
        $this->actingAs($adminB)
            ->getJson(route('admin.prevention-decrochage.alertes.suivis.index', ['id' => $alerte->id]))
            ->assertStatus(403);
        $this->assertSame('generee', $alerte->refresh()->statut); // rien n'a changé
    }

    public function test_scan_et_index_ancres_sur_la_faculte_de_l_admin(): void
    {
        $this->regle('presences', 'absents', 1);
        $etudiantA = $this->creerEtudiant($this->faculteA);
        $etudiantB = $this->creerEtudiant($this->faculteB);
        $this->presence($etudiantA, 'absent', '2024-10-02');
        $this->presence($etudiantB, 'absent', '2024-10-02');

        // Admin faculté A : la faculte_id=B demandée explicitement est REFUSÉE
        // (403, même pattern que FacultePerimetre du Lot 14 — jamais d'ignore
        // silencieux).
        $this->rescan($this->adminFaculte($this->faculteA), ['faculte_id' => $this->faculteB->id])->assertStatus(403);

        // Sans paramètre : ancrage serveur sur sa propre faculté (A).
        $this->rescan($this->adminFaculte($this->faculteA))->assertOk();

        $ciblesEtudiant = AlerteDecrochage::pluck('etudiant_id')->unique();
        $this->assertSame(1, $ciblesEtudiant->count());
        $this->assertSame($etudiantA->id, (int) $ciblesEtudiant->sole()); // uniquement A

        $reponse = $this->indexAlertes($this->adminFaculte($this->faculteA))->assertOk();
        $this->assertCount(1, $reponse->json('alertes'));
    }

    public function test_filtre_statut_reduit_l_index(): void
    {
        $this->regle('presences', 'absents', 1);
        $etudiant = $this->creerEtudiant($this->faculteA);
        $this->presence($etudiant, 'absent', '2024-10-02');
        $this->rescan($this->adminScolarite());

        $alerte = AlerteDecrochage::sole();
        $this->actingAs($this->adminScolarite())
            ->postJson(route('admin.prevention-decrochage.alertes.examiner', ['id' => $alerte->id]))
            ->assertOk();

        $this->indexAlertes($this->adminScolarite(), ['statut' => 'examinee'])
            ->assertOk()
            ->assertJsonCount(1, 'alertes');
        $this->indexAlertes($this->adminScolarite(), ['statut' => 'generee'])
            ->assertOk()
            ->assertJsonCount(0, 'alertes');
    }

    public function test_agent_scolarite_peut_voir_mais_pas_declencher_scan(): void
    {
        $this->regle('presences', 'absents', 1);
        $agent = $this->agentScolarite();

        $this->rescan($agent)->assertStatus(403); // decrochage.traiter absent
        // La lecture reste possible (decrochage.view accordé à agent_scolarite).
        $this->indexAlertes($agent)->assertOk();
    }

    public function test_module_desactive_renvoie_404(): void
    {
        Config::set('modules.prevention-decrochage.active', false);

        $this->actingAs($this->adminScolarite())
            ->getJson(route('admin.prevention-decrochage.alertes.index'))
            ->assertStatus(404);
        $this->rescan($this->adminScolarite())->assertStatus(404);
    }

    public function test_donnees_officielles_non_modifiees_par_le_flux(): void
    {
        $this->regle('presences', 'absents', 1);
        $etudiant = $this->creerEtudiant($this->faculteA);
        $this->presence($etudiant, 'absent', '2024-10-02');
        $note = $this->note($etudiant, 7.5, $this->annee, $this->programmeA);

        $snapPresences = Presence::count();
        $snapNotes = Note::count();
        $snapNoteValeur = (string) $note->note;
        $snapNoteLocked = $note->locked_at;

        $this->rescan($this->adminScolarite())->assertOk();
        $alerte = AlerteDecrochage::sole();
        $this->actingAs($this->adminScolarite())
            ->postJson(route('admin.prevention-decrochage.alertes.examiner', ['id' => $alerte->id]))
            ->assertOk();
        $this->actingAs($this->adminScolarite())
            ->postJson(route('admin.prevention-decrochage.alertes.suivis.creer', ['id' => $alerte->id]), ['mesure' => 'Entretien planifie.'])
            ->assertStatus(201);

        // Aucune écriture officielle : ni notes, ni présences, ni verrous.
        $this->assertSame($snapPresences, Presence::count());
        $this->assertSame($snapNotes, Note::count());
        $this->assertSame($snapNoteValeur, (string) $note->refresh()->note);
        $this->assertNull($note->locked_at);
    }

    public function test_scan_synthetique_sous_trois_secondes(): void
    {
        $this->regle('presences', 'absents', 1);
        for ($i = 0; $i < 120; $i++) {
            $etudiant = $this->creerEtudiant($this->faculteA);
            $this->presence($etudiant, 'absent', '2024-10-0'.(($i % 9) + 1));
            $this->presence($etudiant, 'present', '2024-10-1'.(($i % 9) + 1));
        }

        $debut = microtime(true);
        $this->rescan($this->adminScolarite())->assertOk();
        $duree = microtime(true) - $debut;

        $this->assertLessThan(3.0, $duree, 'Scan synthétique trop lent : '.$duree.' s');
        $this->assertSame(120, AlerteDecrochage::count());
    }
}
