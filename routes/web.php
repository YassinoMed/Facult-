<?php

use App\Http\Controllers\Admin\AdministrateurController;
use App\Http\Controllers\Admin\AdmissionController;
use App\Http\Controllers\Admin\AffectationController;
use App\Http\Controllers\Admin\AffectationProposalController;
use App\Http\Controllers\Admin\ArchiveController;
use App\Http\Controllers\Admin\AttestationController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\AutomaticAssignmentController;
use App\Http\Controllers\Admin\BiController;
use App\Http\Controllers\Admin\CockpitController;
use App\Http\Controllers\Admin\ConfigurationAcademiqueController;
use App\Http\Controllers\Admin\DashboardFaculteController;
use App\Http\Controllers\Admin\DataExchangeController;
use App\Http\Controllers\Admin\DocumentAcademiqueController;
use App\Http\Controllers\Admin\DocumentCenterController;
use App\Http\Controllers\Admin\EnseignantController;
use App\Http\Controllers\Admin\EtudiantController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\FaculteController;
use App\Http\Controllers\Admin\FavoriController;
use App\Http\Controllers\Admin\FileTraitementController;
use App\Http\Controllers\Admin\GedController;
use App\Http\Controllers\Admin\GroupeController;
use App\Http\Controllers\Admin\ImportTemplateController;
use App\Http\Controllers\Admin\InscriptionAcademiqueController;
use App\Http\Controllers\Admin\InscriptionGestionController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\ListesController;
use App\Http\Controllers\Admin\MaintenanceController;
use App\Http\Controllers\Admin\MaquetteController;
use App\Http\Controllers\Admin\MensReferenceController;
use App\Http\Controllers\Admin\MfaController;
use App\Http\Controllers\Admin\MotDePasseController;
use App\Http\Controllers\Admin\NoteController;
use App\Http\Controllers\Admin\NotificationTemplateController;
use App\Http\Controllers\Admin\ParametreController;
use App\Http\Controllers\Admin\ParcoursEtudiantController;
use App\Http\Controllers\Admin\PeriodeInscriptionController;
use App\Http\Controllers\Admin\PersonnelController;
use App\Http\Controllers\Admin\PopulationController;
use App\Http\Controllers\Admin\PreferenceController;
use App\Http\Controllers\Admin\QualiteController;
use App\Http\Controllers\Admin\RechercheController;
use App\Http\Controllers\Admin\ReinscriptionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SalleController;
use App\Http\Controllers\Admin\SauvegardesController;
use App\Http\Controllers\Admin\SessionsController;
use App\Http\Controllers\Admin\SituationController;
use App\Http\Controllers\Admin\SmtpConfigurationController;
use App\Http\Controllers\Admin\StatistiquesController;
use App\Http\Controllers\Admin\SystemeController;
use App\Http\Controllers\Admin\TableauDeBordController;
use App\Http\Controllers\Admin\VerificationController;
use App\Http\Controllers\Admin\VueSauvegardeeController;
use App\Http\Controllers\Enseignant\ConnexionController as EnseignantConnexionController;
use App\Http\Controllers\Enseignant\DemandeController as EnseignantDemandeController;
use App\Http\Controllers\Enseignant\DocumentController as EnseignantDocumentController;
use App\Http\Controllers\Enseignant\DocumentsController as EnseignantMesDocumentsController;
use App\Http\Controllers\Enseignant\ProfilController as EnseignantProfilController;
use App\Http\Controllers\Enseignant\ReclamationController as EnseignantReclamationController;
use App\Http\Controllers\Enseignant\TableauDeBordController as EnseignantTableauDeBordController;
use App\Http\Controllers\Etudiant\ArchiveController as EtudiantArchiveController;
use App\Http\Controllers\Etudiant\ConnexionController;
use App\Http\Controllers\Etudiant\DossierComplementController as EtudiantDossierComplementController;
use App\Http\Controllers\Etudiant\ExamenController as EtudiantExamenController;
use App\Http\Controllers\Etudiant\ProfilController;
use App\Http\Controllers\Etudiant\RecepisseController as EtudiantRecepisseController;
use App\Http\Controllers\Etudiant\RendezVousScolariteController as EtudiantRendezVousScolariteController;
use App\Http\Controllers\Etudiant\SuiviDossierController as EtudiantSuiviDossierController;
use App\Http\Controllers\Etudiant\TableauDeBordController as EtudiantTableauDeBordController;
use App\Http\Controllers\InscriptionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RecherchePortailController;
use App\Http\Controllers\SuiviPublicController;
use App\Http\Controllers\VerifyDocumentController;
use App\Http\Controllers\VerrouillageController;
use App\Services\DataExchange\DefinitionRegistry;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes - Inscription
|--------------------------------------------------------------------------
*/
Route::get('/', [InscriptionController::class, 'create'])->name('inscription.create');
Route::view('/hors-ligne', 'hors-ligne')->name('hors-ligne');
Route::post('/inscription', [InscriptionController::class, 'store'])
    ->name('inscription.store')
    ->middleware('throttle:10,1');
Route::get('/inscription/success', [InscriptionController::class, 'success'])->name('inscription.success');

// LOOP périodes : état précis public (même source que la soumission).
Route::get('/inscription/periode-etat', [InscriptionController::class, 'etatPeriode'])
    ->name('inscription.periode-etat')
    ->middleware('throttle:30,1');

// LOOP 04 : checklist documentaire dynamique par type d'inscription.
Route::get('/inscription/pieces-requises', [InscriptionController::class, 'checklistDocuments'])
    ->name('inscription.pieces-requises')
    ->middleware('throttle:30,1');

// LOOP 08 : Orientation & options de parcours selon éligibilité académique.
Route::get('/inscription/orientation-options', [InscriptionController::class, 'optionsOrientation'])
    ->name('inscription.orientation-options')
    ->middleware('throttle:30,1');

// FAQ officielle (LOOP 15) : lecture seule, sourcée, oriente vers la
// scolarité les questions non couvertes. Aucune donnée de dossier.
Route::get('/inscription/faq', [InscriptionController::class, 'faq'])
    ->name('inscription.faq')
    ->middleware('throttle:30,1');
Route::post('/inscription/faq/assistant', [InscriptionController::class, 'interrogerAssistantFaq'])
    ->name('inscription.faq.assistant')
    ->middleware('throttle:30,1');

/*
|--------------------------------------------------------------------------
| Vérification publique des documents officiels (Phase 7 §50-52)
|--------------------------------------------------------------------------
| GET sans authentification : référence opaque uniquement (ni ID
| séquentiel devinable, ni PII en réponse), rate limiting anti-scan.
*/
Route::get('/verifier/{reference}', [VerifyDocumentController::class, 'verifier'])
    ->name('documents.verifier')
    ->middleware('throttle:30,1')
    ->where('reference', '[A-Za-z0-9\-]+');

// Phase 16 §16.3 : vérification par code opaque (QR imprimé).
Route::get('/verify/{code}', [VerifyDocumentController::class, 'verifier'])
    ->name('documents.verify')
    ->middleware('throttle:30,1')
    ->where('code', '[A-Za-z0-9\-]+');

/*
|--------------------------------------------------------------------------
| Recherche CIN — pré-remplissage public (§1-6)
|--------------------------------------------------------------------------
| POST : le CIN n'apparaît ni dans les URLs ni dans les logs d'accès.
| CSRF obligatoire + rate limiting 10/min (anti-énumération §5).
| Aucune écriture : uniquement une lecture DTO whitelist.
*/
Route::post('/inscription/verifier-cin', [InscriptionController::class, 'verifierCin'])
    ->name('inscription.verifierCin')
    ->middleware('throttle:10,1');

// Identification intelligente MENS (CIN + naissance, throttle strict).
Route::post('/inscription/identifier', [InscriptionController::class, 'identifier'])
    ->name('inscription.identifier')
    ->middleware('throttle:5,1');

/*
|--------------------------------------------------------------------------
| Scan CIN / Passeport + OCR (§1-18) — pré-remplissage public
|--------------------------------------------------------------------------
| POST (CSRF obligatoire) : OCR coûteux ⇒ rate limiting dédié
| identity.scan (configurable). Upload temporaire privé, supprimé
| après extraction (§13 option A). Aucune écriture en base ici.
*/
Route::post('/inscription/identity/scan', [InscriptionController::class, 'scanIdentity'])
    ->name('inscription.identity.scan')
    ->middleware('throttle:identity.scan');

// Suivi public de dossier (LOOP 10) : double facteur confidentiel anti-énumération.
Route::get('/inscription/suivi', [SuiviPublicController::class, 'index'])->name('inscription.suivi');
Route::post('/inscription/suivi', [SuiviPublicController::class, 'rechercher'])
    ->middleware('throttle:10,1')
    ->name('inscription.suivi.rechercher');

/*
|--------------------------------------------------------------------------
| Espace Étudiant (authentification par email/CIN + mot de passe)
|--------------------------------------------------------------------------
| Profil ÉTUDIANT (§2-3) : session `etudiant_auth_id` + middleware
| EnsureStudent (alias etudiant.auth/student). Totalement indépendant du
| back-office : un étudiant n'est jamais un administrateur, et
| réciproquement. L'étudiant consulte UNIQUEMENT son propre dossier.
*/
Route::prefix('etudiant')->name('etudiant.')->group(function () {
    // Connexion / Déconnexion
    Route::get('connexion', [ConnexionController::class, 'show'])->name('connexion.show');
    Route::post('connexion', [ConnexionController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('connexion.login');
    Route::post('deconnexion', [ConnexionController::class, 'logout'])->name('connexion.logout');

    // Espace authentifié : son dossier uniquement (session-scoped, anti-IDOR)
    Route::middleware('etudiant.auth')->group(function () {
        Route::get('tableau-de-bord', [EtudiantTableauDeBordController::class, 'index'])->name('dashboard');
        // Suivi de dossier d'inscription (LOOP 10) : session-scoped, anti-IDOR
        Route::get('suivi', [EtudiantSuiviDossierController::class, 'index'])->name('suivi');
        // Compléments et rectifications de dossier (LOOP 11) : session-scoped, anti-IDOR
        Route::get('corrections', [EtudiantDossierComplementController::class, 'index'])->name('corrections.index');
        Route::post('corrections', [EtudiantDossierComplementController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('corrections.store');
        Route::get('notifications', [NotificationController::class, 'indexEtudiant'])->name('notifications.index');
        Route::patch('notifications/{notification}/lire', [NotificationController::class, 'lireEtudiant'])->name('notifications.lire');
        Route::post('notifications/lire-tout', [NotificationController::class, 'toutLireEtudiant'])->name('notifications.lire-tout');
        Route::get('profil', [ProfilController::class, 'show'])->name('profil.show');
        Route::get('profil/modifier', [ProfilController::class, 'edit'])->name('profil.edit');
        Route::patch('profil', [ProfilController::class, 'update'])
            ->middleware('throttle:10,1')
            ->name('profil.update');
        Route::patch('profil/mot-de-passe', [ProfilController::class, 'updatePassword'])
            ->middleware('throttle:5,1')
            ->name('profil.password');

        // Consultation de SA archive académique (sections dédiées §3)
        Route::get('archives', [EtudiantArchiveController::class, 'show'])->name('archives.show');
        // Parcours LMD en lecture seule (LOOP 13) : session-scopé.
        Route::get('parcours', [EtudiantArchiveController::class, 'parcours'])->name('parcours');
        // PDF informatif du parcours (LOOP C4) : ni relevé, ni attestation.
        Route::get('parcours/pdf', [EtudiantArchiveController::class, 'parcoursPdf'])
            ->middleware('throttle:10,1')
            ->name('parcours.pdf');
        Route::get('inscriptions', [EtudiantArchiveController::class, 'inscriptions'])->name('inscriptions.index');
        Route::get('notes', [EtudiantArchiveController::class, 'notes'])->name('notes.index');
        Route::get('documents', [EtudiantArchiveController::class, 'documents'])->name('documents.index');
        Route::get('historique', [EtudiantArchiveController::class, 'historique'])->name('historique.index');
        Route::get('archives/documents/{document}/download', [EtudiantArchiveController::class, 'download'])
            ->middleware('throttle:10,1')
            ->name('archives.documents.download');
        // Examens & Convocations (§12, §18) : périmètre session strict
        Route::get('examens', [EtudiantExamenController::class, 'index'])->name('examens.index');
        Route::get('examens/{candidat}/convocation', [EtudiantExamenController::class, 'convocation'])
            ->middleware('throttle:10,1')
            ->name('examens.convocation');

        // Récépissé de dépôt (LOOP 13) : PDF officiel à la demande, sans
        // stockage, session-scopé — preuve de soumission, pas de décision.
        Route::get('recepisse', [EtudiantRecepisseController::class, 'telecharger'])
            ->middleware('throttle:10,1')
            ->name('recepisse');

        // Recherche portail (LOOP 18) : périmètre session strict, 2 caractères min.
        Route::get('recherche', [RecherchePortailController::class, 'etudiant'])
            ->middleware('throttle:30,1')
            ->name('recherche');

        // Rendez-vous et assistance scolarité (LOOP 16) : créneaux réels,
        // motif catégorisé, anti-double réservation, confirmation après écriture.
        Route::get('rendez-vous/nouveau', [EtudiantRendezVousScolariteController::class, 'nouveau'])
            ->name('rendez-vous.nouveau');
        Route::get('rendez-vous/creneaux', [EtudiantRendezVousScolariteController::class, 'creneaux'])
            ->middleware('throttle:30,1')
            ->name('rendez-vous.creneaux');
        Route::post('rendez-vous', [EtudiantRendezVousScolariteController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('rendez-vous.store');
        Route::post('rendez-vous/{demande}/annuler', [EtudiantRendezVousScolariteController::class, 'annuler'])
            ->middleware('throttle:10,1')
            ->name('rendez-vous.annuler');
        Route::post('rendez-vous/{demande}/deplacer', [EtudiantRendezVousScolariteController::class, 'deplacer'])
            ->middleware('throttle:10,1')
            ->name('rendez-vous.deplacer');
    });
});

/*
|--------------------------------------------------------------------------
| Espace Enseignant (authentification par email/matricule + mot de passe)
|--------------------------------------------------------------------------
| Profil ENSEIGNANT (§2-3) : session `enseignant_auth_id` + middleware
| EnsureTeacher (alias enseignant.auth). Totalement indépendant du
| back-office : un enseignant n'est jamais un administrateur, et
| réciproquement. L'enseignant consulte UNIQUEMENT ses propres données
| (résolution teacher_id depuis la session, jamais depuis l'URL).
*/
Route::prefix('enseignant')->name('enseignant.')->group(function () {
    Route::get('/', fn () => redirect()->route('enseignant.dashboard'));

    // Connexion / Déconnexion
    Route::get('connexion', [EnseignantConnexionController::class, 'show'])->name('connexion.show');
    Route::post('connexion', [EnseignantConnexionController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('connexion.login');
    Route::post('deconnexion', [EnseignantConnexionController::class, 'logout'])->name('connexion.logout');

    // Espace authentifié : ses données uniquement (session-scoped, anti-IDOR)
    Route::middleware('enseignant.auth')->group(function () {
        Route::get('tableau-de-bord', [EnseignantTableauDeBordController::class, 'index'])->name('dashboard');

        // Notifications
        Route::get('notifications', [NotificationController::class, 'indexEnseignant'])->name('notifications.index');
        Route::patch('notifications/{notification}/lire', [NotificationController::class, 'lireEnseignant'])->name('notifications.lire');
        Route::post('notifications/lire-tout', [NotificationController::class, 'toutLireEnseignant'])->name('notifications.lire-tout');

        // Profil
        Route::get('profil', [EnseignantProfilController::class, 'show'])->name('profil.show');
        Route::get('profil/modifier', [EnseignantProfilController::class, 'edit'])->name('profil.edit');
        Route::patch('profil', [EnseignantProfilController::class, 'update'])
            ->middleware('throttle:10,1')
            ->name('profil.update');
        Route::patch('profil/mot-de-passe', [EnseignantProfilController::class, 'updatePassword'])
            ->middleware('throttle:5,1')
            ->name('profil.password');

        // Documents : bibliothèque générée depuis mes données (canonique,
        // routée + testée). Le téléchargement unitaire vit plus bas.
        Route::get('documents', [EnseignantMesDocumentsController::class, 'index'])->name('documents.index');

        // Documents personnels & officiels — CONVERGENCE (co-édition) :
        // l'index canonique vit dans les modules (routé + testé) ;
        // seul le téléchargement unitaire reste ici (URI distincte).
        Route::get('documents/{document}/telecharger', [EnseignantDocumentController::class, 'download'])
            ->middleware('throttle:20,1')
            ->name('documents.telecharger');

        // Emploi du temps, présences, surveillances : implémentations
        // canoniques dans les modules (routées + testées) — pas de doublon ici.

        // Recherche portail (LOOP 18) : périmètre session strict, 2 caractères min.
        Route::get('recherche', [RecherchePortailController::class, 'enseignant'])
            ->middleware('throttle:30,1')
            ->name('recherche');

        // Demandes administratives
        Route::get('demandes', [EnseignantDemandeController::class, 'index'])->name('demandes.index');
        Route::get('demandes/creer', [EnseignantDemandeController::class, 'create'])->name('demandes.create');
        Route::post('demandes', [EnseignantDemandeController::class, 'store'])->name('demandes.store');
        Route::get('demandes/{demande}', [EnseignantDemandeController::class, 'show'])->name('demandes.show');
        Route::get('demandes/{demande}/piece-jointe', [EnseignantDemandeController::class, 'pieceJointe'])->name('demandes.piece-jointe');
        Route::post('demandes/{demande}/annuler', [EnseignantDemandeController::class, 'annuler'])->name('demandes.annuler');

        // Réclamations & Signalements
        Route::get('reclamations', [EnseignantReclamationController::class, 'index'])->name('reclamations.index');
        Route::get('reclamations/creer', [EnseignantReclamationController::class, 'create'])->name('reclamations.create');
        Route::post('reclamations', [EnseignantReclamationController::class, 'store'])->name('reclamations.store');
        Route::get('reclamations/{reclamation}', [EnseignantReclamationController::class, 'show'])->name('reclamations.show');
        Route::get('reclamations/{reclamation}/piece-jointe', [EnseignantReclamationController::class, 'pieceJointe'])->name('reclamations.piece-jointe');
        Route::post('reclamations/{reclamation}/cloturer', [EnseignantReclamationController::class, 'cloturer'])->name('reclamations.cloturer');
    });
});

/*
|--------------------------------------------------------------------------
| Back-office Scolarité (authentification requise + RBAC granulaire)
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->group(function () {

    // Connexion du personnel de scolarité (invités uniquement)
    Route::middleware('guest')->group(function () {
        Route::get('connexion', [AuthController::class, 'show'])->name('connexion.show');
        Route::post('connexion', [AuthController::class, 'login'])
            ->middleware('throttle:5,1')
            ->name('connexion.login');
        // Double facteur TOTP (LOOP J) : défi post-mot de passe, throttlé.
        Route::get('connexion/defi', [AuthController::class, 'defi'])->name('connexion.defi');
        Route::post('connexion/defi', [AuthController::class, 'verifierDefi'])
            ->middleware('throttle:5,1')
            ->name('connexion.defi.verifier');
        // Mot de passe oublié (broker `users`, 60 min, anti-énumération).
        Route::get('mot-de-passe/oublie', [MotDePasseController::class, 'oublie'])->name('mot-de-passe.oublie');
        Route::post('mot-de-passe/lien', [MotDePasseController::class, 'envoyerLien'])
            ->middleware('throttle:5,1')
            ->name('mot-de-passe.envoyer');
        Route::get('mot-de-passe/reinitialiser/{token}', [MotDePasseController::class, 'reinitialiser'])->name('mot-de-passe.reinitialiser');
        Route::post('mot-de-passe/reinitialiser', [MotDePasseController::class, 'enregistrer'])
            ->middleware('throttle:5,1')
            ->name('mot-de-passe.enregistrer');
    });
    Route::post('deconnexion', [AuthController::class, 'logout'])
        ->middleware('auth')
        ->name('connexion.logout');

    // Sessions actives : chacun gère les siennes (révocation).
    Route::get('sessions', [SessionsController::class, 'index'])
        ->middleware('auth')->name('sessions.index');
    Route::delete('sessions/{session}', [SessionsController::class, 'destroy'])
        ->middleware(['auth', 'throttle:10,1'])->name('sessions.destroy');

    // Double facteur TOTP du compte courant (LOOP J) : auth requise.
    Route::get('double-facteur', [MfaController::class, 'configurer'])
        ->middleware('auth')->name('mfa.configurer');

    // Verrouillage secondaire par PIN (LOOP N) : auth requise, exempté
    // du verrou par son nom (`admin.verrouillage.*`, SecondaireVerrou).
    Route::get('verrouillage/code', [VerrouillageController::class, 'code'])
        ->middleware('auth')->name('verrouillage.code');
    Route::post('verrouillage/code', [VerrouillageController::class, 'definir'])
        ->middleware(['auth', 'throttle:10,1'])->name('verrouillage.definir');
    Route::post('verrouillage/verifier', [VerrouillageController::class, 'verifier'])
        ->middleware(['auth', 'throttle:5,1'])->name('verrouillage.verifier');
    Route::post('verrouillage/verrouiller', [VerrouillageController::class, 'verrouiller'])
        ->middleware('auth')->name('verrouillage.verrouiller');
    Route::post('verrouillage/deconnecter', [VerrouillageController::class, 'deconnecter'])
        ->name('verrouillage.deconnecter');
    Route::post('double-facteur/confirmer', [MfaController::class, 'confirmer'])
        ->middleware(['auth', 'throttle:10,1'])->name('mfa.confirmer');
    Route::post('double-facteur/nouveau-secret', [MfaController::class, 'nouveauSecret'])
        ->middleware(['auth', 'throttle:10,1'])->name('mfa.nouveau-secret');
    Route::post('double-facteur/desactiver', [MfaController::class, 'desactiver'])
        ->middleware(['auth', 'throttle:10,1'])->name('mfa.desactiver');
    /*
    | Zone authentifiée — profil BACK-OFFICE (§7/§10) : tout compte doit
    | avoir un rôle RBAC valide ET un compte actif (lus en base via le
    | middleware `admin` = EnsureBackOffice). L'isolation par faculté
    | est ensuite appliquée par les policies + requêtes scopées.
    */
    Route::middleware(['auth', 'admin', 'secondaire.verrou'])->group(function () {

        // Recherche globale (§7) : sections scopées + permission-gated.
        Route::get('recherche', [RechercheController::class, 'global'])->name('recherche');
        Route::get('recherche/instantanee', [RechercheController::class, 'instantanee'])
            ->middleware('throttle:30,1')
            ->name('recherche.instantanee');
        Route::get('recherche/contexte', [RechercheController::class, 'contexte'])
            ->middleware('throttle:30,1')
            ->name('recherche.contexte');

        // Favoris (Phase 7 §7.4) : strictement personnels.
        Route::post('favoris', [FavoriController::class, 'basculer'])
            ->middleware('throttle:20,1')->name('favoris.basculer');
        Route::delete('favoris/{favori}', [FavoriController::class, 'supprimer'])
            ->middleware('throttle:20,1')->name('favoris.supprimer');

        // Préférences (Phase 7 §7.4) : densité, lignes par page.
        Route::get('preferences', [PreferenceController::class, 'afficher'])->name('preferences');
        Route::patch('preferences', [PreferenceController::class, 'mettreAJour'])
            ->middleware('throttle:20,1')->name('preferences.update');

        // Cockpit (§8) : à traiter, urgences, agenda, activité.
        Route::get('cockpit', [CockpitController::class, 'index'])
            ->middleware('can:cockpit.view')->name('cockpit.index');

        // Centre documentaire (Phase 16 §16.8) : tableau de bord,
        // vue document + historique, génération, révocation, lots.
        Route::get('documents-center', [DocumentCenterController::class, 'index'])
            ->middleware('can:document.view')->name('documents-center.index');
        Route::get('documents-center/{document}', [DocumentCenterController::class, 'show'])
            ->middleware('can:document.view')->name('documents-center.show');
        Route::post('etudiants/{etudiant}/documents-center', [DocumentCenterController::class, 'generer'])
            ->middleware(['can:document.generate', 'throttle:10,1'])->name('documents-center.generer');
        Route::post('documents-center/{document}/revoquer', [DocumentCenterController::class, 'revoquer'])
            ->middleware(['can:document.revoke', 'throttle:10,1'])->name('documents-center.revoquer');
        Route::post('documents-center/{document}/approuver', [DocumentCenterController::class, 'approuver'])
            ->middleware(['can:document.approve', 'throttle:10,1'])->name('documents-center.approuver');
        Route::post('documents-center/{document}/emettre', [DocumentCenterController::class, 'emettre'])
            ->middleware(['can:document.issue', 'throttle:10,1'])->name('documents-center.emettre');
        Route::post('documents-center/{document}/envoyer', [DocumentCenterController::class, 'envoyer'])
            ->middleware(['can:document.send', 'throttle:10,1'])->name('documents-center.envoyer');
        Route::get('documents-center/{document}/telecharger', [DocumentCenterController::class, 'telecharger'])
            ->middleware(['can:document.download', 'throttle:20,1'])->name('documents-center.telecharger');
        Route::get('documents-center/{document}/imprimer', [DocumentCenterController::class, 'imprimer'])
            ->middleware(['can:document.print', 'throttle:20,1'])->name('documents-center.imprimer');
        Route::get('attestations', [AttestationController::class, 'index'])
            ->middleware('can:document.generate')->name('attestations.index');
        Route::post('attestations/generer', [AttestationController::class, 'generer'])
            ->middleware(['can:document.generate', 'throttle:10,1'])->name('attestations.generer');
        Route::get('maquettes', [MaquetteController::class, 'index'])
            ->middleware('can:document_template.view')->name('maquettes.index');
        Route::get('mens', [MensReferenceController::class, 'index'])
            ->middleware('can:data.import')->name('mens.index');
        Route::post('mens/{reference}/creer-dossier', [MensReferenceController::class, 'creerDossier'])
            ->middleware(['can:data.import', 'throttle:10,1'])->name('mens.creer-dossier');
        Route::get('maquettes/creer', [MaquetteController::class, 'create'])
            ->middleware('can:document_template.edit')->name('maquettes.create');
        Route::post('maquettes', [MaquetteController::class, 'store'])
            ->middleware(['can:document_template.edit', 'throttle:20,1'])->name('maquettes.store');
        Route::get('maquettes/{template}', [MaquetteController::class, 'show'])
            ->middleware('can:document_template.view')->name('maquettes.show');
        Route::get('maquettes/{template}/apercu-pdf', [MaquetteController::class, 'apercuPdf'])
            ->middleware(['can:document_template.view', 'throttle:20,1'])->name('maquettes.apercu-pdf');
        Route::get('maquettes/{template}/modifier', [MaquetteController::class, 'edit'])
            ->middleware('can:document_template.edit')->name('maquettes.edit');
        Route::patch('maquettes/{template}', [MaquetteController::class, 'update'])
            ->middleware(['can:document_template.edit', 'throttle:20,1'])->name('maquettes.update');
        Route::post('maquettes/{template}/versions', [MaquetteController::class, 'versionner'])
            ->middleware(['can:document_template.edit', 'throttle:20,1'])->name('maquettes.versionner');
        Route::post('maquettes/{template}/publier', [MaquetteController::class, 'publier'])
            ->middleware(['can:document_template.publish', 'throttle:10,1'])->name('maquettes.publier');
        Route::post('maquettes/{template}/archiver', [MaquetteController::class, 'archiver'])
            ->middleware(['can:document_template.edit', 'throttle:10,1'])->name('maquettes.archiver');
        Route::post('maquettes/{template}/dupliquer', [MaquetteController::class, 'dupliquer'])
            ->middleware(['can:document_template.edit', 'throttle:10,1'])->name('maquettes.dupliquer');
        Route::post('documents-center/lots', [DocumentCenterController::class, 'lancerLot'])
            ->middleware(['can:document_batch.run', 'throttle:10,1'])->name('documents-center.lots.lancer');
        Route::post('documents-center/lots/{lot}/annuler', [DocumentCenterController::class, 'annulerLot'])
            ->middleware(['can:document_batch.cancel', 'throttle:10,1'])->name('documents-center.lots.annuler');

        // Vues sauvegardées (§7.8) : filtres nommés par utilisateur.
        Route::post('vues', [VueSauvegardeeController::class, 'store'])
            ->middleware('throttle:20,1')->name('vues.store');
        Route::delete('vues/{vue}', [VueSauvegardeeController::class, 'destroy'])
            ->middleware('throttle:10,1')->name('vues.destroy');

        // Tableau de bord (§15) : statistiques agrégées en une requête,
        // périmètre faculté appliqué serveur pour les admin_faculte.
        Route::get('/', [TableauDeBordController::class, 'index'])->name('dashboard');

        // Centre de notifications (§4-6).
        Route::get('notifications', [NotificationController::class, 'indexAdmin'])->name('notifications.index');
        Route::patch('notifications/{notification}/lire', [NotificationController::class, 'lireAdmin'])->name('notifications.lire');
        Route::post('notifications/lire-tout', [NotificationController::class, 'toutLireAdmin'])->name('notifications.lire-tout');

        // Gabarits du centre de communication (Phase 15 §15.1).
        Route::get('notifications/gabarits', [NotificationTemplateController::class, 'index'])
            ->middleware('can:configuration.manage')
            ->name('notifications.templates');
        Route::patch('notifications/gabarits/{gabarit}', [NotificationTemplateController::class, 'update'])
            ->middleware(['can:configuration.manage', 'throttle:20,1'])
            ->name('notifications.templates.update');

        /*
        | Dashboard dédié des administrateurs de faculté (§14) : données
        | automatiquement limitées à SA faculté (middleware faculty.admin
        | + scoping serveur dans le contrôleur).
        */
        Route::get('ma-faculte', [DashboardFaculteController::class, 'index'])
            ->middleware('faculty.admin')
            ->name('ma-faculte');

        /*
        | Gestion des administrateurs (§15) — Super Admin uniquement :
        | création de comptes back-office, rattachement faculté,
        | activation/désactivation. Jamais accessible aux autres profils.
        */
        Route::prefix('administrateurs')->name('administrateurs.')->middleware('super_admin')->group(function () {
            Route::get('/', [AdministrateurController::class, 'index'])->name('index');
            Route::get('create', [AdministrateurController::class, 'create'])->name('create');
            Route::post('/', [AdministrateurController::class, 'store'])->name('store');
            Route::get('{administrateur}/modifier', [AdministrateurController::class, 'edit'])->name('edit');
            Route::match(['put', 'patch'], '{administrateur}', [AdministrateurController::class, 'update'])->name('update');
            Route::delete('{administrateur}', [AdministrateurController::class, 'destroy'])
                ->middleware('throttle:10,1')->name('destroy');
            Route::post('{administrateur}/reinitialiser', [AdministrateurController::class, 'reinitialiser'])
                ->middleware('throttle:5,1')->name('reinitialiser');
            Route::patch('{administrateur}/statut', [AdministrateurController::class, 'toggleStatut'])
                ->name('statut');
            // Kill-switch AUTH-01 (13/10) : révocation des sessions d'un compte.
            Route::post('{administrateur}/sessions/purger', [AdministrateurController::class, 'purgerSessions'])
                ->middleware('throttle:5,1')->name('sessions.purger');
        });

        /*
        | Matrice des rôles (lecture seule, super_admin) : l'attribution
        | passe par les comptes, la matrice vit en code versionné.
        */
        Route::get('roles', [RoleController::class, 'index'])
            ->middleware('can:roles.view')->name('roles.index');

        /*
        | Configurations SMTP nommées (LOOP J) : super_admin seul, secret
        | chiffré jamais réaffiché, test de connexion réel sans envoi.
        */
        Route::prefix('smtp')->name('smtp.')->middleware('can:mail.settings.manage')->group(function () {
            Route::get('/', [SmtpConfigurationController::class, 'index'])->name('index');
            Route::get('creer', [SmtpConfigurationController::class, 'create'])->name('create');
            Route::post('/', [SmtpConfigurationController::class, 'store'])
                ->middleware('throttle:10,1')->name('store');
            Route::get('{smtp}/modifier', [SmtpConfigurationController::class, 'edit'])->name('edit');
            Route::match(['put', 'patch'], '{smtp}', [SmtpConfigurationController::class, 'update'])->name('update');
            Route::delete('{smtp}', [SmtpConfigurationController::class, 'destroy'])
                ->middleware('throttle:10,1')->name('destroy');
            Route::post('{smtp}/tester', [SmtpConfigurationController::class, 'tester'])
                ->middleware('can:mail.test.send', 'throttle:5,1')->name('tester');
        });

        /*
        | Registre des étudiants & facultés (fonctions historiques du projet).
        | Consultation pour tout agent authentifié ; les écritures exigent
        | un rôle actif (permission archives.update).
        */
        Route::get('etudiants', [EtudiantController::class, 'index'])->name('etudiants.index');
        Route::get('etudiants/sans-groupe', [EtudiantController::class, 'sansGroupe'])->name('etudiants.sans-groupe');
        Route::post('etudiants/bulk-statut', [EtudiantController::class, 'bulkStatut'])
            ->middleware('can:archives.update', 'throttle:10,1')
            ->name('etudiants.bulkStatut');
        Route::get('etudiants/{etudiant}', [EtudiantController::class, 'show'])->name('etudiants.show');
        // Parcours LMD en lecture seule (LOOP 13) : même service que le
        // portail étudiant, Gate `view` (périmètre faculté).
        Route::get('etudiants/{etudiant}/parcours', [ParcoursEtudiantController::class, 'show'])->name('etudiants.parcours');
        Route::get('etudiants/{etudiant}/export-dossier', [EtudiantController::class, 'exportDossier'])
            ->middleware('throttle:10,1')
            ->name('etudiants.exportDossier');
        Route::patch('etudiants/{etudiant}/statut', [EtudiantController::class, 'updateStatut'])
            ->middleware(['can:archives.update', 'throttle:20,1'])
            ->name('etudiants.updateStatut');
        // Trappe « mot de passe perdu » (LOOP 10) : la scolarité est
        // l'unique porte de secours (aucun canal email étudiant).
        Route::post('etudiants/{etudiant}/reinitialiser-mot-de-passe', [EtudiantController::class, 'reinitialiserMotDePasse'])
            ->middleware(['can:archives.update', 'throttle:10,1'])
            ->name('etudiants.reinitialiserMotDePasse');
        // Destruction irréversible (cascade BD complète) : réservée à
        // archives.delete (super_admin / admin_scolarite) — cohérence RBAC
        // avec admin.archives.destroy (audit §P0-6).
        Route::delete('etudiants/{etudiant}', [EtudiantController::class, 'destroy'])
            ->middleware('can:archives.delete')
            ->name('etudiants.destroy');

        // ── File administrative de traitement des inscriptions (LOOP 18) ──
        Route::get('inscriptions/file-traitement', [FileTraitementController::class, 'index'])
            ->middleware('can:archives.view')
            ->name('inscriptions.file-traitement');
        Route::post('inscriptions/file-traitement/{etudiant}/commenter', [FileTraitementController::class, 'commenter'])
            ->middleware(['can:archives.update', 'throttle:30,1'])
            ->name('inscriptions.file-traitement.commenter');

        // ── Vérification intelligente du dossier étudiant (§14-18) ──
        // ── Centre des inscriptions bloquées (file de traitement) ──
        Route::get('verification/bloquees', [VerificationController::class, 'index'])
            ->name('verification.index');
        Route::post('inscriptions/{inscription}/corriger', [VerificationController::class, 'corrigerInscription'])
            ->middleware(['can:archives.update', 'throttle:20,1'])
            ->name('inscriptions.corriger');
        Route::get('etudiants/{etudiant}/verification', [VerificationController::class, 'show'])
            ->name('verification.show');
        Route::post('etudiants/{etudiant}/verification', [VerificationController::class, 'verifier'])
            ->middleware('throttle:30,1')
            ->name('verification.verifier');
        Route::post('etudiants/{etudiant}/verification/corriger', [VerificationController::class, 'corriger'])
            ->middleware(['can:archives.update', 'throttle:30,1'])
            ->name('verification.corriger');
        Route::post('etudiants/{etudiant}/verification/proposer', [VerificationController::class, 'proposer'])
            ->middleware(['can:archives.update', 'throttle:30,1'])
            ->name('verification.proposer');
        Route::post('etudiants/{etudiant}/verification/demande-complement', [VerificationController::class, 'demanderComplement'])
            ->middleware(['can:archives.update', 'throttle:30,1'])
            ->name('verification.demande-complement');
        Route::post('etudiants/{etudiant}/demande-complement', [VerificationController::class, 'demanderComplement'])
            ->middleware(['can:archives.update', 'throttle:30,1'])
            ->name('etudiants.demande-complement');
        Route::post('verification/corrections/{correction}/decider', [VerificationController::class, 'decider'])
            ->middleware(['can:archives.update', 'throttle:30,1'])
            ->name('verification.decider');
        Route::post('etudiants/verification/bulk', [VerificationController::class, 'bulk'])
            ->middleware('throttle:10,1')
            ->name('verification.bulk');

        // Phase 2 — Population : affectations, situations, inscriptions.
        // Moteur d'affectation : prévisualiser → expliquer → confirmer →
        // transaction → auditer → notifier (permissions existantes réutilisées).
        Route::post('etudiants/{etudiant}/affectations', [AffectationController::class, 'store'])
            ->middleware('can:archives.update', 'throttle:20,1')
            ->name('etudiants.affectations.store');
        Route::get('etudiants/{etudiant}/affectations/previsualiser', [AffectationController::class, 'previsualiser'])
            ->middleware('can:archives.view')
            ->name('etudiants.affectations.previsualiser');
        Route::post('etudiants/{etudiant}/affectations/retirer', [AffectationController::class, 'retirer'])
            ->middleware('can:archives.update', 'throttle:10,1')
            ->name('etudiants.affectations.retirer');
        // LOOP B (automatisation §9) : changement de groupe — workflow
        // distinct (idempotence, simulation, audit, notif après commit).
        Route::post('etudiants/{etudiant}/affectations/changer', [AffectationController::class, 'changer'])
            ->middleware('can:archives.update', 'throttle:20,1')
            ->name('etudiants.affectations.changer');
        Route::get('affectations/masse', [AffectationController::class, 'masse'])
            ->middleware('can:archives.view')
            ->name('affectations.masse');
        Route::post('affectations/masse/analyser', [AffectationController::class, 'analyserMasse'])
            ->middleware(['can:archives.view', 'throttle:10,1'])
            ->name('affectations.masse.analyser');
        Route::get('affectations/masse/analyser', [AffectationController::class, 'simulerRediriger'])
            ->middleware('can:archives.view')
            ->defaults('formulaire', 'admin.affectations.masse')
            ->name('affectations.masse.analyser.rediriger');
        Route::post('affectations/masse/confirmer', [AffectationController::class, 'confirmerMasse'])
            ->middleware(['can:archives.update', 'throttle:5,1'])
            ->name('affectations.masse.confirmer');
        Route::post('affectations/masse/proposer', [AffectationController::class, 'proposerMasse'])
            ->middleware(['can:archives.update', 'throttle:10,1'])
            ->name('affectations.masse.proposer');
        Route::post('affectations/masse/regulariser-inscriptions', [AffectationController::class, 'regulariserInscriptionsMasse'])
            ->middleware(['can:archives.update', 'throttle:10,1'])
            ->name('affectations.masse.regulariser-inscriptions');
        Route::get('affectations/masse/recherche', [AffectationController::class, 'rechercherCandidats'])
            ->middleware(['can:archives.view', 'throttle:30,1'])
            ->name('affectations.masse.recherche');
        Route::post('affectations/masse/exporter', [AffectationController::class, 'exporterMasse'])
            ->middleware(['can:data.export', 'throttle:10,1'])
            ->name('affectations.masse.exporter');
        Route::get('affectations/masse/lots/{lot}', [AffectationController::class, 'showLot'])
            ->middleware('can:archives.view')
            ->name('affectations.masse.lots.show');
        Route::post('affectations/masse/lots/{lot}/annuler', [AffectationController::class, 'annulerLot'])
            ->middleware(['can:archives.update', 'throttle:10,1'])
            ->name('affectations.masse.lots.annuler');

        // LOOP MATURE — Gestion des propositions (mode semi-automatique)
        Route::get('affectations/propositions', [AffectationProposalController::class, 'index'])
            ->middleware('can:archives.view')
            ->name('affectations.propositions.index');
        Route::post('affectations/propositions/{proposal}/accepter', [AffectationProposalController::class, 'accepter'])
            ->middleware('can:archives.update')
            ->name('affectations.propositions.accepter');
        Route::post('affectations/propositions/{proposal}/modifier', [AffectationProposalController::class, 'modifier'])
            ->middleware('can:archives.update')
            ->name('affectations.propositions.modifier');
        Route::post('affectations/propositions/{proposal}/rejeter', [AffectationProposalController::class, 'rejeter'])
            ->middleware('can:archives.update')
            ->name('affectations.propositions.rejeter');
        Route::post('affectations/propositions/bulk-accepter', [AffectationProposalController::class, 'bulkAccepter'])
            ->middleware('can:archives.update')
            ->name('affectations.propositions.bulk-accepter');
        Route::get('affectations/auto', [AffectationController::class, 'auto'])
            ->middleware('can:archives.view')
            ->name('affectations.auto');
        Route::post('affectations/auto/simuler', [AffectationController::class, 'simulerAuto'])
            ->middleware(['can:archives.view', 'throttle:10,1'])
            ->name('affectations.auto.simuler');
        Route::post('affectations/auto/appliquer', [AffectationController::class, 'appliquerAuto'])
            ->middleware(['can:archives.update', 'throttle:5,1'])
            ->name('affectations.auto.appliquer');
        Route::post('affectations/simuler', [AffectationController::class, 'simuler'])
            ->middleware(['can:archives.view', 'throttle:10,1'])
            ->name('affectations.simuler');
        Route::get('affectations/simuler', [AffectationController::class, 'simulerRediriger'])
            ->middleware('can:archives.view')
            ->defaults('formulaire', 'admin.affectations.masse')
            ->name('affectations.simuler.rediriger');
        Route::get('affectations/auto/simuler', [AffectationController::class, 'simulerRediriger'])
            ->middleware('can:archives.view')
            ->defaults('formulaire', 'admin.affectations.auto')
            ->name('affectations.auto.simuler.rediriger');
        Route::post('affectations/appliquer', [AffectationController::class, 'appliquer'])
            ->middleware(['can:archives.update', 'throttle:5,1'])
            ->name('affectations.appliquer');
        // Refonte affectation automatique équilibrée : paramètres →
        // simulation sans écriture → prévisualisation → confirmation par
        // groupe → transaction → audit (mêmes permissions existantes).
        Route::get('affectations/automatique', [AutomaticAssignmentController::class, 'parametres'])
            ->middleware('can:archives.view')
            ->name('affectations.automatique.parametres');
        Route::post('affectations/automatique/simuler', [AutomaticAssignmentController::class, 'simuler'])
            ->middleware(['can:archives.view', 'throttle:10,1'])
            ->name('affectations.automatique.simuler');
        Route::get('affectations/automatique/{simulation}', [AutomaticAssignmentController::class, 'show'])
            ->middleware('can:archives.view')
            ->name('affectations.automatique.show');
        Route::post('affectations/automatique/{simulation}/groupes/{groupe}/confirmer', [AutomaticAssignmentController::class, 'confirmerGroupe'])
            ->middleware(['can:archives.update', 'throttle:5,1'])
            ->name('affectations.automatique.confirmer');
        Route::post('affectations/automatique/{simulation}/regenerer', [AutomaticAssignmentController::class, 'regenerer'])
            ->middleware(['can:archives.view', 'throttle:10,1'])
            ->name('affectations.automatique.regenerer');
        Route::patch('affectations/automatique/items/{item}', [AutomaticAssignmentController::class, 'modifier'])
            ->middleware(['can:archives.update', 'throttle:20,1'])
            ->name('affectations.automatique.modifier');
        Route::post('affectations/automatique/{simulation}/annuler', [AutomaticAssignmentController::class, 'annuler'])
            ->middleware(['can:archives.update', 'throttle:10,1'])
            ->name('affectations.automatique.annuler');
        Route::get('affectations/automatique/{simulation}/exporter', [AutomaticAssignmentController::class, 'exporter'])
            ->middleware(['can:data.export', 'throttle:10,1'])
            ->name('affectations.automatique.exporter');
        Route::get('etudiants/{etudiant}/reinscriptions/creer', [ReinscriptionController::class, 'creer'])
            ->middleware('can:archives.view')
            ->name('etudiants.reinscriptions.creer');
        Route::post('etudiants/{etudiant}/reinscriptions', [ReinscriptionController::class, 'store'])
            ->middleware(['can:archives.create', 'throttle:10,1'])
            ->name('etudiants.reinscriptions.store');
        Route::post('etudiants/{etudiant}/suspendre', [SituationController::class, 'suspendre'])
            ->middleware('can:archives.update', 'throttle:20,1')
            ->name('etudiants.suspendre');
        Route::post('etudiants/{etudiant}/transferer', [SituationController::class, 'transferer'])
            ->middleware('can:archives.update', 'throttle:10,1')
            ->name('etudiants.transferer');
        Route::patch('etudiants/{etudiant}/rattachement', [SituationController::class, 'rattacher'])
            ->middleware('can:archives.update', 'throttle:20,1')
            ->name('etudiants.rattachement');

        // Admissions (roadmap « admission Master ») : acte d'admission
        // externe traçable, consommé par la progression LMD.
        Route::get('admissions', [AdmissionController::class, 'index'])
            ->middleware('can:admissions.view')
            ->name('admissions.index');
        Route::get('admissions/creer', [AdmissionController::class, 'create'])
            ->middleware('can:admissions.manage')
            ->name('admissions.create');
        Route::post('admissions', [AdmissionController::class, 'store'])
            ->middleware(['can:admissions.manage', 'throttle:20,1'])
            ->name('admissions.store');
        Route::delete('admissions/{admission}', [AdmissionController::class, 'destroy'])
            ->middleware(['can:admissions.manage', 'throttle:10,1'])
            ->name('admissions.destroy');

        // Inscriptions : validation, rejet, réinscription, pédagogique.
        Route::post('inscriptions/{inscription}/valider', [InscriptionGestionController::class, 'valider'])
            ->middleware('can:archives.update', 'throttle:20,1')
            ->name('inscriptions.valider');
        // LOOP affectation automatique §26 : relance mêmes règles.
        Route::post('inscriptions/{inscription}/reaffecter', [InscriptionGestionController::class, 'reaffecter'])
            ->middleware('can:archives.update', 'throttle:10,1')
            ->name('inscriptions.reaffecter');
        Route::post('inscriptions/{inscription}/rejeter', [InscriptionGestionController::class, 'rejeter'])
            ->middleware('can:archives.update', 'throttle:20,1')
            ->name('inscriptions.rejeter');
        Route::post('inscriptions/{inscription}/reinscrire', [InscriptionGestionController::class, 'reinscrire'])
            ->middleware('can:archives.create', 'throttle:10,1')
            ->name('inscriptions.reinscrire');
        Route::post('inscriptions/{inscription}/progresser', [InscriptionGestionController::class, 'progresser'])
            ->middleware('can:archives.create', 'throttle:10,1')
            ->name('inscriptions.progresser');
        Route::get('inscriptions/{inscription}/pedagogique', [InscriptionGestionController::class, 'pedagogique'])
            ->middleware('can:archives.view')
            ->name('inscriptions.pedagogique');
        Route::get('inscriptions/{inscription}/attestation', [InscriptionGestionController::class, 'attestation'])
            ->middleware('can:archives.view')
            ->name('inscriptions.attestation');
        Route::post('inscriptions/{inscription}/ecues', [InscriptionGestionController::class, 'inscrireEcue'])
            ->middleware('can:archives.update', 'throttle:20,1')
            ->name('inscriptions.ecues.store');
        Route::post('inscriptions/{inscription}/ecues/lot', [InscriptionGestionController::class, 'inscrireEcues'])
            ->middleware('can:archives.update', 'throttle:10,1')
            ->name('inscriptions.ecues.lot');
        Route::delete('inscriptions-pedagogiques/{pedagogique}', [InscriptionGestionController::class, 'retirerEcue'])
            ->middleware('can:archives.update', 'throttle:20,1')
            ->name('inscriptions.ecues.destroy');

        // Population : statistiques + qualité (lecture).
        Route::get('population/stats', [PopulationController::class, 'stats'])
            ->middleware('can:archives.view')->name('population.stats');
        Route::get('population/qualite', [PopulationController::class, 'qualite'])
            ->middleware('can:archives.view')->name('population.qualite');

        // Personnel administratif : super_admin uniquement (comptes sensibles).
        Route::prefix('personnel')->name('personnel.')->middleware('super_admin')->group(function () {
            Route::get('/', [PersonnelController::class, 'index'])->name('index');
            Route::get('creer', [PersonnelController::class, 'create'])->name('create');
            Route::post('/', [PersonnelController::class, 'store'])
                ->middleware('throttle:10,1')->name('store');
            Route::get('{personnel}', [PersonnelController::class, 'show'])->name('show');
            Route::get('{personnel}/modifier', [PersonnelController::class, 'edit'])->name('edit');
            Route::match(['put', 'patch'], '{personnel}', [PersonnelController::class, 'update'])->name('update');
            Route::delete('{personnel}', [PersonnelController::class, 'destroy'])
                ->middleware('throttle:10,1')->name('destroy');
        });

        // Référentiel global des facultés : réservé aux rôles supérieurs
        // (configuration.manage) — un admin_faculte ne doit ni créer, ni
        // renommer, ni supprimer des facultés, y compris la sienne.
        Route::get('facultes', [FaculteController::class, 'index'])
            ->middleware('can:configuration.manage')->name('facultes.index');
        Route::get('facultes/create', [FaculteController::class, 'create'])
            ->middleware('can:configuration.manage')->name('facultes.create');
        Route::get('facultes/{faculte}/edit', [FaculteController::class, 'edit'])
            ->middleware('can:configuration.manage')->name('facultes.edit');
        Route::post('facultes', [FaculteController::class, 'store'])
            ->middleware('can:configuration.manage')->name('facultes.store');
        Route::match(['put', 'patch'], 'facultes/{faculte}', [FaculteController::class, 'update'])
            ->middleware('can:configuration.manage')->name('facultes.update');
        Route::delete('facultes/{faculte}', [FaculteController::class, 'destroy'])
            ->middleware('can:configuration.manage')->name('facultes.destroy');

        // LOOP périodes d'inscription : même RBAC que les facultés
        // (configuration.manage) — aucun 2e système, scope serveur.
        Route::get('periodes', [PeriodeInscriptionController::class, 'index'])
            ->middleware('can:configuration.manage')->name('periodes.index');
        // État UI (§14) : lecture seule, scopée faculté (404 hors périmètre).
        Route::get('periodes/etat', [PeriodeInscriptionController::class, 'etat'])
            ->middleware(['can:archives.view', 'throttle:30,1'])->name('periodes.etat');
        Route::post('periodes', [PeriodeInscriptionController::class, 'store'])
            ->middleware(['can:configuration.manage', 'throttle:20,1'])->name('periodes.store');
        Route::post('periodes/{periode}/revoquer', [PeriodeInscriptionController::class, 'revoquer'])
            ->middleware(['can:configuration.manage', 'throttle:20,1'])->name('periodes.revoquer');
        Route::post('periodes/{periode}/prolonger', [PeriodeInscriptionController::class, 'prolonger'])
            ->middleware(['can:configuration.manage', 'throttle:20,1'])->name('periodes.prolonger');

        // ── Référentiels académiques : enseignants, salles, groupes ──
        Route::get('enseignants', [EnseignantController::class, 'index'])
            ->middleware('can:enseignants.view')->name('enseignants.index');
        Route::get('enseignants/creer', [EnseignantController::class, 'create'])
            ->middleware('can:enseignants.manage')->name('enseignants.create');
        Route::post('enseignants', [EnseignantController::class, 'store'])
            ->middleware(['can:enseignants.manage', 'throttle:20,1'])->name('enseignants.store');
        Route::get('enseignants/{enseignant}/modifier', [EnseignantController::class, 'edit'])
            ->middleware('can:enseignants.manage')->name('enseignants.edit');
        Route::match(['put', 'patch'], 'enseignants/{enseignant}', [EnseignantController::class, 'update'])
            ->middleware(['can:enseignants.manage', 'throttle:20,1'])->name('enseignants.update');
        Route::delete('enseignants/{enseignant}', [EnseignantController::class, 'destroy'])
            ->middleware(['can:enseignants.manage', 'throttle:20,1'])->name('enseignants.destroy');
        Route::get('enseignants/{enseignant}', [EnseignantController::class, 'show'])
            ->middleware('can:enseignants.view')->name('enseignants.show');

        Route::get('salles', [SalleController::class, 'index'])->name('salles.index');
        Route::get('salles/creer', [SalleController::class, 'create'])
            ->middleware('can:salles.manage')->name('salles.create');
        Route::post('salles', [SalleController::class, 'store'])
            ->middleware('can:salles.manage')->name('salles.store');
        Route::get('salles/{salle}/modifier', [SalleController::class, 'edit'])
            ->middleware('can:salles.manage')->name('salles.edit');
        Route::match(['put', 'patch'], 'salles/{salle}', [SalleController::class, 'update'])
            ->middleware('can:salles.manage')->name('salles.update');
        Route::delete('salles/{salle}', [SalleController::class, 'destroy'])
            ->middleware('can:salles.manage')->name('salles.destroy');

        Route::get('groupes', [GroupeController::class, 'index'])->name('groupes.index');
        Route::get('groupes/creer', [GroupeController::class, 'create'])
            ->middleware('can:groupes.manage')->name('groupes.create');
        Route::post('groupes', [GroupeController::class, 'store'])
            ->middleware('can:groupes.manage')->name('groupes.store');
        Route::get('groupes/{groupe}/modifier', [GroupeController::class, 'edit'])
            ->middleware('can:groupes.manage')->name('groupes.edit');
        Route::match(['put', 'patch'], 'groupes/{groupe}', [GroupeController::class, 'update'])
            ->middleware('can:groupes.manage')->name('groupes.update');
        Route::delete('groupes/{groupe}', [GroupeController::class, 'destroy'])
            ->middleware('can:groupes.manage')->name('groupes.destroy');
        Route::get('groupes/{groupe}', [GroupeController::class, 'show'])
            ->middleware('can:groupes.view')->name('groupes.show');
        Route::get('groupes/{groupe}/etudiants', [GroupeController::class, 'etudiants'])
            ->middleware('can:groupes.view')->name('groupes.etudiants');

        // ── Centre Import/Export unifié : hub, 6 étapes, historique ──
        Route::prefix('donnees')->name('donnees.')->group(function () {
            Route::get('/', [DataExchangeController::class, 'hub'])
                ->middleware('can:data.import')->name('hub');
            Route::get('historique', [DataExchangeController::class, 'historique'])
                ->middleware('can:data.import')->name('historique');
            Route::get('rapports/{rapport}/erreurs/{format}', [DataExchangeController::class, 'erreurs'])
                ->middleware(['can:data.import', 'throttle:20,1'])
                ->whereNumber('rapport')
                ->whereIn('format', ['csv', 'json'])
                ->name('rapport.erreurs');
            Route::get('{entite}/donnees', [DataExchangeController::class, 'voir'])
                ->middleware('can:data.import')
                ->whereIn('entite', DefinitionRegistry::consultables())
                ->name('voir');
            Route::get('{entite}/import', [DataExchangeController::class, 'formulaire'])
                ->middleware('can:data.import')
                ->whereIn('entite', DefinitionRegistry::entites())
                ->name('import.form');
            Route::post('{entite}/import/preview', [DataExchangeController::class, 'preview'])
                ->middleware(['can:data.import', 'throttle:10,1'])
                ->whereIn('entite', DefinitionRegistry::entites())
                ->name('import.preview');
            Route::post('{entite}/import/confirmer', [DataExchangeController::class, 'confirmer'])
                ->middleware(['can:data.import', 'throttle:10,1'])
                ->whereIn('entite', DefinitionRegistry::entites())
                ->name('import.confirmer');
            Route::post('{entite}/import/revoir', [DataExchangeController::class, 'revoir'])
                ->middleware(['can:data.import', 'throttle:10,1'])
                ->whereIn('entite', DefinitionRegistry::entites())
                ->name('import.revoir');
            Route::post('{entite}/import/annuler', [DataExchangeController::class, 'annuler'])
                ->middleware('can:data.import')
                ->whereIn('entite', DefinitionRegistry::entites())
                ->name('import.annuler');
            Route::get('{entite}/export/{format}', [DataExchangeController::class, 'exporter'])
                ->middleware(['can:data.export', 'throttle:20,1'])
                ->whereIn('entite', DefinitionRegistry::exportables())
                ->whereIn('format', ['csv', 'xlsx', 'json', 'pdf'])
                ->name('export');
            Route::get('{entite}/modele/{format}', [DataExchangeController::class, 'modele'])
                ->middleware(['can:data.import', 'throttle:20,1'])
                ->whereIn('entite', DefinitionRegistry::entites())
                ->whereIn('format', ['csv', 'xlsx'])
                ->name('modele');
        });

        // ── Audit central (lecture seule) + export CSV + maintenance système ──
        Route::get('audit', [AuditController::class, 'index'])
            ->middleware('can:history.view')->name('audit.index');
        Route::get('audit/export', [AuditController::class, 'exporter'])
            ->middleware(['can:history.view', 'throttle:10,1'])->name('audit.export');

        // ── GED : recherche documentaire unifiée (§22.11, lecture seule) ──
        Route::get('documents', [GedController::class, 'index'])
            ->middleware('can:documents.view')->name('ged.index');

        // ── Statistiques transversales (§47) + qualité (§19) + paramètres (§48) ──
        Route::get('statistiques', [StatistiquesController::class, 'index'])
            ->middleware('can:statistiques.view')->name('statistiques.index');
        Route::get('bi', [BiController::class, 'index'])
            ->middleware('can:statistiques.view')->name('bi.index');
        Route::get('bi/export', [BiController::class, 'exporter'])
            ->middleware(['can:statistiques.export', 'throttle:10,1'])->name('bi.export');
        Route::get('qualite', [QualiteController::class, 'index'])
            ->middleware('can:qualite.view')->name('qualite.index');
        Route::post('qualite/{anomalie}/transiter', [QualiteController::class, 'transiter'])
            ->middleware(['can:qualite.manage', 'throttle:20,1'])->name('qualite.transiter');
        Route::post('qualite/{anomalie}/assigner', [QualiteController::class, 'assigner'])
            ->middleware(['can:qualite.manage', 'throttle:20,1'])->name('qualite.assigner');
        Route::get('statistiques/export', [StatistiquesController::class, 'exporter'])
            ->middleware(['can:statistiques.export', 'throttle:10,1'])->name('statistiques.export');
        Route::get('parametres', [ParametreController::class, 'index'])
            ->middleware('can:settings.view')->name('parametres.index');
        Route::patch('parametres/{parametre}', [ParametreController::class, 'update'])
            ->middleware(['can:settings.manage', 'throttle:20,1'])->name('parametres.update');
        Route::post('parametres/{parametre}/restaurer', [ParametreController::class, 'restaurer'])
            ->middleware(['can:settings.restore', 'throttle:10,1'])->name('parametres.restaurer');

        Route::middleware('super_admin')->group(function () {
            Route::get('maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
            Route::post('maintenance/purger-cache', [MaintenanceController::class, 'purgerCache'])
                ->middleware('throttle:5,1')->name('maintenance.purger-cache');
            Route::get('systeme', [SystemeController::class, 'index'])->name('systeme.index');
        });

        // ── Sauvegardes database (permissions granulaires view ≠ create ≠ verify ≠ restore ≠ delete) ──
        Route::prefix('system/backups')->name('system.backups.')->group(function () {
            Route::get('/', [SauvegardesController::class, 'index'])
                ->middleware('can:system.backups.view')->name('index');
            Route::post('/', [SauvegardesController::class, 'store'])
                ->middleware(['can:system.backups.create', 'throttle:5,1'])->name('store');
            Route::post('/{backup}/verifier', [SauvegardesController::class, 'verifier'])
                ->middleware(['can:system.backups.verify', 'throttle:10,1'])->name('verify');
            Route::post('/{backup}/tester-restauration', [SauvegardesController::class, 'testerRestauration'])
                ->middleware(['can:system.backups.verify', 'throttle:5,1'])->name('tester-restauration');
            Route::post('/{backup}/restaurer', [SauvegardesController::class, 'restaurer'])
                ->middleware(['can:system.backups.restore', 'throttle:3,1'])->name('restore');
            Route::get('/planification', [SauvegardesController::class, 'planification'])
                ->middleware('can:system.backups.settings')->name('planification');
            Route::post('/planification', [SauvegardesController::class, 'mettreAJourPlanification'])
                ->middleware(['can:system.backups.settings', 'throttle:10,1'])->name('planification.update');
        });

        // ── Modèles d'importation (§91-101) + rapport d'erreurs (§109) ──
        Route::prefix('imports')->name('imports.')->middleware('can:archives.view')->group(function () {
            Route::get('modeles', [ImportTemplateController::class, 'index'])->name('templates');
            Route::get('modeles/{type}/download/{format}', [ImportTemplateController::class, 'download'])
                ->middleware('throttle:20,1')->name('templates.download');
            Route::get('rapports/{rapport}/erreurs', [ImportTemplateController::class, 'erreurs'])
                ->middleware('can:configuration.manage', 'throttle:20,1')->name('templates.erreurs');
        });

        // ── Moteur central de listes et impression (§38 : UNE population
        // pour prévisualisation, CSV, XLSX, PDF et impression) ──
        Route::get('listes', [ListesController::class, 'index'])->name('listes.index');
        Route::get('listes/preview', [ListesController::class, 'preview'])->name('listes.preview');
        Route::get('listes/exports', [ListesController::class, 'exports'])->name('listes.exports');
        Route::get('listes/exports/{rapport}/download', [ListesController::class, 'telechargerExport'])
            ->middleware('throttle:20,1')->name('listes.exports.download');
        Route::get('listes/exporter/{format}', [ListesController::class, 'exporter'])
            ->middleware('throttle:10,1')
            ->whereIn('format', ['csv', 'xlsx', 'pdf'])
            ->name('listes.exporter');
        Route::get('listes/imprimer', [ListesController::class, 'imprimer'])->name('listes.imprimer');
        Route::post('listes/profils', [ListesController::class, 'storeProfil'])
            ->middleware('throttle:10,1')->name('listes.profils.store');
        Route::delete('listes/profils/{profil}', [ListesController::class, 'destroyProfil'])
            ->middleware('throttle:10,1')->name('listes.profils.destroy');

        // ── Exports académiques multicritères (§70-90) ──
        Route::prefix('exports')->name('exports.')->middleware('can:archives.view')->group(function () {
            Route::get('/', [ExportController::class, 'index'])->name('index');
            Route::get('options', [ExportController::class, 'options'])->name('options');
            Route::post('preview', [ExportController::class, 'preview'])
                ->middleware('can:configuration.manage', 'throttle:20,1')->name('preview');
            Route::post('generate', [ExportController::class, 'generate'])
                ->middleware('can:configuration.manage', 'throttle:10,1')->name('generate');
            Route::get('rapports/{rapport}/download', [ExportController::class, 'download'])
                ->middleware('can:configuration.manage', 'throttle:20,1')->name('download');
            Route::post('templates', [ExportController::class, 'storeTemplate'])
                ->middleware('can:configuration.manage')->name('templates.store');
            Route::delete('templates/{template}', [ExportController::class, 'destroyTemplate'])
                ->middleware('can:configuration.manage')->name('templates.destroy');
        });

        // ── Intégrations : imports/exports (web) ──
        Route::prefix('integrations')->name('integrations.')->middleware('can:archives.view')->group(function () {
            Route::get('/', [IntegrationController::class, 'index'])->name('index');
            Route::get('rapports/{rapport}/preview', [IntegrationController::class, 'preview'])
                ->middleware('can:configuration.manage')->name('preview');
            Route::post('import', [IntegrationController::class, 'storeImport'])
                ->middleware('can:configuration.manage', 'throttle:10,1')->name('import');
            Route::post('rapports/{rapport}/confirm', [IntegrationController::class, 'confirm'])
                ->middleware('can:configuration.manage', 'throttle:10,1')->name('confirm');
            Route::post('export', [IntegrationController::class, 'storeExport'])
                ->middleware('can:configuration.manage', 'throttle:10,1')->name('export');
            Route::get('rapports/{rapport}/download', [IntegrationController::class, 'download'])
                ->middleware('can:configuration.manage', 'throttle:20,1')->name('download');
        });

        // ── Module Archives Académiques ──────────────────────────────────
        // Chaque route porte sa permission RBAC et son rate limiting adapté.
        // Les segments fixes sont déclarés avant {dossier} pour éviter
        // toute capture par le paramètre.
        Route::prefix('archives')->name('archives.')->group(function () {

            // Configuration académique (années universitaires + matières)
            Route::get('configuration', [ConfigurationAcademiqueController::class, 'index'])
                ->middleware('can:archives.view')
                ->name('configuration.index');
            Route::post('annees-universitaires', [ConfigurationAcademiqueController::class, 'storeAnnee'])
                ->middleware('can:configuration.manage')
                ->name('annees.store');
            Route::patch('annees-universitaires/{anneeUniversitaire}', [ConfigurationAcademiqueController::class, 'updateAnnee'])
                ->middleware('can:configuration.manage')
                ->name('annees.update');
            Route::delete('annees-universitaires/{anneeUniversitaire}', [ConfigurationAcademiqueController::class, 'destroyAnnee'])
                ->middleware('can:configuration.manage', 'throttle:10,1')
                ->name('annees.destroy');
            Route::post('matieres', [ConfigurationAcademiqueController::class, 'storeMatiere'])
                ->middleware('can:configuration.manage')
                ->name('matieres.store');
            Route::patch('matieres/{matiere}', [ConfigurationAcademiqueController::class, 'updateMatiere'])
                ->middleware('can:configuration.manage')
                ->name('matieres.update');
            Route::delete('matieres/{matiere}', [ConfigurationAcademiqueController::class, 'destroyMatiere'])
                ->middleware('can:configuration.manage', 'throttle:10,1')
                ->name('matieres.destroy');

            // Création d'un dossier (sélection d'étudiant sans dossier)
            Route::get('create', [ArchiveController::class, 'create'])
                ->middleware('can:archives.create')
                ->name('create');

            // Registre des dossiers (recherche + filtres)
            Route::get('/', [ArchiveController::class, 'index'])
                ->middleware('can:archives.view')
                ->name('index');
            Route::post('/', [ArchiveController::class, 'store'])
                ->middleware('can:archives.create', 'throttle:20,1')
                ->name('store');

            // Export global du registre filtré (placé avant {dossier})
            Route::get('export-registre', [ArchiveController::class, 'exportRegistre'])
                ->middleware('can:archives.view', 'throttle:10,1')
                ->name('export-registre');

            // Dossiers individuels (le paramètre {dossier} reste en dernier)
            Route::get('{dossier}', [ArchiveController::class, 'show'])
                ->middleware('can:archives.view', 'throttle:60,1')
                ->name('show');
            Route::get('{dossier}/export', [ArchiveController::class, 'export'])
                ->middleware('can:archives.view', 'throttle:10,1')
                ->name('export');
            Route::get('{dossier}/edit', [ArchiveController::class, 'edit'])
                ->middleware('can:archives.update')
                ->name('edit');
            Route::patch('{dossier}', [ArchiveController::class, 'update'])
                ->middleware('can:archives.update', 'throttle:20,1')
                ->name('update');
            Route::patch('{dossier}/statut', [ArchiveController::class, 'toggleStatut'])
                ->middleware('can:archives.update', 'throttle:20,1')
                ->name('statut');
            Route::delete('{dossier}', [ArchiveController::class, 'destroy'])
                ->middleware('can:archives.delete', 'throttle:10,1')
                ->name('destroy');

            // Inscriptions académiques d'un dossier
            Route::post('{dossier}/inscriptions', [InscriptionAcademiqueController::class, 'store'])
                ->middleware('can:archives.create', 'throttle:20,1')
                ->name('inscriptions.store');
            Route::patch('inscriptions/{inscription}', [InscriptionAcademiqueController::class, 'update'])
                ->middleware('can:archives.update', 'throttle:20,1')
                ->name('inscriptions.update');
            Route::delete('inscriptions/{inscription}', [InscriptionAcademiqueController::class, 'destroy'])
                ->middleware('can:archives.delete', 'throttle:10,1')
                ->name('inscriptions.destroy');

            // Notes académiques
            Route::post('inscriptions/{inscription}/notes', [NoteController::class, 'store'])
                ->middleware('can:notes.create', 'throttle:30,1')
                ->name('notes.store');
            Route::patch('notes/{note}', [NoteController::class, 'update'])
                ->middleware('can:notes.update', 'throttle:30,1')
                ->name('notes.update');
            Route::patch('notes/{note}/verrouiller', [NoteController::class, 'toggleLock'])
                ->middleware('can:notes.unlock', 'throttle:30,1')
                ->name('notes.lock');
            Route::delete('notes/{note}', [NoteController::class, 'destroy'])
                ->middleware('can:notes.delete', 'throttle:10,1')
                ->name('notes.destroy');

            // Documents académiques (upload contrôlé + téléchargement journalisé)
            Route::post('{dossier}/documents', [DocumentAcademiqueController::class, 'store'])
                ->middleware('can:documents.upload', 'throttle:10,1')
                ->name('documents.store');
            Route::get('documents/{document}/download', [DocumentAcademiqueController::class, 'download'])
                ->middleware('can:documents.download', 'throttle:20,1')
                ->name('documents.download');
            Route::delete('documents/{document}', [DocumentAcademiqueController::class, 'destroy'])
                ->middleware('can:documents.delete', 'throttle:10,1')
                ->name('documents.destroy');
        });
    });
});
