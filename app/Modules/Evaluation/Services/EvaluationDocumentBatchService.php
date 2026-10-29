<?php

namespace App\Modules\Evaluation\Services;

use App\Models\ImportReport;
use App\Models\IntegrationLog;
use App\Models\User;
use App\Modules\Evaluation\Models\Deliberation;
use App\Modules\Evaluation\Models\DocumentExamenGenere;
use App\Modules\Evaluation\Models\Epreuve;
use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use ZipArchive;

/**
 * Service de génération documentaire groupée (LOT 5 - LOOP-UNIREGISTRE-NOTES-EVALUATIONS-ADVANCED-02)
 *
 * Responsabilités :
 * - Prévisualisation précise des volumes et contrôles d'autorisations
 * - Génération unitaire ou par lot (émargements, feuilles de saisie des notes, PVs de délibération)
 * - Contrôle d'immuabilité et vérification stricte de publication pour les PVs
 * - Assemblage en archive ZIP sécurisée dans un stockage privé temporaire avec TTL
 * - Téléchargement sous contrôle RBAC et périmètre faculté strict (anti-IDOR)
 * - Traçabilité centralisée (ImportReport, DocumentExamenGenere, IntegrationLog)
 */
class EvaluationDocumentBatchService
{
    public const TYPES_DOCUMENTS = [
        'emargement' => 'Listes d\'émargement',
        'feuille_notes' => 'Feuilles de saisie des notes',
        'pv_deliberation' => 'Procès-verbaux de délibération',
    ];

    public function __construct(
        private readonly ConvocationService $convocationService,
        private readonly PvDeliberationService $pvDeliberationService,
        private readonly PdfGeneratorInterface $pdfGenerator,
    ) {}

    /**
     * Prévisualiser le périmètre documentaire avant toute génération
     *
     * @param array{
     *     session_id?: int,
     *     groupe_id?: int,
     *     epreuve_id?: int,
     *     deliberation_id?: int,
     *     types: array<string>
     * } $criteres
     * @return array{
     *     nombre_epreuves: int,
     *     nombre_deliberations: int,
     *     nombre_candidats: int,
     *     nombre_documents_estimes: int,
     *     avertissements: array<string>,
     *     epreuves: Collection,
     *     deliberations: Collection
     * }
     */
    public function previsualiser(array $criteres, User $utilisateur): array
    {
        $types = array_intersect($criteres['types'] ?? [], array_keys(self::TYPES_DOCUMENTS));
        if (empty($types)) {
            $types = ['emargement', 'feuille_notes'];
        }

        $epreuves = collect();
        $deliberations = collect();
        $avertissements = [];

        // 1. Épreuves (pour émargements et feuilles de notes)
        if (in_array('emargement', $types, true) || in_array('feuille_notes', $types, true)) {
            $epreuvesQuery = Epreuve::query()
                ->with(['matiere', 'groupe.faculte', 'session', 'candidats.etudiant'])
                ->withCount('candidats');

            if (! empty($criteres['session_id'])) {
                $epreuvesQuery->where('session_id', $criteres['session_id']);
            }
            if (! empty($criteres['groupe_id'])) {
                $epreuvesQuery->where('groupe_id', $criteres['groupe_id']);
            }
            if (! empty($criteres['epreuve_id'])) {
                $epreuvesQuery->where('id', $criteres['epreuve_id']);
            }

            $this->appliquerPerimetreFaculteEpreuve($epreuvesQuery, $utilisateur);
            $epreuves = $epreuvesQuery->get();

            foreach ($epreuves as $epreuve) {
                if ($epreuve->candidats_count === 0) {
                    $avertissements[] = "Épreuve #{$epreuve->id} ({$epreuve->matiere?->code}) : aucun candidat inscrit.";
                }
            }
        }

        // 2. Délibérations (pour PV de délibération)
        if (in_array('pv_deliberation', $types, true)) {
            $delibQuery = Deliberation::query()
                ->with(['programme.faculte', 'session', 'groupe', 'decisions']);

            if (! empty($criteres['session_id'])) {
                $delibQuery->where('session_id', $criteres['session_id']);
            }
            if (! empty($criteres['groupe_id'])) {
                $delibQuery->where('groupe_id', $criteres['groupe_id']);
            }
            if (! empty($criteres['deliberation_id'])) {
                $delibQuery->where('id', $criteres['deliberation_id']);
            }

            $this->appliquerPerimetreFaculteDeliberation($delibQuery, $utilisateur);
            $deliberations = $delibQuery->get();

            foreach ($deliberations as $delib) {
                if (! in_array($delib->statut, ['validated', 'published', 'locked', 'archived'], true)) {
                    $avertissements[] = "Délibération #{$delib->id} ({$delib->programme?->nom}) : statut « {$delib->statut} » non validé. PV bloqué.";
                }
            }
        }

        $totalCandidats = $epreuves->sum('candidats_count');
        $nombreDocuments = 0;
        if (in_array('emargement', $types, true)) {
            $nombreDocuments += $epreuves->count();
        }
        if (in_array('feuille_notes', $types, true)) {
            $nombreDocuments += $epreuves->count();
        }
        if (in_array('pv_deliberation', $types, true)) {
            $nombreDocuments += $deliberations->count();
        }

        return [
            'nombre_epreuves' => $epreuves->count(),
            'nombre_deliberations' => $deliberations->count(),
            'nombre_candidats' => $totalCandidats,
            'nombre_documents_estimes' => $nombreDocuments,
            'avertissements' => $avertissements,
            'epreuves' => $epreuves,
            'deliberations' => $deliberations,
        ];
    }

    /**
     * Générer le lot complet de documents et l'archiver en ZIP privé
     *
     * @param array{
     *     session_id?: int,
     *     groupe_id?: int,
     *     epreuve_id?: int,
     *     deliberation_id?: int,
     *     types: array<string>
     * } $criteres
     * @return array{
     *     lot_id: string,
     *     total: int,
     *     reussis: int,
     *     erreurs: array<string>,
     *     chemin_zip: string,
     *     nom_fichier: string,
     *     report_id: int
     * }
     */
    public function genererLot(array $criteres, User $utilisateur): array
    {
        $debut = microtime(true);
        $preview = $this->previsualiser($criteres, $utilisateur);
        $types = array_intersect($criteres['types'] ?? [], array_keys(self::TYPES_DOCUMENTS));
        if (empty($types)) {
            $types = ['emargement', 'feuille_notes'];
        }

        $lotId = (string) Str::uuid();
        $dossierLot = storage_path('app/private/evaluations/documents/lots/'.$lotId);
        File::ensureDirectoryExists($dossierLot);

        $fichiersGeneres = [];
        $erreurs = [];

        // 1. Émargements
        if (in_array('emargement', $types, true)) {
            foreach ($preview['epreuves'] as $epreuve) {
                try {
                    $response = $this->convocationService->listeEmargement($epreuve);
                    $nom = sprintf('emargement-epreuve-%d-%s.pdf', $epreuve->id, Str::slug($epreuve->matiere?->code ?? 'matiere'));
                    $chemin = $dossierLot.'/'.$nom;
                    File::put($chemin, $response->getContent());
                    $fichiersGeneres[] = ['nom' => $nom, 'chemin' => $chemin];
                } catch (\Throwable $e) {
                    $erreurs[] = "Épreuve #{$epreuve->id} (émargement) : ".$e->getMessage();
                }
            }
        }

        // 2. Feuilles de notes
        if (in_array('feuille_notes', $types, true)) {
            foreach ($preview['epreuves'] as $epreuve) {
                try {
                    $pdfContent = $this->genererFeuilleNotesPdf($epreuve, $utilisateur);
                    $nom = sprintf('feuille-notes-epreuve-%d-%s.pdf', $epreuve->id, Str::slug($epreuve->matiere?->code ?? 'matiere'));
                    $chemin = $dossierLot.'/'.$nom;
                    File::put($chemin, $pdfContent);
                    $fichiersGeneres[] = ['nom' => $nom, 'chemin' => $chemin];
                } catch (\Throwable $e) {
                    $erreurs[] = "Épreuve #{$epreuve->id} (feuille de notes) : ".$e->getMessage();
                }
            }
        }

        // 3. Procès-verbaux de délibération
        if (in_array('pv_deliberation', $types, true)) {
            foreach ($preview['deliberations'] as $deliberation) {
                try {
                    if (! in_array($deliberation->statut, ['validated', 'published', 'locked', 'archived'], true)) {
                        throw new DomainException("Délibération #{$deliberation->id} non validée ou publiée (statut {$deliberation->statut}).");
                    }
                    $response = $this->pvDeliberationService->generer($deliberation);
                    $nom = sprintf('pv-deliberation-%d-%s.pdf', $deliberation->id, Str::slug($deliberation->programme?->code ?? 'prog'));
                    $chemin = $dossierLot.'/'.$nom;
                    File::put($chemin, $response->getContent());
                    $fichiersGeneres[] = ['nom' => $nom, 'chemin' => $chemin];
                } catch (\Throwable $e) {
                    $erreurs[] = "Délibération #{$deliberation->id} (PV) : ".$e->getMessage();
                }
            }
        }

        // 4. Assemblage ZIP
        $nomArchive = sprintf('lot-documents-eval-%s.zip', now()->format('Ymd-His'));
        $cheminZip = $dossierLot.'/'.$nomArchive;

        $zip = new ZipArchive;
        if ($zip->open($cheminZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($fichiersGeneres as $f) {
                $zip->addFile($f['chemin'], $f['nom']);
            }
            $zip->close();
        }

        $dureeMs = (int) ((microtime(true) - $debut) * 1000);
        $totalVise = count($fichiersGeneres) + count($erreurs);
        $statutRapport = empty($erreurs) ? 'completed' : (empty($fichiersGeneres) ? 'failed' : 'completed_with_errors');

        $faculteLot = $utilisateur->faculteRestreinteId();
        if ($faculteLot === null && $preview['epreuves']->isNotEmpty()) {
            $premiere = $preview['epreuves']->first();
            $faculteLot = $premiere->groupe?->faculte_id
                ?? $premiere->matiere?->ue?->programme?->faculte_id;
        }
        if ($faculteLot === null && $preview['deliberations']->isNotEmpty()) {
            $delib = $preview['deliberations']->first();
            $faculteLot = $delib->programme?->faculte_id ?? $delib->groupe?->faculte_id;
        }

        // 5. Enregistrement traçabilité ImportReport
        $rapport = ImportReport::create([
            'user_id' => $utilisateur->id,
            'integration' => 'evaluation',
            'direction' => 'export',
            'type' => 'batch_documents',
            'format' => 'zip',
            'filename' => $nomArchive,
            'file_path' => $cheminZip,
            'file_hash' => file_exists($cheminZip) ? hash_file('sha256', $cheminZip) : null,
            'status' => $statutRapport,
            'total_rows' => $totalVise,
            'valid_rows' => count($fichiersGeneres),
            'error_rows' => count($erreurs),
            'errors' => $erreurs,
            'preview' => [
                'lot_id' => $lotId,
                'faculte_id' => $faculteLot,
                'criteres' => $criteres,
                'types' => $types,
                'fichiers' => array_column($fichiersGeneres, 'nom'),
            ],
            'duration_ms' => $dureeMs,
            'expires_at' => now()->addHours(24),
        ]);

        IntegrationLog::tracer(
            'export', 'export', 'export.batch_documents', $statutRapport,
            null, 200, null, null, (string) Str::uuid(), "LOT-{$lotId}",
        );

        return [
            'lot_id' => $lotId,
            'total' => $totalVise,
            'reussis' => count($fichiersGeneres),
            'erreurs' => $erreurs,
            'chemin_zip' => $cheminZip,
            'nom_fichier' => $nomArchive,
            'report_id' => $rapport->id,
        ];
    }

    /**
     * Générer le contenu PDF d'une feuille de notes pour une épreuve
     */
    public function genererFeuilleNotesPdf(Epreuve $epreuve, User $utilisateur): string
    {
        $epreuve->loadMissing(['matiere', 'groupe.faculte', 'session', 'candidats.etudiant', 'candidats.note']);

        $candidats = $epreuve->candidats
            ->sortBy(fn ($c) => ($c->etudiant->nom ?? '').' '.($c->etudiant->prenom ?? ''))
            ->values();

        $lignes = [];
        foreach ($candidats as $idx => $candidat) {
            $note = $candidat->note;
            $valeurAffichee = '—';
            if ($note !== null && $note->valeur !== null) {
                $valeurAffichee = number_format((float) $note->valeur, 2);
            }

            $lignes[] = [
                'numero' => $idx + 1,
                'matricule' => $candidat->etudiant->num_inscription ?? '—',
                'nom' => $candidat->etudiant->nom ?? '—',
                'prenom' => $candidat->etudiant->prenom ?? '—',
                'groupe' => $epreuve->groupe?->code ?? '—',
                'note' => $valeurAffichee.' / '.((float) $epreuve->note_max),
                'statut' => $candidat->statut ?? 'inscrit',
            ];
        }

        $reference = 'FNT-'.now()->format('Y').'-'.strtoupper(Str::random(6));
        $contexte = implode(' · ', array_filter([
            $epreuve->matiere?->libelle_complet,
            'Session : '.($epreuve->session?->libelle ?? '—'),
            'Date : '.($epreuve->date?->format('d/m/Y') ?? '—'),
            'Groupe : '.($epreuve->groupe?->code ?? '—'),
            'Barème : 0 à '.((float) $epreuve->note_max).' (coeff. '.((float) $epreuve->coefficient).')',
        ]));

        $html = view('pdf.liste', [
            'institution' => config('app.name'),
            'titreDocument' => 'Feuille de saisie des notes',
            'reference' => $reference,
            'dateGeneration' => now()->format('d/m/Y H:i'),
            'contexte' => $contexte,
            'metadonnees' => [
                'etablissement' => config('app.name'),
                'faculte' => $epreuve->groupe?->faculte?->nom ?? '—',
                'groupe' => $epreuve->groupe?->code ?? '—',
                'utilisateur' => $utilisateur->name ?? $utilisateur->email,
                'total' => count($lignes),
            ],
            'entetes' => ['numero', 'matricule', 'nom', 'prenom', 'groupe', 'note', 'statut'],
            'lignes' => $lignes,
            'signature' => true,
            'zoneSignature' => true,
            'orientation' => 'portrait',
        ])->render();

        return $this->pdfGenerator->generer($html, ['paper' => 'A4', 'orientation' => 'portrait']);
    }

    /**
     * Téléchargement sécurisé du lot généré avec vérification anti-IDOR
     */
    public function telechargerLot(string $lotId, User $utilisateur): BinaryFileResponse
    {
        // Recherche du rapport associé
        $rapport = ImportReport::where('type', 'batch_documents')
            ->where('preview->lot_id', $lotId)
            ->first();

        if ($rapport === null) {
            throw new DomainException('Archive documentaire introuvable ou expirée.');
        }

        // Vérification autorisation RBAC et périmètre
        $faculteUser = $utilisateur->faculteRestreinteId();
        if ($faculteUser !== null) {
            $faculteLot = $rapport->preview['faculte_id'] ?? null;
            if ($faculteLot !== null) {
                if ((int) $faculteLot !== (int) $faculteUser) {
                    throw new DomainException('Accès refusé : lot hors de votre périmètre faculté.');
                }
            } elseif ((int) $rapport->user_id !== (int) $utilisateur->id) {
                $userCreateur = User::find($rapport->user_id);
                if ($userCreateur?->faculteRestreinteId() !== $faculteUser) {
                    throw new DomainException('Accès refusé : lot hors de votre périmètre faculté.');
                }
            }
        }

        $cheminZip = $rapport->file_path;
        if (! file_exists($cheminZip)) {
            throw new DomainException('Le fichier d\'archive physique est introuvable.');
        }

        return response()->download($cheminZip, $rapport->filename, [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-cache, private, no-store, must-revalidate',
        ], ResponseHeaderBag::DISPOSITION_ATTACHMENT);
    }

    private function appliquerPerimetreFaculteEpreuve(Builder $query, User $utilisateur): void
    {
        $faculteId = $utilisateur->faculteRestreinteId();
        if ($faculteId === null) {
            return;
        }

        $query->where(function (Builder $b) use ($faculteId) {
            $b->whereHas('groupe', fn ($g) => $g->where('faculte_id', $faculteId))
                ->orWhereHas('matiere.ue.programme', fn ($p) => $p->where('faculte_id', $faculteId));
        });
    }

    private function appliquerPerimetreFaculteDeliberation(Builder $query, User $utilisateur): void
    {
        $faculteId = $utilisateur->faculteRestreinteId();
        if ($faculteId === null) {
            return;
        }

        $query->where(function (Builder $b) use ($faculteId) {
            $b->whereHas('programme', fn ($p) => $p->where('faculte_id', $faculteId))
                ->orWhereHas('groupe', fn ($g) => $g->where('faculte_id', $faculteId));
        });
    }
}
