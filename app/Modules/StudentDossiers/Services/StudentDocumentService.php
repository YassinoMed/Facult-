<?php

namespace App\Modules\StudentDossiers\Services;

use App\Modules\StudentDossiers\Events\DocumentArchived;
use App\Modules\StudentDossiers\Events\DocumentDeleted;
use App\Modules\StudentDossiers\Events\DocumentRejected;
use App\Modules\StudentDossiers\Events\DocumentReplaced;
use App\Modules\StudentDossiers\Events\DocumentUploaded;
use App\Modules\StudentDossiers\Events\DocumentValidated;
use App\Modules\StudentDossiers\Models\StudentDocument;
use App\Modules\StudentDossiers\Models\StudentDocumentVersion;
use App\Modules\StudentDossiers\Models\StudentDossier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cycle de vie des documents (§9) : upload, remplacement versionné,
 * validation/rejet/ archivage, suppression physique + lignes.
 * Transactions systématiques, events de domaine pour audit/OCR.
 */
class StudentDocumentService
{
    public function __construct(
        private readonly DocumentStorageService $stockage,
    ) {}

    /**
     * @param  array{category: string, document_type: string, title: string, description?: ?string, expires_at?: ?string}  $donnees
     */
    public function upload(StudentDossier $dossier, array $donnees, UploadedFile $fichier, ?int $televersePar): StudentDocument
    {
        /** @var StudentDocument $document */
        $document = DB::transaction(function () use ($dossier, $donnees, $fichier, $televersePar) {
            $meta = $this->stockage->stocker(
                $fichier,
                (int) $dossier->etudiant_id,
                (string) $dossier->etudiant->annee_academique,
                $donnees['category'],
            );

            $document = StudentDocument::create([
                'student_dossier_id' => $dossier->id,
                'category' => $donnees['category'],
                'document_type' => $donnees['document_type'],
                'title' => $donnees['title'],
                'description' => $donnees['description'] ?? null,
                'original_filename' => $meta['original_filename'],
                'storage_path' => $meta['storage_path'],
                'mime_type' => $meta['mime_type'],
                'extension' => $meta['extension'],
                'size' => $meta['size'],
                'sha256' => $meta['sha256'],
                'status' => 'pending',
                'uploaded_by' => $televersePar,
                'expires_at' => $donnees['expires_at'] ?? null,
            ]);

            $document->versions()->create([
                'version' => 1,
                'original_filename' => $meta['original_filename'],
                'storage_path' => $meta['storage_path'],
                'mime_type' => $meta['mime_type'],
                'extension' => $meta['extension'],
                'size' => $meta['size'],
                'sha256' => $meta['sha256'],
                'uploaded_by' => $televersePar,
            ]);

            return $document;
        });

        DocumentUploaded::dispatch($document->fresh(['dossier.etudiant', 'versions']));

        return $document;
    }

    /**
     * Remplacement (§3) : nouveau fichier + version incrémentée, ancien
     * fichier conservé dans l'historique. Repasse en `pending`.
     */
    public function replace(StudentDocument $document, UploadedFile $fichier, ?int $televersePar): StudentDocument
    {
        $document->loadMissing('dossier.etudiant');

        /** @var StudentDocument $document */
        $document = DB::transaction(function () use ($document, $fichier, $televersePar) {
            $meta = $this->stockage->stocker(
                $fichier,
                (int) $document->dossier->etudiant_id,
                (string) $document->dossier->etudiant->annee_academique,
                $document->category,
            );

            $prochaine = ((int) $document->versions()->max('version')) + 1;

            $document->versions()->create([
                'version' => $prochaine,
                'original_filename' => $meta['original_filename'],
                'storage_path' => $meta['storage_path'],
                'mime_type' => $meta['mime_type'],
                'extension' => $meta['extension'],
                'size' => $meta['size'],
                'sha256' => $meta['sha256'],
                'uploaded_by' => $televersePar,
            ]);

            $document->update([
                'original_filename' => $meta['original_filename'],
                'storage_path' => $meta['storage_path'],
                'mime_type' => $meta['mime_type'],
                'extension' => $meta['extension'],
                'size' => $meta['size'],
                'sha256' => $meta['sha256'],
                'status' => 'pending',
                'uploaded_by' => $televersePar,
                'validated_by' => null,
                'validated_at' => null,
                'rejected_reason' => null,
            ]);

            return $document;
        });

        DocumentReplaced::dispatch($document->fresh(['dossier.etudiant', 'versions']));

        return $document;
    }

    public function validate(StudentDocument $document, ?int $validePar): StudentDocument
    {
        $document->update([
            'status' => 'validated',
            'validated_by' => $validePar,
            'validated_at' => now(),
            'rejected_reason' => null,
        ]);

        DocumentValidated::dispatch($document->fresh('dossier.etudiant'));

        return $document;
    }

    public function reject(StudentDocument $document, string $motif, ?int $rejetePar): StudentDocument
    {
        $document->update([
            'status' => 'rejected',
            'rejected_reason' => $motif,
            'validated_by' => $rejetePar,
            'validated_at' => now(),
        ]);

        DocumentRejected::dispatch($document->fresh('dossier.etudiant'));

        return $document;
    }

    public function archive(StudentDocument $document): StudentDocument
    {
        $document->update(['status' => 'archived']);

        DocumentArchived::dispatch($document->fresh('dossier.etudiant'));

        return $document;
    }

    /**
     * Réponse de téléchargement déterministe (§2-5) : le Content-Type
     * HTTP est le MIME VALIDÉ ET PERSISTÉ (document courant ou version
     * explicitement demandée) — jamais Storage::mimeType() ni l'extension.
     * Disposition attachment + nom sécurisé + nosniff.
     */
    public function reponseTelechargement(
        StudentDocument $document,
        ?StudentDocumentVersion $version = null,
    ): StreamedResponse {
        $mime = $version?->mime_type ?? $document->mime_type;
        $nom = $version?->original_filename ?? $document->original_filename;
        $chemin = $version?->storage_path ?? $document->storage_path;

        // Eager : le 404 doit partir AVANT les headers (la closure du
        // stream s'exécute après l'envoi du statut).
        abort_unless($this->stockage->existe($chemin), 404, 'Document introuvable.');

        return response()->streamDownload(function () use ($chemin) {
            $flux = $this->stockage->lireFlux($chemin);
            fpassthru($flux);
            fclose($flux);
        }, $this->stockage->nomTelechargementSecurise($nom), [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Aperçu en ligne sécurisé (§4/LOOP 05) : disposition inline, Content-Type
     * strict depuis la DB, nom sécurisé, nosniff anti MIME-sniffing.
     */
    public function reponseApercu(
        StudentDocument $document,
        ?StudentDocumentVersion $version = null,
    ): StreamedResponse {
        $mime = $version?->mime_type ?? $document->mime_type;
        $nom = $version?->original_filename ?? $document->original_filename;
        $chemin = $version?->storage_path ?? $document->storage_path;

        abort_unless($this->stockage->existe($chemin), 404, 'Document introuvable.');

        $nomSecurise = $this->stockage->nomTelechargementSecurise($nom);

        return response()->stream(function () use ($chemin) {
            $flux = $this->stockage->lireFlux($chemin);
            fpassthru($flux);
            fclose($flux);
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$nomSecurise.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Suppression définitive : fichiers physiques (toutes versions) +
     * lignes, en transaction.
     */
    public function delete(StudentDocument $document): void
    {
        $document->loadMissing(['versions', 'dossier']);

        $etudiantId = (int) $document->dossier->etudiant_id;
        $dossierId = (int) $document->student_dossier_id;
        $titre = (string) $document->title;
        $categorie = (string) $document->category;

        DB::transaction(function () use ($document) {
            foreach ($document->versions as $version) {
                $this->stockage->supprimer($version->storage_path);
            }
            $this->stockage->supprimer($document->storage_path);
            $document->versions()->delete();
            $document->delete();
        });

        DocumentDeleted::dispatch($etudiantId, $dossierId, $titre, $categorie);
    }
}
