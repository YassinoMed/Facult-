<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\MensReference;
use App\Models\User;
use App\Modules\Evaluation\Models\DecisionDeliberation;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Referentiel\Models\Domaine;
use App\Modules\Referentiel\Models\Mention;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\Specialite;
use App\Services\IdentificationIntelligenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Inscription intelligente MENS : import contrôlé, identification
 * CIN+naissance (4 statuts), divergences sans écrasement, progression
 * officielle + spécialités référentiel, sécurité.
 */
class MensInscriptionIntelligenteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    private AnneeUniversitaire $anneeN;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faculte = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.mens@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');
        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2026-2027', 'date_debut' => '2026-09-01', 'date_fin' => '2027-06-30', 'active' => true,
        ]);
        $this->anneeN = AnneeUniversitaire::create([
            'libelle' => '2025-2026', 'date_debut' => '2025-09-01', 'date_fin' => '2026-06-30', 'active' => false,
        ]);
    }

    public function test_import_mens_preview_puis_confirmation(): void
    {
        $reponse = $this->actingAs($this->admin)
            ->post('/admin/donnees/referentiel-mens/import/preview', ['fichier' => $this->csvMens()])
            ->assertOk();

        $this->assertSame(0, MensReference::count());
        $token = $reponse->viewData('token');
        $this->assertNotEmpty($token);

        $this->actingAs($this->admin)
            ->post('/admin/donnees/referentiel-mens/import/confirmer', ['token' => $token, 'mode' => 'creer'])
            ->assertOk();

        $this->assertSame(2, MensReference::count());
        $this->assertDatabaseHas('import_reports', [
            'type' => 'data.referentiel-mens', 'status' => 'completed', 'valid_rows' => 2,
        ]);

        $reference = MensReference::where('cin_normalise', '12345678')->firstOrFail();
        $this->assertSame('12345678', $reference->cin_original);
        $this->assertSame(64, strlen((string) $reference->source_hash));
        $this->assertSame($this->admin->id, (int) $reference->imported_by);
    }

    public function test_import_invalide_et_reimport_idempotent(): void
    {
        // Ligne sans nom : rejetée en preview, rien d'écrit.
        $reponse = $this->actingAs($this->admin)
            ->post('/admin/donnees/referentiel-mens/import/preview', ['fichier' => $this->csvMensInvalide()])
            ->assertOk();

        $this->assertSame(0, MensReference::count());
        $this->assertNotEmpty($reponse->viewData('resultat'));

        // Import valide puis réimport upsert : pas de doublon.
        $preview = $this->actingAs($this->admin)
            ->post('/admin/donnees/referentiel-mens/import/preview', ['fichier' => $this->csvMens()])
            ->assertOk();
        $this->actingAs($this->admin)
            ->post('/admin/donnees/referentiel-mens/import/confirmer', ['token' => $preview->viewData('token'), 'mode' => 'creer'])
            ->assertOk();
        $this->assertSame(2, MensReference::count());

        $preview2 = $this->actingAs($this->admin)
            ->post('/admin/donnees/referentiel-mens/import/preview', ['fichier' => $this->csvMens()])
            ->assertOk();
        $this->actingAs($this->admin)
            ->post('/admin/donnees/referentiel-mens/import/confirmer', ['token' => $preview2->viewData('token'), 'mode' => 'upsert'])
            ->assertOk();
        $this->assertSame(2, MensReference::count());
    }

    public function test_identification_mens_seul_prefill_sans_dossier(): void
    {
        MensReference::create([
            'cin_normalise' => '80000001', 'cin_original' => '80000001', 'date_naissance' => '2004-03-15',
            'nom' => 'Benali', 'prenom' => 'Yassine', 'nom_ar' => 'بن علي', 'sexe' => 'M',
            'nationalite' => 'Tunisienne', 'matricule_national' => 'MAT-1',
            'annee' => '2026-2027', 'niveau' => 'L1',
            'source_hash' => str_repeat('a', 64),
        ]);

        $resultat = app(IdentificationIntelligenteService::class)->identifier('80000001', '2004-03-15');

        $this->assertSame('MENS_SEUL', $resultat['statut']);
        $this->assertSame('Benali', $resultat['prefill']['nom']);
        $this->assertSame('بن علي', $resultat['prefill']['nom_ar']);
        $this->assertSame([], $resultat['divergences']);
        $this->assertNull($resultat['progression']);
        $this->assertSame(0, Etudiant::count());
    }

    public function test_identification_inconnu_et_date_incorrecte_indistinguables(): void
    {
        $service = app(IdentificationIntelligenteService::class);

        $inconnu = $service->identifier('99999999', '2000-01-01');
        $this->assertSame('INCONNU', $inconnu['statut']);
        $this->assertSame('', $inconnu['prefill']['nom']);

        MensReference::create([
            'cin_normalise' => '80000002', 'date_naissance' => '2004-03-15',
            'nom' => 'X', 'prenom' => 'Y', 'annee' => '2026-2027', 'source_hash' => str_repeat('b', 64),
        ]);

        $mauvaiseDate = $service->identifier('80000002', '2000-01-01');
        $this->assertSame('INCONNU', $mauvaiseDate['statut']);
        $this->assertSame($inconnu['prefill'], $mauvaiseDate['prefill']);
    }

    public function test_identification_mens_et_local_avec_divergence(): void
    {
        MensReference::create([
            'cin_normalise' => '80000003', 'date_naissance' => '2004-03-15',
            'nom' => 'Mohamed Yassine', 'prenom' => 'Ali', 'annee' => '2026-2027', 'source_hash' => str_repeat('c', 64),
        ]);
        $local = $this->creerEtudiant('80000003', ['nom' => 'Mohamed Yassin']);

        $resultat = app(IdentificationIntelligenteService::class)->identifier('80000003', '2004-03-15');

        $this->assertSame('MENS_ET_LOCAL', $resultat['statut']);
        // Priorité locale (dossier validé), MENS intacte.
        $this->assertSame('Mohamed Yassin', $resultat['prefill']['nom']);
        $this->assertSame('Mohamed Yassine', MensReference::firstWhere('cin_normalise', '80000003')->nom);
        $this->assertSame('A_VERIFIER', $resultat['divergences'][0]['gravite']);
        $this->assertSame('SANS_HISTORIQUE', $resultat['progression']['situation']);
        $this->assertSame($local->num_inscription, $resultat['local']['num_inscription']);
    }

    public function test_progression_l1_l2_et_redoublement(): void
    {
        $programme = $this->programmeLicence();

        $admis = $this->creerEtudiant('80000004');
        $inscription = $this->inscrire($admis, 'L1', $programme->id);
        $this->decider($admis, $inscription, 'admis');

        $resultat = app(IdentificationIntelligenteService::class)->identifier('80000004', '2004-03-15');

        $this->assertSame('LOCAL_SEUL', $resultat['statut']);
        $this->assertSame('progresser', $resultat['progression']['situation']);
        $this->assertSame('L2', $resultat['progression']['niveau_propose']);

        $redouble = $this->creerEtudiant('80000005');
        $inscriptionBis = $this->inscrire($redouble, 'L2', $programme->id);
        $this->decider($redouble, $inscriptionBis, 'redouble');

        $resultatBis = app(IdentificationIntelligenteService::class)->identifier('80000005', '2004-03-15');

        $this->assertSame('redoubler', $resultatBis['progression']['situation']);
        $this->assertSame('L2', $resultatBis['progression']['niveau_propose']);
    }

    public function test_l3_sans_admission_master_bloque(): void
    {
        $programme = $this->programmeLicence();

        $etudiant = $this->creerEtudiant('80000006');
        $inscription = $this->inscrire($etudiant, 'L3', $programme->id);
        $this->decider($etudiant, $inscription, 'admis');

        $resultat = app(IdentificationIntelligenteService::class)->identifier('80000006', '2004-03-15');

        // Fin de cycle : jamais de M1 deviné.
        $this->assertSame('fin_cycle', $resultat['progression']['situation']);
        $this->assertNull($resultat['progression']['niveau_propose']);
        $this->assertSame([], $resultat['specialites']);
    }

    public function test_specialites_multiples_depuis_referentiel(): void
    {
        $domaine = Domaine::create(['faculte_id' => $this->faculte->id, 'code' => 'D1', 'nom' => 'Domaine', 'actif' => true]);
        $mention = Mention::create(['code' => 'M1', 'nom' => 'Mention', 'domaine_id' => $domaine->id, 'actif' => true]);
        $gestion = Specialite::create(['mention_id' => $mention->id, 'code' => 'GES', 'nom' => 'Gestion', 'actif' => true]);
        $finance = Specialite::create(['mention_id' => $mention->id, 'code' => 'FIN', 'nom' => 'Finance', 'actif' => true]);

        $programmeGestion = Programme::create([
            'faculte_id' => $this->faculte->id, 'specialite_id' => $gestion->id,
            'code' => 'GES', 'nom' => 'Gestion', 'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);
        $programmeFinance = Programme::create([
            'faculte_id' => $this->faculte->id, 'specialite_id' => $finance->id,
            'code' => 'FIN', 'nom' => 'Finance', 'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);

        foreach ([$programmeGestion, $programmeFinance] as $programme) {
            Niveau::create([
                'programme_id' => $programme->id, 'code' => 'L1', 'nom' => 'L1', 'ordre' => 1,
            ]);
            Niveau::create([
                'programme_id' => $programme->id, 'code' => 'L2', 'nom' => 'L2', 'ordre' => 2,
            ]);
        }

        $etudiant = $this->creerEtudiant('80000007', ['programme_id' => $programmeGestion->id]);
        $inscription = $this->inscrire($etudiant, 'L1', $programmeGestion->id);
        $this->decider($etudiant, $inscription, 'admis');

        $resultat = app(IdentificationIntelligenteService::class)->identifier('80000007', '2004-03-15');

        $this->assertSame('L2', $resultat['progression']['niveau_propose']);
        $codes = array_column($resultat['specialites'], 'code');
        $this->assertContains('GES', $codes);
        $this->assertContains('FIN', $codes);
    }

    public function test_endpoint_identifier_statuts_et_securite(): void
    {
        MensReference::create([
            'cin_normalise' => '80000008', 'date_naissance' => '2004-03-15',
            'nom' => 'Benali', 'prenom' => 'Sara', 'annee' => '2026-2027', 'source_hash' => str_repeat('d', 64),
        ]);

        $reponse = $this->postJson(route('inscription.identifier'), [
            'cin' => '80000008', 'date_naissance' => '2004-03-15',
        ])->assertOk()->assertJsonPath('status', 'MENS_FOUND');

        $corps = $reponse->getContent();
        $this->assertStringNotContainsString('password', strtolower($corps));
        $this->assertStringNotContainsString('hash', strtolower($corps));

        // Throttle strict : 6e appel refusé.
        for ($i = 0; $i < 4; $i++) {
            $this->postJson(route('inscription.identifier'), ['cin' => '80000008', 'date_naissance' => '2004-03-15']);
        }
        $this->postJson(route('inscription.identifier'), ['cin' => '80000008', 'date_naissance' => '2004-03-15'])
            ->assertStatus(429);
    }

    public function test_endpoint_existant_minimal_sans_pii(): void
    {
        $this->creerEtudiant('80000009');

        $reponse = $this->postJson(route('inscription.identifier'), [
            'cin' => '80000009', 'date_naissance' => '2004-03-15',
        ])->assertOk()->assertJsonPath('status', 'EXISTING_STUDENT');

        $corps = strtolower($reponse->getContent());
        $this->assertStringNotContainsString('benali', $corps);
        $this->assertStringNotContainsString('80000009@mens.test', $corps);
        $this->assertStringNotContainsString('adresse', $corps);
        $this->assertStringNotContainsString('matricule', $corps);
        $this->assertStringNotContainsString('formation', $corps);

        $this->postJson(route('inscription.identifier'), [
            'cin' => '99999999', 'date_naissance' => '2000-01-01',
        ])->assertOk()->assertJsonPath('status', 'UNKNOWN');
    }

    public function test_masquage_email_regle_metier(): void
    {
        $cas = [
            'med.yassine@proton.me' => 'med******@proton.me',
            'mohamed@proton.me' => 'moh******@proton.me',
            'ab@proton.me' => 'ab******@proton.me',
        ];

        foreach ($cas as $clair => $masque) {
            $this->assertSame($masque, (new Etudiant(['email' => $clair]))->email_masque);
        }
    }

    public function test_endpoint_existant_retourne_masque_uniquement(): void
    {
        $this->creerEtudiant('80000010');

        $this->postJson(route('inscription.identifier'), [
            'cin' => '80000010', 'date_naissance' => '2004-03-15',
        ])->assertOk()
            ->assertJsonPath('status', 'EXISTING_STUDENT')
            ->assertJsonPath('masked_email', '800******@mens.test')
            ->assertJsonMissing(['email' => '80000010@mens.test']);

        $this->assertStringNotContainsString(
            '80000010@mens.test',
            strtolower($this->postJson(route('inscription.identifier'), [
                'cin' => '80000010', 'date_naissance' => '2004-03-15',
            ])->getContent())
        );
    }

    private function csvMens(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'mens.csv',
            "cin;date_naissance;nom;prenom;annee;niveau\n12345678;2004-03-15;Benali;Mehdi;2026-2027;L1\n12345679;2003-05-17;Alami;Sara;2026-2027;L2\n",
            'text/csv'
        );
    }

    private function csvMensInvalide(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'mens.csv',
            "cin;date_naissance;nom;prenom;annee\n99999999;2004-03-15;;Mehdi;2026-2027\n",
            'text/csv'
        );
    }

    private function programmeLicence(): Programme
    {
        $programme = Programme::create([
            'faculte_id' => $this->faculte->id, 'code' => 'LIC', 'nom' => 'Licence',
            'duree_annees' => 3, 'semestres_nombre' => 6, 'credits_requis' => 180,
        ]);

        foreach (['L1' => 1, 'L2' => 2, 'L3' => 3] as $code => $ordre) {
            Niveau::create([
                'programme_id' => $programme->id, 'code' => $code, 'nom' => $code, 'ordre' => $ordre,
            ]);
        }

        return $programme;
    }

    /** @param array<string, mixed> $attributs */
    private function creerEtudiant(string $cin, array $attributs = []): Etudiant
    {
        $etudiant = Etudiant::create(array_merge([
            'cin' => $cin, 'nom' => 'Benali', 'prenom' => 'Sara',
            'email' => strtolower($cin).'@mens.test',
            'date_naissance' => '2004-03-15', 'sexe' => 'F', 'faculte_id' => $this->faculte->id,
            'niveau' => 'L1', 'annee_academique' => '2026-2027', 'adresse' => 'X', 'ville' => 'Casa',
            'code_postal' => '20000', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ], $attributs));
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    private function inscrire(Etudiant $etudiant, string $niveau, int $programmeId): InscriptionAcademique
    {
        $niveauRef = Niveau::where('programme_id', $programmeId)
            ->where('code', $niveau)->firstOrFail();

        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->anneeN->id,
            'programme_id' => $programmeId,
            'niveau_id' => $niveauRef->id,
            'niveau' => $niveau,
            'statut' => 'en_cours',
        ]);
        $inscription->semestres()->create(['numero' => 1]);
        $inscription->semestres()->create(['numero' => 2]);

        return $inscription;
    }

    private function decider(Etudiant $etudiant, InscriptionAcademique $inscription, string $decision): void
    {
        $deliberation = Deliberation::create([
            'annee_universitaire_id' => $this->anneeN->id,
            'statut' => 'published',
            'created_by' => $this->admin->id,
            'published_at' => now(),
        ]);
        DecisionDeliberation::create([
            'deliberation_id' => $deliberation->id,
            'etudiant_id' => $etudiant->id,
            'inscription_academique_id' => $inscription->id,
            'moyenne' => 12.0,
            'credits_obtenus' => 30,
            'credits_total' => 30,
            'decision' => $decision,
            'publie' => true,
        ]);
    }
}
