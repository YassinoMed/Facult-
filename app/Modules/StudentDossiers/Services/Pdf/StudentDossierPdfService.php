<?php

namespace App\Modules\StudentDossiers\Services\Pdf;

use App\Models\HistoriqueAcademique;
use App\Modules\StudentDossiers\Events\StudentDossierPdfGenerated;
use App\Modules\StudentDossiers\Jobs\GenerateStudentDossierPdfJob;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;

/**
 * Dossier étudiant PDF (§6/§9/§14/§17) : données STRICTEMENT
 * whitelistées (jamais password/hash/token/storage_path), HTML rendu
 * depuis Blade, génération sync ou job selon la volumétrie.
 */
class StudentDossierPdfService
{
    public const TYPE = 'dossier';

    public function __construct(
        private readonly PdfDocumentService $pdfs,
    ) {}

    /**
     * Demande de PDF : frais → servi ; volumineux → job ; sinon sync.
     *
     * @return array{statut: 'pret'|'en_cours', document?: GeneratedDocument}
     */
    public function demander(StudentDossier $dossier, ?int $generePar): array
    {
        $dossier->loadMissing(['etudiant.faculte', 'etudiant.dossierAcademique', 'documents.versions']);

        $empreinte = $this->empreinte($dossier);
        $frais = $this->pdfs->documentFrais($dossier, self::TYPE, $empreinte);

        if ($frais !== null) {
            return ['statut' => 'pret', 'document' => $frais];
        }

        $seuil = (int) config('student-dossiers.pdf.job_threshold', 200);

        if ($dossier->documents->count() > $seuil) {
            GenerateStudentDossierPdfJob::dispatch($dossier->id, $empreinte, $generePar);

            return ['statut' => 'en_cours'];
        }

        return ['statut' => 'pret', 'document' => $this->genererMaintenant($dossier, $generePar, $empreinte)];
    }

    public function genererMaintenant(StudentDossier $dossier, ?int $generePar, ?string $empreinte = null): GeneratedDocument
    {
        $dossier->loadMissing(['etudiant.faculte', 'etudiant.dossierAcademique', 'documents.versions']);

        $empreinte ??= $this->empreinte($dossier);
        $donnees = $this->donnees($dossier);

        $html = view('modules.student-dossiers.pdf.dossier', $donnees)->render();

        $document = $this->pdfs->genererEtStocker(
            $dossier,
            self::TYPE,
            $html,
            $empreinte,
            $generePar,
            [
                'paper' => (string) config('student-dossiers.pdf.paper', 'A4'),
                'orientation' => (string) config('student-dossiers.pdf.orientation', 'portrait'),
            ],
        );

        StudentDossierPdfGenerated::dispatch($document->fresh('dossier.etudiant'));

        return $document;
    }

    /**
     * HTML source (testable : A4, footer, pagination, UTF-8, secrets absents).
     *
     * @return array<string, mixed>
     */
    public function donnees(StudentDossier $dossier): array
    {
        $etudiant = $dossier->etudiant;

        return [
            'institution' => (string) config('student-dossiers.pdf.institution_name', 'Université'),
            'reference' => $dossier->reference,
            'dateGeneration' => now()->format('d/m/Y'),
            'identite' => [
                'Nom' => (string) $etudiant->nom,
                'Prénom' => (string) $etudiant->prenom,
                'CIN' => (string) $etudiant->cin,
                'N° inscription' => (string) $etudiant->num_inscription,
                'Matricule' => (string) ($etudiant->dossierAcademique?->matricule ?? '—'),
                'Date de naissance' => $etudiant->date_naissance?->format('d/m/Y') ?? '—',
                'Sexe' => $etudiant->sexe === 'F' ? 'Féminin' : 'Masculin',
                'Email' => (string) $etudiant->email,
            ],
            'academique' => [
                'Faculté' => (string) ($etudiant->faculte?->nom ?? '—'),
                'Niveau' => (string) $etudiant->niveau,
                'Année universitaire' => (string) $etudiant->annee_academique,
                'Statut du dossier' => $dossier->status,
            ],
            'documents' => $dossier->documents->map(fn ($document) => [
                'categorie' => (string) config(
                    'student-dossiers.categories.'.$document->category,
                    $document->category,
                ),
                'type' => (string) $document->document_type,
                'titre' => (string) $document->title,
                'statut' => (string) $document->status,
                'version' => (int) $document->versions->max('version'),
                'date' => $document->created_at->format('d/m/Y'),
                'expiration' => $document->expires_at?->format('d/m/Y') ?? '—',
            ])->values()->all(),
            'historique' => HistoriqueAcademique::where('etudiant_id', $etudiant->id)
                ->where('action', 'like', 'student_document.%')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(['action', 'created_at'])
                ->map(fn ($entree) => [
                    'date' => $entree->created_at->format('d/m/Y H:i'),
                    'action' => (string) $entree->action,
                ])->all(),
        ];
    }

    /**
     * Empreinte du contenu (§19) : tout changement (document, statut,
     * version, identité, référence) invalide le PDF en cache.
     */
    public function empreinte(StudentDossier $dossier): string
    {
        $etudiant = $dossier->etudiant;

        return hash('sha256', json_encode([
            'dossier' => [$dossier->id, $dossier->reference, $dossier->status, $dossier->updated_at?->toAtomString()],
            'etudiant' => [
                $etudiant->id, $etudiant->nom, $etudiant->prenom, $etudiant->cin,
                $etudiant->num_inscription, $etudiant->email, $etudiant->date_naissance?->toDateString(),
                $etudiant->sexe, $etudiant->faculte_id, $etudiant->niveau, $etudiant->annee_academique,
                $etudiant->dossierAcademique?->matricule,
            ],
            'documents' => $dossier->documents->map(fn ($document) => [
                $document->id, $document->status, $document->title, $document->versions->max('version'),
                $document->updated_at?->toAtomString(),
            ])->all(),
        ], JSON_UNESCAPED_UNICODE));
    }
}
