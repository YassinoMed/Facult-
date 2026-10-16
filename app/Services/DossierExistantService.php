<?php

namespace App\Services;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Notifications\AlerteChangementEmail;
use App\Notifications\CodeConfirmationChangementEmail;
use App\Notifications\CodeConfirmationNouvelleAdresse;
use App\Notifications\CodeVerificationDossierExistant;
use App\Notifications\ConfirmationChangementEmail;
use App\Notifications\ConfirmationMiseAJourDossierExistant;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Throwable;

/**
 * Parcours public sans login pour dossier étudiant existant (extension LOOP).
 *
 * Principe de sécurité central : le couple CIN + date de naissance NE
 * CONSTITUE PAS une preuve d'identité suffisante. Aucune donnée du
 * dossier ne quitte le serveur tant qu'un code de vérification à usage
 * unique, envoyé à l'adresse email DÉJÀ ENREGISTRÉE, n'a pas été validé.
 *
 * Garanties :
 * - recherche fail-closed (CIN normalisé + date de naissance exacte) ;
 * - réponse de recherche identique qu'un dossier existe ou non
 *   (anti-énumération : aucun oracle d'existence) ;
 * - code à usage unique : hashé (jamais stocké en clair), durée de
 *   vie limitée, invalidé après succès, nombre d'essais borné ;
 * - autorisation temporaire : jeton opaque lié à UN dossier et à CE
 *   parcours uniquement (jamais de session authentifiée générale) ;
 *   durée de vie limitée, consumption atomique (usage unique) ;
 * - modification : whitelist stricte de champs, transaction +
 *   verrou pessimiste, garde de statut (valide/rejete interdits),
 *   historique d'audit ancienne/nouvelle valeur.
 */
class DossierExistantService
{
    /** Durée de vie du code de vérification (secondes). */
    public const DUREE_CODE = 600;

    /** Durée de vie de l'autorisation temporaire (secondes). */
    public const DUREE_AUTORISATION = 900;

    /** Nombre maximal d'essais de vérification par code émis. */
    public const MAX_ESSAIS_CODE = 5;

    /** Nombre maximal de recherches par identité sur 15 minutes. */
    public const MAX_RECHERCHES = 5;

    /** Délai minimal entre deux envois de code (secondes). */
    public const DELAI_RENVOI = 60;

    /** Durée de vie du code de confirmation de la nouvelle adresse (secondes). */
    public const DUREE_CODE_EMAIL = 1800;

    /** Nombre maximal d'essais de confirmation par code email émis. */
    public const MAX_ESSAIS_CODE_EMAIL = 5;

    /** Durée de vie du code envoyé à l'adresse candidate (secondes). */
    public const DUREE_CODE_EMAIL_NOUVELLE = 1800;

    /** Nombre maximal d'essais de confirmation par code candidate émis. */
    public const MAX_ESSAIS_CODE_EMAIL_NOUVELLE = 5;

    /** Durée de vie du code du parcours autonome (sans l'ancienne adresse, secondes). */
    public const DUREE_CODE_AUTONOME = 1800;

    /** Nombre maximal d'essais de confirmation par code autonome émis. */
    public const MAX_ESSAIS_CODE_AUTONOME = 5;

    /** Nombre maximal de demandes autonomes par identité sur 15 minutes. */
    public const MAX_DEMANDES_AUTONOME = 5;

    /**
     * Champs modifiables en ligne via ce parcours. Tout champ absent de
     * cette liste est IGNORÉ, même s'il figure dans la requête HTTP
     * (CIN, nom, prénom, sexe, date de naissance, email, statut,
     * affectation académique : procédure administrative).
     */
    public const CHAMPS_MODIFIABLES = ['telephone', 'adresse', 'ville', 'code_postal'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly DocumentsRequisService $documentsRequis,
        private readonly PeriodeInscriptionService $periodes,
    ) {}

    /* ── Étape 1 : recherche du dossier ──────────────────────────── */

    /**
     * Recherche fail-closed : CIN normalisé (majuscules, espaces
     * supprimés — même normalisation que le parcours de création) +
     * date de naissance exacte. Un étudiant sans date de naissance
     * connue n'est JAMAIS retourné.
     */
    public function rechercher(string $cin, string $dateNaissance): ?Etudiant
    {
        $cinNormalise = self::normaliserCin($cin);

        return Etudiant::query()
            ->whereNotNull('cin')
            ->whereRaw("REPLACE(UPPER(TRIM(cin)), ' ', '') = ?", [$cinNormalise])
            ->whereDate('date_naissance', $dateNaissance)
            ->first();
    }

    /**
     * Normalisation CIN partagée (recherche + clés de limitation) :
     * majuscules, espaces supprimés. Identique à celle du parcours de
     * création (`InscriptionController::store`).
     */
    private static function normaliserCin(string $cin): string
    {
        $sansEspaces = preg_replace('/\s+/', '', mb_strtoupper(trim($cin)));

        return is_string($sansEspaces) ? $sansEspaces : '';
    }

    /**
     * La période d'inscription autorise-t-elle une modification en
     * ligne de CE dossier (§3.3 : revalidation serveur obligatoire) ?
     *
     * - année résolue depuis le libellé du dossier, repli sur l'année
     *   active ; sans référentiel d'année : autorisé (régime de
     *   transition legacy, documenté — jamais de blocage sur
     *   référentiel incomplet) ;
     * - une évaluation CLOSED prouvée (périodes configurées mais
     *   aucune ouverte) REFUSE la modification ;
     * - SANS_PERIODE (aucune période configurée) reste autorisé,
     *   cohérent avec `PeriodeInscriptionService::evaluer`.
     */
    public function periodeAutorisee(Etudiant $etudiant): bool
    {
        $libelle = trim((string) ($etudiant->annee_academique ?? ''));
        $annee = $libelle !== ''
            ? AnneeUniversitaire::where('libelle', $libelle)->first()
            : null;
        $annee ??= AnneeUniversitaire::active();

        if ($annee === null) {
            return true;
        }

        // Le dossier ne porte pas son type d'inscription : une période
        // ouverte sur n'importe quel type (ou typeless = tous) suffit.
        foreach ([null, 'premiere', 'reinscription', 'transfert'] as $type) {
            if ($this->periodes->evaluer($etudiant, $annee, $type)['autorisee']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Limiteur de débit par identité (anti-force-brute distribué,
     * indépendant du throttle par IP des routes). Clé dérivée d'un
     * hash : aucune donnée personnelle dans la clé de cache.
     */
    public function rechercheBloquee(string $cin, string $dateNaissance): bool
    {
        return RateLimiter::tooManyAttempts($this->cleRecherche($cin, $dateNaissance), self::MAX_RECHERCHES);
    }

    public function compterRecherche(string $cin, string $dateNaissance): void
    {
        RateLimiter::hit($this->cleRecherche($cin, $dateNaissance), 900);
    }

    /**
     * Génère et envoie un code de vérification à l'adresse email DÉJÀ
     * ENREGISTRÉE du dossier (jamais à une adresse saisie à la volée).
     * Le code est stocké hashé, avec compteur d'essais et expiration.
     *
     * @throws RuntimeException si le dossier n'a pas d'email exploitable
     *                          (fail-closed : aucun code « orphelin »)
     */
    public function envoyerCodeVerification(Etudiant $etudiant): void
    {
        $email = trim((string) $etudiant->email);

        if ($email === '' || ! str_contains($email, '@')) {
            // Fail-closed : sans canal de vérification déjà enregistré,
            // le parcours ne peut pas prouver l'identité.
            throw new RuntimeException('Aucune adresse email enregistrée pour ce dossier.');
        }

        $cleRenvoi = $this->cleRenvoi($etudiant);

        if (RateLimiter::tooManyAttempts($cleRenvoi, 1)) {
            throw new RuntimeException('Un code vient déjà d\'être envoyé. Patientez avant de demander un nouveau code.');
        }

        $code = (string) random_int(100000, 999999);

        Cache::put($this->cleCode($etudiant), [
            'code_hash' => hash('sha256', $code),
            'tentatives' => 0,
            'expire_a' => now()->addSeconds(self::DUREE_CODE)->getTimestamp(),
        ], self::DUREE_CODE);

        // Cooldown d'envoi (anti-spam du canal email).
        RateLimiter::hit($cleRenvoi, self::DELAI_RENVOI);

        // Envoi SYNCHRONE : l'étudiant attend ce code pour continuer.
        // Jamais d'adresse librement saisie ici : c'est celle du dossier.
        $etudiant->notify(new CodeVerificationDossierExistant($code, self::DUREE_CODE / 60));
    }

    /* ── Étape 2 : vérification du code → autorisation temporaire ── */

    /**
     * Vérifie le code soumis. En cas de succès : le code est invalidé
     * (usage unique) et un jeton d'autorisation temporaire est émis,
     * LIÉ à ce dossier uniquement (jamais de session authentifiée
     * générale). Retourne le jeton brut (à conserver côté session du
     * navigateur) ou null en cas d'échec.
     */
    public function verifierCodeEtAutoriser(Etudiant $etudiant, string $code): ?string
    {
        $entree = Cache::get($this->cleCode($etudiant));

        if (! is_array($entree) || ! isset($entree['code_hash'], $entree['expire_a'])) {
            return null; // code absent, expiré ou déjà consommé
        }

        if ($entree['expire_a'] < now()->getTimestamp()) {
            Cache::forget($this->cleCode($etudiant));

            return null;
        }

        $essais = (int) ($entree['tentatives'] ?? 0);

        if ($essais >= self::MAX_ESSAIS_CODE) {
            // Trop d'essais : le code est définitivement invalidé ;
            // une nouvelle recherche est nécessaire.
            Cache::forget($this->cleCode($etudiant));

            return null;
        }

        if (! hash_equals((string) $entree['code_hash'], hash('sha256', $code))) {
            $entree['tentatives'] = $essais + 1;
            Cache::put($this->cleCode($etudiant), $entree, self::DUREE_CODE);

            return null;
        }

        // Succès : code consommé (usage unique), autorisation émise.
        Cache::forget($this->cleCode($etudiant));

        return $this->emettreAutorisation($etudiant);
    }

    /**
     * Émet un jeton d'autorisation opaque, stocké hashé et lié au
     * dossier. Le jeton brut n'existe que côté navigateur (session) ;
     * le serveur ne conserve que son empreinte.
     */
    public function emettreAutorisation(Etudiant $etudiant): string
    {
        $jeton = bin2hex(random_bytes(24));

        Cache::put($this->cleAutorisation($jeton), [
            'etudiant_id' => $etudiant->id,
            'expire_a' => now()->addSeconds(self::DUREE_AUTORISATION)->getTimestamp(),
        ], self::DUREE_AUTORISATION);

        return $jeton;
    }

    /**
     * Valide l'autorisation temporaire SANS la consommer (affichage du
     * formulaire). Retourne le dossier lié ou null (absente, expirée,
     * dossier différent).
     */
    public function autorisationValide(?string $jeton): ?Etudiant
    {
        $entree = $this->lireAutorisation($jeton);

        if ($entree === null) {
            return null;
        }

        return Etudiant::find($entree['etudiant_id']);
    }

    /**
     * Consomme l'autorisation de façon ATOMIQUE (usage unique garanti
     * même en cas de soumissions concurrentes : pull = lecture +
     * suppression en une opération). Le dossier est rechargé par
     * l'appelant à partir de l'identifiant LIÉ au jeton — jamais à
     * partir d'un identifiant transmis par le navigateur.
     */
    public function consommerAutorisation(?string $jeton, int $etudiantId): ?Etudiant
    {
        $entree = Cache::pull($this->cleAutorisation((string) $jeton));

        if (! is_array($entree) || ! isset($entree['etudiant_id'], $entree['expire_a'])) {
            return null;
        }

        if ($entree['expire_a'] < now()->getTimestamp()) {
            return null;
        }

        if ((int) $entree['etudiant_id'] !== $etudiantId) {
            return null; // jeton lié à un autre dossier : rejet
        }

        return Etudiant::find($etudiantId);
    }

    /* ── Étape 3 : données du formulaire (post-vérification) ─────── */

    /**
     * Données du formulaire prérempli : whitelist explicite. Appelé
     * UNIQUEMENT après vérification d'identité réussie. L'adresse
     * complète n'est JAMAIS exposée au gabarit : seul `email_masque`
     * est transmis (§2 : ne pas afficher l'ancienne adresse complète).
     *
     * @return array{dossier: array<string, mixed>, modifiables: list<string>, pieces: array<int, mixed>}
     */
    public function donneesFormulaire(Etudiant $etudiant): array
    {
        return [
            'dossier' => [
                'num_inscription' => $etudiant->num_inscription,
                'cin_masquee' => $etudiant->cin_masquee,
                'nom' => $etudiant->nom,
                'prenom' => $etudiant->prenom,
                'date_naissance' => $etudiant->date_naissance?->format('d/m/Y'),
                'sexe' => $etudiant->sexe,
                'email_masque' => $etudiant->email_masque,
                'telephone' => $etudiant->telephone,
                'adresse' => $etudiant->adresse,
                'ville' => $etudiant->ville,
                'code_postal' => $etudiant->code_postal,
                'faculte_nom' => $etudiant->faculte?->nom,
                'niveau' => $etudiant->niveau,
                'annee_academique' => $etudiant->annee_academique,
                'statut_label' => $etudiant->statut_label,
            ],
            'modifiables' => self::CHAMPS_MODIFIABLES,
            'pieces' => $this->documentsRequis->checklistDossier(
                $etudiant,
                $etudiant->type_inscription ?? 'premiere',
            ),
        ];
    }

    /* ── Étape 4 : enregistrement de la modification ─────────────── */

    /**
     * Enregistre les modifications autorisées dans une transaction :
     * - rechargement du dossier sous verrou pessimiste (anti-course) ;
     * - garde de statut : un dossier valide ou rejeté n'est JAMAIS
     *   modifié silencieusement (procédure administrative) ;
     * - garde de période : une évaluation CLOSED prouvée refuse
     *   l'écriture (revalidation serveur §3.3, relue sous verrou) ;
     * - whitelist stricte (aucun mass assignment depuis la requête) ;
     * - historique d'audit ancienne/nouvelle valeur ;
     * - le statut n'est JAMAIS modifié ici.
     *
     * @param  array<string, mixed>  $champs  Champs déjà validés et filtrés
     *                                        sur CHAMPS_MODIFIABLES
     * @return array{etudiant: Etudiant, changements: array<string, array{ancienne: mixed, nouvelle: mixed}>, bloques: list<string>}
     *
     * @throws RuntimeException si le statut du dossier interdit la modification
     */
    public function enregistrerModification(int $etudiantId, array $champs): array
    {
        return DB::transaction(function () use ($etudiantId, $champs) {
            // Rechargement SOUS VERROU : le statut est relu au moment
            // de l'écriture (protège contre une décision administrative
            // intervenue entre-temps).
            $etudiant = Etudiant::query()->lockForUpdate()->findOrFail($etudiantId);

            if ($etudiant->statut === 'valide') {
                throw new RuntimeException('Dossier déjà validé : modification en ligne interdite.');
            }

            if ($etudiant->statut === 'rejete') {
                throw new RuntimeException('Dossier rejeté : réouverture par la scolarité requise.');
            }

            if (! $this->periodeAutorisee($etudiant)) {
                throw new RuntimeException("Période d'inscription fermée : modification en ligne indisponible.");
            }

            $changements = [];

            foreach (self::CHAMPS_MODIFIABLES as $champ) {
                if (! array_key_exists($champ, $champs)) {
                    continue;
                }

                $nouvelle = $champs[$champ];

                if ($nouvelle === $etudiant->{$champ}) {
                    continue;
                }

                $changements[$champ] = [
                    'ancienne' => $etudiant->{$champ},
                    'nouvelle' => $nouvelle,
                ];

                $etudiant->{$champ} = $nouvelle;
            }

            if ($changements !== []) {
                $etudiant->save();

                $this->audit->tracer(
                    $etudiant,
                    'modification_dossier_public',
                    'etudiant',
                    $etudiant->id,
                    array_map(fn (array $c) => $c['ancienne'], $changements),
                    array_map(fn (array $c) => $c['nouvelle'], $changements),
                    'web',
                );
            }

            return [
                'etudiant' => $etudiant,
                'changements' => $changements,
                'bloques' => [],
            ];
        });
    }

    private function lireAutorisation(?string $jeton): ?array
    {
        if ($jeton === null || $jeton === '') {
            return null;
        }

        $entree = Cache::get($this->cleAutorisation($jeton));

        if (! is_array($entree) || ! isset($entree['etudiant_id'], $entree['expire_a'])) {
            return null;
        }

        if ($entree['expire_a'] < now()->getTimestamp()) {
            // Autorisation expirée : nettoyage immédiat.
            Cache::forget($this->cleAutorisation($jeton));

            return null;
        }

        return $entree;
    }

    /* ── Clés de cache (sans PII dans les clés) ──────────────────── */

    private function cleCode(Etudiant $etudiant): string
    {
        return 'dossier_existant:code:'.hash('sha256', 'code:'.$etudiant->id);
    }

    private function cleRenvoi(Etudiant $etudiant): string
    {
        return 'dossier_existant:renvoi:'.hash('sha256', 'renvoi:'.$etudiant->id);
    }

    private function cleAutorisation(string $jeton): string
    {
        return 'dossier_existant:autorisation:'.hash('sha256', $jeton);
    }

    private function cleRecherche(string $cin, string $dateNaissance): string
    {
        return 'dossier_existant:recherche:'.hash('sha256', self::normaliserCin($cin).'|'.$dateNaissance);
    }

    private function cleCodeEmail(Etudiant $etudiant): string
    {
        return 'dossier_existant:email:'.hash('sha256', 'changement-email:'.$etudiant->id);
    }

    private function cleRenvoiEmail(Etudiant $etudiant): string
    {
        return 'dossier_existant:email-renvoi:'.hash('sha256', 'changement-email-renvoi:'.$etudiant->id);
    }

    private function cleCodeEmailNouvelle(Etudiant $etudiant): string
    {
        return 'dossier_existant:email-nouvelle:'.hash('sha256', 'changement-email-nouvelle:'.$etudiant->id);
    }

    private function cleRenvoiEmailNouvelle(Etudiant $etudiant): string
    {
        return 'dossier_existant:email-nouvelle-renvoi:'.hash('sha256', 'changement-email-nouvelle-renvoi:'.$etudiant->id);
    }

    private function cleAutonome(string $jeton): string
    {
        return 'dossier_existant:autonome:'.hash('sha256', $jeton);
    }

    private function cleRenvoiAutonome(Etudiant $etudiant): string
    {
        return 'dossier_existant:autonome-renvoi:'.hash('sha256', 'changement-autonome-renvoi:'.$etudiant->id);
    }

    private function cleDemandeAutonome(string $cin, string $dateNaissance): string
    {
        return 'dossier_existant:autonome-demande:'.hash('sha256', self::normaliserCin($cin).'|'.$dateNaissance);
    }

    /**
     * Clé de limitation par DESTINATAIRE candidate (anti-mailbomb : une même
     * adresse ne peut pas être arrosée via des CIN multiples). Clé dérivée
     * d'un hash : aucune adresse en clair dans le stockage.
     */
    private function cleRenvoiCandidat(string $emailNormalise): string
    {
        return 'dossier_existant:candidat-renvoi:'.hash('sha256', 'candidature:'.$emailNormalise);
    }

    /**
     * Empreinte de l'ancienne adresse en vigueur (comparaison sans PII).
     */
    private static function empreinteEmail(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    /**
     * Masque une adresse email pour affichage et audit (jamais l'adresse
     *
     * complète) : « med******@proton.me ». Même règle que
     * `Etudiant::getEmailMasqueAttribute`, applicable aux chaînes brutes
     * (ancienne/nouvelle adresse, valeurs de flash).
     */
    public static function masquerEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '******';
        }

        [$local, $domaine] = explode('@', $email, 2);

        return mb_substr($local, 0, min(3, mb_strlen($local))).'******@'.$domaine;
    }

    /* ── Changement d'adresse email (popup, double preuve) ────────── */

    /**
     * Étape 1 — demande : contrôle serveur puis envoi d'un code de
     * confirmation à l'adresse DÉJÀ VÉRIFIÉE du dossier (l'adresse
     * actuellement enregistrée, via `$etudiant->notify()`), jamais à la
     * nouvelle adresse. C'est la preuve supplémentaire exigée avant de
     * valider le changement (mission §3) : le titulaire légitime doit
     * démontrer qu'il contrôle encore l'adresse en cours — un tiers qui
     * recevrait un code suite à une faute de frappe sur la nouvelle
     * adresse ne peut RIEN valider.
     *
     * Pré-requis (l'appelant a déjà résolu le dossier depuis le jeton
     * temporaire de session — jamais depuis la requête) :
     * - dossier `en_attente`, période d'inscription ouverte, adresse
     *   actuelle exploitable (fail-closed : sans canal de vérification
     *   déjà vérifié, le changement est refusé) ;
     * - nouvelle adresse normalisée, différente de l'actuelle, non
     *   utilisée par un autre dossier (contrôle applicatif + contrainte
     *   UNIQUE en base au moment de l'écriture).
     *
     * Le code est stocké hashé et LIÉ à la nouvelle adresse : l'étape de
     * confirmation ne reprend AUCUNE adresse de la requête (anti-substitution).
     * Rien n'est persisté en base ici ; un échec d'envoi invalide le code
     * (aucun orphelin) et lève une RuntimeException.
     *
     * @throws RuntimeException si un contrôle échoue ou si l'envoi échoue
     */
    public function demanderChangementEmail(Etudiant $etudiant, string $nouvelEmail): void
    {
        $normalise = mb_strtolower(trim($nouvelEmail));

        if ($normalise === '' || ! str_contains($normalise, '@')) {
            throw new RuntimeException('Adresse email invalide.');
        }

        $emailActuel = trim((string) $etudiant->email);

        // Fail-closed : la preuve de changement repose sur l'adresse
        // déjà vérifiée du dossier — sans elle, le changement est
        // refusé (parcours de récupération : service de la scolarité).
        if ($emailActuel === '' || ! str_contains($emailActuel, '@')) {
            throw new RuntimeException('Changement impossible : aucune adresse de vérification valide sur votre dossier. Contactez le service de la scolarité.');
        }

        if ($normalise === mb_strtolower($emailActuel)) {
            throw new RuntimeException('Cette adresse est déjà celle enregistrée sur votre dossier.');
        }

        // Garde de statut : la popup est proposée pour un dossier en
        // attente OU validé (l'email est une coordonnée de contact, pas
        // une donnée académique ; le changement reste protégé par la
        // double preuve, audité et notifié aux deux adresses). Un dossier
        // rejeté exige la réouverture par la scolarité.
        $this->controlerGardesChangementEmail($etudiant, $normalise);

        if (RateLimiter::tooManyAttempts($this->cleRenvoiEmail($etudiant), 1)) {
            throw new RuntimeException('Un code vient déjà d\'être envoyé. Patientez avant de demander un nouveau code.');
        }

        $code = (string) random_int(100000, 999999);

        Cache::put($this->cleCodeEmail($etudiant), [
            'code_hash' => hash('sha256', $code),
            'nouvel_email' => $normalise,
            // Snapshot de l'adresse en vigueur : si elle change entre la
            // demande et l'application (intervention_admin concurrente),
            // la preuve initiale est caduque → refus contrôlé (invariant 8).
            'ancien_email_hash' => self::empreinteEmail($emailActuel),
            'tentatives' => 0,
            'expire_a' => now()->addSeconds(self::DUREE_CODE_EMAIL)->getTimestamp(),
        ], self::DUREE_CODE_EMAIL);

        try {
            // Envoi SYNCHRONE vers l'adresse DÉJÀ VÉRIFIÉE du dossier
            // (`$etudiant->notify()` → adresse enregistrée). La nouvelle
            // adresse ne reçoit rien à ce stade.
            $etudiant->notify(new CodeConfirmationChangementEmail($code, (int) (self::DUREE_CODE_EMAIL / 60)));
        } catch (Throwable) {
            Cache::forget($this->cleCodeEmail($etudiant));

            throw new RuntimeException('Envoi du code de confirmation impossible. Vérifiez votre adresse actuelle et réessayez.');
        }

        RateLimiter::hit($this->cleRenvoiEmail($etudiant), self::DELAI_RENVOI);
    }

    /**
     * Étape 2 — première preuve (adresse actuelle) : valide le code envoyé
     * à l'adresse DÉJÀ VÉRIFIÉE du dossier, SANS rien persister. En cas de
     * succès, le code est consommé (usage unique atomique) et la candidate
     * est retournée avec l'empreinte de l'ancienne adresse en vigueur :
     * l'appelant émet alors le second code, après contrôle d'immutabilité
     * de l'adresse source. L'ancienne adresse reste en vigueur.
     *
     * Retourne null si le code est absent, expiré, épuisé ou invalide.
     *
     * @return array{nouvel_email: string, ancien_email_hash: string}|null
     */
    public function verifierCodeAncienneAdresse(Etudiant $etudiant, string $code): ?array
    {
        // Consumption ATOMIQUE (pull = lecture + suppression en une
        // opération) : en cas de soumissions concurrentes ou rejouées,
        // un seul flux obtient l'entrée — usage unique garanti.
        $entree = Cache::pull($this->cleCodeEmail($etudiant));

        if (! is_array($entree) || ! isset($entree['code_hash'], $entree['nouvel_email'], $entree['expire_a'])) {
            return null;
        }

        if ($entree['expire_a'] < now()->getTimestamp()) {
            return null; // entrée déjà retirée par le pull
        }

        $essais = (int) ($entree['tentatives'] ?? 0);

        if ($essais >= self::MAX_ESSAIS_CODE_EMAIL) {
            return null; // code épuisé, définitivement retiré
        }

        if (! hash_equals((string) $entree['code_hash'], hash('sha256', $code))) {
            // Tentative incorrecte : l'entrée est réposée avec son
            // compteur d'essais incrémenté (le code reste valable
            // jusqu'à épuisement des essais ou expiration).
            $entree['tentatives'] = $essais + 1;
            Cache::put($this->cleCodeEmail($etudiant), $entree, self::DUREE_CODE_EMAIL);

            return null;
        }

        // Succès : code consommé (usage unique) avant l'émission du
        // second code. Aucun Cache::forget nécessaire : le pull a déjà
        // retiré l'entrée. RIEN n'est persisté en base ici.
        return [
            'nouvel_email' => (string) $entree['nouvel_email'],
            // Entrées antérieures (avant snapshot) : empreinte absente →
            // traitée comme inchangée (compatibilité, jamais comme preuve).
            'ancien_email_hash' => (string) ($entree['ancien_email_hash'] ?? ''),
        ];
    }

    /**
     * Étape 2b — émission du second code, vers l'adresse CANDIDATE.
     * L'email servant d'identifiant de connexion à l'espace étudiant, la
     * candidate n'est JAMAIS appliquée avant validation de ce code :
     * l'ancienne adresse reste l'adresse en vigueur, et la candidate vit
     * uniquement dans un état temporaire côté serveur (cache hashé + lié,
     * jamais en base).
     *
     * Les gardes (statut, période, unicité) sont relues ici : le dossier
     * a pu changer entre la demande et la première preuve. Rien n'est
     * persisté en base ; un échec d'envoi invalide le code (aucun
     * orphelin), libère le délai de renvoi de la première preuve (nouvelle
     * tentative immédiate possible) et lève une RuntimeException.
     *
     * @throws RuntimeException si un contrôle échoue ou si l'envoi échoue
     */
    public function emettreCodeNouvelleAdresse(Etudiant $etudiant, string $nouvelEmail, string $ancienEmailHash = ''): void
    {
        $dossier = Etudiant::find($etudiant->id);

        if ($dossier === null) {
            throw new RuntimeException('Dossier introuvable : reprenez le parcours depuis la recherche.');
        }

        // Invariant 8 : l'adresse source ne doit pas avoir changé entre la
        // demande et la première preuve (sinon la preuve est caduque).
        if ($ancienEmailHash !== '' && ! hash_equals($ancienEmailHash, self::empreinteEmail((string) $dossier->email))) {
            throw new RuntimeException('Votre dossier a été modifié entre-temps : reprenez la vérification. Contactez le service de la scolarité.');
        }

        $normalise = mb_strtolower(trim($nouvelEmail));

        if ($normalise === '' || ! str_contains($normalise, '@')) {
            throw new RuntimeException('Adresse email invalide.');
        }

        if ($normalise === mb_strtolower(trim((string) $dossier->email))) {
            throw new RuntimeException('Cette adresse est déjà celle enregistrée sur votre dossier.');
        }

        $this->controlerGardesChangementEmail($dossier, $normalise);

        if (RateLimiter::tooManyAttempts($this->cleRenvoiEmailNouvelle($dossier), 1)) {
            throw new RuntimeException('Un code vient déjà d\'être envoyé à votre nouvelle adresse. Patientez avant de demander un nouveau code.');
        }

        // Anti-mailbomb par destinataire : une même candidate ne peut pas
        // être arrosée via des dossiers multiples.
        if (RateLimiter::tooManyAttempts($this->cleRenvoiCandidat($normalise), 1)) {
            throw new RuntimeException('Un code vient déjà d\'être envoyé à cette adresse. Patientez avant de demander un nouveau code.');
        }

        $code = (string) random_int(100000, 999999);

        Cache::put($this->cleCodeEmailNouvelle($dossier), [
            'code_hash' => hash('sha256', $code),
            'nouvel_email' => $normalise,
            'ancien_email_hash' => self::empreinteEmail((string) $dossier->email),
            'tentatives' => 0,
            'expire_a' => now()->addSeconds(self::DUREE_CODE_EMAIL_NOUVELLE)->getTimestamp(),
        ], self::DUREE_CODE_EMAIL_NOUVELLE);

        try {
            // Envoi SYNCHRONE vers l'adresse CANDIDATE (jamais touchée
            // avant cette étape). L'adresse en vigueur reste inchangée.
            Notification::route('mail', $normalise)->notify(
                new CodeConfirmationNouvelleAdresse($code, (int) (self::DUREE_CODE_EMAIL_NOUVELLE / 60))
            );
        } catch (Throwable) {
            Cache::forget($this->cleCodeEmailNouvelle($dossier));
            // La première preuve a été consommée : sans cette libération,
            // l'étudiant devrait attendre le délai de renvoi pour
            // recommencer depuis la saisie.
            RateLimiter::clear($this->cleRenvoiEmail($dossier));

            throw new RuntimeException('Envoi du code de confirmation à votre nouvelle adresse impossible. Vérifiez sa saisie et réessayez.');
        }

        RateLimiter::hit($this->cleRenvoiEmailNouvelle($dossier), self::DELAI_RENVOI);
        RateLimiter::hit($this->cleRenvoiCandidat($normalise), self::DELAI_RENVOI);
    }

    /**
     * Étape 3 — confirmation finale : valide le code envoyé à la candidate
     * puis applique le changement dans une transaction (rechargement sous
     * verrou, gardes statut + période + unicité relues au moment de
     * l'écriture, backstop UNIQUE en base). SEUL le champ `email` est
     * modifié. Ce n'est qu'ICI que la nouvelle adresse devient effective.
     *
     * Retourne null si le code est absent, expiré, épuisé ou invalide
     * (aucune écriture, ancienne adresse conservée). Lève une
     * RuntimeException si le dossier a changé d'état entre-temps (aucune
     * donnée altérée).
     *
     * @return array{etudiant: Etudiant, ancien_email: string, nouvel_email: string}|null
     *
     * @throws RuntimeException si le statut, la période ou l'unicité interdisent l'écriture
     */
    public function confirmerNouvelleAdresse(Etudiant $etudiant, string $code): ?array
    {
        // Consumption ATOMIQUE (pull = lecture + suppression en une
        // opération) : en cas de soumissions concurrentes ou rejouées,
        // un seul flux obtient l'entrée — usage unique garanti.
        $entree = Cache::pull($this->cleCodeEmailNouvelle($etudiant));

        if (! is_array($entree) || ! isset($entree['code_hash'], $entree['nouvel_email'], $entree['expire_a'])) {
            return null;
        }

        if ($entree['expire_a'] < now()->getTimestamp()) {
            return null; // entrée déjà retirée par le pull
        }

        $essais = (int) ($entree['tentatives'] ?? 0);

        if ($essais >= self::MAX_ESSAIS_CODE_EMAIL_NOUVELLE) {
            return null; // code épuisé, définitivement retiré
        }

        if (! hash_equals((string) $entree['code_hash'], hash('sha256', $code))) {
            // Tentative incorrecte : l'entrée est réposée avec son
            // compteur d'essais incrémenté (le code reste valable
            // jusqu'à épuisement des essais ou expiration).
            $entree['tentatives'] = $essais + 1;
            Cache::put($this->cleCodeEmailNouvelle($etudiant), $entree, self::DUREE_CODE_EMAIL_NOUVELLE);

            return null;
        }

        // Succès : code consommé (usage unique) avant l'écriture.
        // Aucun Cache::forget nécessaire : le pull a déjà retiré l'entrée.

        $nouvelEmail = (string) $entree['nouvel_email'];
        $ancienEmailHash = (string) ($entree['ancien_email_hash'] ?? '');

        try {
            return DB::transaction(function () use ($etudiant, $nouvelEmail, $ancienEmailHash) {
                $dossier = Etudiant::query()->lockForUpdate()->findOrFail($etudiant->id);

                $this->controlerImmuabiliteSource($ancienEmailHash, (string) $dossier->email);

                if ($dossier->statut !== 'en_attente' && $dossier->statut !== 'valide') {
                    throw new RuntimeException('Dossier non modifiable en ligne : contactez le service de la scolarité.');
                }

                if (! $this->periodeAutorisee($dossier)) {
                    throw new RuntimeException("Période d'inscription fermée : modification en ligne indisponible.");
                }

                if ($this->emailUtiliseParAutre($nouvelEmail, $dossier->id)) {
                    throw new RuntimeException('Cette adresse email est déjà utilisée par un autre dossier.');
                }

                $ancienEmail = (string) $dossier->email;
                $dossier->email = $nouvelEmail;
                $dossier->save();

                // Audit minimisé : adresses MASQUÉES (traçabilité sans PII
                // complète — l'adresse effective vit sur le dossier).
                $this->audit->tracer(
                    $dossier,
                    'changement_email_public',
                    'etudiant',
                    $dossier->id,
                    ['email' => self::masquerEmail($ancienEmail)],
                    ['email' => self::masquerEmail($nouvelEmail)],
                    'web',
                );

                return [
                    'etudiant' => $dossier,
                    'ancien_email' => $ancienEmail,
                    'nouvel_email' => $nouvelEmail,
                ];
            });
        } catch (UniqueConstraintViolationException|QueryException) {
            // Course sur l'unicité entre le contrôle et l'écriture
            // (les RuntimeException des gardes remontent intactes).
            throw new RuntimeException('Cette adresse email est déjà utilisée par un autre dossier.');
        }
    }

    /**
     * Notifications post-changement, APRÈS commit uniquement (fail-soft
     * individuel : un échec ne remet jamais en cause l'enregistrement).
     *
     * - alerte à l'ANCIENNE adresse (sécurité : détecter un changement
     *   frauduleux ; nouvelle adresse masquée) ;
     * - confirmation à la NOUVELLE adresse (désormais vérifiée).
     *
     * @return array{ancienne_notifiee: bool, nouvelle_notifiee: bool}
     */
    public function notifierChangementEmail(Etudiant $etudiant, string $ancienEmail, string $nouvelEmail): array
    {
        $ancienneNotifiee = true;
        $nouvelleNotifiee = true;

        if (trim($ancienEmail) !== '' && str_contains($ancienEmail, '@')) {
            try {
                Notification::route('mail', trim($ancienEmail))->notify(
                    new AlerteChangementEmail(self::masquerEmail($nouvelEmail))
                );
            } catch (Throwable) {
                report(new RuntimeException('Alerte de changement d\'email non envoyée à l\'ancienne adresse (changement conservé).'));
                $ancienneNotifiee = false;
            }
        }

        try {
            Notification::route('mail', $nouvelEmail)->notify(
                new ConfirmationChangementEmail($etudiant->num_inscription)
            );
        } catch (Throwable) {
            report(new RuntimeException('Email de confirmation de changement d\'adresse non envoyé (changement conservé).'));
            $nouvelleNotifiee = false;
        }

        return [
            'ancienne_notifiee' => $ancienneNotifiee,
            'nouvelle_notifiee' => $nouvelleNotifiee,
        ];
    }

    /**
     * Nouvelle adresse en attente de confirmation, MASQUÉE (réaffichage
     * de l'étape code après une erreur, sans jamais exposer l'adresse).
     * Retourne null si aucune demande n'est en cours.
     */
    public function nouvelEmailMasqueEnAttente(Etudiant $etudiant): ?string
    {
        $entree = Cache::get($this->cleCodeEmail($etudiant));

        if (! is_array($entree) || ! isset($entree['nouvel_email'], $entree['expire_a'])) {
            return null;
        }

        if ($entree['expire_a'] < now()->getTimestamp()) {
            return null;
        }

        return self::masquerEmail((string) $entree['nouvel_email']);
    }

    /**
     * Gardes communes du changement d'adresse (statut, période, unicité),
     * relues à chaque étape sensible (demande, émission du second code,
     * application) : le dossier peut changer entre deux étapes.
     *
     * @throws RuntimeException si le statut, la période ou l'unicité interdisent la poursuite
     */
    private function controlerGardesChangementEmail(Etudiant $etudiant, string $emailNormalise): void
    {
        // Garde de statut : dossier en attente OU validé (l'email est une
        // coordonnée de contact, pas une donnée académique ; le changement
        // reste protégé par la double preuve, audité et notifié aux deux
        // adresses). Un dossier rejeté exige la réouverture par la scolarité.
        if (! in_array($etudiant->statut, ['en_attente', 'valide'], true)) {
            throw new RuntimeException('Dossier non modifiable en ligne : contactez le service de la scolarité.');
        }

        if (! $this->periodeAutorisee($etudiant)) {
            throw new RuntimeException("Période d'inscription fermée : modification en ligne indisponible.");
        }

        if ($this->emailUtiliseParAutre($emailNormalise, $etudiant->id)) {
            throw new RuntimeException('Cette adresse email est déjà utilisée par un autre dossier.');
        }
    }

    /**
     * Adresse candidate en attente de confirmation finale, MASQUÉE
     * (réaffichage de l'étape de confirmation vers la nouvelle adresse
     * après une erreur, sans jamais exposer l'adresse). Retourne null si
     * aucune candidature n'est en cours.
     */
    public function candidatureMasqueeEnAttente(Etudiant $etudiant): ?string
    {
        $entree = Cache::get($this->cleCodeEmailNouvelle($etudiant));

        if (! is_array($entree) || ! isset($entree['nouvel_email'], $entree['expire_a'])) {
            return null;
        }

        if ($entree['expire_a'] < now()->getTimestamp()) {
            return null;
        }

        return self::masquerEmail((string) $entree['nouvel_email']);
    }

    /* ── Parcours autonome (sans l'ancienne adresse, sans scolarité) ── */

    /**
     * Limiteur de débit par identité du parcours autonome (anti-force-brute
     * distribué, complémentaire du throttle par IP des routes). Même clé
     * dérivée hashée que la recherche : aucune donnée personnelle en clair.
     */
    public function demandeAutonomeBloquee(string $cin, string $dateNaissance): bool
    {
        return RateLimiter::tooManyAttempts($this->cleDemandeAutonome($cin, $dateNaissance), self::MAX_DEMANDES_AUTONOME);
    }

    public function compterDemandeAutonome(string $cin, string $dateNaissance): void
    {
        RateLimiter::hit($this->cleDemandeAutonome($cin, $dateNaissance), 900);
    }

    /**
     * Étape 1 — demande autonome : CIN + date de naissance + ANCIENNE
     * adresse + MOT DE PASSE du compte + nouvelle adresse, en UNE seule
     * soumission à réponse neutre (aucun oracle d'existence : le retour
     * est identique que le dossier corresponde ou non).
     *
     * Preuves exigées (toutes, sans exception) :
     * - correspondance CIN (normalisé) + date exacte + ancienne adresse
     *   normalisée (connaissance — INSUFFISANTE seule, mission §Interdiction) ;
     * - mot de passe du compte vérifié en bcrypt (preuve supplémentaire
     *   fiable et déjà disponible — pas de question faible inventée) ;
     * - dossier avec mot de passe défini, statut autorisé, période ouverte ;
     * - nouvelle adresse normalisée, différente, non utilisée.
     *
     * En cas de succès : une autorisation temporaire liée au dossier exact
     * et à la SEULE opération `email_change` est émise (jeton opaque rendu
     * à l'appelant pour mise en session, jamais en URL), et un code à usage
     * unique est envoyé à la NOUVELLE adresse (jamais appliquée avant sa
     * confirmation ; l'ancienne reste active). Retourne le jeton brut, ou
     * null en cas d'échec — SANS distinguer la cause (neutralité).
     * Rien n'est persisté en base ici.
     */
    public function demanderChangementAutonome(string $cin, string $dateNaissance, string $ancienEmail, string $motDePasse, string $nouvelEmail): ?string
    {
        if ($this->demandeAutonomeBloquee($cin, $dateNaissance)) {
            return null;
        }

        $this->compterDemandeAutonome($cin, $dateNaissance);

        $etudiant = $this->rechercher($cin, $dateNaissance);
        $ancienNormalise = mb_strtolower(trim($ancienEmail));

        // Vérification à durée comparable dans tous les cas (anti-oracle
        // temporel, même doctrine que la connexion) : un bcrypt est TOUJOURS
        // exécuté, même sans dossier correspondant.
        $empreinte = ($etudiant !== null && is_string($etudiant->password) && $etudiant->password !== '')
            ? $etudiant->password
            : '$2y$12$k1pW5dHhc72vFo3SF3rAOeLUrgzwefl91XUMRfRyMNa8FoPzyuZIi';

        $motDePasseValide = Hash::check($motDePasse, $empreinte);

        if ($etudiant === null
            || $ancienNormalise === ''
            || $ancienNormalise !== mb_strtolower(trim((string) $etudiant->email))
            || ! $motDePasseValide
        ) {
            return null;
        }

        $normalise = mb_strtolower(trim($nouvelEmail));

        if ($normalise === '' || ! str_contains($normalise, '@')) {
            return null;
        }

        if ($normalise === mb_strtolower(trim((string) $etudiant->email))) {
            return null;
        }

        try {
            $this->controlerGardesChangementEmail($etudiant, $normalise);
        } catch (RuntimeException) {
            return null;
        }

        if (RateLimiter::tooManyAttempts($this->cleRenvoiAutonome($etudiant), 1)) {
            return null;
        }

        // Anti-mailbomb par destinataire (même candidate via des CIN multiples).
        if (RateLimiter::tooManyAttempts($this->cleRenvoiCandidat($normalise), 1)) {
            return null;
        }

        $jeton = bin2hex(random_bytes(24));
        $code = (string) random_int(100000, 999999);

        Cache::put($this->cleAutonome($jeton), [
            'etudiant_id' => $etudiant->id,
            'operation' => 'email_change',
            'nouvel_email' => $normalise,
            'ancien_email_hash' => self::empreinteEmail((string) $etudiant->email),
            'code_hash' => hash('sha256', $code),
            'tentatives' => 0,
            'expire_a' => now()->addSeconds(self::DUREE_CODE_AUTONOME)->getTimestamp(),
        ], self::DUREE_CODE_AUTONOME);

        try {
            // Envoi SYNCHRONE vers la NOUVELLE adresse (candidate). En cas
            // d'échec : aucun orphelin, aucun cooldown (nouvelle tentative
            // immédiate possible), ancienne adresse conservée.
            Notification::route('mail', $normalise)->notify(
                new CodeConfirmationNouvelleAdresse($code, (int) (self::DUREE_CODE_AUTONOME / 60))
            );
        } catch (Throwable) {
            Cache::forget($this->cleAutonome($jeton));

            return null;
        }

        RateLimiter::hit($this->cleRenvoiAutonome($etudiant), self::DELAI_RENVOI);
        RateLimiter::hit($this->cleRenvoiCandidat($normalise), self::DELAI_RENVOI);

        return $jeton;
    }

    /**
     * Étape 2 — confirmation autonome : valide le code envoyé à la candidate
     * puis applique le changement dans une transaction (autorisation
     * `email_change` consommée atomiquement AVANT l'écriture : usage unique
     * garanti même en concurrence ; rechargement sous verrou, gardes statut
     * + période + unicité relues, backstop UNIQUE). SEUL le champ `email`
     * est modifié. L'identifiant du dossier provient EXCLUSIVEMENT du jeton
     * (jamais de la requête).
     *
     * Retourne null si l'autorisation est absente, expirée, épuisée, liée à
     * une autre opération, ou si le code est invalide (ancienne adresse
     * conservée dans tous les cas). Lève une RuntimeException si le dossier
     * a changé d'état entre-temps (aucune donnée altérée).
     *
     * @return array{etudiant: Etudiant, ancien_email: string, nouvel_email: string}|null
     *
     * @throws RuntimeException si le statut, la période ou l'unicité interdisent l'écriture
     */
    public function confirmerChangementAutonome(?string $jeton, string $code): ?array
    {
        if ($jeton === null || $jeton === '') {
            return null;
        }

        $entree = Cache::get($this->cleAutonome($jeton));

        if (! is_array($entree)
            || ($entree['operation'] ?? null) !== 'email_change'
            || ! isset($entree['etudiant_id'], $entree['nouvel_email'], $entree['code_hash'], $entree['expire_a'])
        ) {
            return null;
        }

        if ($entree['expire_a'] < now()->getTimestamp()) {
            Cache::forget($this->cleAutonome($jeton));

            return null;
        }

        $essais = (int) ($entree['tentatives'] ?? 0);

        if ($essais >= self::MAX_ESSAIS_CODE_AUTONOME) {
            Cache::forget($this->cleAutonome($jeton));

            return null;
        }

        if (! hash_equals((string) $entree['code_hash'], hash('sha256', $code))) {
            $entree['tentatives'] = $essais + 1;
            Cache::put($this->cleAutonome($jeton), $entree, self::DUREE_CODE_AUTONOME);

            return null;
        }

        // Succès : consumption ATOMIQUE (pull) avant l'écriture — toute
        // soumission concurrente ou rejouée échoue (autorisation à usage unique).
        $entree = Cache::pull($this->cleAutonome($jeton));

        if (! is_array($entree) || ($entree['operation'] ?? null) !== 'email_change') {
            return null;
        }

        $nouvelEmail = (string) $entree['nouvel_email'];
        $etudiantId = (int) $entree['etudiant_id'];
        $ancienEmailHash = (string) ($entree['ancien_email_hash'] ?? '');

        try {
            return DB::transaction(function () use ($etudiantId, $nouvelEmail, $ancienEmailHash) {
                $dossier = Etudiant::query()->lockForUpdate()->findOrFail($etudiantId);

                $this->controlerImmuabiliteSource($ancienEmailHash, (string) $dossier->email);

                if ($dossier->statut !== 'en_attente' && $dossier->statut !== 'valide') {
                    throw new RuntimeException('Dossier non modifiable en ligne : contactez le service de la scolarité.');
                }

                if (! $this->periodeAutorisee($dossier)) {
                    throw new RuntimeException("Période d'inscription fermée : modification en ligne indisponible.");
                }

                if ($this->emailUtiliseParAutre($nouvelEmail, $dossier->id)) {
                    throw new RuntimeException('Cette adresse email est déjà utilisée par un autre dossier.');
                }

                $ancienEmail = (string) $dossier->email;
                $dossier->email = $nouvelEmail;
                $dossier->save();

                $this->audit->tracer(
                    $dossier,
                    'changement_email_autonome',
                    'etudiant',
                    $dossier->id,
                    ['email' => self::masquerEmail($ancienEmail)],
                    ['email' => self::masquerEmail($nouvelEmail)],
                    'web',
                );

                return [
                    'etudiant' => $dossier,
                    'ancien_email' => $ancienEmail,
                    'nouvel_email' => $nouvelEmail,
                ];
            });
        } catch (UniqueConstraintViolationException|QueryException) {
            throw new RuntimeException('Cette adresse email est déjà utilisée par un autre dossier.');
        }
    }

    /**
     * Invariant 8 — immutabilité de l'adresse source : si l'adresse en
     * vigueur ne correspond plus à celle prouvée à la demande, la preuve
     * est caduque (modification concurrente du compte) → refus contrôlé,
     * ancienne adresse conservée. Un snapshot absent (entrées historiques)
     * est toléré, jamais interprété comme preuve.
     *
     * @throws RuntimeException si l'adresse source a changé entre-temps
     */
    private function controlerImmuabiliteSource(string $ancienEmailHash, string $emailActuel): void
    {
        if ($ancienEmailHash !== '' && ! hash_equals($ancienEmailHash, self::empreinteEmail($emailActuel))) {
            throw new RuntimeException('Votre dossier a été modifié entre-temps : reprenez la vérification. Contactez le service de la scolarité.');
        }
    }

    /**
     * Unicité insensible à la casse et aux espaces, hors dossier courant.
     */
    private function emailUtiliseParAutre(string $emailNormalise, int $etudiantId): bool
    {
        return Etudiant::query()
            ->where('id', '!=', $etudiantId)
            ->whereRaw('LOWER(TRIM(email)) = ?', [$emailNormalise])
            ->exists();
    }

    /* ── Email final (après commit) ──────────────────────────────── */

    /**
     * Envoi de l'email de confirmation APRÈS commit. Tolérant aux
     * pannes : un échec d'envoi ne remet JAMAIS en cause
     * l'enregistrement déjà validé ; l'état est journalisé (sans PII)
     * et l'envoi reste récupérable (notification en file avec retries).
     * Retourne vrai si l'envoi a été accepté/planifié.
     */
    public function notifierConfirmation(Etudiant $etudiant, int $nbChampsModifies): bool
    {
        try {
            $etudiant->notify(new ConfirmationMiseAJourDossierExistant(
                $etudiant->num_inscription,
                $nbChampsModifies,
            ));

            return true;
        } catch (Throwable) {
            // Fail-soft : la source de vérité reste le dossier en base.
            // La notification est en file (retries) ou le transport a
            // échoué : journalisé, jamais bloquant.
            report(new RuntimeException('Email de confirmation de mise à jour non envoyé (dossier conservé).'));

            return false;
        }
    }
}
