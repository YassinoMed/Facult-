<?php

namespace App\Services;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Modules\StudentDossiers\Contracts\AntivirusScannerInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Service de gestion sécurisée des documents académiques.
 *
 * Règles absolues :
 * - stockage HORS de public/ (storage/app/private) ;
 * - nom interne aléatoire (jamais le nom fourni par l'utilisateur) ;
 * - double vérification serveur : extension + MIME réel + taille ;
 * - refus de tout format exécutable ou actif ;
 * - hash SHA-256 du contenu pour l'intégrité ;
 * - les téléchargements passent obligatoirement par un contrôleur autorisé.
 */
class DocumentService
{
    /** Types de documents gérés (validés également côté FormRequest). */
    public const TYPES_DOCUMENTS = [
        'releve_notes' => 'Relevé de notes',
        'attestation' => 'Attestation de scolarité',
        'diplome' => 'Diplôme',
        'certificat' => 'Certificat',
        'convention' => 'Convention de stage',
        'autre' => 'Autre document',
    ];

    /** Taille maximale acceptée : 10 Mo. */
    public const TAILLE_MAX = 10485760;

    /** Extensions strictement autorisées. */
    private const EXTENSIONS_AUTORISEES = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'txt'];

    /** Types MIME réels autorisés (détection par contenu, pas par extension). */
    private const MIMES_AUTORISES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
    ];

    /**
     * Cohérence stricte extension ↔ contenu : chaque extension n'accepte
     * que ses MIME réels (anti-polyglotte : un JPEG renommé .pdf est refusé).
     *
     * @var array<string, array<int, string>>
     */
    private const MIMES_PAR_EXTENSION = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'txt' => ['text/plain'],
    ];

    /**
     * Stocke un document de manière sécurisée et journalise la métadonnée.
     */
    public function stocker(
        Etudiant $etudiant,
        ?AnneeUniversitaire $annee,
        UploadedFile $fichier,
        string $typeDocument,
        int $televersePar,
    ): DocumentAcademique {
        $this->verifierFichier($fichier);

        $extension = mb_strtolower($fichier->getClientOriginalExtension() ?: 'bin');
        $contenu = $fichier->getContent();
        $hash = hash('sha256', $contenu);
        $nomInterne = Str::uuid()->toString().'.'.$extension;

        // Organisation logique : archives/etudiant_{id}/{annee}/documents/
        $dossier = sprintf(
            'archives/etudiant_%d/%s/documents',
            $etudiant->id,
            $annee?->id ?? 'courant',
        );

        return DB::transaction(function () use ($etudiant, $annee, $fichier, $typeDocument, $televersePar, $contenu, $hash, $nomInterne, $dossier) {
            $chemin = $fichier->storeAs($dossier, $nomInterne, 'local');

            if ($chemin === false) {
                throw ValidationException::withMessages([
                    'document' => 'Le téléversement du document a échoué. Veuillez réessayer.',
                ]);
            }

            // Hook antivirus partagé (§22.14) : même contrat que StudentDossiers,
            // NullAntivirusScanner par défaut (aucun moteur = accepté, tracé).
            $resultat = app(AntivirusScannerInterface::class)->analyser([
                'storage_path' => $chemin,
                'sha256' => $hash,
                'size' => strlen($contenu),
                'disk' => 'local',
                'categorie' => $typeDocument,
            ]);

            if (! $resultat->sain) {
                Storage::disk('local')->delete($chemin);

                $detail = $resultat->signature ?? $resultat->erreur;
                throw ValidationException::withMessages([
                    'document' => 'Document refusé par l’analyse antivirus'.($detail !== null ? " ({$detail})." : '.'),
                ]);
            }

            return DocumentAcademique::create([
                'etudiant_id' => $etudiant->id,
                'annee_universitaire_id' => $annee?->id,
                'type_document' => $typeDocument,
                'nom_original' => mb_substr($fichier->getClientOriginalName(), 0, 255),
                'chemin' => $chemin,
                'mime_type' => $fichier->getMimeType(),
                'taille' => strlen($contenu),
                'hash_fichier' => $hash,
                'uploaded_by' => $televersePar,
            ]);
        });
    }

    /**
     * Supprime un document (ligne + fichier physique).
     */
    public function supprimer(DocumentAcademique $document): bool
    {
        $supprime = $document->delete();

        if ($supprime) {
            Storage::disk('local')->delete($document->chemin);
        }

        return $supprime;
    }

    /**
     * Téléchargement contrôlé : jamais de lien direct vers public/.
     */
    public function telecharger(DocumentAcademique $document)
    {
        abort_if(! Storage::disk('local')->exists($document->chemin), 404, 'Document introuvable.');

        return Storage::disk('local')->download(
            $document->chemin,
            $this->nomTelechargementSecurise($document->nom_original),
            ['Content-Type' => $document->mime_type, 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    /**
     * Nom de téléchargement assaini : basename + suppression CR/LF/
     * guillemets (anti header-injection via Content-Disposition).
     */
    public function nomTelechargementSecurise(string $nom): string
    {
        $nom = preg_replace('/[\r\n"\\\\]+/', '', basename($nom)) ?? '';
        $nom = trim($nom, ' .');

        return $nom !== '' ? mb_substr($nom, 0, 255) : 'document';
    }

    /**
     * Vérifications serveur renforcées (défense en profondeur au-delà de la FormRequest).
     */
    private function verifierFichier(UploadedFile $fichier): void
    {
        if (! $fichier->isValid()) {
            throw ValidationException::withMessages(['document' => 'Le fichier téléversé est invalide.']);
        }

        if ($fichier->getSize() > self::TAILLE_MAX) {
            throw ValidationException::withMessages(['document' => 'Le document ne doit pas dépasser 10 Mo.']);
        }

        $extension = mb_strtolower($fichier->getClientOriginalExtension() ?: '');

        if (! in_array($extension, self::EXTENSIONS_AUTORISEES, true)) {
            throw ValidationException::withMessages([
                'document' => "Format « {$extension} » interdit. Formats acceptés : PDF, JPG, PNG, DOC, DOCX, XLS, XLSX, TXT.",
            ]);
        }

        $mime = $fichier->getMimeType();

        if (! in_array($mime, self::MIMES_AUTORISES, true)) {
            throw ValidationException::withMessages([
                'document' => "Le type MIME du fichier ({$mime}) n'est pas autorisé.",
            ]);
        }

        // Cohérence extension ↔ contenu réel (anti-polyglotte).
        if (! in_array($mime, self::MIMES_PAR_EXTENSION[$extension] ?? [], true)) {
            throw ValidationException::withMessages([
                'document' => "Contenu incohérent avec l'extension « .{$extension} » (MIME détecté : {$mime}).",
            ]);
        }

        $this->verifierSignature($fichier, $extension);
    }

    /**
     * Signatures magiques minimales : le contenu doit ressembler au format
     * déclaré (PDF, images, ZIP bureautique, OLE legacy).
     */
    private function verifierSignature(UploadedFile $fichier, string $extension): void
    {
        $contenu = $fichier->getContent();
        $erreur = match ($extension) {
            'pdf' => str_starts_with($contenu, '%PDF-') ? null : 'Fichier PDF invalide (signature manquante).',
            'jpg', 'jpeg' => str_starts_with($contenu, "\xFF\xD8\xFF") ? null : 'Image JPEG invalide (signature manquante).',
            'png' => str_starts_with($contenu, "\x89PNG\r\n\x1a\n") ? null : 'Image PNG invalide (signature manquante).',
            'docx', 'xlsx' => str_starts_with($contenu, 'PK') ? null : 'Document Office invalide (archive ZIP attendue).',
            'doc', 'xls' => str_starts_with($contenu, "\xD0\xCF\x11\xE0") ? null : 'Document Office legacy invalide (signature OLE manquante).',
            default => null,
        };

        if ($erreur !== null) {
            throw ValidationException::withMessages(['document' => $erreur]);
        }
    }
}
