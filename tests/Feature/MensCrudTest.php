<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\HistoriqueAcademique;
use App\Models\MensReference;
use App\Models\User;
use App\Modules\Referentiel\Models\Programme;
use App\Services\DataExchange\DataImportService;
use App\Services\IdentificationIntelligenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * LOOP-MENS-ADMIN-CRUD-01 : manipulation directe du référentiel MENS
 * (ajout, consultation, correction contrôlée, suppression protégée).
 * Mêmes permissions (data.import) et même périmètre facultaire que
 * l'index ; import idempotent et identification fail-closed intacts.
 */
class MensCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $adminFaculte;

    private User $agent;

    private Faculte $fst;

    private Faculte $fmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fst = Faculte::create(['nom' => 'FST', 'code' => 'FST', 'description' => 'T', 'active' => true]);
        $this->fmp = Faculte::create(['nom' => 'FMP', 'code' => 'FMP', 'description' => 'M', 'active' => true]);

        $this->admin = User::creerCompteBackOffice([
            'name' => 'Scolarité', 'email' => 'sco.crud@universite.test', 'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->adminFaculte = User::creerCompteBackOffice([
            'name' => 'Faculté', 'email' => 'fac.crud@universite.test', 'password' => 'Motdepasse@123',
            'faculte_id' => $this->fst->id,
        ], 'admin_faculte');

        $this->agent = User::creerCompteBackOffice([
            'name' => 'Agent', 'email' => 'agent.crud@universite.test', 'password' => 'Motdepasse@123',
        ], 'agent_scolarite');

        Programme::create(['faculte_id' => $this->fst->id, 'code' => 'LMI', 'nom' => 'Licence MI']);
        Programme::create(['faculte_id' => $this->fmp->id, 'code' => 'MED', 'nom' => 'Médecine']);
    }

    private function creerReference(array $remplacements = []): MensReference
    {
        return MensReference::create(array_merge([
            'cin_normalise' => '11223344',
            'cin_original' => '11223344',
            'date_naissance' => '2004-01-15',
            'sexe' => 'F',
            'nom' => 'Test',
            'prenom' => 'Référence',
            'annee' => '2025-2026',
            'programme_code' => 'LMI',
            'source_hash' => hash('sha256', 'graine-test'),
        ], $remplacements));
    }

    private function creerEtudiant(string $cin, int $faculteId, string $nom = 'Local', string $dateNaissance = '2004-01-15'): Etudiant
    {
        static $compteur = 0;
        $compteur++;

        return Etudiant::create([
            'cin' => $cin, 'nom' => $nom, 'prenom' => 'Dossier',
            'email' => "etudiant{$compteur}.crud@universite.test",
            'date_naissance' => $dateNaissance, 'sexe' => 'F', 'faculte_id' => $faculteId,
            'niveau' => 'L1', 'annee_academique' => '2025-2026', 'adresse' => 'X',
            'ville' => 'V', 'code_postal' => '1', 'annee_bac' => '2024', 'password' => 'Etudiant@123',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function donneesValides(array $remplacements = []): array
    {
        return array_merge([
            'cin' => '55667788',
            'date_naissance' => '2003-05-05',
            'nom' => 'Nouvelle',
            'prenom' => 'Référence',
            'sexe' => 'F',
            'annee' => '2025-2026',
            'programme_code' => 'LMI',
        ], $remplacements);
    }

    public function test_ajout_valide_avec_journal_sans_pii(): void
    {
        $reponse = $this->actingAs($this->admin)->post(route('admin.mens.store'), $this->donneesValides());

        $reference = MensReference::where('cin_normalise', '55667788')->firstOrFail();

        $reponse->assertSessionHasNoErrors()->assertRedirect(route('admin.mens.show', $reference));

        $this->assertSame('2025-2026', $reference->annee);
        $this->assertSame('55667788', $reference->cin_original);
        $this->assertSame('LMI', $reference->programme_code);
        $this->assertNull($reference->imported_by);
        $this->assertNull($reference->imported_at);
        $this->assertSame(64, mb_strlen((string) $reference->source_hash));

        $trace = HistoriqueAcademique::where('action', HistoriqueAcademique::ACTION_MENS_CREATION)->latest('id')->firstOrFail();
        $this->assertSame('mens_reference', $trace->entite);
        $this->assertSame($reference->id, $trace->entite_id);
        $this->assertSame($this->admin->id, $trace->utilisateur_id);

        $payload = (string) $trace->nouvelle_valeur;
        $this->assertStringContainsString('55***788', $payload);
        $this->assertStringNotContainsString('55667788', $payload);
        $this->assertStringNotContainsString('Nouvelle', $payload);
        $this->assertStringNotContainsString('2003-05-05', $payload);
    }

    public function test_ajout_champs_invalides_refuse(): void
    {
        $this->actingAs($this->admin)->post(route('admin.mens.store'), [])
            ->assertSessionHasErrors(['cin', 'nom', 'prenom', 'annee']);

        $this->actingAs($this->admin)->post(route('admin.mens.store'), $this->donneesValides(['cin' => 'ABC123']))
            ->assertSessionHasErrors('cin');

        $this->actingAs($this->admin)->post(route('admin.mens.store'), $this->donneesValides(['cin' => '1234 5678']))
            ->assertSessionHasErrors('cin');

        $this->actingAs($this->admin)->post(route('admin.mens.store'), $this->donneesValides(['date_naissance' => '2004-13-40']))
            ->assertSessionHasErrors('date_naissance');

        $this->actingAs($this->admin)->post(route('admin.mens.store'), $this->donneesValides(['sexe' => 'X']))
            ->assertSessionHasErrors('sexe');

        $this->assertSame(0, MensReference::count());
    }

    public function test_doublon_cin_annee_refuse(): void
    {
        $this->creerReference();

        $this->actingAs($this->admin)->post(route('admin.mens.store'), $this->donneesValides(['cin' => '11223344']))
            ->assertSessionHasErrors('cin');

        $this->assertSame(1, MensReference::count());

        // Même CIN, autre année → autorisé (la clé est CIN + année).
        $this->actingAs($this->admin)->post(route('admin.mens.store'), $this->donneesValides(['cin' => '11223344', 'annee' => '2024-2025']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, MensReference::count());
    }

    public function test_correction_conserve_unicite_et_journalise(): void
    {
        $referenceA = $this->creerReference();
        $referenceB = $this->creerReference(['cin_normalise' => '55667788', 'cin_original' => '55667788']);

        // Collision avec une autre référence → refus, état inchangé.
        $this->actingAs($this->admin)->put(route('admin.mens.update', $referenceB), $this->donneesValides(['cin' => '11223344']))
            ->assertSessionHasErrors('cin');

        $this->assertSame('55667788', $referenceB->fresh()->cin_normalise);

        // Clé conservée (la référence s'exclut elle-même) + correction de nom → accepté + journal.
        $this->actingAs($this->admin)->put(route('admin.mens.update', $referenceB), $this->donneesValides([
            'cin' => '55667788',
            'date_naissance' => '2004-01-15',
            'nom' => 'Corrigée',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Corrigée', $referenceB->fresh()->nom);

        $trace = HistoriqueAcademique::where('action', HistoriqueAcademique::ACTION_MENS_MODIFICATION)->latest('id')->firstOrFail();
        $payload = (string) $trace->nouvelle_valeur;
        $this->assertStringContainsString('"champs_modifies":["nom"]', $payload);
        $this->assertStringContainsString('55***788', $payload);
        $this->assertStringNotContainsString('55667788', $payload);
        $this->assertStringNotContainsString('Corrigée', $payload);

        // Correction sans changement → aucune écriture, aucun journal.
        $this->actingAs($this->admin)->put(route('admin.mens.update', $referenceB), $this->donneesValides([
            'cin' => '55667788',
            'date_naissance' => '2004-01-15',
            'nom' => 'Corrigée',
        ]))->assertSessionHas('info');

        $this->assertSame(1, HistoriqueAcademique::where('action', HistoriqueAcademique::ACTION_MENS_MODIFICATION)->count());

        // Correction de la clé (nouvelle année) : autorisée, unicité conservée.
        $this->actingAs($this->admin)->put(route('admin.mens.update', $referenceB), $this->donneesValides([
            'cin' => '55667788',
            'date_naissance' => '2004-01-15',
            'nom' => 'Corrigée',
            'annee' => '2024-2025',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('2024-2025', $referenceB->fresh()->annee);
        $this->assertSame(1, MensReference::where('cin_normalise', '55667788')->where('annee', '2024-2025')->count());
        $this->assertSame(0, MensReference::where('cin_normalise', '55667788')->where('annee', '2025-2026')->count());
        $this->assertDatabaseHas('mens_references', ['id' => $referenceA->id]);
    }

    public function test_consultation_details(): void
    {
        $reference = $this->creerReference(['nom' => 'Visible', 'prenom' => 'Détail']);

        $this->actingAs($this->admin)->get(route('admin.mens.show', $reference))
            ->assertOk()
            ->assertSee('Visible', false)
            ->assertSee('11223344', false)
            ->assertSee('2025-2026', false)
            ->assertSee(route('admin.mens.edit', $reference), false);

        // Formulaires d'ajout et de correction rendus (mêmes composants UI).
        $this->actingAs($this->admin)->get(route('admin.mens.create'))
            ->assertOk()
            ->assertSee('Ajouter une référence MENS', false);

        $this->actingAs($this->admin)->get(route('admin.mens.edit', $reference))
            ->assertOk()
            ->assertSee('11223344', false);
    }

    public function test_suppression_autorisee_et_journalisee(): void
    {
        $reference = $this->creerReference(['cin_normalise' => '66554433', 'cin_original' => '66554433']);

        $this->actingAs($this->admin)->delete(route('admin.mens.destroy', $reference))
            ->assertRedirect(route('admin.mens.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('mens_references', ['id' => $reference->id]);

        $trace = HistoriqueAcademique::where('action', HistoriqueAcademique::ACTION_MENS_SUPPRESSION)->latest('id')->firstOrFail();
        $this->assertNull($trace->nouvelle_valeur);
        $this->assertStringContainsString('66***433', (string) $trace->ancienne_valeur);
        $this->assertStringNotContainsString('66554433', (string) $trace->ancienne_valeur);
    }

    public function test_suppression_bloquee_par_dossier_local(): void
    {
        $this->creerEtudiant('11223344', $this->fst->id);
        $reference = $this->creerReference();

        $this->actingAs($this->admin)
            ->from(route('admin.mens.show', $reference))
            ->delete(route('admin.mens.destroy', $reference))
            ->assertRedirect(route('admin.mens.show', $reference))
            ->assertSessionHasErrors('reference');

        $this->assertDatabaseHas('mens_references', ['id' => $reference->id]);
        $this->assertSame(0, HistoriqueAcademique::where('action', HistoriqueAcademique::ACTION_MENS_SUPPRESSION)->count());
    }

    public function test_suppression_bloquee_par_trace_historique(): void
    {
        $reference = $this->creerReference();

        HistoriqueAcademique::create([
            'action' => 'inscription.dossier_cree_mens',
            'entite' => 'student_dossier',
            'entite_id' => 1,
            'nouvelle_valeur' => json_encode(['reference' => 'DOS-0001', 'mens_id' => $reference->id, 'annee_mens' => $reference->annee], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);

        $this->actingAs($this->admin)->delete(route('admin.mens.destroy', $reference))
            ->assertSessionHasErrors('reference');

        $this->assertDatabaseHas('mens_references', ['id' => $reference->id]);

        // Contrôle négatif : une trace visant une AUTRE référence ne bloque pas.
        $autre = $this->creerReference(['cin_normalise' => '77889900', 'cin_original' => '77889900']);

        $this->actingAs($this->admin)->delete(route('admin.mens.destroy', $autre))
            ->assertRedirect(route('admin.mens.index'));

        $this->assertDatabaseMissing('mens_references', ['id' => $autre->id]);
    }

    public function test_recherche_filtres_pagination(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->creerReference([
                'cin_normalise' => str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'cin_original' => str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'nom' => 'Rang'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'prenom' => 'Mens',
            ]);
        }

        // Pagination : 25/page, la 30ᵉ référence n'apparaît qu'en page 2.
        $contenuPageDeux = (string) $this->actingAs($this->admin)
            ->get(route('admin.mens.index', ['page' => 2]))->assertOk()
            ->assertSee('Ajouter une référence', false)
            ->getContent();
        $this->assertStringContainsString('Rang30', $contenuPageDeux);
        $this->assertStringNotContainsString('Rang01', $contenuPageDeux);

        // Recherche CIN (nom/prénom/CIN).
        $this->actingAs($this->admin)->get(route('admin.mens.index', ['q' => '00000007']))
            ->assertOk()->assertSee('Rang07', false);

        // Filtre année : aucune correspondance → état vide explicite.
        $this->actingAs($this->admin)->get(route('admin.mens.index', ['annee' => '2024-2025']))
            ->assertOk()
            ->assertDontSee('Rang01', false)
            ->assertSee('Aucune référence MENS', false);

        // Filtre « dossier local » (statut calculé — aucun statut en schéma).
        $this->creerEtudiant('00000001', $this->fst->id);

        $this->actingAs($this->admin)->get(route('admin.mens.index', ['appariement' => 'apparies']))
            ->assertOk()
            ->assertSee('Rang01', false)
            ->assertDontSee('Rang02', false);
    }

    public function test_non_autorise_403(): void
    {
        $reference = $this->creerReference();

        $this->actingAs($this->agent)->get(route('admin.mens.create'))->assertForbidden();
        $this->actingAs($this->agent)->post(route('admin.mens.store'), $this->donneesValides())->assertForbidden();
        $this->actingAs($this->agent)->get(route('admin.mens.show', $reference))->assertForbidden();
        $this->actingAs($this->agent)->put(route('admin.mens.update', $reference), $this->donneesValides())->assertForbidden();
        $this->actingAs($this->agent)->delete(route('admin.mens.destroy', $reference))->assertForbidden();

        $this->assertDatabaseHas('mens_references', ['id' => $reference->id]);
        $this->assertSame(1, MensReference::count());

        // Invité (compte réinitialisé — actingAs ne s'applique plus) :
        // aucune action possible, redirection vers la connexion scolarité.
        $this->app['auth']->forgetGuards();

        $this->post(route('admin.mens.store'), $this->donneesValides())
            ->assertRedirect(route('admin.connexion.show'));
        $this->assertSame(1, MensReference::count());
    }

    public function test_perimetre_faculte_restreint(): void
    {
        // Référence hors périmètre FST (programme FMP, aucun dossier local).
        $horsPerimetre = $this->creerReference([
            'cin_normalise' => '88776655', 'cin_original' => '88776655',
            'programme_code' => 'MED', 'nom' => 'Hors', 'prenom' => 'Perimetre',
        ]);

        $this->actingAs($this->adminFaculte)->get(route('admin.mens.show', $horsPerimetre))->assertNotFound();
        $this->actingAs($this->adminFaculte)->get(route('admin.mens.edit', $horsPerimetre))->assertNotFound();
        $this->actingAs($this->adminFaculte)
            ->put(route('admin.mens.update', $horsPerimetre), $this->donneesValides(['cin' => '88776655', 'programme_code' => 'MED']))
            ->assertNotFound();
        $this->actingAs($this->adminFaculte)
            ->delete(route('admin.mens.destroy', $horsPerimetre))
            ->assertNotFound();

        $this->assertDatabaseHas('mens_references', ['id' => $horsPerimetre->id]);

        // Création hors périmètre → 404, rien créé ; dans le périmètre → acceptée.
        $this->actingAs($this->adminFaculte)
            ->post(route('admin.mens.store'), $this->donneesValides(['cin' => '99887711', 'programme_code' => 'MED']))
            ->assertNotFound();
        $this->assertDatabaseMissing('mens_references', ['cin_normalise' => '99887711']);

        $this->actingAs($this->adminFaculte)
            ->post(route('admin.mens.store'), $this->donneesValides(['cin' => '99887722', 'programme_code' => 'LMI']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('mens_references', ['cin_normalise' => '99887722']);

        // Création rattachée par un dossier local de SA faculté (CIN appariée).
        $this->creerEtudiant('44556677', $this->fst->id);

        $this->actingAs($this->adminFaculte)
            ->post(route('admin.mens.store'), $this->donneesValides(['cin' => '44556677', 'programme_code' => null]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('mens_references', ['cin_normalise' => '44556677']);

        // Référence dans le périmètre : consultable ; correction la sortant du
        // périmètre (programme d'une autre faculté) → 404, état inchangé.
        $dansPerimetre = $this->creerReference(['cin_normalise' => '55443322', 'cin_original' => '55443322']);

        $this->actingAs($this->adminFaculte)->get(route('admin.mens.show', $dansPerimetre))->assertOk();

        $this->actingAs($this->adminFaculte)
            ->put(route('admin.mens.update', $dansPerimetre), $this->donneesValides(['cin' => '55443322', 'programme_code' => 'MED']))
            ->assertNotFound();
        $this->assertSame('LMI', $dansPerimetre->fresh()->programme_code);
    }

    public function test_identification_et_parcours_public_apres_crud(): void
    {
        $this->creerEtudiant('77001122', $this->fst->id, 'LocalNom', '2004-03-03');

        // Ajout CRUD d'une référence MENS pour la même identité (nom divergent).
        $this->actingAs($this->admin)->post(route('admin.mens.store'), $this->donneesValides([
            'cin' => '77001122', 'date_naissance' => '2004-03-03',
            'nom' => 'MensNom', 'prenom' => 'MensPrenom',
        ]))->assertSessionHasNoErrors();

        $resultat = app(IdentificationIntelligenteService::class)->identifier('77001122', '2004-03-03');
        $this->assertSame('MENS_ET_LOCAL', $resultat['statut']);
        $this->assertContains('nom', array_column($resultat['divergences'], 'champ'));

        // Parcours public : sonde en invité — le throttle Laravel est
        // indexé par utilisateur authentifié ; `forgetGuards()` donne le
        // seau « domaine | IP » des vrais visiteurs (aucune requête admin
        // ne vient gonfler ce compteur limité à 5/min).
        $this->app['auth']->forgetGuards();

        $this->postJson(route('inscription.identifier'), ['cin' => '77001122', 'date_naissance' => '03/03/2004'])
            ->assertOk()
            ->assertJsonPath('status', 'EXISTING_STUDENT');

        // Correction CRUD : la divergence reflète la nouvelle valeur MENS.
        $reference = MensReference::where('cin_normalise', '77001122')->firstOrFail();

        $this->actingAs($this->admin)->put(route('admin.mens.update', $reference), $this->donneesValides([
            'cin' => '77001122', 'date_naissance' => '2004-03-03',
            'nom' => 'MensNom2', 'prenom' => 'MensPrenom',
        ]))->assertSessionHasNoErrors();

        $resultat = app(IdentificationIntelligenteService::class)->identifier('77001122', '2004-03-03');
        $divergenceNom = collect($resultat['divergences'])->firstWhere('champ', 'nom');
        $this->assertNotNull($divergenceNom);
        $this->assertSame('MensNom2', $divergenceNom['mens']);

        // MENS seul : préremplissage public ; suppression CRUD → plus d'identification.
        $this->actingAs($this->admin)->post(route('admin.mens.store'), $this->donneesValides([
            'cin' => '99998888', 'date_naissance' => '2002-02-02',
            'nom' => 'Seul', 'prenom' => 'Mens', 'sexe' => 'M',
        ]))->assertSessionHasNoErrors();

        $this->app['auth']->forgetGuards();

        $this->postJson(route('inscription.identifier'), ['cin' => '99998888', 'date_naissance' => '02/02/2002'])
            ->assertOk()
            ->assertJsonPath('status', 'MENS_FOUND');

        $seule = MensReference::where('cin_normalise', '99998888')->firstOrFail();

        $this->actingAs($this->admin)->delete(route('admin.mens.destroy', $seule))
            ->assertRedirect(route('admin.mens.index'));

        $this->app['auth']->forgetGuards();

        $this->postJson(route('inscription.identifier'), ['cin' => '99998888', 'date_naissance' => '02/02/2002'])
            ->assertOk()
            ->assertJsonPath('status', 'UNKNOWN');
    }

    public function test_import_idempotent_non_regression(): void
    {
        $csv = "cin,date_naissance,nom,prenom,annee\n11223344,2004-01-15,Importe,Test,2025-2026\n";
        $fichier = fn () => UploadedFile::fake()->createWithContent('referentiel-mens.csv', $csv, 'text/csv');

        $premier = app(DataImportService::class)->preparer($fichier(), 'referentiel-mens', $this->admin->id);
        $this->assertFalse($premier['resultat']->bloque());

        app(DataImportService::class)->confirmer($premier['token'], 'creer', $this->admin->id);
        $this->assertSame(1, MensReference::count());

        // Réimport du même fichier : upsert idempotent, aucune duplication.
        $second = app(DataImportService::class)->preparer($fichier(), 'referentiel-mens', $this->admin->id);
        app(DataImportService::class)->confirmer($second['token'], 'upsert', $this->admin->id);

        $this->assertSame(1, MensReference::count());
        $this->assertSame('Importe', MensReference::first()->nom);
        $this->assertNotNull(MensReference::first()->imported_at);
    }

    public function test_assistance_prefill_doublon_et_exclusion_correction(): void
    {
        $this->creerEtudiant('66009911', $this->fst->id, 'Amrani', '2004-04-04');

        // Pré-remplissage depuis le dossier local (champs d'identité seuls).
        $this->actingAs($this->admin)
            ->getJson(route('admin.mens.assistance', ['cin' => '66009911', 'annee' => '2025-2026']))
            ->assertOk()
            ->assertJsonPath('prefill.nom', 'Amrani')
            ->assertJsonPath('prefill.prenom', 'Dossier')
            ->assertJsonPath('prefill.date_naissance', '2004-04-04')
            ->assertJsonPath('prefill.sexe', 'F')
            ->assertJsonPath('ambigu', false)
            ->assertJsonPath('doublon', false);

        // Doublon après création (même CIN + année) : signalé AVANT
        // enregistrement ; l'auto-exclusion `ignorer` couvre la correction.
        $reference = $this->creerReference(['cin_normalise' => '66009911', 'cin_original' => '66009911']);

        $this->actingAs($this->admin)
            ->getJson(route('admin.mens.assistance', ['cin' => '66009911', 'annee' => '2025-2026']))
            ->assertOk()
            ->assertJsonPath('doublon', true)
            ->assertJsonPath('prefill.nom', 'Amrani');

        $this->actingAs($this->admin)
            ->getJson(route('admin.mens.assistance', ['cin' => '66009911', 'annee' => '2025-2026', 'ignorer' => $reference->id]))
            ->assertOk()
            ->assertJsonPath('doublon', false);

        // Repli sans dossier local : la référence d'une autre année fournit l'identité.
        $this->creerReference([
            'cin_normalise' => '22114455', 'cin_original' => '22114455',
            'annee' => '2024-2025', 'nom' => 'Ancien', 'prenom' => 'Dossier',
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('admin.mens.assistance', ['cin' => '22114455', 'annee' => '2025-2026']))
            ->assertOk()
            ->assertJsonPath('prefill.nom', 'Ancien')
            ->assertJsonPath('doublon', false);

        // Sonde trop courte (le formulaire ne l'envoie jamais) : réponse
        // neutre, jamais d'erreur ni de fuite.
        $this->actingAs($this->admin)
            ->getJson(route('admin.mens.assistance', ['cin' => '12']))
            ->assertOk()
            ->assertJsonPath('prefill', null);
    }

    public function test_assistance_perimetre_et_ambiguite(): void
    {
        // Dossier local hors faculté : aucune identité révélée au compte restreint.
        $this->creerEtudiant('33112244', $this->fmp->id, 'HorsFac');

        $this->actingAs($this->adminFaculte)
            ->getJson(route('admin.mens.assistance', ['cin' => '33112244']))
            ->assertOk()
            ->assertJsonPath('prefill', null);

        // Dossier local dans SA faculté : pré-remplissage autorisé.
        $this->creerEtudiant('33115566', $this->fst->id, 'DansFac');

        $this->actingAs($this->adminFaculte)
            ->getJson(route('admin.mens.assistance', ['cin' => '33115566']))
            ->assertOk()
            ->assertJsonPath('prefill.nom', 'DansFac');

        // Ambiguïté locale (même CIN sous deux formes — la contrainte
        // unique porte sur la chaîne) : pré-remplissage bloqué, comme
        // `creerDossier` — jamais de choix silencieux.
        $this->creerEtudiant('33445566', $this->fst->id, 'FormeUn');
        $this->creerEtudiant('3344 5566', $this->fst->id, 'FormeDeux');

        $this->actingAs($this->adminFaculte)
            ->getJson(route('admin.mens.assistance', ['cin' => '33445566']))
            ->assertOk()
            ->assertJsonPath('prefill', null)
            ->assertJsonPath('ambigu', true);

        // Repli MENS cloisonné : une référence hors périmètre (programme
        // MED, aucun dossier FST) n'alimente pas le pré-remplissage.
        $this->creerReference([
            'cin_normalise' => '33778899', 'cin_original' => '33778899',
            'programme_code' => 'MED', 'annee' => '2024-2025',
        ]);

        $this->actingAs($this->adminFaculte)
            ->getJson(route('admin.mens.assistance', ['cin' => '33778899', 'annee' => '2025-2026']))
            ->assertOk()
            ->assertJsonPath('prefill', null)
            ->assertJsonPath('ambigu', false);
    }

    public function test_referentiels_autocompletion_et_acces(): void
    {
        $this->creerReference([
            'cin_normalise' => '55664422', 'cin_original' => '55664422',
            'annee' => '2024-2025', 'etablissement' => 'Lycée Ibn Sina', 'nationalite' => 'Marocaine',
        ]);

        $reponse = $this->actingAs($this->admin)
            ->getJson(route('admin.mens.referentiels'))
            ->assertOk()
            ->assertJsonStructure(['annees', 'programmes', 'etablissements', 'nationalites'])
            ->json();

        // Valeurs réelles uniquement : années (universitaires + référentiel),
        // programmes, établissements et nationalités issus des lignes MENS.
        $this->assertContains('2024-2025', $reponse['annees']);
        $this->assertSame(['LMI', 'MED'], collect($reponse['programmes'])->pluck('code')->sort()->values()->all());
        $this->assertContains('Lycée Ibn Sina', $reponse['etablissements']);
        $this->assertContains('Marocaine', $reponse['nationalites']);

        // Compte restreint : uniquement les programmes de sa faculté.
        $restreint = $this->actingAs($this->adminFaculte)
            ->getJson(route('admin.mens.referentiels'))
            ->assertOk()
            ->json();
        $this->assertSame(['LMI'], collect($restreint['programmes'])->pluck('code')->all());

        // Agent (data.export seulement) : refusé ; invité : rejeté.
        $this->actingAs($this->agent)->getJson(route('admin.mens.referentiels'))->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson(route('admin.mens.referentiels'))->assertUnauthorized();
    }

    public function test_formulaire_assiste_embarque_referentiels_et_sonde(): void
    {
        // Ajout : composant d'assistance + listes d'auto-complétion.
        // Garde-fou : le tag composant doit être COMPILÉ (jamais de balise
        // `<x-input>` laissée en texte littéral — champ invisible).
        $this->actingAs($this->admin)->get(route('admin.mens.create'))
            ->assertOk()
            ->assertSee('assistanceMens')
            ->assertSee('liste-annees')
            ->assertSee('liste-programmes')
            ->assertSee('liste-etablissements')
            ->assertSee('liste-nationalites')
            ->assertSee('name="cin"', false)
            ->assertSee('x-on:input.debounce.400ms="sonderCin()"', false)
            ->assertDontSee('<x-input', false);

        // Correction : même assistance (auto-exclusion du doublon par `ignorer`).
        $reference = $this->creerReference();

        $this->actingAs($this->admin)->get(route('admin.mens.edit', $reference))
            ->assertOk()
            ->assertSee('assistanceMens')
            ->assertSee('name="cin"', false)
            ->assertSee('value="11223344"', false)
            ->assertDontSee('<x-input', false);
    }
}
