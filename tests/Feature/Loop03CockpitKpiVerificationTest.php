<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\Matiere;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\Evaluation\Models\Examen;
use App\Modules\Evaluation\Models\NoteEpreuve;
use App\Modules\Evaluation\Models\SessionExamen;
use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestType;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\SemestreLmd;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Modules\Tasks\Models\Task;
use App\Services\BIService;
use App\Services\Kpi\CockpitKpiService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * LOOP-03 — vérification indépendante du cockpit KPI scolarité.
 *
 * Ce fichier est volontairement distinct de `CockpitKpiServiceTest` et des
 * deux rapports LOOP-03 préexistants : il prouve les défauts candidats par
 * des tests négatifs avant correction, puis sert de non-régression ciblée.
 */
class Loop03CockpitKpiVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculteA;

    private Faculte $faculteB;

    private AnneeUniversitaire $annee;

    private User $adminA;

    private int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->faculteA = Faculte::create([
            'nom' => 'Faculté Vérification A',
            'code' => 'FAC-VA',
            'description' => 'Vérification LOOP-03 A',
            'active' => true,
        ]);

        $this->faculteB = Faculte::create([
            'nom' => 'Faculté Vérification B',
            'code' => 'FAC-VB',
            'description' => 'Vérification LOOP-03 B',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027 Vérification',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        $this->adminA = User::creerCompteBackOffice([
            'name' => 'Admin Vérification A',
            'email' => 'admin.verification.a@universite.test',
            'password' => 'MotDePasse123!',
            'faculte_id' => $this->faculteA->id,
        ], 'admin_faculte');
    }

    public function test_mediane_paire_du_cockpit_est_la_moyenne_des_deux_valeurs_centrales(): void
    {
        // n = 6 (>= 5) : valeurs triées 10, 11, 12, 13, 14, 15.
        // Médiane attendue = (12 + 13) / 2 = 12,5 — pas la 3e valeur (12).
        foreach ([10.0, 11.0, 12.0, 13.0, 14.0, 15.0] as $index => $moyenne) {
            $etudiant = $this->creerEtudiant('CIN-MED-'.$index, $this->faculteA);
            $this->creerInscription($etudiant, $moyenne);
        }

        $snapshot = app(CockpitKpiService::class)
            ->calculerSnapshot($this->annee->id, $this->faculteA->id, null, null, true);

        $this->assertSame(12.5, $snapshot->get('moyenne_mediane')->valeur);
    }

    public function test_mediane_paire_du_service_bi_est_la_moyenne_des_deux_valeurs_centrales(): void
    {
        foreach ([10.0, 11.0, 12.0, 13.0, 14.0, 15.0] as $index => $moyenne) {
            $etudiant = $this->creerEtudiant('CIN-BI-MED-'.$index, $this->faculteA);
            $this->creerInscription($etudiant, $moyenne);
        }

        $indicateurs = app(BIService::class)->indicateurs($this->annee->id, $this->faculteA->id);

        $this->assertSame(12.5, $indicateurs['moyenne_mediane']['valeur']);
    }

    public function test_cache_cockpit_est_invalide_apres_creation_dune_demande_en_ligne(): void
    {
        $etudiant = $this->creerEtudiant('CIN-CACHE-DEMANDE', $this->faculteA);
        $type = RequestType::create([
            'code' => 'TYPE-VERIF-LOOP03',
            'name' => 'Type vérification LOOP-03',
            'active' => true,
            'processing_days' => 1,
        ]);

        $service = app(CockpitKpiService::class);

        // Amorce le cache avec une demande en retard = 0.
        $avant = $service->calculerSnapshot($this->annee->id, $this->faculteA->id, null, null, true);
        $this->assertSame(0, $avant->get('demandes_en_retard')->valeur);

        // Nouvelle demande soumise il y a 3 jours, délai de traitement 1 jour :
        // elle est en retard et doit invalider le cache du cockpit.
        $demande = OnlineRequest::create([
            'reference' => 'REQ-VERIF-LOOP03-1',
            'etudiant_id' => $etudiant->id,
            'request_type_id' => $type->id,
            'annee_universitaire_id' => $this->annee->id,
            'objet' => 'Demande en retard de vérification',
        ]);
        $demande->forceFill([
            'status' => 'submitted',
            'faculte_id' => $this->faculteA->id,
            'submitted_at' => now()->subDays(3),
        ])->save();

        $apres = $service->calculerSnapshot($this->annee->id, $this->faculteA->id, null, null, false);

        $this->assertSame(1, $apres->get('demandes_en_retard')->valeur);
    }

    public function test_cache_cockpit_est_invalide_apres_creation_dune_epreuve_et_dune_note(): void
    {
        $etudiant = $this->creerEtudiant('CIN-CACHE-EPREUVE', $this->faculteA);

        $service = app(CockpitKpiService::class);

        // Amorce le cache avant l'épreuve et la note.
        $avant = $service->calculerSnapshot($this->annee->id, $this->faculteA->id, null, null, true);
        $this->assertSame(0, $avant->get('epreuves_sans_salle')->valeur);
        $this->assertSame(0, $avant->get('notes_en_attente_validation')->valeur);

        // L'épreuve elle-même est créée après l'amorçage du cache : elle doit
        // changer l'empreinte de version et forcer un recalcul.
        $epreuve = $this->creerEpreuveSansSalle($this->faculteA);
        $apresEpreuve = $service->calculerSnapshot($this->annee->id, $this->faculteA->id, null, null, false);
        $this->assertSame(1, $apresEpreuve->get('epreuves_sans_salle')->valeur);

        // Une note soumise doit également invalider le cache.
        NoteEpreuve::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $etudiant->id,
            'valeur' => 10,
            'note_max' => 20,
            'statut' => 'submitted',
        ]);

        $apresNote = $service->calculerSnapshot($this->annee->id, $this->faculteA->id, null, null, false);
        $this->assertSame(1, $apresNote->get('notes_en_attente_validation')->valeur);
    }

    public function test_anti_idor_http_json_et_csv_ignorent_la_faculte_demandee(): void
    {
        // Faculté A : 2 étudiants. Faculté B : 7 étudiants.
        for ($i = 0; $i < 2; $i++) {
            $this->creerEtudiant('CIN-IDOR-A-'.$i, $this->faculteA);
        }
        for ($i = 0; $i < 7; $i++) {
            $this->creerEtudiant('CIN-IDOR-B-'.$i, $this->faculteB);
        }

        $json = $this->actingAs($this->adminA)
            ->getJson(route('admin.cockpit.kpi', ['faculte_id' => $this->faculteB->id]))
            ->assertOk();

        $this->assertSame($this->faculteA->id, $json->json('perimetre.faculte_id'));
        $this->assertSame(2, $json->json('indicateurs.effectif_total.valeur'));

        $csv = $this->actingAs($this->adminA)
            ->get(route('admin.cockpit.kpi.export', ['faculte_id' => $this->faculteB->id]))
            ->assertOk();

        $lignes = preg_split("/\r\n|\n|\r/", $csv->streamedContent()) ?: [];
        $ligneEffectif = collect($lignes)->first(
            fn (string $ligne) => str_starts_with($ligne, 'effectif_total;')
        );

        $this->assertNotNull($ligneEffectif);
        $colonnes = str_getcsv($ligneEffectif, ';');
        $this->assertSame('2', $colonnes[3]);

        // Périmètre programme d'une autre faculté : aucune donnée de B ne
        // doit fuiter même lorsque les deux paramètres sont combinés.
        $programmeB = Programme::create([
            'faculte_id' => $this->faculteB->id,
            'code' => 'PRG-IDOR-B',
            'nom' => 'Programme IDOR B',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'type' => 'licence',
            'statut' => 'actif',
        ]);

        $jsonProgramme = $this->actingAs($this->adminA)
            ->getJson(route('admin.cockpit.kpi', [
                'faculte_id' => $this->faculteB->id,
                'programme_id' => $programmeB->id,
            ]))
            ->assertOk();

        $this->assertSame($this->faculteA->id, $jsonProgramme->json('perimetre.faculte_id'));
        $this->assertSame(0, $jsonProgramme->json('indicateurs.effectif_total.valeur'));
    }

    public function test_la_fraicheur_est_bornee_au_perimetre_et_non_globale(): void
    {
        $etudiantA = $this->creerEtudiant('CIN-FRAICHEUR-A', $this->faculteA);
        $inscriptionA = $this->creerInscription($etudiantA, 12.0);
        $inscriptionA->forceFill(['updated_at' => now()->subDays(3)])->save();

        $etudiantB = $this->creerEtudiant('CIN-FRAICHEUR-B', $this->faculteB);
        $inscriptionB = $this->creerInscription($etudiantB, 14.0);
        $inscriptionB->forceFill(['updated_at' => now()])->save();

        $snapshot = app(CockpitKpiService::class)
            ->calculerSnapshot($this->annee->id, $this->faculteA->id, null, null, true);

        $fraicheur = $snapshot->get('inscriptions_validees')->derniereMaj;

        $this->assertNotNull($fraicheur);
        $this->assertTrue(
            Carbon::parse($fraicheur)->lessThan(now()->subDay()),
            'La fraîcheur du KPI doit refléter la faculté A, pas la mise à jour récente de la faculté B.'
        );
    }

    public function test_les_filtres_annee_et_programme_sappliquent_aux_epreuves_et_aux_notes(): void
    {
        $epreuve = $this->creerEpreuveSansSalle($this->faculteA);

        $service = app(CockpitKpiService::class);

        $dansAnnee = $service->calculerSnapshot($this->annee->id, $this->faculteA->id, null, null, true);
        $this->assertSame(1, $dansAnnee->get('epreuves_sans_salle')->valeur);

        $autreAnnee = AnneeUniversitaire::create([
            'libelle' => '2025-2026 Vérification',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => false,
        ]);

        $horsAnnee = $service->calculerSnapshot($autreAnnee->id, $this->faculteA->id, null, null, true);
        $this->assertSame(0, $horsAnnee->get('epreuves_sans_salle')->valeur);

        $programmeEtranger = Programme::create([
            'faculte_id' => $this->faculteA->id,
            'code' => 'PRG-FILTRE-ETRANGER',
            'nom' => 'Programme filtre étranger',
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'type' => 'licence',
            'statut' => 'actif',
        ]);

        $horsProgramme = $service->calculerSnapshot(
            $this->annee->id,
            $this->faculteA->id,
            $programmeEtranger->id,
            null,
            true
        );
        $this->assertSame(0, $horsProgramme->get('epreuves_sans_salle')->valeur);

        NoteEpreuve::create([
            'epreuve_id' => $epreuve->id,
            'etudiant_id' => $this->creerEtudiant('CIN-NOTE-FILTRE', $this->faculteA)->id,
            'valeur' => 11,
            'note_max' => 20,
            'statut' => 'submitted',
        ]);

        $noteDansPerimetre = $service->calculerSnapshot($this->annee->id, $this->faculteA->id, null, null, true);
        $this->assertSame(1, $noteDansPerimetre->get('notes_en_attente_validation')->valeur);

        $noteHorsAnnee = $service->calculerSnapshot($autreAnnee->id, $this->faculteA->id, null, null, true);
        $this->assertSame(0, $noteHorsAnnee->get('notes_en_attente_validation')->valeur);
    }

    public function test_le_drill_down_ne_retombe_jamais_sur_les_inscriptions_pour_un_kpi_non_mappe(): void
    {
        $service = app(CockpitKpiService::class);

        $this->assertInstanceOf(Task::class, $service->donneesSources('taches_en_retard_sla')->getModel());
        $this->assertInstanceOf(NoteEpreuve::class, $service->donneesSources('notes_en_attente_validation')->getModel());
        $this->assertInstanceOf(DecisionDeliberation::class, $service->donneesSources('taux_reussite_deliberations')->getModel());
        $this->assertInstanceOf(Epreuve::class, $service->donneesSources('epreuves_sans_surveillant')->getModel());

        $this->expectException(\InvalidArgumentException::class);
        $service->donneesSources('indicateur_totalement_inconnu');
    }

    public function test_la_version_bi_change_apres_creation_dune_demande_et_apres_modification_sans_timestamp(): void
    {
        $etudiant = $this->creerEtudiant('CIN-VERSION-BI', $this->faculteA);
        $inscription = $this->creerInscription($etudiant, 10.0);

        $versionInitiale = app(BIService::class)->version();

        $type = RequestType::create([
            'code' => 'TYPE-VERSION-BI',
            'name' => 'Type version BI',
            'active' => true,
            'processing_days' => 1,
        ]);

        OnlineRequest::create([
            'reference' => 'REQ-VERSION-BI-1',
            'etudiant_id' => $etudiant->id,
            'request_type_id' => $type->id,
            'annee_universitaire_id' => $this->annee->id,
            'objet' => 'Demande version BI',
        ]);

        $this->assertNotSame($versionInitiale, app(BIService::class)->version());

        $empreinteAvant = app(CockpitKpiService::class)->versionEmpreinte();

        // Mise à jour d'une ligne ancienne SANS déplacer updated_at :
        // seule l'incrémentation événementielle peut la détecter.
        $inscription->timestamps = false;
        $inscription->update(['credits_obtenus' => 29]);

        $this->assertNotSame($empreinteAvant, app(CockpitKpiService::class)->versionEmpreinte());
    }

    public function test_les_filtres_annee_et_programme_sont_expliques_pour_les_taches_transversales(): void
    {
        $snapshot = app(CockpitKpiService::class)
            ->calculerSnapshot($this->annee->id, $this->faculteA->id, null, null, true);

        $explication = (string) $snapshot->get('taches_critiques_ouvertes')->explication;

        $this->assertStringContainsString('transversal', $explication);
    }

    public function test_acces_cockpit_refuse_sans_permission(): void
    {
        $this->actingAs($this->adminA);

        // Simule un compte back-office valide mais dépourvu de la permission
        // `cockpit.view` : les trois points d'entrée doivent rester fermés.
        Gate::define('cockpit.view', fn () => false);

        $this->get(route('admin.cockpit.index'))->assertForbidden();
        $this->getJson(route('admin.cockpit.kpi'))->assertForbidden();
        $this->get(route('admin.cockpit.kpi.export'))->assertForbidden();
    }

    private function creerEtudiant(string $cin, Faculte $faculte): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Nom'.$cin,
            'prenom' => 'Prenom'.$cin,
            'email' => strtolower($cin).'@universite.test',
            'sexe' => 'M',
            'faculte_id' => $faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'Campus',
            'ville' => 'Casablanca',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'MotDePasse123!',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-'.$cin])->save();
        $etudiant->definirStatut('valide');

        return $etudiant->fresh();
    }

    private function creerInscription(Etudiant $etudiant, float $moyenne): InscriptionAcademique
    {
        return InscriptionAcademique::create([
            'etudiant_id' => $etudiant->id,
            'annee_universitaire_id' => $this->annee->id,
            'niveau' => 'L1',
            'statut' => 'valide',
            'credits_obtenus' => 30,
            'credits_total' => 30,
            'moyenne_annuelle' => $moyenne,
        ]);
    }

    private function creerEpreuveSansSalle(Faculte $faculte): Epreuve
    {
        $this->compteur++;
        $suffixe = $this->compteur.'-'.random_int(1000, 9999);

        $programme = Programme::create([
            'faculte_id' => $faculte->id,
            'code' => 'PRG-VERIF-'.$suffixe,
            'nom' => 'Programme vérification '.$suffixe,
            'duree_annees' => 3,
            'semestres_nombre' => 6,
            'credits_requis' => 180,
            'type' => 'licence',
            'statut' => 'actif',
        ]);

        $niveau = Niveau::create([
            'programme_id' => $programme->id,
            'code' => 'L1',
            'nom' => 'L1 vérification '.$suffixe,
            'ordre' => 1,
        ]);

        $semestre = SemestreLmd::create([
            'niveau_id' => $niveau->id,
            'code' => 'S1',
            'nom' => 'S1 vérification '.$suffixe,
            'numero' => 1,
            'ordre' => 1,
        ]);

        $ue = UniteEnseignement::create([
            'semestre_id' => $semestre->id,
            'programme_id' => $programme->id,
            'code' => 'UE-VERIF-'.$suffixe,
            'nom' => 'UE vérification '.$suffixe,
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $matiere = Matiere::create([
            'code' => 'MAT-VERIF-'.$suffixe,
            'nom' => 'Matière vérification '.$suffixe,
            'ue_id' => $ue->id,
            'niveau' => 'L1',
            'semestre' => 1,
            'credits' => 6,
            'coefficient' => 2,
        ]);

        $session = SessionExamen::create([
            'annee_universitaire_id' => $this->annee->id,
            'code' => 'SESS-VERIF-'.$suffixe,
            'nom' => 'Session vérification '.$suffixe,
            'type' => 'principale',
            'date_debut' => '2027-01-05',
            'date_fin' => '2027-01-20',
            'statut' => 'open',
        ]);

        $examen = Examen::create([
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'libelle' => 'Examen vérification '.$suffixe,
            'type' => 'examen',
            'statut' => 'scheduled',
        ]);

        return Epreuve::create([
            'examen_id' => $examen->id,
            'session_id' => $session->id,
            'annee_universitaire_id' => $this->annee->id,
            'matiere_id' => $matiere->id,
            'groupe_id' => null,
            'salle_id' => null,
            'date' => '2027-01-10',
            'heure_debut' => '09:00',
            'heure_fin' => '11:00',
            'statut' => 'scheduled',
        ]);
    }
}
