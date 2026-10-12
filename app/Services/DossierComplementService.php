<?php

namespace App\Services;

use App\Models\DossierCorrection;
use App\Models\Etudiant;
use App\Models\HistoriqueAcademique;
use App\Models\User;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Modules\StudentDossiers\Services\StudentDocumentService;
use App\Services\Verification\VerificationEngine;
use App\Services\Verification\VerificationResult;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Service orchestrant les demandes de complément et les corrections du dossier (LOOP 11).
 *
 * Principes et règles de sécurité :
 * 1. Habilitation agent : seuls les rôles habilités (scolarité/admin) peuvent initier une demande de complément.
 * 2. Cloisonnement : l'étudiant est strictement restreint à son propre dossier (anti-IDOR).
 * 3. Périmètre strict : seules les sections ou pièces demandées sont modifiables par l'étudiant.
 * 4. Immuabilité post-décision : si le statut est 'valide' ou 'rejete', toute modification
 *    en ligne est strictement interdite (403/DomainException) sans réouverture officielle préalable.
 * 5. Conservation et versions : les anciens documents restent historisés (StudentDocumentVersion).
 * 6. Revalidation systématique : re-vérification complète via VerificationEngine à la resoumission.
 * 7. Notifications idempotentes : alertes in-app avec clé de déduplication.
 */
class DossierComplementService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly StudentDocumentService $documentService,
        private readonly VerificationEngine $engine,
    ) {}

    /**
     * Permet à un agent scolarité d'indiquer les champs et pièces à corriger avec motif.
     *
     * @param  array<int, array{champ: string, motif: string, nouvelle_valeur?: ?string}>  $champs
     * @param  array<int, array{document_id: int, motif: string}>  $pieces
     * @return array{champs_demandes: int, pieces_demandees: int}
     *
     * @throws DomainException
     */
    public function demanderComplement(
        Etudiant $etudiant,
        User $agent,
        array $champs = [],
        array $pieces = [],
        ?string $messageGeneral = null
    ): array {
        // Habilitation : seul le personnel scolarité autorisé peut demander des compléments
        abort_unless(
            Gate::forUser($agent)->allows('update', $etudiant)
            || (method_exists($agent, 'hasRole') && ($agent->hasRole('admin_scolarite') || $agent->hasRole('super_admin'))),
            403,
            'Action non autorisée : permissions insuffisantes pour demander un complément.'
        );

        // Si le dossier a fait l'objet d'une décision définitive, la demande de complément
        // exige que le statut soit préalablement réouvert officiellement en 'en_attente'.
        if (in_array($etudiant->statut, ['valide', 'rejete'], true)) {
            throw new DomainException(
                "Le dossier est actuellement « {$etudiant->statut} ». Une réouverture officielle préalable en « en_attente » est requise."
            );
        }

        if (empty($champs) && empty($pieces)) {
            throw new DomainException('Au moins un champ ou une pièce doit être désigné(e) pour demander un complément.');
        }

        $champsTraites = 0;
        $piecesTraitees = 0;

        DB::transaction(function () use ($etudiant, $agent, $champs, $pieces, $messageGeneral, &$champsTraites, &$piecesTraitees) {
            // 1. Enregistrement des champs à corriger (DossierCorrection statut proposee)
            foreach ($champs as $item) {
                $champ = trim((string) ($item['champ'] ?? ''));
                $motif = trim((string) ($item['motif'] ?? ''));

                if (! in_array($champ, DossierCorrection::CHAMPS, true)) {
                    continue;
                }

                if ($motif === '') {
                    $motif = 'Information à corriger ou compléter.';
                }

                DossierCorrection::updateOrCreate(
                    [
                        'etudiant_id' => $etudiant->id,
                        'champ' => $champ,
                        'statut' => 'proposee',
                    ],
                    [
                        'ancienne_valeur' => $etudiant->{$champ} !== null ? (string) $etudiant->{$champ} : null,
                        'nouvelle_valeur' => $item['nouvelle_valeur'] ?? null,
                        'motif' => $motif,
                        'propose_par' => $agent->id,
                    ]
                );

                $champsTraites++;
            }

            // 2. Rejet des pièces documentaires à remplacer (StudentDocument status rejected)
            $dossierGed = StudentDossier::where('etudiant_id', $etudiant->id)->first();
            if ($dossierGed !== null) {
                foreach ($pieces as $p) {
                    $docId = (int) ($p['document_id'] ?? 0);
                    $motif = trim((string) ($p['motif'] ?? 'Document non conforme ou illisible.'));

                    /** @var StudentDocument|null $doc */
                    $doc = StudentDocument::where('student_dossier_id', $dossierGed->id)
                        ->where('id', $docId)
                        ->first();

                    if ($doc !== null) {
                        $this->documentService->reject($doc, $motif, (int) $agent->id);
                        $piecesTraitees++;
                    }
                }
            }

            // 3. Traçabilité dans l'audit académique (sans PII)
            $this->audit->tracer(
                $etudiant,
                HistoriqueAcademique::ACTION_CORRECTION_PROPOSEE,
                'etudiant',
                $etudiant->id,
                null,
                [
                    'champs' => array_column($champs, 'champ'),
                    'pieces_count' => $piecesTraitees,
                    'message' => $messageGeneral,
                ]
            );
        });

        // 4. Notification in-app à l'étudiant avec lien direct et clé de déduplication idempotente
        try {
            $totalDemandes = $champsTraites + $piecesTraitees;
            $texteNotification = "Le service de scolarité a requis {$totalDemandes} complément(s) sur votre dossier d'inscription. Veuillez vérifier les éléments signalés et soumettre vos rectifications.";
            if ($messageGeneral !== null && trim($messageGeneral) !== '') {
                $texteNotification .= ' Consigne : '.trim($messageGeneral);
            }

            $this->notifications->notifierEtudiant(
                $etudiant,
                'dossier.complement_requis',
                'Complément requis pour votre dossier d\'inscription',
                $texteNotification,
                route('etudiant.corrections.index'),
                [
                    'etudiant_id' => $etudiant->id,
                    'champs_count' => $champsTraites,
                    'pieces_count' => $piecesTraitees,
                ],
                1,
                'dossier.complement:'.$etudiant->id.':'.now()->format('YmdH')
            );
        } catch (\Throwable) {
            // Fail-soft pour la notification : la transaction reste validée
        }

        return [
            'champs_demandes' => $champsTraites,
            'pieces_demandees' => $piecesTraitees,
        ];
    }

    /**
     * Récupère l'ensemble des éléments que l'étudiant est autorisé à corriger.
     *
     * @return array<string, mixed>
     */
    public function recupererElementsACorriger(Etudiant $etudiant): array
    {
        $estDefinitif = in_array($etudiant->statut, ['valide', 'rejete'], true);
        $estModifiable = ! $estDefinitif && $etudiant->statut === 'en_attente';

        $messageStatut = null;
        if ($etudiant->statut === 'valide') {
            $messageStatut = 'Votre inscription est déjà validée définitivement. Aucune modification en ligne n\'est autorisée.';
        } elseif ($etudiant->statut === 'rejete') {
            $messageStatut = 'Votre dossier a fait l\'objet d\'un rejet définitif. Aucune modification en ligne n\'est autorisée sans réouverture officielle par la scolarité.';
        }

        // Champs avec proposition active
        $champs = DossierCorrection::where('etudiant_id', $etudiant->id)
            ->where('statut', 'proposee')
            ->orderBy('id')
            ->get()
            ->map(fn (DossierCorrection $c) => [
                'id' => $c->id,
                'champ' => $c->champ,
                'champ_libelle' => $this->libelleChamp($c->champ),
                'ancienne_valeur' => $c->ancienne_valeur,
                'valeur_actuelle' => $etudiant->{$c->champ},
                'suggestion' => $c->nouvelle_valeur,
                'motif' => $c->motif,
                'date_demande' => $c->created_at?->format('d/m/Y'),
            ])
            ->values()
            ->all();

        // Pièces rejetées dans le dossier GED
        $pieces = [];
        $dossierGed = StudentDossier::where('etudiant_id', $etudiant->id)->first();
        if ($dossierGed !== null) {
            $pieces = StudentDocument::where('student_dossier_id', $dossierGed->id)
                ->where('status', 'rejected')
                ->orderBy('id')
                ->get()
                ->map(fn (StudentDocument $doc) => [
                    'id' => $doc->id,
                    'title' => $doc->title,
                    'document_type' => $doc->document_type,
                    'category' => $doc->category,
                    'original_filename' => $doc->original_filename,
                    'motif' => $doc->rejected_reason ?? 'Document non conforme ou illisible.',
                    'date_rejet' => $doc->updated_at?->format('d/m/Y'),
                ])
                ->values()
                ->all();
        }

        $total = count($champs) + count($pieces);

        return [
            'statut_dossier' => $etudiant->statut,
            'est_modifiable' => $estModifiable,
            'message_statut' => $messageStatut,
            'champs' => $champs,
            'pieces' => $pieces,
            'total_elements' => $total,
            'a_des_demandes' => $total > 0,
        ];
    }

    /**
     * Soumet les corrections fournies par l'étudiant connecté (session-scoped).
     *
     * @param  array<string, mixed>  $champsModifies
     * @param  array<string|int, UploadedFile>  $fichiersRemplacement
     * @return array{success: bool, verdict: string, score: int, champs_mis_a_jour: int, pieces_remplacees: int}
     *
     * @throws DomainException|ValidationException
     */
    public function soumettreCorrection(
        Etudiant $etudiant,
        array $champsModifies = [],
        array $fichiersRemplacement = []
    ): array {
        // 1. Guard critique : interdiction de modification post-décision définitive
        if (in_array($etudiant->statut, ['valide', 'rejete'], true)) {
            throw new DomainException(
                "Le dossier a fait l'objet d'une décision définitive ({$etudiant->statut}). Toute modification en ligne est interdite."
            );
        }

        if ($etudiant->statut !== 'en_attente') {
            throw new DomainException('Modification non autorisée dans l\'état actuel du dossier.');
        }

        // 2. Périmètre strict : seules les sections ou pièces demandées sont modifiables
        /** @var Collection<int, DossierCorrection> $correctionsActives */
        $correctionsActives = DossierCorrection::where('etudiant_id', $etudiant->id)
            ->where('statut', 'proposee')
            ->get()
            ->keyBy('champ');

        $dossierGed = StudentDossier::where('etudiant_id', $etudiant->id)->first();
        $piecesRejetees = collect();
        if ($dossierGed !== null) {
            $piecesRejetees = StudentDocument::where('student_dossier_id', $dossierGed->id)
                ->where('status', 'rejected')
                ->get()
                ->keyBy('id');
        }

        if ($correctionsActives->isEmpty() && $piecesRejetees->isEmpty()) {
            throw new DomainException('Aucun complément n\'est actuellement attendu sur votre dossier.');
        }

        // Filtrer les champs soumis pour ne retenir STRICTEMENT que ceux autorisés
        $champsRetenus = [];
        foreach ($champsModifies as $champ => $valeur) {
            if ($correctionsActives->has($champ)) {
                $champsRetenus[$champ] = is_string($valeur) ? trim($valeur) : $valeur;
            }
        }

        // Filtrer les pièces soumises pour ne retenir STRICTEMENT que les documents rejetés
        $piecesRetenues = [];
        foreach ($fichiersRemplacement as $docId => $fichier) {
            $idInt = (int) $docId;
            if ($piecesRejetees->has($idInt) && $fichier instanceof UploadedFile) {
                $piecesRetenues[$idInt] = $fichier;
            }
        }

        if (empty($champsRetenus) && empty($piecesRetenues)) {
            throw new DomainException('Aucune correction valide n\'a été fournie pour les éléments signalés.');
        }

        // 3. Revalidation stricte des entrées soumises
        $reglesValidation = [
            'nom' => ['sometimes', 'required', 'string', 'max:100'],
            'prenom' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => ['sometimes', 'required', 'email:rfc,dns', 'max:255'],
            'telephone' => ['sometimes', 'required', 'string', 'regex:/^[0-9+\s\-]{6,25}$/'],
            'cin' => ['sometimes', 'required', 'string', 'max:30'],
            'date_naissance' => ['sometimes', 'required', 'date', 'before:today'],
            'sexe' => ['sometimes', 'required', 'in:M,F'],
            'adresse' => ['sometimes', 'required', 'string', 'max:255'],
        ];

        $reglesActives = [];
        foreach ($champsRetenus as $k => $v) {
            if (isset($reglesValidation[$k])) {
                $reglesActives["champs.{$k}"] = $reglesValidation[$k];
            }
        }

        if (! empty($reglesActives)) {
            $validateur = Validator::make(['champs' => $champsRetenus], $reglesActives, [
                'champs.date_naissance.before' => 'La date de naissance doit être antérieure à aujourd\'hui.',
                'champs.telephone.regex' => 'Le format du numéro de téléphone est invalide.',
                'champs.sexe.in' => 'Le sexe doit être M ou F.',
            ]);

            if ($validateur->fails()) {
                throw new ValidationException($validateur);
            }
        }

        // Revalidation des fichiers de remplacement
        foreach ($piecesRetenues as $docId => $fichier) {
            $validateurFichier = Validator::make(['fichier' => $fichier], [
                'fichier' => ['required', 'file', 'max:5120', 'mimes:pdf,jpeg,png,jpg'],
            ], [
                'fichier.max' => 'La taille du document ne peut excéder 5 Mo.',
                'fichier.mimes' => 'Format de fichier non accepté (PDF, JPEG ou PNG uniquement).',
            ]);

            if ($validateurFichier->fails()) {
                throw new ValidationException($validateurFichier);
            }
        }

        // 4. Écriture transactionnelle : application, mise à jour des versions et trace audit
        $nbChampsMaj = 0;
        $nbPiecesRemplacees = 0;

        DB::transaction(function () use (
            $etudiant,
            $champsRetenus,
            $piecesRetenues,
            $correctionsActives,
            $piecesRejetees,
            &$nbChampsMaj,
            &$nbPiecesRemplacees
        ) {
            // Mise à jour des champs sur le modèle étudiant
            if (! empty($champsRetenus)) {
                $anciennesValeurs = [];
                foreach ($champsRetenus as $champ => $valeur) {
                    $anciennesValeurs[$champ] = $etudiant->{$champ};
                    $etudiant->{$champ} = $valeur;

                    // Clôture de la proposition correspondante
                    /** @var DossierCorrection $proposition */
                    $proposition = $correctionsActives->get($champ);
                    if ($proposition !== null) {
                        $proposition->update([
                            'statut' => 'appliquee',
                            'nouvelle_valeur' => (string) $valeur,
                            'appliquee_le' => now(),
                            'motif_decision' => 'Corrigé par l\'étudiant suite à demande de complément.',
                        ]);
                    }
                }

                $etudiant->save();
                $nbChampsMaj = count($champsRetenus);

                $this->audit->tracer(
                    $etudiant,
                    HistoriqueAcademique::ACTION_CORRECTION_DOSSIER,
                    'etudiant',
                    $etudiant->id,
                    $anciennesValeurs,
                    $champsRetenus
                );
            }

            // Remplacement des pièces documentaires via StudentDocumentService
            foreach ($piecesRetenues as $docId => $fichier) {
                /** @var StudentDocument $doc */
                $doc = $piecesRejetees->get($docId);
                if ($doc !== null) {
                    // replace() incrémente la version (StudentDocumentVersion), conserve les
                    // fichiers antérieurs et repasse le statut en 'pending'.
                    $this->documentService->replace($doc, $fichier, null);
                    $nbPiecesRemplacees++;
                }
            }
        });

        // 5. Revalidation immédiate par le moteur de vérification (§15-16)
        /** @var VerificationResult $resultat */
        $resultat = $this->engine->verifier($etudiant->fresh());

        // 6. Notification in-app confirmant la bonne réception des corrections
        try {
            $this->notifications->notifierEtudiant(
                $etudiant,
                'dossier.correction_soumise',
                'Compléments transmis avec succès',
                'Vos corrections ont bien été enregistrées. Votre dossier a été ré-analysé et sera examiné par le service de scolarité.',
                route('etudiant.suivi'),
                [
                    'score_apres' => $resultat->score,
                    'verdict_apres' => $resultat->verdict,
                    'champs_mis_a_jour' => $nbChampsMaj,
                    'pieces_remplacees' => $nbPiecesRemplacees,
                ],
                1,
                'reponse_complement:'.$etudiant->id.':'.now()->format('YmdHi')
            );
        } catch (\Throwable) {
            // Fail-soft pour la notification
        }

        return [
            'success' => true,
            'verdict' => $resultat->verdict,
            'score' => $resultat->score,
            'champs_mis_a_jour' => $nbChampsMaj,
            'pieces_remplacees' => $nbPiecesRemplacees,
        ];
    }

    /**
     * Libellé humain compréhensible d'un champ pour l'interface étudiant.
     */
    private function libelleChamp(string $champ): string
    {
        return match ($champ) {
            'nom' => 'Nom de famille',
            'prenom' => 'Prénom',
            'email' => 'Adresse email',
            'telephone' => 'Numéro de téléphone',
            'cin' => 'Numéro de CIN ou Passeport',
            'date_naissance' => 'Date de naissance',
            'sexe' => 'Genre / Sexe',
            'adresse' => 'Adresse postale',
            'code_postal' => 'Code postal',
            'ville' => 'Ville',
            default => ucfirst(str_replace('_', ' ', $champ)),
        };
    }
}
