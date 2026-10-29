<?php

namespace Tests\Feature\Security;

use App\Events\StudentRegistered;
use App\Models\AnneeUniversitaire;
use App\Models\DocumentVerification;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\PortalPasswordResetToken;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Support\JournalisationPrivee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LOOP-03 — Confidentialité, données personnelles et journaux :
 * - Cartographie et minimisation des données personnelles exposées.
 * - Aucune donnée sensible (CIN, mot de passe) dans les URLs publiques.
 * - En-têtes HTTP de sécurité stricts (anti-sniffing, anti-framing).
 * - Réponses neutres anti-énumération (dossier existant, suivi, réinitialisation).
 * - Vérification publique de documents et QR codes : nom masqué, IP hachée, zéro PII.
 * - Fichiers temporaires d'identité : suppression systématique après analyse.
 * - Hygiène des journaux : masquage anti-PII centralisé et assainissement SQL.
 * - Sondes diagnostiques (/api/v1/ready) : état global sans détail d'infrastructure.
 */
class Loop03ConfidentialiteDonneesPersonnellesTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private AnneeUniversitaire $annee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('private');

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences',
            'code' => 'FS',
            'active' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
            'active' => true,
        ]);
    }

    private function creerEtudiant(string $cin, string $email, string $nom = 'Ben Salah', string $prenom = 'Karim'): Etudiant
    {
        $etudiant = Etudiant::create([
            'cin' => $cin,
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => $email,
            'telephone' => '+21699112233',
            'date_naissance' => '2004-05-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => 'Avenue Habib Bourguiba',
            'ville' => 'Tunis',
            'code_postal' => '1000',
            'annee_bac' => '2024',
            'password' => 'PassSecret@123',
        ]);
        $etudiant->forceFill(['num_inscription' => 'INS-2026-'.$cin])->save();
        $etudiant->definirStatut('valide');

        return $etudiant;
    }

    /* ── 1. Recherche CIN : méthode POST et protection URL ──────────── */

    public function test_recherche_publique_cin_interdit_methode_get_et_cin_dans_url(): void
    {
        // 1. La méthode GET sur la vérification CIN est refusée (Method Not Allowed)
        $this->get('/inscription/verifier-cin')->assertStatus(405);

        // 2. Aucune route n'accepte le CIN dans l'URL (404)
        $this->get('/inscription/verifier-cin/08123456')->assertNotFound();
        $this->get('/inscription/08123456')->assertNotFound();

        // 3. Les en-têtes HTTP de sécurité sont présents pour protéger la confidentialité
        $reponse = $this->get(route('inscription.existant.accueil'));
        $reponse->assertOk();
        $reponse->assertHeader('X-Content-Type-Options', 'nosniff');
        $reponse->assertHeader('X-Frame-Options');
    }

    /* ── 2. Anti-énumération : suivi public de dossier ───────────────── */

    public function test_anti_enumeration_suivi_public_reponse_neutre_identique(): void
    {
        $etudiant = $this->creerEtudiant('08778899', 'etudiant.suivi@universite.tn');

        // Cas A : numéro qui n'existe pas du tout
        $respFauxNumero = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => 'INS-INCONNU-999',
            'secondaire' => '2004-05-15',
        ]);
        $respFauxNumero->assertOk();
        $respFauxNumero->assertSee('Aucun dossier correspondant n’a été trouvé avec ces références');

        // Cas B : numéro réel mais mauvais second facteur
        $respFausseDate = $this->post(route('inscription.suivi.rechercher'), [
            'reference' => $etudiant->num_inscription,
            'secondaire' => '1990-01-01',
        ]);
        $respFausseDate->assertOk();
        $respFausseDate->assertSee('Aucun dossier correspondant n’a été trouvé avec ces références');

        // Neutralité stricte : message identique
        $this->assertSame(
            $respFauxNumero->viewData('erreurNeutre'),
            $respFausseDate->viewData('erreurNeutre')
        );

        // En mode JSON (popup embarqué)
        $jsonFaux = $this->postJson(route('inscription.suivi.rechercher'), [
            'reference' => 'INS-INCONNU-999',
            'secondaire' => '2004-05-15',
        ])->assertOk();
        $this->assertFalse($jsonFaux->json('ok'));

        $jsonMauvais = $this->postJson(route('inscription.suivi.rechercher'), [
            'reference' => $etudiant->num_inscription,
            'secondaire' => '1990-01-01',
        ])->assertOk();
        $this->assertFalse($jsonMauvais->json('ok'));
        $this->assertSame($jsonFaux->json('message'), $jsonMauvais->json('message'));
    }

    /* ── 3. Anti-énumération : recherche dossier existant ────────────── */

    public function test_anti_enumeration_dossier_existant_reponse_neutre_flux_canonique(): void
    {
        $this->creerEtudiant('08112233', 'existant@universite.tn');

        // Cas A : CIN existant (8 chiffres) mais date erronée
        $reponseA = $this->post(route('inscription.existant.recherche'), [
            'cin' => '08112233',
            'date_naissance' => '1990-01-01',
        ]);
        $reponseA->assertRedirect(route('inscription.existant.accueil'));
        $this->assertTrue(session('dossier_existant.code_envoye', false));

        // Cas B : CIN inexistant (8 chiffres)
        $reponseB = $this->post(route('inscription.existant.recherche'), [
            'cin' => '08999999',
            'date_naissance' => '2004-05-15',
        ]);
        $reponseB->assertRedirect(route('inscription.existant.accueil'));
        $this->assertTrue(session('dossier_existant.code_envoye', false));
    }

    /* ── 4. Anti-énumération : réinitialisation de mot de passe ──────── */

    public function test_reinitialisation_mot_de_passe_reponse_neutre_anti_enumeration(): void
    {
        $etudiant = $this->creerEtudiant('08334455', 'etudiant.reset@universite.tn');

        // Cas A : email existant
        $respExistant = $this->post(route('etudiant.mot-de-passe.envoyer'), [
            'email' => $etudiant->email,
        ]);
        $respExistant->assertRedirect(route('etudiant.mot-de-passe.oublie'));
        $respExistant->assertSessionHas('success');

        // Cas B : email inexistant
        $respInexistant = $this->post(route('etudiant.mot-de-passe.envoyer'), [
            'email' => 'introuvable@universite.tn',
        ]);
        $respInexistant->assertRedirect(route('etudiant.mot-de-passe.oublie'));
        $respInexistant->assertSessionHas('success');

        // Le message retourné est strictement identique
        $this->assertSame(
            'Si ce compte existe, un lien de réinitialisation vient d’être envoyé. Il est valable 60 minutes.',
            session('success')
        );

        // Le token enregistré pour l'étudiant est un hash SHA-256 (jamais en clair)
        $tokenRecord = PortalPasswordResetToken::where('email', $etudiant->email)->first();
        $this->assertNotNull($tokenRecord);
        $this->assertSame(64, strlen($tokenRecord->token_hash));
    }

    /* ── 5. Confidentialité JSON : email masqué et préremplissage ───── */

    public function test_identification_intelligente_mens_ne_divulgue_que_email_masque(): void
    {
        $etudiant = $this->creerEtudiant('08556677', 'sami.benali@universite.tn');

        $reponse = $this->postJson(route('inscription.identifier'), [
            'cin' => $etudiant->cin,
            'date_naissance' => '2004-05-15',
        ]);

        $reponse->assertOk();
        $donnees = $reponse->json();

        $this->assertSame('EXISTING_STUDENT', $donnees['status']);
        // Email masqué : « sam******@universite.tn »
        $this->assertSame('sam******@universite.tn', $donnees['masked_email']);
        // L'email complet et les données sensibles ne sont JAMAIS renvoyés
        $reponse->assertJsonMissing(['email' => 'sami.benali@universite.tn']);
        $reponse->assertJsonMissing(['password']);
        $reponse->assertJsonMissing(['telephone']);
        $reponse->assertJsonMissing(['adresse']);
    }

    /* ── 6. Vérification publique de document et QR code ─────────────── */

    public function test_verification_publique_document_ne_divulgue_que_strict_minimum(): void
    {
        $etudiant = $this->creerEtudiant('08998877', 'conf.doc@univ.tn', 'BEN SALAH', 'Karim');

        $dossier = StudentDossier::create([
            'etudiant_id' => $etudiant->id,
            'reference' => 'DOS-CONF-01',
            'status' => 'complete',
        ]);

        $doc = GeneratedDocument::create([
            'student_dossier_id' => $dossier->id,
            'type' => 'attestation_scolarite',
            'reference' => 'ATT-VERIF-2026',
            'code_verification' => 'VERIF-CODE-ABC123XYZ',
            'storage_path' => 'officiels/attestation_conf.pdf',
            'statut' => 'valide',
            'sha256' => hash('sha256', 'att-conf'),
            'contenu_hash' => hash('sha256', 'att-conf'),
            'snapshot_donnees' => [
                'nom' => 'BEN SALAH',
                'prenom' => 'Karim',
                'cin' => '08998877',
                'moyenne' => '16.50',
                'telephone' => '+21699112233',
            ],
        ]);

        // Visite publique avec l'IP cliente
        $reponse = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.42'])
            ->get(route('documents.verifier', $doc->code_verification));

        $reponse->assertOk();
        $html = $reponse->getContent();

        // 1. Nom masqué affiché (ex: B••••••••)
        $this->assertStringContainsString('B••••••••', $html);

        // 2. Zéro PII : CIN, prénom, téléphone, moyenne absents du HTML
        $this->assertStringNotContainsString('08998877', $html);
        $this->assertStringNotContainsString('Karim', $html);
        $this->assertStringNotContainsString('+21699112233', $html);
        $this->assertStringNotContainsString('16.50', $html);

        // 3. L'IP du visiteur est tracée sous forme hachée uniquement
        $trace = DocumentVerification::where('document_id', $doc->id)->latest()->first();
        $this->assertNotNull($trace);
        $this->assertNotSame('192.168.1.42', $trace->ip_hachee);
        $this->assertSame(hash('sha256', '192.168.1.42'.config('app.key')), $trace->ip_hachee);

        // 4. Référence inexistante -> 404 Not Found neutre
        $this->get(route('documents.verifier', 'REF-FAUSSE-999'))->assertNotFound();
    }

    /* ── 7. Nettoyage systématique des fichiers temporaires OCR ──────── */

    public function test_nettoyage_systematique_fichiers_temporaires_ocr(): void
    {
        $fichier = UploadedFile::fake()->create('cin.jpg', 200, 'image/jpeg');

        // Appel scan d'identité
        $reponse = $this->post(route('inscription.identity.scan'), [
            'document' => $fichier,
            'document_type' => 'cin',
        ]);

        // Le répertoire temporaire d'analyse doit être vide : aucun fichier persistant
        $fichiersRestants = Storage::disk('local')->allFiles('identity-scan/tmp');
        $this->assertEmpty($fichiersRestants, 'Le fichier temporaire de scan d\'identité doit être systématiquement supprimé après extraction.');
    }

    /* ── 8. Hygiène des journaux anti-PII ────────────────────────────── */

    public function test_hygiene_des_logs_anti_pii(): void
    {
        // 1. JournalisationPrivee masque les clés sensibles même imbriquées
        $donneesBrutes = [
            'password' => 'SuperSecret123',
            'cin' => '08123456',
            'telephone' => '+21698765432',
            'pin' => '1234',
            'otp' => '654321',
            'code_verification' => '998877',
            'token' => 'abcde12345',
            'date_naissance' => '2004-01-01',
            'nom' => 'Test',
        ];

        $assaini = JournalisationPrivee::assainir($donneesBrutes);

        $this->assertSame('[MASQUÉ]', $assaini['password']);
        $this->assertSame('[MASQUÉ]', $assaini['cin']);
        $this->assertSame('[MASQUÉ]', $assaini['telephone']);
        $this->assertSame('[MASQUÉ]', $assaini['pin']);
        $this->assertSame('[MASQUÉ]', $assaini['otp']);
        $this->assertSame('[MASQUÉ]', $assaini['code_verification']);
        $this->assertSame('[MASQUÉ]', $assaini['token']);
        $this->assertSame('[MASQUÉ]', $assaini['date_naissance']);
        $this->assertSame('Test', $assaini['nom']);

        // 2. L'événement d'inscription ne logge aucune donnée personnelle
        $etudiant = $this->creerEtudiant('08889900', 'journal.etudiant@univ.tn');

        $messages = [];
        Log::listen(function (MessageLogged $msg) use (&$messages) {
            $messages[] = $msg->message.' '.json_encode($msg->context);
        });

        Event::dispatch(new StudentRegistered($etudiant));

        $logComplet = implode("\n", $messages);
        $this->assertStringNotContainsString('08889900', $logComplet);
        $this->assertStringNotContainsString('journal.etudiant@univ.tn', $logComplet);
        $this->assertStringNotContainsString('PassSecret@123', $logComplet);
    }

    /* ── 9. Diagnostic : sonde /api/v1/ready sans détail infra ────────── */

    public function test_sonde_ready_ne_divulgue_aucun_detail_infrastructure_au_public(): void
    {
        // IP non autorisée (visiteur public)
        $reponse = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.195'])
            ->getJson(route('api.v1.ready'));

        $this->assertContains($reponse->status(), [200, 503]);
        $corps = $reponse->json();

        // Réponse minimale : status + timestamp uniquement
        $this->assertArrayHasKey('status', $corps);
        $this->assertArrayHasKey('timestamp', $corps);

        // Aucune divulgation d'infrastructure interne
        $this->assertArrayNotHasKey('sondes', $corps);
        $this->assertArrayNotHasKey('database', $corps);
        $this->assertArrayNotHasKey('cache', $corps);
        $this->assertArrayNotHasKey('disk', $corps);
    }

    /* ── 10. Modèle Étudiant : sérialisation sûre sans mot de passe ──── */

    public function test_modele_etudiant_masque_password_et_remember_token_en_serialisation(): void
    {
        $etudiant = $this->creerEtudiant('08445566', 'serial@univ.tn');
        $etudiant->remember_token = 'token_de_session_secret';

        $tableau = $etudiant->toArray();
        $json = json_encode($etudiant);
        $this->assertNotFalse($json);

        $this->assertArrayNotHasKey('password', $tableau);
        $this->assertArrayNotHasKey('remember_token', $tableau);
        $this->assertStringNotContainsString('PassSecret@123', $json);
        $this->assertStringNotContainsString('token_de_session_secret', $json);
    }
}
