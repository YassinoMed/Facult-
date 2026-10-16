<?php

namespace App\Http\Controllers;

use App\Events\StudentRegistered;
use App\Http\Requests\InscriptionRequest;
use App\Http\Requests\ScanIdentityDocumentRequest;
use App\Http\Requests\VerifierCinRequest;
use App\Models\Etudiant;
use App\Models\MensReference;
use App\Modules\Referentiel\Models\Niveau;
use App\Services\AssistantFaqService;
use App\Services\AuditService;
use App\Services\DocumentsRequisService;
use App\Services\DonneesFormulaireInscription;
use App\Services\EligibiliteParcoursService;
use App\Services\IdentificationIntelligenteService;
use App\Services\Identity\IdentityDocumentService;
use App\Services\PeriodeInscriptionService;
use App\Services\StudentLookupService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class InscriptionController extends Controller
{
    public function create(DonneesFormulaireInscription $donnees)
    {
        // Données centralisées (service partagé avec le parcours embarqué) :
        // mêmes facultés, référentiels, état des périodes et jeton.
        return view('inscription.create', $donnees->pourCreation());
    }

    /**
     * Inscription finale (§6/§7) : le pré-remplissage n'a JAMAIS d'effet
     * d'écriture — c'est uniquement ici, après validation explicite de
     * l'utilisateur, que le dossier est créé :
     * - $request->validated() : uniquement les champs autorisés (pas de
     *   mass assignment : $fillable strict, statut/cin non injectables) ;
     * - transaction atomique ;
     * - unicité du CIN garantie par la contrainte BD + règle unique
     *   (message métier, aucun doublon possible).
     */
    public function store(InscriptionRequest $request, PeriodeInscriptionService $periodes)
    {
        // LOOP périodes : recalcul serveur au moment de la soumission
        // (une page ouverte à 09:59 reste refusée à 10:01 si fermée).
        try {
            $periodes->verifierCreationPublique(
                $request->integer('faculte_id') ?: null,
                $request->string('annee_academique')->toString() ?: null,
                $request->string('cin')->toString() ?: null,
            );
        } catch (\DomainException $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => ['periode' => [$e->getMessage()]],
                ], 422);
            }

            return back()->withErrors(['periode' => $e->getMessage()])->withInput();
        }

        // Anti-doublon CIN normalisé (LOOP 07) et protection contre le double dépôt / rejeu (LOOP 09)
        $cinNormalise = strtoupper(preg_replace('/\s+/', '', (string) $request->input('cin')) ?? '');
        $idempotencyToken = $request->string('idempotency_token')->toString();
        $emailNormalise = mb_strtolower(trim((string) $request->input('email')));

        if ($cinNormalise !== '') {
            $existant = Etudiant::whereNotNull('cin')
                ->whereRaw("REPLACE(UPPER(TRIM(cin)), ' ', '') = ?", [$cinNormalise])
                ->first();

            if ($existant) {
                // Idempotence & rejeu (LOOP 09) : activé si jeton d'idempotence fourni et email identique
                if ($idempotencyToken !== '') {
                    $tokenSessionKey = "idempotency_token_{$idempotencyToken}";
                    $estMemeEmail = mb_strtolower(trim((string) $existant->email)) === $emailNormalise;

                    $estMemeTokenSession = session($tokenSessionKey) === $existant->num_inscription;
                    $estCreationRecente = $existant->created_at
                        && $existant->created_at->diffInSeconds(now()) < 60
                        && $estMemeEmail;

                    if ($estMemeEmail && ($estMemeTokenSession || $estCreationRecente)) {
                        if ($request->wantsJson()) {
                            return response()->json([
                                'ok' => true,
                                'deja_enregistre' => true,
                                'num_inscription' => $existant->num_inscription,
                                'nom_etudiant' => $existant->nom_complet,
                                'faculte_nom' => $existant->faculte?->nom,
                                'message' => 'Votre inscription a déjà été enregistrée avec succès !',
                            ]);
                        }

                        return redirect()->route('inscription.success')
                            ->with('num_inscription', $existant->num_inscription)
                            ->with('nom_etudiant', $existant->nom_complet)
                            ->with('faculte_nom', $existant->faculte?->nom)
                            ->with('success', 'Votre inscription a déjà été enregistrée avec succès !');
                    }
                }

                if ($request->wantsJson()) {
                    return response()->json([
                        'message' => 'Un dossier existe déjà avec ce numéro de CIN. Veuillez vous connecter pour continuer votre inscription.',
                        'errors' => ['cin' => ['Un dossier existe déjà avec ce numéro de CIN. Veuillez vous connecter pour continuer votre inscription.']],
                    ], 422);
                }

                return back()->withErrors([
                    'cin' => 'Un dossier existe déjà avec ce numéro de CIN. Veuillez vous connecter pour continuer votre inscription.',
                ])->withInput();
            }
        }

        // Anti-doublon Email normalisé
        if ($emailNormalise !== '') {
            $existantEmail = Etudiant::whereRaw('LOWER(TRIM(email)) = ?', [$emailNormalise])->first();
            if ($existantEmail) {
                if ($idempotencyToken !== '' && session("idempotency_token_{$idempotencyToken}") === $existantEmail->num_inscription) {
                    if ($request->wantsJson()) {
                        return response()->json([
                            'ok' => true,
                            'deja_enregistre' => true,
                            'num_inscription' => $existantEmail->num_inscription,
                            'nom_etudiant' => $existantEmail->nom_complet,
                            'faculte_nom' => $existantEmail->faculte?->nom,
                            'message' => 'Votre inscription a déjà été enregistrée avec succès !',
                        ]);
                    }

                    return redirect()->route('inscription.success')
                        ->with('num_inscription', $existantEmail->num_inscription)
                        ->with('nom_etudiant', $existantEmail->nom_complet)
                        ->with('faculte_nom', $existantEmail->faculte?->nom)
                        ->with('success', 'Votre inscription a déjà été enregistrée avec succès !');
                }

                if ($request->wantsJson()) {
                    return response()->json([
                        'message' => 'Un dossier existe déjà avec cette adresse email. Veuillez vous connecter pour continuer votre inscription.',
                        'errors' => ['email' => ['Un dossier existe déjà avec cette adresse email. Veuillez vous connecter pour continuer votre inscription.']],
                    ], 422);
                }

                return back()->withErrors([
                    'email' => 'Un dossier existe déjà avec cette adresse email. Veuillez vous connecter pour continuer votre inscription.',
                ])->withInput();
            }
        }

        // Fail-closed MENS (correctif critique) : l'identification est
        // RECALCULÉE côté serveur sur les valeurs SOUMISES — aucune
        // confiance au wizard/JS/session. Seul un couple présent dans
        // `mens_references` (statut MENS_SEUL ou MENS_ET_LOCAL, sans
        // divergence BLOQUANT) autorise la création.
        $naissanceSoumise = trim((string) $request->input('date_naissance'));

        if ($naissanceSoumise === '') {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $this->messageIdentification(),
                    'errors' => ['identification' => [$this->messageIdentification()]],
                ], 422);
            }

            return back()->withErrors(['identification' => $this->messageIdentification()])->withInput();
        }

        $identification = app(IdentificationIntelligenteService::class)->identifier(
            (string) $request->input('cin'),
            $naissanceSoumise,
        );

        $bloquant = collect($identification['divergences'] ?? [])
            ->contains(fn ($divergence) => ($divergence['gravite'] ?? null) === 'BLOQUANT');

        if (! in_array($identification['statut'], ['MENS_SEUL', 'MENS_ET_LOCAL'], true) || $bloquant) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $this->messageIdentification(),
                    'errors' => ['identification' => [$this->messageIdentification()]],
                ], 422);
            }

            return back()->withErrors(['identification' => $this->messageIdentification()])->withInput();
        }

        // LOOP 08 : Revalidation stricte côté serveur de l'éligibilité au parcours et orientation
        $bilanEligibilite = app(EligibiliteParcoursService::class)->evaluer($request->all());

        if (! $bilanEligibilite['eligible']) {
            if ($request->wantsJson()) {
                $bloquants = $bilanEligibilite['bloquants'] ?? ['eligibilite' => 'Inscription non éligible.'];
                $messagePremier = is_array($bloquants) ? (string) reset($bloquants) : (string) $bloquants;

                return response()->json([
                    'message' => $messagePremier,
                    'errors' => is_array($bloquants) ? $bloquants : ['eligibilite' => [$bloquants]],
                ], 422);
            }

            return back()->withErrors($bilanEligibilite['bloquants'])->withInput();
        }

        try {
            $etudiant = DB::transaction(function () use ($request, $bilanEligibilite): Etudiant {
                $donnees = $request->validated();
                $etudiant = Etudiant::create($donnees);

                // Rattachement automatique au niveau du référentiel si programme renseigné
                if ($etudiant->programme_id && ! $etudiant->niveau_id) {
                    $niveauRef = Niveau::where('programme_id', $etudiant->programme_id)
                        ->where('code', $etudiant->niveau)
                        ->first();
                    if ($niveauRef) {
                        $etudiant->niveau_id = $niveauRef->id;
                        $etudiant->saveQuietly();
                    }
                }

                // Traçabilité des exceptions administratives
                if ($bilanEligibilite['exception_accordee'] ?? false) {
                    app(AuditService::class)->tracer(
                        $etudiant,
                        'qualite.derogation_eligibilite',
                        'etudiant',
                        $etudiant->id,
                        null,
                        [
                            'programme_id' => $etudiant->programme_id,
                            'niveau' => $etudiant->niveau,
                            'derogation' => $request->input('derogation_administrative') ?: 'Dérogation accordée',
                        ]
                    );
                }

                return $etudiant;
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            // Concurrence : un doublon exact a été commis en parallèle (race condition)
            // Idempotence (LOOP 09) : si l'enregistrement a été créé dans les 30 dernières secondes, rediriger vers success sans erreur 500
            if ($cinNormalise !== '') {
                $existant = Etudiant::whereNotNull('cin')
                    ->whereRaw("REPLACE(UPPER(TRIM(cin)), ' ', '') = ?", [$cinNormalise])
                    ->first();
                if ($existant && $existant->created_at && $existant->created_at->diffInSeconds(now()) < 30) {
                    if ($request->wantsJson()) {
                        return response()->json([
                            'ok' => true,
                            'deja_enregistre' => true,
                            'num_inscription' => $existant->num_inscription,
                            'nom_etudiant' => $existant->nom_complet,
                            'faculte_nom' => $existant->faculte?->nom,
                            'message' => 'Votre inscription a été enregistrée avec succès !',
                        ]);
                    }

                    return redirect()->route('inscription.success')
                        ->with('num_inscription', $existant->num_inscription)
                        ->with('nom_etudiant', $existant->nom_complet)
                        ->with('faculte_nom', $existant->faculte?->nom)
                        ->with('success', 'Votre inscription a été enregistrée avec succès !');
                }
            }

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Un dossier avec ce numéro de CIN ou cette adresse email a déjà été enregistré.',
                    'errors' => ['cin' => ['Un dossier avec ce numéro de CIN ou cette adresse email a déjà été enregistré.']],
                ], 422);
            }

            return back()->withErrors([
                'cin' => 'Un dossier avec ce numéro de CIN ou cette adresse email a déjà été enregistré.',
            ])->withInput();
        }

        // Trace en session pour idempotence et retour arrière (LOOP 09)
        if ($cinNormalise !== '') {
            session()->put("inscription_effectuee_{$cinNormalise}", $etudiant->num_inscription);
        }
        if ($idempotencyToken !== '') {
            session()->put("idempotency_token_{$idempotencyToken}", $etudiant->num_inscription);
        }

        // Point d'extension (§6) : les modules réagissent sans que le
        // parcours d'inscription les connaisse. Aucun effet de bord ici
        // si aucun listener n'est enregistré.
        StudentRegistered::dispatch($etudiant);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'deja_enregistre' => false,
                'num_inscription' => $etudiant->num_inscription,
                'nom_etudiant' => $etudiant->nom_complet,
                'faculte_nom' => $etudiant->faculte?->nom,
                'message' => 'Votre inscription a été enregistrée avec succès !',
            ]);
        }

        return redirect()->route('inscription.success')
            ->with('num_inscription', $etudiant->num_inscription)
            ->with('nom_etudiant', $etudiant->nom_complet)
            ->with('faculte_nom', $etudiant->faculte?->nom)
            ->with('success', 'Votre inscription a été enregistrée avec succès !');
    }

    /**
     * FAQ officielle (LOOP 15) : réponses sourcées depuis le parcours réel
     * (config `inscriptions.faq` — contenus approuvés, aucune règle inventée).
     * Page publique en lecture seule : aucune donnée de dossier, aucune
     * action métier, aucune question stockée (rien à journaliser, zéro PII).
     * Les questions non couvertes sont orientées vers la scolarité ou,
     * pour les étudiants connectés, vers une demande d'assistance (LOOP 16).
     */
    public function faq(Request $request, AssistantFaqService $assistant): View
    {
        $questionRecherchee = $request->string('q')->trim()->toString();
        $reponseAssistant = $questionRecherchee !== ''
            ? $assistant->repondre($questionRecherchee)
            : null;

        return view('inscription.faq', [
            'entrees' => config('inscriptions.faq', []),
            'contact' => config('inscriptions.contact_scolarite'),
            'reponseAssistant' => $reponseAssistant,
            'questionRecherchee' => $questionRecherchee,
        ]);
    }

    /**
     * Assistant FAQ interactif (LOOP 15) : interrogation asynchrone des sources officielles.
     * En lecture seule, fail-safe, zéro PII, orientation scolarité si incertain.
     */
    public function interrogerAssistantFaq(Request $request, AssistantFaqService $assistant): JsonResponse
    {
        $donnees = $request->validate([
            'question' => ['required', 'string', 'max:500'],
        ]);

        $reponse = $assistant->repondre($donnees['question']);

        return response()->json($reponse);
    }

    /**
     * LOOP 08 : Fournit les options de parcours ouvertes et explique les contraintes d'éligibilité.
     */
    public function optionsOrientation(Request $request, EligibiliteParcoursService $service): JsonResponse
    {
        $faculteId = $request->integer('faculte_id');
        if ($faculteId <= 0) {
            return response()->json(['error' => 'Faculté obligatoire ou invalide'], 422);
        }

        $typeInscription = $request->string('type_inscription', 'premiere')->toString();
        $anneeBac = $request->string('annee_bac')->toString() ?: null;
        $cin = $request->string('cin')->toString() ?: null;
        $anneeAcademique = $request->string('annee_academique')->toString() ?: null;

        $options = $service->optionsOrientation($faculteId, $typeInscription, $anneeBac, $cin, $anneeAcademique);

        return response()->json($options);
    }

    public function success()
    {
        return view('inscription.success');
    }

    /**
     * Message public générique d'échec d'identification (§10) : ne
     * révèle ni l'existence d'un dossier, ni le matricule national,
     * ni aucune donnée MENS.
     */
    private function messageIdentification(): string
    {
        return 'Les informations fournies ne permettent pas de confirmer votre identité. Vérifiez votre CIN et votre date de naissance.';
    }

    /**
     * Recherche publique par CIN + DATE DE NAISSANCE (audit §P0-4,
     * extension LOOP « dossier existant »).
     *
     * POST (CSRF obligatoire, CIN jamais exposé dans les URLs/logs) :
     * - étudiant introuvable OU date incorrecte → { "exists": false }
     *   (réponse identique : aucune énumération possible) ;
     * - correspondance exacte → { "exists": true, "verification_requise": true,
     *   "email_masque", "redirection" } — AUCUNE donnée personnelle du
     *   dossier (le couple CIN + naissance ne constitue PAS une preuve
     *   d'identité suffisante) : l'accès aux données exige le code de
     *   vérification à usage unique du parcours sécurisé dédié.
     * La création/mise à jour n'a JAMAIS lieu ici.
     */
    public function verifierCin(VerifierCinRequest $request, StudentLookupService $lookup): JsonResponse
    {
        $etudiant = $lookup->trouverPourPrefill(
            $request->validated('cin'),
            $request->validated('date_naissance'),
        );

        if ($etudiant === null) {
            return response()->json(['exists' => false]);
        }

        return response()->json([
            'exists' => true,
            'verification_requise' => true,
            'email_masque' => $etudiant->email_masque,
            'redirection' => route('inscription.existant.accueil'),
        ]);
    }

    /**
     * État période précis pour la page publique (LOOP UI §14) : même
     * source que la soumission. Sans authentification (page publique),
     * sans PII en réponse, rate limiting anti-scan.
     */
    public function etatPeriode(Request $request, PeriodeInscriptionService $periodes): JsonResponse
    {
        $valide = $request->validate([
            'faculte_id' => 'nullable|integer|exists:facultes,id',
            'annee' => 'nullable|string|max:20',
            'cin' => 'nullable|string|max:30',
        ]);

        return response()->json($periodes->etatPublic(
            isset($valide['faculte_id']) ? (int) $valide['faculte_id'] : null,
            $valide['annee'] ?? null,
            $valide['cin'] ?? null,
        ));
    }

    /**
     * Identification intelligente (mission MENS) : CIN + date de
     * naissance → statut + préremplissage STRICTEMENT minimal.
     * Dossier local existant : `{status: EXISTING_STUDENT}` UNIQUEMENT
     * (aucune donnée personnelle exposée — §8/§24). MENS seul :
     * préremplissage d'identité (sans contact). Même porte que
     * verifierCin (CIN+naissance, CSRF, throttle strict).
     */
    public function identifier(VerifierCinRequest $request, IdentificationIntelligenteService $identification): JsonResponse
    {
        $resultat = $identification->identifier(
            $request->validated('cin'),
            $request->validated('date_naissance'),
        );

        if ($resultat['statut'] === 'INDISPONIBLE') {
            return response()->json([
                'status' => 'MENS_UNAVAILABLE',
                'message' => 'Le référentiel national est temporairement indisponible. Vous pouvez poursuivre votre saisie manuellement.',
            ]);
        }

        if ($resultat['statut'] === 'AMBIGU' || $resultat['certitude'] === 'ambigue') {
            return response()->json([
                'status' => 'AMBIGUOUS',
                'message' => 'Une ambiguïté d’identification a été détectée sur votre dossier. Veuillez contacter le service de scolarité.',
            ]);
        }

        if (in_array($resultat['statut'], ['LOCAL_SEUL', 'MENS_ET_LOCAL'], true)) {
            $local = Etudiant::where(
                'cin',
                MensReference::normaliserCin($request->validated('cin'))
            )->whereDate('date_naissance', $request->validated('date_naissance'))->firstOrFail();

            // Extension LOOP « dossier existant » : AUCUNE donnée du
            // dossier avant vérification d'identité (code à usage unique
            // envoyé à l'adresse déjà enregistrée). L'adresse n'est
            // retournée que masquée (pattern établi), avec la redirection
            // vers le parcours sécurisé de mise à jour.
            return response()->json([
                'status' => 'EXISTING_STUDENT',
                'masked_email' => $local->email_masque,
                'redirection' => route('inscription.existant.accueil'),
                'message' => 'Un dossier existe déjà pour ces références. Poursuivez via le parcours sécurisé de mise à jour.',
            ]);
        }

        if ($resultat['statut'] === 'MENS_SEUL') {
            $prefill = $resultat['prefill'];

            return response()->json([
                'status' => 'MENS_FOUND',
                'certitude' => $resultat['certitude'],
                'source' => 'MENS',
                'prefill' => array_intersect_key($prefill, array_flip([
                    'cin', 'nom', 'prenom', 'nom_ar', 'prenom_ar',
                    'date_naissance', 'sexe', 'nationalite',
                ])),
            ]);
        }

        return response()->json(['status' => 'UNKNOWN']);
    }

    /**
     * Scan CIN / Passeport + OCR (§1-18) — contrôleur volontairement
     * LÉGER : toute la logique vit dans IdentityDocumentService.
     *
     * POST (CSRF, rate limiting identity.scan §19) :
     * - upload temporaire PRIVÉ (nom aléatoire, jamais public/) ;
     * - extraction OCR normalisée avec confiance par champ (§5/§8) ;
     * - recherche BD fail-closed : numéro + date de naissance extraite
     *   (jamais le numéro seul — anti-énumération, audit §P0-4) ;
     * - réponse whitelist : JAMAIS password, hash, token, ni contenu
     *   OCR brut (§12/§18) ;
     * - §13 option A : le fichier est SUPPRIMÉ dans tous les cas
     *   (finally) — aucun compte n'est créé ici.
     */
    public function scanIdentity(ScanIdentityDocumentRequest $request, IdentityDocumentService $identity): JsonResponse
    {
        $type = $request->validated('document_type');
        $chemin = $identity->uploadDocument($request->file('document'), $type);

        try {
            $resultat = $identity->extractData($chemin, $type);
            $alertes = $identity->validateData($resultat);
            $existant = $identity->matchExistingStudent($resultat);
            $divergences = $existant !== null
                ? $identity->comparerAvecExistant($resultat, $existant)
                : [];

            return response()->json(array_merge(
                ['success' => true],
                $identity->buildPrefillData($resultat, $existant, $divergences, $alertes),
            ));
        } finally {
            // §13 option A : suppression systématique du document temporaire
            $identity->supprimerDocument($chemin);
        }
    }

    /**
     * Checklist documentaire dynamique (LOOP 04) :
     * - Retourne la liste des pièces requises pour un type d'inscription (premiere, reinscription, transfert) ;
     * - Si le couple CIN + date de naissance est fourni et correspond à un étudiant existant,
     *   évalue l'état réel du dossier GED (valide, expire, en_attente, rejete avec motif, non_fourni) ;
     * - Anti-énumération (§P0-4) : ne divulgue jamais l'état d'un dossier sans authentification ou couple CIN+naissance valide.
     */
    public function checklistDocuments(Request $request, DocumentsRequisService $documentsRequis): JsonResponse
    {
        $valide = $request->validate([
            'type' => 'nullable|string|in:premiere,reinscription,transfert',
            'cin' => 'nullable|string|max:30',
            'date_naissance' => 'nullable|date',
        ]);

        $type = $valide['type'] ?? 'premiere';

        if (! empty($valide['cin']) && ! empty($valide['date_naissance'])) {
            $etudiant = Etudiant::where(
                'cin',
                MensReference::normaliserCin($valide['cin'])
            )->whereDate('date_naissance', $valide['date_naissance'])->first();

            if ($etudiant !== null) {
                return response()->json([
                    'success' => true,
                    'type' => $type,
                    'dossier' => $documentsRequis->checklistDossier($etudiant, $type),
                    'catalogue' => $documentsRequis->catalogue(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'type' => $type,
            'pieces' => $documentsRequis->checklistPourType($type),
            'catalogue' => $documentsRequis->catalogue(),
        ]);
    }
}
