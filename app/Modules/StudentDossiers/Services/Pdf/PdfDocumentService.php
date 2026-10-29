<?php

namespace App\Modules\StudentDossiers\Services\Pdf;

use App\Modules\StudentDossiers\Contracts\PdfGeneratorInterface;
use App\Modules\StudentDossiers\Models\GeneratedDocument;
use App\Modules\StudentDossiers\Models\StudentDossier;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Persistance des PDF générés (§8-10/§19-20) : stockage privé,
 * SHA-256, anti-régénération par hash de contenu (un dossier inchangé
 * ne régénère jamais).
 */
class PdfDocumentService
{
    public function __construct(
        private readonly PdfGeneratorInterface $moteur,
    ) {}

    /**
     * @param  array{paper?: string, orientation?: string}  $options
     */
    public function genererEtStocker(
        StudentDossier $dossier,
        string $type,
        string $html,
        string $contenuHash,
        ?int $generePar,
        array $options = [],
        ?string $codeVerification = null,
    ): GeneratedDocument {
        $pdf = $this->moteur->generer($html, $options);

        $chemin = implode('/', [
            'student-dossiers/generated',
            date('Y'),
            (string) $dossier->etudiant_id,
            $type,
            Str::uuid()->toString().'.pdf',
        ]);

        Storage::disk('private')->put($chemin, $pdf);

        $version = ((int) GeneratedDocument::where('student_dossier_id', $dossier->id)
            ->where('type', $type)
            ->max('version')) + 1;

        $prefixeRef = $type === 'dossier' ? 'PDF' : 'PDF-'.strtoupper(substr($type, 0, 3));

        return GeneratedDocument::create([
            'reference' => sprintf('%s-%s-%02d', $prefixeRef, $dossier->reference, $version),
            'code_verification' => $codeVerification,
            'student_dossier_id' => $dossier->id,
            'type' => $type,
            'version' => $version,
            'storage_path' => $chemin,
            'sha256' => hash('sha256', $pdf),
            'contenu_hash' => $contenuHash,
            'generated_by' => $generePar,
        ]);
    }

    public function documentFrais(StudentDossier $dossier, string $type, string $contenuHash): ?GeneratedDocument
    {
        $document = GeneratedDocument::where('student_dossier_id', $dossier->id)
            ->where('type', $type)
            ->where('contenu_hash', $contenuHash)
            ->latest('id')
            ->first();

        if ($document === null || ! Storage::disk('private')->exists($document->storage_path)) {
            return null;
        }

        return $document;
    }

    /**
     * Dernier PDF existant (fichier présent), pour l'affichage
     * conditionnel des boutons — sans recalcul d'empreinte.
     */
    public function dernierPdf(StudentDossier $dossier, string $type = 'dossier'): ?GeneratedDocument
    {
        $document = GeneratedDocument::where('student_dossier_id', $dossier->id)
            ->where('type', $type)
            ->latest('id')
            ->first();

        if ($document === null || ! Storage::disk('private')->exists($document->storage_path)) {
            return null;
        }

        return $document;
    }

    public function supprimerFichier(GeneratedDocument $document): void
    {
        Storage::disk('private')->delete($document->storage_path);
    }
}
