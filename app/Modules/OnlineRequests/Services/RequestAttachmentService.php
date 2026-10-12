<?php

namespace App\Modules\OnlineRequests\Services;

use App\Modules\OnlineRequests\Models\OnlineRequest;
use App\Modules\OnlineRequests\Models\RequestAttachment;
use App\Modules\StudentDossiers\Contracts\AntivirusScannerInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pièces justificatives : même discipline que StudentDossiers (privé,
 * UUID, SHA-256, MIME réel + signatures). Si la demande était en
 * `waiting_student`, le dépôt la fait revenir en `in_review` (§2).
 */
class RequestAttachmentService
{
    public function __construct(
        private readonly OnlineRequestService $demandes,
    ) {}

    public function televerser(OnlineRequest $demande, UploadedFile $fichier, ?int $televersePar): RequestAttachment
    {
        $this->valider($fichier);

        $extension = mb_strtolower($fichier->getClientOriginalExtension());
        $chemin = implode('/', [
            'online-requests',
            date('Y'),
            (string) $demande->id,
            Str::uuid()->toString().'.'.$extension,
        ]);

        $contenu = $fichier->get();

        Storage::disk('private')->put($chemin, $contenu);

        $hash = hash('sha256', $contenu);

        // Hook antivirus partagé (Phase 9 §49) : même contrat que la
        // GED (Null par défaut = accepté, tracé).
        $resultat = app(AntivirusScannerInterface::class)->analyser([
            'storage_path' => $chemin,
            'sha256' => $hash,
            'size' => strlen($contenu),
        ]);

        if (! $resultat->sain) {
            Storage::disk('private')->delete($chemin);

            throw new RuntimeException('Pièce refusée par l’analyse antivirus'.($resultat->signature !== null ? " ({$resultat->signature})." : '.'));
        }

        // Versionnement (Phase 9 §19) : l'historique est conservé, la
        // version la plus récente fait foi.
        $version = ((int) $demande->pieces()->max('version')) + 1;

        $piece = $demande->pieces()->create([
            'original_filename' => $fichier->getClientOriginalName(),
            'storage_path' => $chemin,
            'mime_type' => (string) $fichier->getMimeType(),
            'extension' => $extension,
            'size' => strlen($contenu),
            'version' => $version,
            'sha256' => $hash,
            'uploaded_by' => $televersePar,
        ]);

        if ($demande->status === 'waiting_student') {
            $this->demandes->transitionner($demande, 'in_review');
        }

        return $piece;
    }

    public function supprimer(RequestAttachment $piece): void
    {
        Storage::disk('private')->delete($piece->storage_path);
        $piece->delete();
    }

    /**
     * @throws RuntimeException
     */
    private function valider(UploadedFile $fichier): void
    {
        /** @var array<int, string> $extensions */
        $extensions = config('online-requests.extensions', []);
        /** @var array<string, array<int, string>> $mimes */
        $mimes = config('online-requests.mimes', []);
        /** @var array<int, string> $interdites */
        $interdites = config('online-requests.extensions_interdites', []);
        $tailleMax = (int) config('online-requests.taille_max', 10 * 1024 * 1024);

        if (! $fichier->isValid()) {
            throw new RuntimeException('Fichier invalide ou téléversement incomplet.');
        }

        $extension = mb_strtolower($fichier->getClientOriginalExtension() ?: '');

        if ($extension === '' || in_array($extension, $interdites, true)) {
            throw new RuntimeException("Format « {$extension} » interdit.");
        }

        if (! in_array($extension, $extensions, true)) {
            throw new RuntimeException('Formats acceptés : PDF, JPG, PNG, WebP.');
        }

        if ($fichier->getSize() === false || $fichier->getSize() > $tailleMax) {
            throw new RuntimeException('Fichier trop volumineux (maximum 10 Mo).');
        }

        $mimeReel = (string) $fichier->getMimeType();

        if (! in_array($mimeReel, $mimes[$extension] ?? [], true)) {
            throw new RuntimeException("Contenu incohérent avec l'extension « .{$extension} ».");
        }

        $contenu = $fichier->get();
        if ($extension === 'pdf' && ! str_starts_with($contenu, '%PDF-')) {
            throw new RuntimeException('Fichier PDF invalide (signature manquante).');
        }
        if (in_array($extension, ['jpg', 'jpeg'], true) && ! str_starts_with($contenu, "\xFF\xD8\xFF")) {
            throw new RuntimeException('Image JPEG invalide (signature manquante).');
        }
        if ($extension === 'png' && ! str_starts_with($contenu, "\x89PNG\r\n\x1a\n")) {
            throw new RuntimeException('Image PNG invalide (signature manquante).');
        }
        if ($extension === 'webp' && (strlen($contenu) < 12 || substr($contenu, 0, 4) !== 'RIFF' || substr($contenu, 8, 4) !== 'WEBP')) {
            throw new RuntimeException('Image WebP invalide (signature manquante).');
        }
    }
}
