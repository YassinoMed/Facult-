<?php

namespace App\Modules\StudentDossiers\Services;

use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Modules\StudentDossiers\Models\InscriptionDocument;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDocumentVersion;
use App\Modules\StudentDossiers\Models\StudentDossier;
use App\Services\AuditService;
use App\Services\DocumentsRequisService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * LOOP-UNIREGISTRE-DOCUMENTS-REUTILISABLES-01 : réutilisation annuelle
 * des pièces du dossier permanent.
 *
 * Règles :
 *  - le fichier physique n'est jamais copié ni re-téléversé ;
 *  - seules les pièces VALIDÉES, non expirées, physiquement présentes et
 *    conformes au format exigé sont réutilisées ;
 *  - un document simplement téléversé (`pending`) n'est JAMAIS validé ;
 *  - chaque année conserve sa décision et l'instantané de la version
 *    retenue (les années passées ne sont pas réécrites) ;
 *  - la synchronisation est idempotente (clé unique inscription + type).
 */
final class ReutilisationDocumentsService
{
    public function __construct(
        private readonly DocumentStorageService $stockage,
        private readonly StudentDossierService $dossiers,
        private readonly DocumentsRequisService $documentsRequis,
        private readonly AuditService $audit,
    ) {}

    /**
     * Associe les pièces permanentes à une inscription annuelle et
     * enregistre une décision par pièce exigée.
     *
     * @return array<string, mixed>
     */
    public function synchroniser(InscriptionAcademique $inscription, ?int $auteurId = null): array
    {
        // Une année clôturée est un historique figé : même un appel direct
        // ne doit jamais réécrire ses décisions. Le statut peut être absent
        // de l'instance juste après `create()` (valeur par défaut en base),
        // d'où la lecture ciblée uniquement dans ce cas.
        $statut = $inscription->statut;

        if ($statut === null && $inscription->exists) {
            $statut = InscriptionAcademique::whereKey($inscription->getKey())->value('statut');
        }

        if ($statut !== null && $statut !== 'en_cours') {
            return $this->etatInscription($inscription);
        }

        $inscription->loadMissing(['etudiant', 'anneeUniversitaire']);

        $dossier = $this->dossiers->obtenirOuCreer($inscription->etudiant);
        $pieces = $this->documentsRequis->checklistPourType($this->typeInscription($inscription));
        $documents = $this->documentsRequis->piecesDossier($inscription->etudiant, true);
        $existantes = InscriptionDocument::where('inscription_academique_id', $inscription->id)
            ->get()
            ->keyBy('document_type')
            ->all();
        $evaluations = $this->evaluerPieces($documents, $pieces, $dossier, $existantes);

        DB::transaction(function () use ($inscription, $evaluations, $auteurId): void {
            foreach ($evaluations as $evaluation) {
                $this->persisterDecision($inscription, $evaluation, $auteurId);
            }
        });

        return $this->construireBilan($evaluations);
    }

    /**
     * Prévisualisation SANS écriture : utilisée avant la création de
     * l'inscription (écran de réinscription, API de contexte).
     *
     * @return array<string, mixed>
     */
    public function previsualiser(Etudiant $etudiant, ?string $type = null): array
    {
        $typeCible = $this->normaliserType($type);
        $pieces = $this->documentsRequis->checklistPourType($typeCible);
        $dossier = StudentDossier::where('etudiant_id', $etudiant->id)->first();
        $documents = $dossier === null
            ? collect()
            : $this->documentsRequis->piecesDossier($etudiant, true);

        $evaluations = $this->evaluerPieces(
            $documents,
            $pieces,
            $dossier,
            [], // aucune inscription : tout document admissible est réutilisable
        );

        return $this->construireBilan($evaluations);
    }

    /**
     * État documentaire LIVE d'une inscription, SANS écriture : utilisé
     * pour l'affichage (aucun effet de bord sur un GET). Les associations
     * persistées restent la trace des décisions prises par les événements.
     *
     * @return array<string, mixed>
     */
    public function etatInscription(InscriptionAcademique $inscription): array
    {
        $inscription->loadMissing(['etudiant', 'anneeUniversitaire']);

        $dossier = StudentDossier::where('etudiant_id', $inscription->etudiant_id)->first();
        $pieces = $this->documentsRequis->checklistPourType($this->typeInscription($inscription));
        $documents = $dossier === null
            ? collect()
            : $this->documentsRequis->piecesDossier($inscription->etudiant, true);
        $existantes = InscriptionDocument::where('inscription_academique_id', $inscription->id)
            ->get()
            ->keyBy('document_type')
            ->all();

        return $this->construireBilan(
            $this->evaluerPieces($documents, $pieces, $dossier, $existantes)
        );
    }

    /**
     * Dernière inscription de l'étudiant encore ouverte. Les années
     * clôturées (validées/ajournées/abandonnées) ne sont JAMAIS réécrites.
     */
    public function derniereInscriptionActive(Etudiant $etudiant): ?InscriptionAcademique
    {
        return $etudiant->inscriptionsAcademiques()
            ->where('statut', 'en_cours')
            ->orderByDesc('annee_universitaire_id')
            ->first();
    }

    /**
     * Synchronise la dernière inscription ouverte (point d'entrée des
     * événements documentaires). Fail-soft : jamais d'exception au
     * téléversement/validation.
     *
     * @return array<string, mixed>|null
     */
    public function synchroniserDerniereInscriptionActive(Etudiant|int $etudiant): ?array
    {
        $modele = $etudiant instanceof Etudiant ? $etudiant : Etudiant::find($etudiant);

        if ($modele === null) {
            return null;
        }

        $inscription = $this->derniereInscriptionActive($modele);

        if ($inscription === null) {
            return null;
        }

        return $this->synchroniser($inscription, auth()->id());
    }

    /**
     * Évaluation pure (aucune écriture) d'une série de pièces.
     *
     * @param  Collection<string, StudentDocument>  $documents
     * @param  array<int, array<string, mixed>>  $pieces
     * @param  array<string, InscriptionDocument>  $associationsExistantes
     * @return array<int, array{piece: array<string, mixed>, document: ?StudentDocument, resultat: array<string, mixed>}>
     */
    private function evaluerPieces(
        Collection $documents,
        array $pieces,
        ?StudentDossier $dossier,
        array $associationsExistantes = [],
    ): array {
        $evaluations = [];

        foreach ($pieces as $piece) {
            $code = (string) $piece['code'];
            /** @var StudentDocument|null $document */
            $document = $documents->get($code);
            /** @var InscriptionDocument|null $existante */
            $existante = $associationsExistantes[$code] ?? null;

            if ($document === null) {
                $resultat = [
                    'decision' => 'manquant',
                    'motif' => null,
                    'version' => null,
                    'snapshot' => null,
                ];
            } else {
                $resultat = $this->evaluerDocument($document, $piece, $dossier, $existante);
            }

            $evaluations[] = [
                'piece' => $piece,
                'document' => $document,
                'resultat' => $resultat,
            ];
        }

        return $evaluations;
    }

    /**
     * @param  array<string, mixed>  $piece
     * @return array{decision: string, motif: ?string, version: ?StudentDocumentVersion, snapshot: ?array<string, mixed>}
     */
    private function evaluerDocument(
        StudentDocument $document,
        array $piece,
        ?StudentDossier $dossier,
        ?InscriptionDocument $existante,
    ): array {
        $version = $document->versions->sortByDesc('version')->first();
        $snapshot = $this->snapshot($document, $version);

        if ($dossier === null || (int) $document->student_dossier_id !== (int) $dossier->id) {
            return $this->resultat('non_conforme', 'Document rattaché à un autre dossier étudiant.', $version, $snapshot);
        }

        // Ne jamais considérer un téléversement comme une validation.
        if ($document->status === 'pending') {
            return $this->resultat('en_attente', 'Téléversé, en attente de contrôle par la scolarité.', $version, $snapshot);
        }

        if ($document->status === 'rejected') {
            return $this->resultat('rejete', $document->rejected_reason ?: 'Refusé par la scolarité.', $version, $snapshot);
        }

        if ($document->status === 'expired') {
            return $this->resultat('expire', 'Document marqué expiré.', $version, $snapshot);
        }

        if ($document->status === 'archived') {
            return $this->resultat('non_conforme', 'Document archivé, non réutilisable pour une nouvelle inscription.', $version, $snapshot);
        }

        if ($document->status !== 'validated') {
            return $this->resultat('non_conforme', 'Statut documentaire non reconnu.', $version, $snapshot);
        }

        if ($document->validated_at === null) {
            return $this->resultat('non_conforme', 'Validation non tracée (date d’approbation absente).', $version, $snapshot);
        }

        if ($document->expires_at !== null && $document->expires_at->isPast()) {
            return $this->resultat('expire', 'Date de validité dépassée.', $version, $snapshot);
        }

        // Lisibilité réelle : le fichier privé doit exister et correspondre
        // exactement aux métadonnées validées (aucune confiance à la seule BD).
        $tailleReelle = $this->stockage->taille((string) $document->storage_path);

        if ($tailleReelle === null) {
            return $this->resultat('non_conforme', 'Fichier privé introuvable ou illisible.', $version, $snapshot);
        }

        if ($tailleReelle <= 0 || (int) $document->size <= 0) {
            return $this->resultat('non_conforme', 'Fichier vide.', $version, $snapshot);
        }

        if ($tailleReelle !== (int) $document->size) {
            return $this->resultat('non_conforme', 'Taille du fichier incohérente avec les métadonnées.', $version, $snapshot);
        }

        // Intégrité : le contenu doit correspondre à l'empreinte validée.
        // Une taille identique ne prouve pas que le fichier est inchangé.
        if ($document->sha256 !== null && $document->sha256 !== '') {
            $empreinteReelle = $this->stockage->empreinte((string) $document->storage_path);

            if ($empreinteReelle === null || ! hash_equals((string) $document->sha256, $empreinteReelle)) {
                return $this->resultat('non_conforme', 'Empreinte du fichier incohérente avec les métadonnées.', $version, $snapshot);
            }
        }

        $extension = mb_strtolower((string) $document->extension);
        $formats = array_map(
            static fn (mixed $format): string => mb_strtolower((string) $format),
            $piece['formats'] ?? []
        );

        if ($formats !== [] && ! in_array($extension, $formats, true)) {
            return $this->resultat('non_conforme', "Format .{$extension} non conforme à l’exigence.", $version, $snapshot);
        }

        /** @var array<int, string> $mimes */
        $mimes = config("student-dossiers.mimes.{$extension}", []);

        if ($mimes !== [] && ! in_array((string) $document->mime_type, $mimes, true)) {
            return $this->resultat('non_conforme', 'Type MIME incohérent avec le format du fichier.', $version, $snapshot);
        }

        // Décision de l'année : une année clôturée n'est jamais réécrite
        // (les événements ne ciblent que l'inscription ouverte). Pour
        // l'année courante, on conserve `reutilise` si la version n'a pas
        // changé ; tout nouveau fichier (remplacement, pièce fournie après
        // l'inscription) est `associe`, jamais présenté comme réutilisé.
        if ($existante !== null) {
            $memeVersion = (int) $existante->student_document_id === (int) $document->id
                && (int) $existante->student_document_version_id === (int) ($version?->id ?? 0);

            if ($memeVersion && in_array($existante->decision, InscriptionDocument::DECISIONS_VALIDES, true)) {
                return $this->resultat(
                    $existante->decision,
                    $existante->decision === 'reutilise'
                        ? 'Document validé antérieur à l’inscription : réutilisé sans nouveau téléversement.'
                        : 'Document validé rattaché à cette inscription.',
                    $version,
                    $snapshot,
                );
            }

            return $this->resultat(
                'associe',
                'Document validé rattaché à cette inscription.',
                $version,
                $snapshot,
            );
        }

        return $this->resultat(
            'reutilise',
            'Document validé antérieur à l’inscription : réutilisé sans nouveau téléversement.',
            $version,
            $snapshot,
        );
    }

    /**
     * @param  array{piece: array<string, mixed>, document: ?StudentDocument, resultat: array<string, mixed>}  $evaluation
     */
    private function persisterDecision(
        InscriptionAcademique $inscription,
        array $evaluation,
        ?int $auteurId,
    ): void {
        $piece = $evaluation['piece'];
        $document = $evaluation['document'];
        $resultat = $evaluation['resultat'];
        $code = (string) $piece['code'];

        $ligne = InscriptionDocument::firstOrNew([
            'inscription_academique_id' => $inscription->id,
            'document_type' => $code,
        ]);

        $estNouvelle = ! $ligne->exists;
        $ancienneDecision = $ligne->decision;
        $ancienDocument = $ligne->student_document_id;
        $ancienneVersion = $ligne->student_document_version_id;

        $ligne->fill([
            'student_document_id' => $document?->id,
            'student_document_version_id' => $resultat['version']?->id,
            'obligatoire' => (bool) ($piece['obligatoire'] ?? true),
            'decision' => (string) $resultat['decision'],
            'motif' => $resultat['motif'],
            'snapshot' => $resultat['snapshot'],
            'decide_par' => $auteurId ?? $ligne->decide_par,
            'decide_le' => now(),
        ]);

        $change = $estNouvelle || $ligne->isDirty([
            'student_document_id',
            'student_document_version_id',
            'obligatoire',
            'decision',
            'motif',
            'snapshot',
        ]);

        if (! $change) {
            return;
        }

        try {
            $ligne->save();
        } catch (UniqueConstraintViolationException) {
            // Course entre deux synchronisations : la contrainte unique a
            // arbitré, on reprend la ligne gagnante.
            $ligne = InscriptionDocument::where('inscription_academique_id', $inscription->id)
                ->where('document_type', $code)
                ->firstOrFail();
            $ligne->fill([
                'student_document_id' => $document?->id,
                'student_document_version_id' => $resultat['version']?->id,
                'obligatoire' => (bool) ($piece['obligatoire'] ?? true),
                'decision' => (string) $resultat['decision'],
                'motif' => $resultat['motif'],
                'snapshot' => $resultat['snapshot'],
                'decide_par' => $auteurId ?? $ligne->decide_par,
                'decide_le' => now(),
            ])->save();
        }

        $decision = (string) $resultat['decision'];
        $decisionChangee = $estNouvelle
            || $ancienneDecision !== $decision
            || (int) $ancienDocument !== (int) ($document?->id)
            || (int) $ancienneVersion !== (int) ($resultat['version']?->id);

        if ($decisionChangee) {
            $this->tracer(
                $inscription,
                $ligne,
                $code,
                $decision,
                $estNouvelle ? null : $ancienneDecision,
                $auteurId,
            );
        }
    }

    /**
     * @param  array<int, array{piece: array<string, mixed>, document: ?StudentDocument, resultat: array<string, mixed>}>  $evaluations
     * @return array<string, mixed>
     */
    private function construireBilan(array $evaluations): array
    {
        $pieces = [];
        $reutilises = [];
        $associes = [];
        $aFournir = [];
        $enAttente = [];
        $documentsReutilisables = [];
        $obligatoiresTotal = 0;
        $obligatoiresValides = 0;

        foreach ($evaluations as $evaluation) {
            $piece = $evaluation['piece'];
            $document = $evaluation['document'];
            $resultat = $evaluation['resultat'];
            $code = (string) $piece['code'];
            $decision = (string) $resultat['decision'];
            $obligatoire = (bool) ($piece['obligatoire'] ?? false);

            if ($obligatoire) {
                $obligatoiresTotal++;
            }

            if (in_array($decision, InscriptionDocument::DECISIONS_VALIDES, true)) {
                if ($obligatoire) {
                    $obligatoiresValides++;
                }

                if ($decision === 'reutilise') {
                    $reutilises[] = $code;

                    if ($document !== null) {
                        $documentsReutilisables[$code] = $document;
                    }
                } else {
                    $associes[] = $code;
                }
            } elseif ($obligatoire && $decision === 'en_attente') {
                $enAttente[] = $code;
            } elseif ($obligatoire && in_array($decision, InscriptionDocument::DECISIONS_A_FOURNIR, true)) {
                $aFournir[] = $code;
            }

            $pieces[] = array_merge($piece, [
                'decision' => $decision,
                'decision_libelle' => $this->libelleDecision($decision),
                'motif' => $resultat['motif'],
                'document_id' => $document?->id,
                'version_id' => $resultat['version']?->id,
                'snapshot' => $resultat['snapshot'],
            ]);
        }

        $complet = $obligatoiresTotal === 0 || ($obligatoiresValides === $obligatoiresTotal);

        return [
            'complet' => $complet,
            'peut_soumettre' => $complet,
            'obligatoires_total' => $obligatoiresTotal,
            'obligatoires_valides' => $obligatoiresValides,
            'progression' => "{$obligatoiresValides} / {$obligatoiresTotal} obligatoires validés",
            'reutilises' => $reutilises,
            'associes' => $associes,
            'valides' => array_values(array_unique(array_merge($reutilises, $associes))),
            'a_fournir' => $aFournir,
            'en_attente' => $enAttente,
            'documents_reutilisables' => collect($documentsReutilisables)->values(),
            'pieces' => $pieces,
        ];
    }

    /**
     * @return array{decision: string, motif: ?string, version: ?StudentDocumentVersion, snapshot: ?array<string, mixed>}
     */
    private function resultat(
        string $decision,
        ?string $motif,
        ?StudentDocumentVersion $version,
        ?array $snapshot,
    ): array {
        return [
            'decision' => $decision,
            'motif' => $motif,
            'version' => $version,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(StudentDocument $document, ?StudentDocumentVersion $version): array
    {
        return [
            'version' => $version?->version,
            'validated_at' => $document->validated_at?->toIso8601String(),
            'expires_at' => $document->expires_at?->toIso8601String(),
            'mime_type' => $document->mime_type,
            'extension' => $document->extension,
            'size' => (int) $document->size,
            'sha256' => $document->sha256,
        ];
    }

    private function typeInscription(InscriptionAcademique $inscription): string
    {
        return $this->normaliserType($inscription->type);
    }

    private function normaliserType(?string $type): string
    {
        return in_array((string) $type, ['premiere', 'reinscription', 'transfert'], true)
            ? (string) $type
            : 'premiere';
    }

    private function libelleDecision(string $decision): string
    {
        return match ($decision) {
            'reutilise' => 'Réutilisé automatiquement',
            'associe' => 'Validé pour cette inscription',
            'en_attente' => 'En attente de contrôle',
            'rejete' => 'Refusé — à remplacer',
            'expire' => 'Expiré — à renouveler',
            'non_conforme' => 'Non conforme — à remplacer',
            default => 'Manquant — à fournir',
        };
    }

    private function tracer(
        InscriptionAcademique $inscription,
        InscriptionDocument $ligne,
        string $code,
        string $decision,
        ?string $ancienneDecision,
        ?int $auteurId,
    ): void {
        $action = match ($decision) {
            'reutilise' => 'inscription.document_reutilise',
            'associe' => 'inscription.document_associe',
            default => 'inscription.document_decision',
        };

        $this->audit->tracer(
            $inscription->etudiant,
            $action,
            'inscription_document',
            $ligne->id,
            $ancienneDecision !== null ? ['decision' => $ancienneDecision] : null,
            [
                'document_type' => $code,
                'decision' => $decision,
                'annee_universitaire_id' => (int) $inscription->annee_universitaire_id,
            ],
            $auteurId === null ? 'systeme' : 'web',
        );
    }
}
