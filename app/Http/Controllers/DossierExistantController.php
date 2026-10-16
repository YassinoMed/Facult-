<?php

namespace App\Http\Controllers;

use App\Http\Requests\DossierExistantAutonomeConfirmationRequest;
use App\Http\Requests\DossierExistantAutonomeRequest;
use App\Http\Requests\DossierExistantEmailConfirmationRequest;
use App\Http\Requests\DossierExistantEmailNouvelleConfirmationRequest;
use App\Http\Requests\DossierExistantEmailRequest;
use App\Http\Requests\DossierExistantModificationRequest;
use App\Http\Requests\VerifierCinRequest;
use App\Models\Etudiant;
use App\Services\AuditService;
use App\Services\DossierExistantService;
use App\Services\PeriodeInscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Parcours public sans login pour dossier étudiant EXISTANT (extension LOOP).
 *
 * Séquence : recherche (CIN + date de naissance) → code de vérification
 * à usage unique envoyé à l'adresse email déjà enregistrée → formulaire
 * prérempli (données autorisées) → soumission validée, historisée, puis
 * email de confirmation envoyé après commit.
 *
 * Garanties :
 * - aucune donnée du dossier ne quitte le serveur avant la vérification
 *   du code (réponses neutres et identiques, anti-énumération) ;
 * - pas de session authentifiée générale : uniquement une autorisation
 *   temporaire, liée à CE dossier et à CE parcours, expirante et à
 *   usage unique (consumption atomique en cache) ;
 * - anti-IDOR structurel : le dossier traité provient TOUJOURS du jeton
 *   d'autorisation (jamais d'un identifiant transmis par le navigateur) ;
 * - throttling : par IP (routes) ET par identité (RateLimiter par CIN).
 */
class DossierExistantController extends Controller
{
    /** Clé de session : identité de recherche en attente de vérification. */
    private const SESSION_RECHERCHE = 'dossier_existant.recherche';

    /** Clé de session : jeton d'autorisation temporaire. */
    private const SESSION_JETON = 'dossier_existant.jeton';

    /** Clé de session : jeton d'autorisation `email_change` (parcours autonome). */
    private const SESSION_JETON_AUTONOME = 'dossier_existant.jeton_autonome';

    public function __construct(
        private readonly DossierExistantService $service,
        private readonly AuditService $audit,
        private readonly PeriodeInscriptionService $periodes,
    ) {}

    /* ── Écran d'accueil : recherche + vérification ──────────────── */

    public function accueil(): View
    {
        return view('inscription.existant.accueil', [
            'codeEnvoye' => Session::get('dossier_existant.code_envoye', false),
            // Même source de vérité que le portail de création : état
            // global des périodes (bandeau d'accueil, lecture seule).
            'etatPeriode' => $this->periodes->etatGlobal(),
        ]);
    }

    /**
     * Recherche du dossier : la réponse est STRICTEMENT IDENTIQUE
     * (message neutre) qu'un dossier corresponde ou non — un tiers ne
     * peut pas confirmer l'existence d'un dossier à partir de cette
     * étape. Le code n'est envoyé qu'à l'adresse déjà enregistrée.
     */
    public function rechercher(VerifierCinRequest $request): RedirectResponse|JsonResponse
    {
        $cin = $request->validated('cin');
        $dateNaissance = $request->validated('date_naissance');

        // Limiteur par identité : au-delà du plafond, réponse identique
        // et silencieuse (aucun oracle, aucun envoi supplémentaire).
        if ($this->service->rechercheBloquee($cin, $dateNaissance)) {
            return $this->retourAccueilCodeEnvoye();
        }

        $this->service->compterRecherche($cin, $dateNaissance);

        $etudiant = $this->service->rechercher($cin, $dateNaissance);

        if ($etudiant !== null) {
            // Mémorisation serveur (jamais exposée au navigateur) de
            // l'identité en attente de vérification : l'étape suivante
            // re-résoud le dossier à partir de ces valeurs.
            Session::put(self::SESSION_RECHERCHE, [
                'cin' => $cin,
                'date_naissance' => $dateNaissance,
            ]);

            try {
                $this->service->envoyerCodeVerification($etudiant);
            } catch (RuntimeException) {
                // Cooldown d'envoi ou dossier sans email exploitable :
                // réponse inchangée (le message « code envoyé » reste
                // exact si un code a réellement été émis récemment).
            } catch (Throwable) {
                // Fail-soft transport : l'utilisateur peut re-demander.
            }
        }

        // Soumission embarquée (modal d'accueil, sans navigation) : même
        // neutralité stricte en JSON (le message ne varie jamais).
        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'etape' => 'code',
                'message' => 'Si un dossier correspondant à ces informations existe, un code de vérification à usage unique vient d\'être envoyé à l\'adresse email enregistrée sur ce dossier. Ce code est valable 10 minutes.',
            ]);
        }

        return $this->retourAccueilCodeEnvoye();
    }

    private function retourAccueilCodeEnvoye(): RedirectResponse
    {
        return redirect()
            ->route('inscription.existant.accueil')
            ->with('dossier_existant.code_envoye', true)
            // Conservation de la saisie (CIN + naissance uniquement : ce
            // sont les valeurs tapées par l'utilisateur lui-même — aucun
            // oracle d'existence, aucune donnée du dossier).
            ->withInput()
            ->with('statut', 'Si un dossier correspondant à ces informations existe, un code de vérification à usage unique vient d\'être envoyé à l\'adresse email enregistrée sur ce dossier. Ce code est valable 10 minutes. Vérifiez votre boîte de réception et vos indésirables ; sans email reçu, vérifiez la saisie de votre CIN et de votre date de naissance, ou contactez la scolarité.');
    }

    /**
     * Vérification du code : en cas de succès, émission d'une
     * autorisation temporaire (usage unique, liée au dossier) placée
     * en session — jamais dans l'URL. Aucune session authentifiée
     * générale n'est créée.
     */
    public function verifier(Request $request): RedirectResponse|JsonResponse
    {
        $donnees = $request->validate([
            'code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/'],
        ], [
            'code.required' => 'Le code de vérification est obligatoire.',
            'code.regex' => 'Le code de vérification est composé de 6 chiffres.',
        ]);

        $identite = Session::get(self::SESSION_RECHERCHE);
        $cin = is_array($identite) ? (string) ($identite['cin'] ?? '') : '';
        $dateNaissance = is_array($identite) ? (string) ($identite['date_naissance'] ?? '') : '';

        $etudiant = ($cin !== '' && $dateNaissance !== '')
            ? $this->service->rechercher($cin, $dateNaissance)
            : null;

        if ($etudiant === null) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Session de vérification expirée. Reprenez le parcours depuis la recherche.',
                ]);
            }

            return $this->echecVerification('Session de vérification expirée. Reprenez le parcours depuis la recherche.');
        }

        $jeton = $this->service->verifierCodeEtAutoriser($etudiant, $donnees['code']);

        if ($jeton === null) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Code invalide, expiré ou nombre maximal de tentatives atteint. Demandez un nouveau code et vérifiez votre boîte de réception.',
                ]);
            }

            return $this->echecVerification('Code invalide, expiré ou nombre maximal de tentatives atteint. Demandez un nouveau code et vérifiez votre boîte de réception.');
        }

        // Traçabilité de la vérification d'identité (sans PII).
        $this->audit->tracer($etudiant, 'verification_identite_dossier_public', 'etudiant', $etudiant->id);

        Session::put(self::SESSION_JETON, $jeton);
        Session::forget(self::SESSION_RECHERCHE);
        Session::forget('dossier_existant.code_envoye');

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'etape' => 'dossier']);
        }

        return redirect()->route('inscription.existant.formulaire');
    }

    private function echecVerification(string $message): RedirectResponse
    {
        return redirect()
            ->route('inscription.existant.accueil')
            ->with('dossier_existant.code_envoye', true)
            ->withErrors(['code' => $message]);
    }

    /* ── Formulaire prérempli (post-vérification uniquement) ─────── */

    public function formulaire(Request $request): View|RedirectResponse
    {
        $etudiant = $this->service->autorisationValide($request->session()->get(self::SESSION_JETON));

        if ($etudiant === null) {
            return redirect()
                ->route('inscription.existant.accueil')
                ->with('statut', 'Votre autorisation de consultation est absente ou expirée. Reprenez la vérification pour accéder à votre dossier.');
        }

        return view('inscription.existant.formulaire', $this->donneesVue($etudiant));
    }

    /**
     * Données du dossier pour l'interface embarquée (modal d'accueil, sans
     * navigation) : même contenu et mêmes gardes que `formulaire()`, en
     * JSON. Exige le jeton temporaire de session (jamais d'identifiant
     * client). Aucune écriture, aucune donnée avant vérification.
     */
    public function donnees(Request $request): JsonResponse|RedirectResponse
    {
        $etudiant = $this->service->autorisationValide($request->session()->get(self::SESSION_JETON));

        if ($etudiant === null) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Votre autorisation de consultation est absente ou expirée. Reprenez la vérification pour accéder à votre dossier.',
                ], 403);
            }

            return redirect()
                ->route('inscription.existant.accueil')
                ->with('statut', 'Votre autorisation de consultation est absente ou expirée. Reprenez la vérification pour accéder à votre dossier.');
        }

        return response()->json(array_merge(['ok' => true], $this->donneesVue($etudiant)));
    }

    /**
     * Construit les variables de la vue dossier (HTML canonique ET JSON
     * embarqué) : source unique, mêmes gardes, mêmes données.
     *
     * @return array{dossier: array<string, mixed>, pieces: array<int, mixed>, modifiables: list<string>, dossierModifiable: bool, emailModifiable: bool, messageStatut: ?string}
     */
    private function donneesVue(Etudiant $etudiant): array
    {
        $donnees = $this->service->donneesFormulaire($etudiant);

        $periodeOuverte = $this->service->periodeAutorisee($etudiant);

        return [
            'dossier' => $donnees['dossier'],
            'pieces' => $donnees['pieces'],
            'modifiables' => $donnees['modifiables'],
            // Coordonnées : uniquement un dossier en attente, période ouverte.
            'dossierModifiable' => $etudiant->statut === 'en_attente' && $periodeOuverte,
            // Popup email : en attente OU validé (coordonnée de contact ;
            // le changement reste protégé par la double preuve + audit +
            // notifications). Un dossier rejeté passe par la scolarité.
            'emailModifiable' => in_array($etudiant->statut, ['en_attente', 'valide'], true),
            'messageStatut' => match (true) {
                $etudiant->statut === 'valide' => 'Votre dossier a déjà été validé par la scolarité : vos coordonnées ne sont plus modifiables en ligne. Seule votre adresse email peut être mise à jour ci-dessous, après vérification par code.',
                $etudiant->statut === 'rejete' => 'Votre dossier a fait l\'objet d\'un rejet. Pour un recours ou une réouverture, contactez le service de la scolarité.',
                ! $periodeOuverte => 'Les inscriptions sont actuellement fermées : votre dossier est consultable mais non modifiable en ligne. Contactez le service de la scolarité si une correction est urgente.',
                default => null,
            },
        ];
    }

    /* ── Changement d'adresse email (popup, double preuve) ─────── */

    /**
     * Étape 1 — demande : le dossier est résolu depuis le jeton temporaire
     * de session (jamais depuis la requête). En cas de succès, un code de
     * confirmation est envoyé à l'adresse DÉJÀ VÉRIFIÉE du dossier
     * (l'adresse actuellement enregistrée — preuve supplémentaire §3) ;
     * la popup affiche alors l'étape de saisie du code. Le jeton principal
     * N'EST PAS consommé ici (le parcours coordonné se poursuit après).
     */
    public function demanderEmail(DossierExistantEmailRequest $request): RedirectResponse|JsonResponse
    {
        $etudiant = $this->service->autorisationValide($request->session()->get(self::SESSION_JETON));

        if ($etudiant === null) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Votre autorisation de modification est absente ou expirée. Reprenez la vérification pour modifier votre adresse email.',
                ], 403);
            }

            return $this->echecEnregistrement('Votre autorisation de modification est absente ou expirée. Reprenez la vérification pour modifier votre adresse email.');
        }

        try {
            $this->service->demanderChangementEmail($etudiant, $request->validated('nouvel_email'));
        } catch (RuntimeException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['nouvel_email' => [$e->getMessage()]],
                ], 422);
            }

            return redirect()
                ->route('inscription.existant.formulaire')
                ->with('dossier_existant.email_etape', 'saisie')
                ->withErrors(['nouvel_email' => $e->getMessage()]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'etape' => 'code',
                'message' => 'Un code de confirmation à usage unique vient d\'être envoyé à votre adresse actuelle (celle enregistrée sur le dossier, valable 30 minutes). Saisissez-le pour valider le changement.',
            ]);
        }

        return redirect()
            ->route('inscription.existant.formulaire')
            ->with('dossier_existant.email_etape', 'code')
            ->with('dossier_existant.email_nouveau_masque', DossierExistantService::masquerEmail($request->validated('nouvel_email')))
            ->with('statut', 'Un code de confirmation à usage unique vient d\'être envoyé à votre adresse actuelle (celle enregistrée sur le dossier, valable 30 minutes). Saisissez-le pour valider le changement.');
    }

    /**
     * Étape 2 — première preuve (code reçu à l'adresse ACTUELLE) : en cas
     * de succès, RIEN n'est persisté — un second code est émis vers la
     * NOUVELLE adresse (candidate) et la popup affiche l'étape de saisie
     * de ce second code. L'ancienne adresse reste en vigueur jusqu'à la
     * confirmation finale (l'email est un identifiant de connexion).
     */
    public function confirmerEmail(DossierExistantEmailConfirmationRequest $request): RedirectResponse|JsonResponse
    {
        $etudiant = $this->service->autorisationValide($request->session()->get(self::SESSION_JETON));

        if ($etudiant === null) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Votre autorisation de modification est absente ou expirée. Reprenez la vérification pour modifier votre adresse email.',
                ], 403);
            }

            return $this->echecEnregistrement('Votre autorisation de modification est absente ou expirée. Reprenez la vérification pour modifier votre adresse email.');
        }

        $preuve = $this->service->verifierCodeAncienneAdresse($etudiant, $request->validated('code'));

        if ($preuve === null) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'etape' => 'code',
                    'message' => 'Code invalide, expiré ou nombre maximal de tentatives atteint. Demandez un nouveau code.',
                ]);
            }

            return redirect()
                ->route('inscription.existant.formulaire')
                ->with('dossier_existant.email_etape', 'code')
                ->with('dossier_existant.email_nouveau_masque', $this->service->nouvelEmailMasqueEnAttente($etudiant))
                ->withErrors(['code' => 'Code invalide, expiré ou nombre maximal de tentatives atteint. Demandez un nouveau code.'])
                ->withInput();
        }

        $nouvelEmail = $preuve['nouvel_email'];

        try {
            $this->service->emettreCodeNouvelleAdresse($etudiant, $nouvelEmail, $preuve['ancien_email_hash']);
        } catch (RuntimeException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['nouvel_email' => [$e->getMessage()]],
                ], 422);
            }

            return redirect()
                ->route('inscription.existant.formulaire')
                ->with('dossier_existant.email_etape', 'saisie')
                ->withErrors(['nouvel_email' => $e->getMessage()]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'etape' => 'code-nouvelle',
                'message' => 'Un code de confirmation à usage unique vient d\'être envoyé à votre nouvelle adresse (valable 30 minutes). Votre adresse actuelle reste inchangée jusqu\'à sa saisie : c\'est elle qui validera définitivement le changement.',
            ]);
        }

        return redirect()
            ->route('inscription.existant.formulaire')
            ->with('dossier_existant.email_etape', 'code-nouvelle')
            ->with('dossier_existant.email_nouveau_masque', DossierExistantService::masquerEmail($nouvelEmail))
            ->with('statut', 'Un code de confirmation à usage unique vient d\'être envoyé à votre nouvelle adresse (valable 30 minutes). Votre adresse actuelle reste inchangée jusqu\'à sa saisie : c\'est elle qui validera définitivement le changement.');
    }

    /**
     * Étape 3 — confirmation finale (code reçu à la NOUVELLE adresse) :
     * seul le code est repris de la requête, la candidate provient du
     * serveur (liée au code). En cas de succès : SEUL le champ email est
     * modifié (transaction), historique masqué, puis notifications
     * post-commit (alerte ancienne adresse + confirmation nouvelle
     * adresse, fail-soft individuel). C'est ICI que le changement devient
     * effectif — jamais avant.
     */
    public function confirmerNouvelleAdresse(DossierExistantEmailNouvelleConfirmationRequest $request): RedirectResponse|JsonResponse
    {
        $etudiant = $this->service->autorisationValide($request->session()->get(self::SESSION_JETON));

        if ($etudiant === null) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Votre autorisation de modification est absente ou expirée. Reprenez la vérification pour modifier votre adresse email.',
                ], 403);
            }

            return $this->echecEnregistrement('Votre autorisation de modification est absente ou expirée. Reprenez la vérification pour modifier votre adresse email.');
        }

        try {
            $resultat = $this->service->confirmerNouvelleAdresse($etudiant, $request->validated('code_nouvelle'));
        } catch (RuntimeException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $e->getMessage().' Contactez le service de la scolarité.',
                ]);
            }

            return redirect()
                ->route('inscription.existant.accueil')
                ->with('statut', $e->getMessage().' Contactez le service de la scolarité.');
        }

        if ($resultat === null) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'etape' => 'code-nouvelle',
                    'message' => 'Code invalide, expiré ou nombre maximal de tentatives atteint. Reprenez la saisie de votre nouvelle adresse pour recevoir un nouveau code.',
                ]);
            }

            return redirect()
                ->route('inscription.existant.formulaire')
                ->with('dossier_existant.email_etape', 'code-nouvelle')
                ->with('dossier_existant.email_nouveau_masque', $this->service->candidatureMasqueeEnAttente($etudiant))
                ->withErrors(['code_nouvelle' => 'Code invalide, expiré ou nombre maximal de tentatives atteint. Reprenez la saisie de votre nouvelle adresse pour recevoir un nouveau code.'])
                ->withInput();
        }

        $notifications = $this->service->notifierChangementEmail(
            $resultat['etudiant'],
            $resultat['ancien_email'],
            $resultat['nouvel_email']
        );

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'succes' => DossierExistantService::masquerEmail($resultat['nouvel_email']),
                'notifications_en_attente' => ! $notifications['ancienne_notifiee'] || ! $notifications['nouvelle_notifiee'],
                'message' => 'Votre adresse email a été mise à jour. Le parcours de mise à jour de votre dossier se poursuit normalement.',
            ]);
        }

        return redirect()
            ->route('inscription.existant.formulaire')
            ->with('dossier_existant.email_succes', DossierExistantService::masquerEmail($resultat['nouvel_email']))
            ->with('dossier_existant.email_notifications_en_attente', ! $notifications['ancienne_notifiee'] || ! $notifications['nouvelle_notifiee'])
            ->with('statut', 'Votre adresse email a été mise à jour. Le parcours de mise à jour de votre dossier se poursuit normalement.');
    }

    /* ── Parcours autonome (sans l'ancienne adresse, sans scolarité) ── */

    /**
     * Étape 1 — demande autonome : CIN + naissance + ANCIENNE adresse +
     * MOT DE PASSE + nouvelle adresse, en une seule soumission. La réponse
     * est STRICTEMENT NEUTRE dans tous les cas (aucun oracle d'existence :
     * l'étape de saisie du code s'affiche que le dossier corresponde ou
     * non — seul un code réellement émis permet de poursuivre). Le jeton
     * `email_change` est placé en session, jamais dans l'URL.
     */
    public function demanderAutonome(DossierExistantAutonomeRequest $request): RedirectResponse|JsonResponse
    {
        // Un jeton périmé ne doit jamais survivre à une nouvelle demande.
        $request->session()->forget(self::SESSION_JETON_AUTONOME);

        $jeton = $this->service->demanderChangementAutonome(
            $request->validated('cin'),
            $request->validated('date_naissance'),
            $request->validated('ancien_email'),
            $request->validated('password'),
            $request->validated('nouvel_email'),
        );

        if ($jeton !== null) {
            $request->session()->put(self::SESSION_JETON_AUTONOME, $jeton);
        }

        // Soumission sans navigation (fetch) : même neutralité en JSON.
        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'etape' => 'code',
                'message' => 'Si les informations correspondent à un dossier existant, un code de confirmation à usage unique vient d\'être envoyé à la nouvelle adresse saisie (valable 30 minutes). Saisissez-le ci-dessous pour valider définitivement le changement ; sinon, vérifiez votre saisie ou suivez la procédure de récupération.',
            ]);
        }

        return redirect()
            ->route('inscription.existant.accueil')
            ->with('dossier_existant.autonome_etape', 'code')
            ->with('statut', 'Si les informations correspondent à un dossier existant, un code de confirmation à usage unique vient d\'être envoyé à la nouvelle adresse saisie (valable 30 minutes). Saisissez-le ci-dessous pour valider définitivement le changement ; sinon, vérifiez votre saisie ou suivez la procédure de récupération.');
    }

    /**
     * Étape 2 — confirmation autonome : seul le code est repris de la
     * requête ; le dossier et la candidate proviennent de l'autorisation
     * `email_change` (usage unique, consommée avant l'écriture). En cas de
     * succès : SEUL le champ email est modifié, historique masqué, puis
     * notifications post-commit (fail-soft). L'ancienne adresse reste
     * active jusqu'à cet instant.
     */
    public function confirmerAutonome(DossierExistantAutonomeConfirmationRequest $request): RedirectResponse|JsonResponse
    {
        try {
            $resultat = $this->service->confirmerChangementAutonome(
                $request->session()->get(self::SESSION_JETON_AUTONOME),
                $request->validated('code_autonome'),
            );
        } catch (RuntimeException) {
            $request->session()->forget(self::SESSION_JETON_AUTONOME);

            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'La modification n\'a pas pu aboutir. Vérifiez votre saisie ou contactez le service de la scolarité.',
                ]);
            }

            return redirect()
                ->route('inscription.existant.accueil')
                ->with('statut', 'La modification n\'a pas pu aboutir. Vérifiez votre saisie ou contactez le service de la scolarité.');
        }

        if ($resultat === null) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'etape' => 'code',
                    'message' => 'Code invalide, expiré ou nombre maximal de tentatives atteint. Reprenez la demande pour recevoir un nouveau code.',
                ]);
            }

            return redirect()
                ->route('inscription.existant.accueil')
                ->with('dossier_existant.autonome_etape', 'code')
                ->withErrors(['code_autonome' => 'Code invalide, expiré ou nombre maximal de tentatives atteint. Reprenez la demande pour recevoir un nouveau code.'])
                ->withInput();
        }

        $request->session()->forget(self::SESSION_JETON_AUTONOME);

        $notifications = $this->service->notifierChangementEmail(
            $resultat['etudiant'],
            $resultat['ancien_email'],
            $resultat['nouvel_email']
        );

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'succes' => DossierExistantService::masquerEmail($resultat['nouvel_email']),
                'notifications_en_attente' => ! $notifications['ancienne_notifiee'] || ! $notifications['nouvelle_notifiee'],
                'message' => 'Votre adresse email a été mise à jour.',
            ]);
        }

        return redirect()
            ->route('inscription.existant.accueil')
            ->with('dossier_existant.autonome_succes', DossierExistantService::masquerEmail($resultat['nouvel_email']))
            ->with('dossier_existant.email_notifications_en_attente', ! $notifications['ancienne_notifiee'] || ! $notifications['nouvelle_notifiee'])
            ->with('statut', 'Votre adresse email a été mise à jour.');
    }

    /* ── Soumission finale ───────────────────────────────────────── */

    /**
     * Enregistrement de la demande de mise à jour :
     * 1. consumption ATOMIQUE de l'autorisation (usage unique, même en
     *    cas de soumissions concurrentes : le second appel échoue) ;
     * 2. rechargement du dossier depuis la base, sous verrou, avec
     *    garde de statut (valide/rejete interdits) ;
     * 3. validation stricte + whitelist de champs (jamais $request->all()) ;
     * 4. transaction + historique d'audit ;
     * 5. email de confirmation UNIQUEMENT après le commit ; un échec
     *    d'envoi ne remet jamais en cause l'enregistrement.
     *
     * Une répétition de la même requête est rejetée (autorisation déjà
     * consommée) : aucun nouveau dossier, aucune confirmation multiple.
     */
    public function enregistrer(DossierExistantModificationRequest $request): RedirectResponse|JsonResponse
    {
        $jeton = $request->session()->get(self::SESSION_JETON);

        $apercu = $this->service->autorisationValide($jeton);

        if ($apercu === null) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Votre autorisation de modification est absente ou expirée. Reprenez la vérification pour soumettre vos modifications.',
                ], 403);
            }

            return $this->echecEnregistrement('Votre autorisation de modification est absente ou expirée. Reprenez la vérification pour soumettre vos modifications.');
        }

        // Gate atomique : le jeton est consommé (usage unique) AVANT
        // l'écriture — toute soumission concurrente ou rejouée échoue.
        $etudiant = $this->service->consommerAutorisation($jeton, $apercu->id);

        if ($etudiant === null) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Votre autorisation de modification est absente ou expirée. Reprenez la vérification pour soumettre vos modifications.',
                ], 403);
            }

            return $this->echecEnregistrement('Votre autorisation de modification est absente ou expirée. Reprenez la vérification pour soumettre vos modifications.');
        }

        $request->session()->forget(self::SESSION_JETON);

        // Filtrage serveur final : uniquement les champs modifiables
        // (les données proviennent de $request->validated(), jamais de
        // $request->all()).
        $champsAutorises = array_intersect_key(
            $request->validated(),
            array_flip(DossierExistantService::CHAMPS_MODIFIABLES),
        );

        try {
            $resultat = $this->service->enregistrerModification($etudiant->id, $champsAutorises);
        } catch (RuntimeException $e) {
            // Statut changé entre-temps (décision administrative) :
            // modification refusée, aucune donnée altérée.
            if ($request->wantsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $e->getMessage().' Contactez le service de la scolarité.',
                ]);
            }

            return redirect()
                ->route('inscription.existant.accueil')
                ->with('statut', $e->getMessage().' Contactez le service de la scolarité.');
        }

        $nbChampsModifies = count($resultat['changements']);

        // Email APRÈS commit uniquement ; échec non bloquant.
        $emailAccuse = $this->service->notifierConfirmation($resultat['etudiant'], $nbChampsModifies);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'confirmation' => [
                    'num_inscription' => $resultat['etudiant']->num_inscription,
                    'nb_champs_modifies' => $nbChampsModifies,
                    'email_en_attente' => ! $emailAccuse,
                ],
            ]);
        }

        return redirect()
            ->route('inscription.existant.confirmation')
            ->with('dossier_existant.confirmation', [
                'num_inscription' => $resultat['etudiant']->num_inscription,
                'nb_champs_modifies' => $nbChampsModifies,
                'email_en_attente' => ! $emailAccuse,
            ]);
    }

    /* ── Écran final (sans données sensibles) ────────────────────── */

    public function confirmation(Request $request): View|RedirectResponse
    {
        $confirmation = $request->session()->get('dossier_existant.confirmation');

        if (! is_array($confirmation)) {
            return redirect()->route('inscription.existant.accueil');
        }

        // Le flash est consommé : un rechargement ou un rejeu ne
        // réaffiche pas un faux succès.
        $request->session()->forget('dossier_existant.confirmation');

        return view('inscription.existant.confirmation', [
            'numInscription' => (string) ($confirmation['num_inscription'] ?? ''),
            'nbChampsModifies' => (int) ($confirmation['nb_champs_modifies'] ?? 0),
            'emailEnAttente' => (bool) ($confirmation['email_en_attente'] ?? false),
            'contactScolarite' => config('inscriptions.contact_scolarite'),
        ]);
    }

    private function echecEnregistrement(string $message): RedirectResponse
    {
        return redirect()
            ->route('inscription.existant.accueil')
            ->with('statut', $message);
    }
}
