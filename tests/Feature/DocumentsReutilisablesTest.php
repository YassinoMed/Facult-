<?php

namespace Tests\Feature;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Models\User;
use App\Modules\StudentDossiers\Models\InscriptionDocument;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDocumentVersion;
use App\Modules\StudentDossiers\Services\ReutilisationDocumentsService;
use App\Modules\StudentDossiers\Services\StudentDocumentService;
use App\Modules\StudentDossiers\Services\StudentDossierService;
use App\Services\InscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LOOP-UNIREGISTRE-DOCUMENTS-REUTILISABLES-01 : réutilisation annuelle
 * des pièces permanentes, sans duplication de fichier ni validation
 * implicite d'un simple téléversement.
 */
class DocumentsReutilisablesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Faculte $autreFaculte;

    private User $scolarite;

    private AnneeUniversitaire $annee1;

    private AnneeUniversitaire $annee2;

    protected function setUp(): void
    {
        parent::setUp();

        config(['modules.student-dossiers.active' => true]);
        Storage::fake('private');

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences et Techniques',
            'code' => 'FST',
            'description' => 'Sciences et technologies',
            'active' => true,
        ]);

        $this->autreFaculte = Faculte::create([
            'nom' => 'Faculté des Lettres',
            'code' => 'FL',
            'description' => 'Lettres',
            'active' => true,
        ]);

        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Scolarité',
            'email' => 'sco.reutilisation@universite.test',
            'password' => 'Motdepasse@123',
        ], 'admin_scolarite');

        $this->annee1 = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => false,
        ]);

        $this->annee2 = AnneeUniversitaire::create([
            'libelle' => '2026-2027',
            'date_debut' => '2026-09-01',
            'date_fin' => '2027-06-30',
            'active' => true,
        ]);

        config()->set('inscriptions.documents_requis_par_type', [
            'premiere' => ['cin', 'releve_notes', 'photo_identite'],
            'reinscription' => ['cin', 'quitus_scolarite'],
            'transfert' => ['cin', 'attestation_transfert', 'releves_anterieurs'],
        ]);
        config()->set('inscriptions.documents_requis_desactives', false);
    }

    public function test_premiere_inscription_reutilise_les_pieces_validees_et_ne_valide_jamais_un_televersement(): void
    {
        $etudiant = $this->creerEtudiant('REU00001');
        $cin = $this->deposer($etudiant, 'cin', 'validated');
        $releve = $this->deposer($etudiant, 'releve_notes', 'pending');

        $inscription = $this->inscrire($etudiant, $this->annee1, 'premiere');

        $associationCin = $this->association($inscription, 'cin');
        $this->assertSame('reutilise', $associationCin->decision);
        $this->assertSame($cin->id, $associationCin->student_document_id);
        $this->assertNotNull($associationCin->student_document_version_id);

        // Téléversé ≠ validé : la pièce en attente n'est jamais réutilisée.
        $this->assertSame('en_attente', $this->association($inscription, 'releve_notes')->decision);
        $this->assertSame('manquant', $this->association($inscription, 'photo_identite')->decision);

        $bilan = app(ReutilisationDocumentsService::class)->etatInscription($inscription);
        $this->assertFalse($bilan['complet']);
        $this->assertSame(['cin'], $bilan['reutilises']);
        $this->assertSame(['releve_notes'], $bilan['en_attente']);
        $this->assertSame(['photo_identite'], $bilan['a_fournir']);
        $this->assertSame('1 / 3 obligatoires validés', $bilan['progression']);

        $this->assertSame(2, StudentDocument::count());
        $this->assertSame(2, StudentDocumentVersion::count());

        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => 'inscription.document_reutilise',
        ]);
    }

    public function test_reinscription_reutilise_sans_duplication_et_ne_demande_que_la_piece_manquante(): void
    {
        $etudiant = $this->creerEtudiant('REU00002');
        $this->deposer($etudiant, 'cin', 'validated');
        $this->deposer($etudiant, 'releve_notes', 'validated');
        $this->deposer($etudiant, 'photo_identite', 'validated');

        $premiere = $this->inscrire($etudiant, $this->annee1, 'premiere');

        $documentsAvant = StudentDocument::count();
        $versionsAvant = StudentDocumentVersion::count();
        $fichiersAvant = count(Storage::disk('private')->allFiles());

        $reinscription = $this->inscrire($etudiant, $this->annee2, 'reinscription');

        $cinPremiere = $this->association($premiere, 'cin');
        $cinSeconde = $this->association($reinscription, 'cin');

        $this->assertSame('reutilise', $cinSeconde->decision);
        $this->assertSame($cinPremiere->student_document_id, $cinSeconde->student_document_id);
        $this->assertSame($cinPremiere->student_document_version_id, $cinSeconde->student_document_version_id);

        $bilan = app(ReutilisationDocumentsService::class)->etatInscription($reinscription);
        $this->assertFalse($bilan['complet']);
        $this->assertSame(['quitus_scolarite'], $bilan['a_fournir']);
        $this->assertContains('cin', $bilan['reutilises']);
        $this->assertContains('photo_identite', $bilan['reutilises']);

        // Aucune copie : mêmes lignes, mêmes versions, mêmes fichiers.
        $this->assertSame($documentsAvant, StudentDocument::count());
        $this->assertSame($versionsAvant, StudentDocumentVersion::count());
        $this->assertSame($fichiersAvant, count(Storage::disk('private')->allFiles()));
    }

    public function test_refuse_expire_fichier_absent_et_format_invalide_ne_sont_jamais_reutilises(): void
    {
        $etudiant = $this->creerEtudiant('REU00003');
        $cin = $this->deposer($etudiant, 'cin', 'validated');
        Storage::disk('private')->delete($cin->storage_path);

        $motifSensible = 'CIN 98765432 illisible — dossier de Sara Benali';
        $this->deposer($etudiant, 'releve_notes', 'rejected', ['motif' => $motifSensible]);
        $this->deposer($etudiant, 'photo_identite', 'validated', ['expires_at' => now()->subDay()]);

        $inscription = $this->inscrire($etudiant, $this->annee1, 'premiere');

        $this->assertSame('non_conforme', $this->association($inscription, 'cin')->decision);
        $associationReleve = $this->association($inscription, 'releve_notes');
        $this->assertSame('rejete', $associationReleve->decision);
        $this->assertSame($motifSensible, $associationReleve->motif);
        $this->assertSame('expire', $this->association($inscription, 'photo_identite')->decision);

        // Le motif libre reste sur la décision annuelle (accès contrôlé),
        // jamais recopié dans le journal d'audit (pas de PII en logs).
        $audits = DB::table('historique_academique')
            ->where('etudiant_id', $etudiant->id)
            ->get();

        $this->assertNotEmpty($audits);

        foreach ($audits as $audit) {
            $contenuAudit = (string) $audit->ancienne_valeur.(string) $audit->nouvelle_valeur;
            $this->assertStringNotContainsString('98765432', $contenuAudit);
            $this->assertStringNotContainsString('Sara Benali', $contenuAudit);
        }

        $bilan = app(ReutilisationDocumentsService::class)->etatInscription($inscription);
        $this->assertSame([], $bilan['reutilises']);
        $this->assertEqualsCanonicalizing(
            ['cin', 'releve_notes', 'photo_identite'],
            $bilan['a_fournir'],
        );
    }

    public function test_format_non_conforme_est_demande_pour_remplacement(): void
    {
        $etudiant = $this->creerEtudiant('REU00009');
        $this->deposer($etudiant, 'cin', 'validated');

        // Relevé validé mais au format interdit par le catalogue (docx).
        $this->deposer($etudiant, 'releve_notes', 'validated', [
            'extension' => 'docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
        $this->deposer($etudiant, 'photo_identite', 'validated');

        $inscription = $this->inscrire($etudiant, $this->annee1, 'premiere');

        $this->assertSame('non_conforme', $this->association($inscription, 'releve_notes')->decision);
        $this->assertSame(['releve_notes'], app(ReutilisationDocumentsService::class)->etatInscription($inscription)['a_fournir']);
    }

    public function test_fichier_altere_de_meme_taille_nest_jamais_reutilise(): void
    {
        $etudiant = $this->creerEtudiant('REU00013');
        $cin = $this->deposer($etudiant, 'cin', 'validated');

        $contenu = (string) Storage::disk('private')->get($cin->storage_path);
        $altere = str_replace('cin', 'CIN', $contenu);
        $this->assertSame(strlen($contenu), strlen($altere));
        $this->assertNotSame($contenu, $altere);

        Storage::disk('private')->put($cin->storage_path, $altere);

        $inscription = $this->inscrire($etudiant, $this->annee1, 'premiere');

        $this->assertSame('non_conforme', $this->association($inscription, 'cin')->decision);
        $this->assertContains('cin', app(ReutilisationDocumentsService::class)->etatInscription($inscription)['a_fournir']);
    }

    public function test_remplacement_conserve_lhistorique_et_ne_reecrit_pas_une_annee_cloturee(): void
    {
        $etudiant = $this->creerEtudiant('REU00004');
        $cin = $this->deposer($etudiant, 'cin', 'validated');
        $this->deposer($etudiant, 'releve_notes', 'validated');
        $this->deposer($etudiant, 'photo_identite', 'validated');

        $premiere = $this->inscrire($etudiant, $this->annee1, 'premiere');
        $premiere->update(['statut' => 'valide']);

        $this->deposer($etudiant, 'quitus_scolarite', 'validated');
        $reinscription = $this->inscrire($etudiant, $this->annee2, 'reinscription');

        $associationAncienne = $this->association($premiere, 'cin');
        $ancienneVersion = $associationAncienne->student_document_version_id;
        $ancienChemin = $cin->storage_path;
        $ancienneTaille = $cin->size;

        $this->travel(5)->seconds();

        app(StudentDocumentService::class)->replace(
            $cin->fresh(),
            UploadedFile::fake()->createWithContent('cin-v2.pdf', $this->contenuPdf('cin-v2')),
            $this->scolarite->id,
        );

        $cin->refresh();
        $this->assertSame(2, $cin->versions()->count());
        $this->assertSame('pending', $cin->status);

        // L'année clôturée garde sa version et sa décision d'origine.
        $this->assertSame($ancienneVersion, $this->association($premiere, 'cin')->student_document_version_id);
        $this->assertSame('reutilise', $this->association($premiere, 'cin')->decision);

        // Même un appel direct au service ne réécrit pas l'année clôturée.
        app(ReutilisationDocumentsService::class)->synchroniser($premiere);
        $this->assertSame($ancienneVersion, $this->association($premiere, 'cin')->student_document_version_id);
        $this->assertSame('reutilise', $this->association($premiere, 'cin')->decision);

        // L'année ouverte suit le remplacement, en attente de contrôle.
        $associationNouvelle = $this->association($reinscription, 'cin');
        $this->assertSame('en_attente', $associationNouvelle->decision);
        $this->assertNotSame($ancienneVersion, $associationNouvelle->student_document_version_id);

        // Aucune suppression du fichier historique.
        Storage::disk('private')->assertExists($ancienChemin);
        $this->assertSame($ancienneTaille, Storage::disk('private')->size($ancienChemin));

        app(StudentDocumentService::class)->validate($cin->fresh(), $this->scolarite->id);

        $this->assertSame('associe', $this->association($reinscription, 'cin')->fresh()->decision);
        $this->assertSame('reutilise', $this->association($premiere, 'cin')->fresh()->decision);

        $this->travelBack();
    }

    public function test_televersement_posterieur_alimente_lannee_sans_validation_automatique(): void
    {
        $etudiant = $this->creerEtudiant('REU00005');
        $inscription = $this->inscrire($etudiant, $this->annee1, 'premiere');

        $this->assertSame('manquant', $this->association($inscription, 'cin')->decision);

        $dossier = app(StudentDossierService::class)->obtenirOuCreer($etudiant);
        $document = app(StudentDocumentService::class)->upload(
            $dossier,
            ['category' => 'identity', 'document_type' => 'cin', 'title' => 'CIN'],
            UploadedFile::fake()->createWithContent('cin.pdf', $this->contenuPdf('cin')),
            $this->scolarite->id,
        );

        $association = $this->association($inscription, 'cin')->fresh();
        $this->assertSame('en_attente', $association->decision);
        $this->assertSame($document->id, $association->student_document_id);
        $this->assertSame(['cin'], app(ReutilisationDocumentsService::class)->etatInscription($inscription)['en_attente']);

        $this->travel(5)->seconds();
        app(StudentDocumentService::class)->validate($document->fresh(), $this->scolarite->id);

        $this->assertSame('associe', $this->association($inscription, 'cin')->fresh()->decision);
        $this->assertDatabaseHas('historique_academique', [
            'etudiant_id' => $etudiant->id,
            'action' => 'inscription.document_associe',
        ]);

        $this->travelBack();
    }

    public function test_changement_didentite_conserve_la_reutilisation_et_isole_les_etudiants(): void
    {
        $etudiant = $this->creerEtudiant('REU00006');
        $cin = $this->deposer($etudiant, 'cin', 'validated');
        $this->deposer($etudiant, 'releve_notes', 'validated');
        $this->deposer($etudiant, 'photo_identite', 'validated');
        $this->deposer($etudiant, 'quitus_scolarite', 'validated');

        $this->inscrire($etudiant, $this->annee1, 'premiere');
        $reinscription = $this->inscrire($etudiant, $this->annee2, 'reinscription');

        // Correction d'identité : le dossier permanent reste rattaché à l'ID.
        $etudiant->update(['cin' => 'REU00006B', 'nom' => 'Nouveau', 'prenom' => 'Nom']);

        $bilan = app(ReutilisationDocumentsService::class)->etatInscription($reinscription);
        $this->assertTrue($bilan['complet']);
        $this->assertContains('cin', $bilan['reutilises']);

        $association = $this->association($reinscription, 'cin');
        $this->assertTrue(Gate::forUser($this->scolarite)->allows('view', $association));

        $adminAutreFaculte = User::creerCompteBackOffice([
            'name' => 'Admin Lettres',
            'email' => 'admin.lettres@universite.test',
            'password' => 'Motdepasse@123',
            'faculte_id' => $this->autreFaculte->id,
        ], 'admin_faculte');

        $this->assertFalse(Gate::forUser($adminAutreFaculte)->allows('view', $association));

        $autreEtudiant = $this->creerEtudiant('REU00007', $this->autreFaculte);

        $this->withSession(['etudiant_auth_id' => $autreEtudiant->id])
            ->get(route('etudiant.dossier.documents.download', $cin))
            ->assertNotFound();
    }

    public function test_synchronisation_idempotente(): void
    {
        $etudiant = $this->creerEtudiant('REU00008');
        $this->deposer($etudiant, 'cin', 'validated');
        $this->deposer($etudiant, 'releve_notes', 'validated');
        $this->deposer($etudiant, 'photo_identite', 'validated');

        // Inscription créée SANS passer par le service : aucun événement.
        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee1->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
            'type' => 'premiere',
        ]);

        $this->assertSame(0, InscriptionDocument::where('inscription_academique_id', $inscription->id)->count());

        $service = app(ReutilisationDocumentsService::class);
        $service->synchroniser($inscription);
        $nombre = InscriptionDocument::where('inscription_academique_id', $inscription->id)->count();
        $decisions = InscriptionDocument::where('inscription_academique_id', $inscription->id)
            ->orderBy('document_type')
            ->pluck('decision', 'document_type')
            ->all();

        $service->synchroniser($inscription->fresh());

        $this->assertSame($nombre, InscriptionDocument::where('inscription_academique_id', $inscription->id)->count());
        $this->assertSame(
            $decisions,
            InscriptionDocument::where('inscription_academique_id', $inscription->id)
                ->orderBy('document_type')
                ->pluck('decision', 'document_type')
                ->all(),
        );
        $this->assertSame(3, StudentDocument::count());
        $this->assertSame(3, StudentDocumentVersion::count());
    }

    public function test_commande_de_rattrapage_dry_run_backfill_et_tout_sans_reecrire_une_annee_cloturee(): void
    {
        $etudiant = $this->creerEtudiant('REU00012');
        $cin = $this->deposer($etudiant, 'cin', 'validated');
        $this->deposer($etudiant, 'releve_notes', 'validated');
        $this->deposer($etudiant, 'photo_identite', 'validated');
        $this->deposer($etudiant, 'quitus_scolarite', 'validated');

        $service = app(ReutilisationDocumentsService::class);

        // Année clôturée : ses décisions sont figées.
        $fermee = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee1->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
            'type' => 'premiere',
        ]);
        $service->synchroniser($fermee);
        $fermee->update(['statut' => 'valide']);
        $decisionsFermees = InscriptionDocument::where('inscription_academique_id', $fermee->id)
            ->orderBy('document_type')
            ->pluck('decision', 'document_type')
            ->all();
        $versionFermee = InscriptionDocument::where('inscription_academique_id', $fermee->id)
            ->where('document_type', 'cin')
            ->value('student_document_version_id');

        // Année ouverte, créée hors service : aucune association.
        $ouverte = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee2->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
            'type' => 'reinscription',
        ]);
        $this->assertSame(0, InscriptionDocument::where('inscription_academique_id', $ouverte->id)->count());

        // Simulation : aucune écriture.
        $this->artisan('documents:associer-inscriptions', [
            '--etudiant' => $etudiant->id,
            '--dry-run' => true,
        ])->assertExitCode(0);
        $this->assertSame(0, InscriptionDocument::where('inscription_academique_id', $ouverte->id)->count());

        // Backfill réel : l'année ouverte est associée, l'année clôturée intacte.
        $this->artisan('documents:associer-inscriptions', ['--etudiant' => $etudiant->id])
            ->assertExitCode(0);
        $this->assertGreaterThan(0, InscriptionDocument::where('inscription_academique_id', $ouverte->id)->count());
        $this->assertSame(
            $decisionsFermees,
            InscriptionDocument::where('inscription_academique_id', $fermee->id)
                ->orderBy('document_type')
                ->pluck('decision', 'document_type')
                ->all(),
        );

        $decisionsOuvertes = InscriptionDocument::where('inscription_academique_id', $ouverte->id)
            ->orderBy('document_type')
            ->pluck('decision', 'document_type')
            ->all();
        $this->assertSame('reutilise', $decisionsOuvertes['cin']);

        // Une modification hors événement ne réécrit PAS le backfill par défaut.
        $cin->update(['status' => 'pending']);

        $this->artisan('documents:associer-inscriptions', ['--etudiant' => $etudiant->id])
            ->assertExitCode(0);
        $this->assertSame(
            $decisionsOuvertes,
            InscriptionDocument::where('inscription_academique_id', $ouverte->id)
                ->orderBy('document_type')
                ->pluck('decision', 'document_type')
                ->all(),
        );

        // `--tout` resynchronise l'année ouverte, jamais l'année clôturée.
        $this->artisan('documents:associer-inscriptions', [
            '--etudiant' => $etudiant->id,
            '--tout' => true,
        ])->assertExitCode(0);

        $this->assertSame(
            'en_attente',
            InscriptionDocument::where('inscription_academique_id', $ouverte->id)
                ->where('document_type', 'cin')
                ->value('decision'),
        );
        $this->assertSame(
            $decisionsFermees,
            InscriptionDocument::where('inscription_academique_id', $fermee->id)
                ->orderBy('document_type')
                ->pluck('decision', 'document_type')
                ->all(),
        );
        $this->assertSame(
            $versionFermee,
            InscriptionDocument::where('inscription_academique_id', $fermee->id)
                ->where('document_type', 'cin')
                ->value('student_document_version_id'),
        );
    }

    public function test_commande_necrit_rien_si_le_module_est_desactive(): void
    {
        $etudiant = $this->creerEtudiant('REU00014');
        $this->deposer($etudiant, 'cin', 'validated');

        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee1->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
            'type' => 'premiere',
        ]);

        config(['modules.student-dossiers.active' => false]);

        $this->artisan('documents:associer-inscriptions', ['--etudiant' => $etudiant->id])
            ->assertExitCode(0);

        $this->assertSame(
            0,
            InscriptionDocument::where('inscription_academique_id', $inscription->id)->count(),
        );
    }

    public function test_inscription_creee_sans_statut_explicite_utilise_le_defaut_en_base(): void
    {
        $etudiant = $this->creerEtudiant('REU00015');
        $this->deposer($etudiant, 'cin', 'validated');
        $this->deposer($etudiant, 'releve_notes', 'validated');
        $this->deposer($etudiant, 'photo_identite', 'validated');

        // Aucun `statut` fourni : la valeur `en_cours` vient de la base.
        $inscription = app(InscriptionService::class)->creerInscriptionAnnuelle(
            $etudiant->id,
            $this->annee1->id,
            ['niveau' => 'L1', 'type' => 'premiere'],
        );

        $this->assertNull($inscription->getAttribute('statut'));
        $this->assertSame('reutilise', $this->association($inscription, 'cin')->decision);
        $this->assertSame(
            3,
            InscriptionDocument::where('inscription_academique_id', $inscription->id)
                ->whereIn('document_type', ['cin', 'releve_notes', 'photo_identite'])
                ->count(),
        );
    }

    public function test_espace_etudiant_et_dossier_admin_affichent_reutilisation_et_pieces_a_fournir(): void
    {
        $etudiant = $this->creerEtudiant('REU00010');
        $cin = $this->deposer($etudiant, 'cin', 'validated');
        $this->deposer($etudiant, 'releve_notes', 'pending');
        $dossier = app(StudentDossierService::class)->obtenirOuCreer($etudiant);

        $this->inscrire($etudiant, $this->annee1, 'premiere');

        $this->withSession(['etudiant_auth_id' => $etudiant->id])
            ->get(route('etudiant.dossier.show'))
            ->assertOk()
            ->assertSee('Réutilisées / déjà validées')
            ->assertSee('En attente de contrôle')
            ->assertSee('À fournir ou à renouveler')
            ->assertDontSee($cin->storage_path);

        $this->actingAs($this->scolarite)
            ->get(route('admin.student-dossiers.show', $dossier))
            ->assertOk()
            ->assertSee('Inscription 2025-2026 — pièces')
            ->assertSee('Réutilisées / validées');
    }

    public function test_synchronisation_est_transactionnelle_en_cas_dechec(): void
    {
        $etudiant = $this->creerEtudiant('REU00011');
        $this->deposer($etudiant, 'cin', 'validated');
        $this->deposer($etudiant, 'releve_notes', 'validated');
        $this->deposer($etudiant, 'photo_identite', 'validated');

        $inscription = $etudiant->inscriptionsAcademiques()->create([
            'annee_universitaire_id' => $this->annee1->id,
            'niveau' => 'L1',
            'statut' => 'en_cours',
            'type' => 'premiere',
        ]);

        $creations = 0;

        InscriptionDocument::created(function () use (&$creations): void {
            $creations++;

            if ($creations === 2) {
                throw new \RuntimeException('Échec simulé.');
            }
        });

        try {
            app(ReutilisationDocumentsService::class)->synchroniser($inscription);
            $this->fail('La synchronisation aurait dû échouer.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Échec simulé.', $e->getMessage());
        } finally {
            InscriptionDocument::flushEventListeners();
        }

        $this->assertSame(
            0,
            InscriptionDocument::where('inscription_academique_id', $inscription->id)->count(),
            'Un échec en cours de synchronisation doit annuler toutes les décisions de l’année.',
        );
    }

    private function creerEtudiant(string $cin, ?Faculte $faculte = null): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => 'Benali',
            'prenom' => 'Sara',
            'email' => mb_strtolower($cin).'@reutilisation.test',
            'date_naissance' => '2004-01-01',
            'sexe' => 'F',
            'faculte_id' => ($faculte ?? $this->faculte)->id,
            'niveau' => 'L1',
            'annee_academique' => '2026-2027',
            'adresse' => 'X',
            'ville' => 'Casa',
            'code_postal' => '20000',
            'annee_bac' => '2024',
            'password' => 'Etudiant@123',
        ]);
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    private function inscrire(Etudiant $etudiant, AnneeUniversitaire $annee, string $type): InscriptionAcademique
    {
        return app(InscriptionService::class)->creerInscriptionAnnuelle($etudiant->id, $annee->id, [
            'niveau' => 'L1',
            'statut' => 'en_cours',
            'type' => $type,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function deposer(Etudiant $etudiant, string $type, string $status, array $options = []): StudentDocument
    {
        $dossier = app(StudentDossierService::class)->obtenirOuCreer($etudiant);
        $extension = (string) ($options['extension'] ?? ($type === 'photo_identite' ? 'png' : 'pdf'));
        $mime = (string) ($options['mime_type'] ?? match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/pdf',
        });
        $contenu = (string) ($options['contenu'] ?? ($extension === 'pdf'
            ? $this->contenuPdf($type)
            : "\x89PNG\r\n\x1a\n".str_repeat("\0", 32)));
        $chemin = 'tests/'.$etudiant->id.'/'.Str::uuid().'.'.$extension;

        Storage::disk('private')->put($chemin, $contenu);

        $document = StudentDocument::create([
            'student_dossier_id' => $dossier->id,
            'category' => match ($type) {
                'cin', 'photo_identite', 'acte_naissance' => 'identity',
                'quitus_scolarite', 'recu_inscription' => 'registration',
                'attestation_transfert' => 'administrative',
                default => 'academic',
            },
            'document_type' => $type,
            'title' => $type,
            'original_filename' => $type.'.'.$extension,
            'storage_path' => $chemin,
            'mime_type' => $mime,
            'extension' => $extension,
            'size' => strlen($contenu),
            'sha256' => hash('sha256', $contenu),
            'status' => $status,
            'uploaded_by' => $this->scolarite->id,
            'validated_by' => $status === 'validated' ? $this->scolarite->id : null,
            'validated_at' => $status === 'validated' ? now() : null,
            'rejected_reason' => $options['motif'] ?? null,
            'expires_at' => $options['expires_at'] ?? null,
        ]);

        $document->versions()->create([
            'version' => 1,
            'original_filename' => $document->original_filename,
            'storage_path' => $chemin,
            'mime_type' => $mime,
            'extension' => $extension,
            'size' => strlen($contenu),
            'sha256' => hash('sha256', $contenu),
            'uploaded_by' => $this->scolarite->id,
        ]);

        return $document;
    }

    private function association(InscriptionAcademique $inscription, string $type): InscriptionDocument
    {
        return InscriptionDocument::where('inscription_academique_id', $inscription->id)
            ->where('document_type', $type)
            ->firstOrFail();
    }

    private function contenuPdf(string $graine): string
    {
        return "%PDF-1.4\n% {$graine}\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
    }
}
