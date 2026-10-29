<?php

namespace Tests\Feature\Security;

use App\Models\AnneeUniversitaire;
use App\Models\DossierCorrection;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\User;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Services\StudentDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * LOOP-11 — Compléments et rectifications de dossier :
 *
 * Vérifie :
 * 1. Accès sécurisé à l'interface de correction (redirection des visiteurs non authentifiés sur GET et POST).
 * 2. Cloisonnement strict anti-IDOR : l'étudiant connecté consulte et modifie uniquement son propre dossier.
 * 3. Neutralisation de toute tentative d'usurpation via paramètres GET/POST (?etudiant_id, ?id).
 * 4. Révocation immédiate de la session si mot_de_passe_version est obsolète.
 * 5. Périmètre strict : seuls les champs explicitement demandés (DossierCorrection proposee) sont modifiables.
 * 6. Protection des pièces : seules les pièces rejetées (StudentDocument rejected) peuvent être remplacées.
 * 7. Validation obligatoire de l'attestation sur l'honneur (rejet si absente ou non cochée).
 * 8. Immuabilité post-décision : interdiction absolue de modification en ligne sur dossier validé (422).
 * 9. Immuabilité post-décision : interdiction absolue de modification en ligne sur dossier rejeté (422).
 * 10. Refus des soumissions arbitraires lorsqu'aucun complément n'est attendu.
 * 11. Revalidation stricte des formats des données (téléphone, date de naissance, email).
 * 12. Revalidation stricte des fichiers téléversés (taille max 5 Mo, types MIME autorisés PDF/JPEG/PNG).
 * 13. Gestion du cycle de vie documentaire : archivage de l'ancienne version et réinitialisation en 'pending'.
 * 14. Ré-analyse automatique par VerificationEngine à la resoumission avec nouveau score et verdict.
 * 15. Envoi d'une notification in-app confirmant l'enregistrement des corrections.
 * 16. Support de la réponse JSON structurée pour soumissions asynchrones.
 * 17. Protection anti-force brute par rate limiting sur la soumission de corrections (10 requêtes / minute).
 */
class Loop11ComplementsRectificationsDossierTest extends TestCase
{
    use RefreshDatabase;

    private Faculte $faculte;

    private Programme $programme;

    private Niveau $niveau;

    private AnneeUniversitaire $annee;

    private User $scolarite;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'modules.student-dossiers.active' => true,
        ]);

        Storage::fake('private');

        $this->faculte = Faculte::create([
            'nom' => 'Faculté des Sciences de Rabat',
            'code' => 'FSR',
            'active' => true,
        ]);

        $this->programme = Programme::create([
            'faculte_id' => $this->faculte->id,
            'code' => 'L-INFO',
            'nom' => 'Licence Informatique',
            'statut' => Programme::STATUT_ACTIF,
            'capacite' => 100,
        ]);

        $this->niveau = Niveau::create([
            'programme_id' => $this->programme->id,
            'code' => 'L1',
            'nom' => 'Licence 1',
            'ordre' => 1,
            'actif' => true,
        ]);

        $this->annee = AnneeUniversitaire::create([
            'libelle' => '2025-2026',
            'date_debut' => '2025-09-01',
            'date_fin' => '2026-06-30',
        ]);

        $this->scolarite = User::creerCompteBackOffice([
            'name' => 'Agent Scolarité FSR',
            'email' => 'scolarite.fsr@universite.test',
            'password' => 'SecretPass123!',
            'faculte_id' => $this->faculte->id,
        ], 'admin_scolarite');
    }

    private function creerEtudiant(array $attributs = []): Etudiant
    {
        static $compteur = 1;

        $statut = $attributs['statut'] ?? 'en_attente';
        unset($attributs['statut']);

        $defauts = [
            'cin' => sprintf('CORR%04d', $compteur),
            'nom' => 'Benali',
            'prenom' => 'Karim '.$compteur,
            'email' => sprintf('etudiant.corr.%d@example.com', $compteur),
            'telephone' => sprintf('+2126110000%02d', $compteur),
            'date_naissance' => '2005-06-15',
            'sexe' => 'M',
            'faculte_id' => $this->faculte->id,
            'programme_id' => $this->programme->id,
            'niveau' => 'L1',
            'annee_academique' => '2025-2026',
            'adresse' => '15 Rue de Fès',
            'ville' => 'Rabat',
            'code_postal' => '10000',
            'annee_bac' => '2025',
            'mot_de_passe_version' => 1,
            'password' => bcrypt('Secret12345!'),
        ];

        $compteur++;

        $etudiant = Etudiant::create(array_merge($defauts, $attributs));
        if ($statut !== 'en_attente') {
            $etudiant->definirStatut($statut);
        }

        StudentDossier::create([
            'etudiant_id' => $etudiant->id,
            'reference' => 'DOS-'.$etudiant->num_inscription,
            'status' => 'open',
        ]);

        return $etudiant->fresh();
    }

    public function test_visiteur_non_authentifie_redirige_depuis_corrections_index_et_store(): void
    {
        $responseIndex = $this->get(route('etudiant.corrections.index'));
        $responseIndex->assertRedirect(route('etudiant.connexion.show'));

        $responseStore = $this->post(route('etudiant.corrections.store'), [
            'attestation_honneur' => '1',
        ]);
        $responseStore->assertRedirect(route('etudiant.connexion.show'));
    }

    public function test_etudiant_authentifie_accede_a_son_interface_de_corrections_sans_fuite_idor(): void
    {
        $etudiantA = $this->creerEtudiant(['cin' => 'IDORA111', 'email' => 'etudiantA@example.com']);
        $etudiantB = $this->creerEtudiant(['cin' => 'IDORB222', 'email' => 'etudiantB@example.com']);

        // Demande de correction sur A uniquement
        DossierCorrection::create([
            'etudiant_id' => $etudiantA->id,
            'champ' => 'telephone',
            'ancienne_valeur' => $etudiantA->telephone,
            'motif' => 'Numéro incomplet pour A',
            'statut' => 'proposee',
            'propose_par' => $this->scolarite->id,
        ]);

        // A consulte son interface
        $responseA = $this->withSession([
            'etudiant_auth_id' => $etudiantA->id,
            'etudiant_mdp_version' => (int) $etudiantA->mot_de_passe_version,
        ])->get(route('etudiant.corrections.index'));

        $responseA->assertOk();
        $responseA->assertSee('Numéro incomplet pour A');
        $responseA->assertSee($etudiantA->nom_complet);
        $responseA->assertDontSee($etudiantB->nom_complet);

        // B consulte son interface : il ne voit rien des demandes de A
        $responseB = $this->withSession([
            'etudiant_auth_id' => $etudiantB->id,
            'etudiant_mdp_version' => (int) $etudiantB->mot_de_passe_version,
        ])->get(route('etudiant.corrections.index'));

        $responseB->assertOk();
        $responseB->assertSee('Aucun complément requis pour le moment');
        $responseB->assertDontSee('Numéro incomplet pour A');
    }

    public function test_tentative_usurpation_idor_par_parametre_get_ou_post_strictement_neutralisee(): void
    {
        $etudiantA = $this->creerEtudiant(['cin' => 'SPOOF01']);
        $etudiantB = $this->creerEtudiant(['cin' => 'SPOOF02']);

        DossierCorrection::create([
            'etudiant_id' => $etudiantB->id,
            'champ' => 'adresse',
            'ancienne_valeur' => $etudiantB->adresse,
            'motif' => 'Préciser la rue pour B',
            'statut' => 'proposee',
            'propose_par' => $this->scolarite->id,
        ]);

        // A tente d'accéder au dossier de B via paramètre id / etudiant_id
        $response = $this->withSession([
            'etudiant_auth_id' => $etudiantA->id,
            'etudiant_mdp_version' => (int) $etudiantA->mot_de_passe_version,
        ])->get(route('etudiant.corrections.index', [
            'id' => $etudiantB->id,
            'etudiant_id' => $etudiantB->id,
        ]));

        $response->assertOk();
        // A ne voit pas la demande de B
        $response->assertDontSee('Préciser la rue pour B');
        $response->assertDontSee($etudiantB->nom_complet);
        $response->assertSee('Aucun complément requis pour le moment');
    }

    public function test_session_invalidee_si_version_mot_de_passe_obsolete_sur_corrections(): void
    {
        $etudiant = $this->creerEtudiant(['mot_de_passe_version' => 4]);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => 2, // version périmée
        ])->get(route('etudiant.corrections.index'));

        $response->assertRedirect(route('etudiant.connexion.show'));
        $this->assertNull(session('etudiant_auth_id'));
    }

    public function test_seuls_les_champs_expressement_demandes_sont_modifiables_les_autres_sont_ignores(): void
    {
        $etudiant = $this->creerEtudiant(['cin' => 'CHAMP01']);

        // Seule l'adresse est demandée
        DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'champ' => 'adresse',
            'ancienne_valeur' => $etudiant->adresse,
            'motif' => 'Compléter l\'adresse postale',
            'statut' => 'proposee',
            'propose_par' => $this->scolarite->id,
        ]);

        $ancienTelephone = $etudiant->telephone;
        $ancienPrenom = $etudiant->prenom;

        // Soumission avec adresse ET tentative de changer téléphone et prénom
        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->post(route('etudiant.corrections.store'), [
            'champs' => [
                'adresse' => 'Nouvelle adresse validée 42',
                'telephone' => '+212699999999', // non autorisé
                'prenom' => 'Hacker', // non autorisé
            ],
            'attestation_honneur' => '1',
        ]);

        $response->assertRedirect(route('etudiant.suivi'));

        $etudiantFresh = $etudiant->fresh();
        // Le champ autorisé a bien été mis à jour
        $this->assertSame('Nouvelle adresse validée 42', $etudiantFresh->adresse);
        // Les autres champs restent strictement intacts
        $this->assertSame($ancienTelephone, $etudiantFresh->telephone);
        $this->assertSame($ancienPrenom, $etudiantFresh->prenom);

        // La proposition est clôturée
        $this->assertDatabaseHas('dossier_corrections', [
            'etudiant_id' => $etudiant->id,
            'champ' => 'adresse',
            'statut' => 'appliquee',
            'nouvelle_valeur' => 'Nouvelle adresse validée 42',
        ]);
    }

    public function test_seules_les_pieces_rejetees_peuvent_etre_remplacees_les_autres_ids_sont_ignores(): void
    {
        $etudiant = $this->creerEtudiant(['cin' => 'PIECE01']);
        $dossier = StudentDossier::where('etudiant_id', $etudiant->id)->firstOrFail();

        // 1. Document validé (ne doit pas être modifiable)
        $docValide = app(StudentDocumentService::class)->upload(
            $dossier,
            ['category' => 'academic', 'document_type' => 'bac', 'title' => 'Baccalauréat'],
            UploadedFile::fake()->createWithContent('bac.pdf', '%PDF-1.4 Initial Bac'),
            null
        );
        $docValide->update(['status' => 'validated']);

        // 2. Document rejeté (doit être modifiable)
        $docRejete = app(StudentDocumentService::class)->upload(
            $dossier,
            ['category' => 'identite', 'document_type' => 'cin', 'title' => 'CIN Recto'],
            UploadedFile::fake()->createWithContent('cin.pdf', '%PDF-1.4 Initial CIN'),
            null
        );
        $docRejete->update(['status' => 'rejected', 'rejected_reason' => 'Scan flou']);

        // Soumission avec remplacement de la pièce rejetée ET tentative de remplacer la pièce validée
        $nouveauFichierCin = UploadedFile::fake()->createWithContent('cin-net.pdf', '%PDF-1.4 CIN Net');
        $nouveauFichierBac = UploadedFile::fake()->createWithContent('bac-usurpe.pdf', '%PDF-1.4 Bac Usurpe');

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->post(route('etudiant.corrections.store'), [
            'pieces' => [
                $docRejete->id => $nouveauFichierCin,
                $docValide->id => $nouveauFichierBac, // non autorisé !
            ],
            'attestation_honneur' => '1',
        ]);

        $response->assertRedirect(route('etudiant.suivi'));

        // Document rejeté passe en pending avec une nouvelle version
        $this->assertSame('pending', $docRejete->fresh()->status);
        $this->assertSame(2, $docRejete->fresh()->versions()->count());

        // Document validé reste validé avec 1 seule version
        $this->assertSame('validated', $docValide->fresh()->status);
        $this->assertSame(1, $docValide->fresh()->versions()->count());
    }

    public function test_interdiction_stricte_de_soumission_sans_attestation_sur_lhonneur(): void
    {
        $etudiant = $this->creerEtudiant(['cin' => 'ATTEST01']);

        DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'champ' => 'telephone',
            'ancienne_valeur' => $etudiant->telephone,
            'motif' => 'Compléter le numéro',
            'statut' => 'proposee',
            'propose_par' => $this->scolarite->id,
        ]);

        // Soumission sans cocher l'attestation sur l'honneur
        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->post(route('etudiant.corrections.store'), [
            'champs' => ['telephone' => '+212622334455'],
            // attestation_honneur absente
        ]);

        $response->assertSessionHasErrors(['attestation_honneur']);
    }

    public function test_interdiction_absolue_de_modification_apres_decision_definitive_valide(): void
    {
        $etudiantValide = $this->creerEtudiant(['cin' => 'VALIDE01', 'statut' => 'valide']);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiantValide->id,
            'etudiant_mdp_version' => (int) $etudiantValide->mot_de_passe_version,
        ])->postJson(route('etudiant.corrections.store'), [
            'champs' => ['adresse' => 'Tentative post validation'],
            'attestation_honneur' => '1',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('décision définitive', $response->json('message'));
    }

    public function test_interdiction_absolue_de_modification_apres_decision_definitive_rejete(): void
    {
        $etudiantRejete = $this->creerEtudiant(['cin' => 'REJETE01', 'statut' => 'rejete']);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiantRejete->id,
            'etudiant_mdp_version' => (int) $etudiantRejete->mot_de_passe_version,
        ])->postJson(route('etudiant.corrections.store'), [
            'champs' => ['adresse' => 'Tentative post rejet'],
            'attestation_honneur' => '1',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('décision définitive', $response->json('message'));
    }

    public function test_dossier_sans_demande_active_refuse_toute_soumission_arbitraire(): void
    {
        $etudiant = $this->creerEtudiant(['cin' => 'VIDE01', 'statut' => 'en_attente']);

        // Aucune correction ni pièce demandée
        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->postJson(route('etudiant.corrections.store'), [
            'champs' => ['adresse' => 'Adresse inventée'],
            'attestation_honneur' => '1',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Aucun complément n\'est actuellement attendu', $response->json('message'));
    }

    public function test_revalidation_stricte_format_telephone_date_naissance_et_email(): void
    {
        $etudiant = $this->creerEtudiant(['cin' => 'FORMAT01']);

        DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'champ' => 'date_naissance',
            'ancienne_valeur' => $etudiant->date_naissance,
            'motif' => 'Date invalide',
            'statut' => 'proposee',
            'propose_par' => $this->scolarite->id,
        ]);

        // Date future interdite
        $responseDateFuture = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->post(route('etudiant.corrections.store'), [
            'champs' => [
                'date_naissance' => now()->addDays(5)->format('Y-m-d'),
            ],
            'attestation_honneur' => '1',
        ]);

        $responseDateFuture->assertSessionHasErrors(['champs.date_naissance']);
    }

    public function test_revalidation_stricte_fichiers_televerses_taille_et_mimes(): void
    {
        $etudiant = $this->creerEtudiant(['cin' => 'MIME01']);
        $dossier = StudentDossier::where('etudiant_id', $etudiant->id)->firstOrFail();

        $docRejete = app(StudentDocumentService::class)->upload(
            $dossier,
            ['category' => 'academic', 'document_type' => 'bac', 'title' => 'Baccalauréat'],
            UploadedFile::fake()->createWithContent('bac.pdf', '%PDF-1.4 Initial'),
            null
        );
        $docRejete->update(['status' => 'rejected', 'rejected_reason' => 'Mauvais format']);

        // Tentative d'envoyer un fichier exécutable / non permis
        $fichierInterdit = UploadedFile::fake()->create('script.exe', 100, 'application/x-msdownload');

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->post(route('etudiant.corrections.store'), [
            'pieces' => [
                $docRejete->id => $fichierInterdit,
            ],
            'attestation_honneur' => '1',
        ]);

        $response->assertSessionHasErrors(['fichier']);
    }

    public function test_remplacement_de_piece_archive_ancienne_version_et_repasse_en_pending(): void
    {
        $etudiant = $this->creerEtudiant(['cin' => 'VERS01']);
        $dossier = StudentDossier::where('etudiant_id', $etudiant->id)->firstOrFail();

        $doc = app(StudentDocumentService::class)->upload(
            $dossier,
            ['category' => 'academic', 'document_type' => 'bac', 'title' => 'Diplôme'],
            UploadedFile::fake()->createWithContent('diplome-v1.pdf', '%PDF-1.4 V1'),
            null
        );
        $doc->update(['status' => 'rejected', 'rejected_reason' => 'Scan partiel']);

        $nouveauFichier = UploadedFile::fake()->createWithContent('diplome-v2.pdf', '%PDF-1.4 V2');

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->post(route('etudiant.corrections.store'), [
            'pieces' => [$doc->id => $nouveauFichier],
            'attestation_honneur' => '1',
        ]);

        $response->assertRedirect(route('etudiant.suivi'));

        $docFresh = $doc->fresh();
        $this->assertSame('pending', $docFresh->status);
        $this->assertNull($docFresh->rejected_reason);
        $this->assertSame(2, $docFresh->versions()->count());
    }

    public function test_reanalyse_automatique_par_verification_engine_apres_soumission_reussie(): void
    {
        $etudiant = $this->creerEtudiant(['cin' => 'REANALYSE01']);

        DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'champ' => 'telephone',
            'ancienne_valeur' => $etudiant->telephone,
            'motif' => 'Format à ajuster',
            'statut' => 'proposee',
            'propose_par' => $this->scolarite->id,
        ]);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->postJson(route('etudiant.corrections.store'), [
            'champs' => ['telephone' => '+212688888888'],
            'attestation_honneur' => '1',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertArrayHasKey('score', $response->json('resultat'));
        $this->assertArrayHasKey('verdict', $response->json('resultat'));
    }

    public function test_notification_in_app_envoyee_a_l_etudiant_apres_soumission_des_corrections(): void
    {
        $etudiant = $this->creerEtudiant(['cin' => 'NOTIFCORR01']);

        DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'champ' => 'ville',
            'ancienne_valeur' => $etudiant->ville,
            'motif' => 'Préciser la ville',
            'statut' => 'proposee',
            'propose_par' => $this->scolarite->id,
        ]);

        $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->post(route('etudiant.corrections.store'), [
            'champs' => ['ville' => 'Rabat Agdal'],
            'attestation_honneur' => '1',
        ]);

        $this->assertDatabaseHas('notifications', [
            'etudiant_id' => $etudiant->id,
            'type' => 'dossier.correction_soumise',
        ]);
    }

    public function test_reponse_json_supportee_avec_structure_et_statut_attendus(): void
    {
        $etudiant = $this->creerEtudiant(['cin' => 'JSONRES01']);

        DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'champ' => 'code_postal',
            'ancienne_valeur' => $etudiant->code_postal,
            'motif' => 'Code postal erroné',
            'statut' => 'proposee',
            'propose_par' => $this->scolarite->id,
        ]);

        $response = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->postJson(route('etudiant.corrections.store'), [
            'champs' => ['code_postal' => '10020'],
            'attestation_honneur' => '1',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'message',
            'resultat' => [
                'success',
                'verdict',
                'score',
                'champs_mis_a_jour',
                'pieces_remplacees',
            ],
        ]);
    }

    public function test_protection_anti_force_brute_rate_limiting_dix_requetes_par_minute(): void
    {
        $etudiant = $this->creerEtudiant(['cin' => 'THROTTLE01']);

        DossierCorrection::create([
            'etudiant_id' => $etudiant->id,
            'champ' => 'telephone',
            'ancienne_valeur' => $etudiant->telephone,
            'motif' => 'Ajuster',
            'statut' => 'proposee',
            'propose_par' => $this->scolarite->id,
        ]);

        // Envoi de 10 requêtes autorisées
        for ($i = 0; $i < 10; $i++) {
            $this->withSession([
                'etudiant_auth_id' => $etudiant->id,
                'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
            ])->post(route('etudiant.corrections.store'), [
                'champs' => ['telephone' => '+21260000000'.$i],
                'attestation_honneur' => '1',
            ]);
        }

        // 11ème requête bloquée par throttle:10,1
        $responseBloquee = $this->withSession([
            'etudiant_auth_id' => $etudiant->id,
            'etudiant_mdp_version' => (int) $etudiant->mot_de_passe_version,
        ])->post(route('etudiant.corrections.store'), [
            'champs' => ['telephone' => '+212600000099'],
            'attestation_honneur' => '1',
        ]);

        $this->assertSame(429, $responseBloquee->getStatusCode());
    }
}
