<?php

namespace App\Modules\StudentDossiers\Services;

use App\Modules\StudentDossiers\Contracts\AntivirusScannerInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stockage confidentiel (§5-6) : disque privé, noms internes aléatoires,
 * validation profonde du CONTENU (extension + MIME réel + cohérence +
 * taille + signature), SHA-256, hook antivirus remplaçable.
 *
 * INTERDIT : chemin ou nom fourni par l'utilisateur, URL publique.
 */
class DocumentStorageService
{
    public function __construct(
        private readonly AntivirusScannerInterface $antivirus,
    ) {}

    /**
     * Valide + stocke un fichier. Retourne les métadonnées persistables.
     *
     * @return array{storage_path: string, mime_type: string, extension: string, size: int, sha256: string, original_filename: string}
     */
    public function stocker(
        UploadedFile $fichier,
        int $etudiantId,
        string $annee,
        string $categorie,
        string $racine = 'student-dossiers',
    ): array {
        $this->valider($fichier, $categorie);

        // Segments de chemin blanchisés : aucune valeur BD ne doit pouvoir
        // faire sortir du répertoire (traversal si donnée corrompue).
        // LOOP-UNIREGISTRE-INSCRIPTIONS-PAIEMENTS-01 : la racine reste
        // paramétrable pour isoler les reçus de paiement des pièces
        // permanentes, sans dupliquer la validation profonde.
        $segmentRacine = preg_replace('/[^a-z0-9_-]/i', '', $racine) ?: 'documents';
        $segmentAnnee = preg_match('/^\d{4}-\d{4}$/', trim($annee)) === 1 ? trim($annee) : 'sans-annee';
        $segmentCategorie = preg_replace('/[^a-z0-9_-]/i', '', $categorie) ?: 'divers';

        $extension = mb_strtolower($fichier->getClientOriginalExtension());
        $nomInterne = Str::uuid()->toString().'.'.$extension;
        $chemin = implode('/', [
            $segmentRacine,
            $segmentAnnee,
            (string) $etudiantId,
            $segmentCategorie,
            $nomInterne,
        ]);

        $contenu = $fichier->get();
        $sha256 = hash('sha256', $contenu);

        Storage::disk('private')->put($chemin, $contenu);

        $resultat = $this->antivirus->analyser([
            'storage_path' => $chemin,
            'sha256' => $sha256,
            'size' => strlen($contenu),
            'disk' => 'private',
            'categorie' => $categorie,
        ]);

        if (! $resultat->sain) {
            // Jamais de fichier refusé ou non analysé dans le dossier.
            Storage::disk('private')->delete($chemin);

            $detail = $resultat->signature ?? $resultat->erreur;
            throw new RuntimeException(
                'Document refusé par l\'analyse antivirus'.($detail !== null ? " ({$detail})." : '.')
            );
        }

        return [
            'storage_path' => $chemin,
            'mime_type' => (string) $fichier->getMimeType(),
            'extension' => $extension,
            'size' => strlen($contenu),
            'sha256' => $sha256,
            'original_filename' => $fichier->getClientOriginalName(),
        ];
    }

    public function supprimer(string $chemin): void
    {
        Storage::disk('private')->delete($chemin);
    }

    public function existe(string $chemin): bool
    {
        return Storage::disk('private')->exists($chemin);
    }

    /**
     * Taille réelle du fichier privé, ou null s'il est absent/illisible.
     * LOOP-UNIREGISTRE-DOCUMENTS-REUTILISABLES-01 : sert à vérifier
     * qu'un document réutilisé est toujours physiquement lisible (la
     * métadonnée seule ne prouve jamais la présence du fichier).
     */
    public function taille(string $chemin): ?int
    {
        if (! $this->existe($chemin)) {
            return null;
        }

        try {
            return (int) Storage::disk('private')->size($chemin);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Empreinte SHA-256 du fichier privé, ou null s'il est absent/illisible.
     * LOOP-UNIREGISTRE-DOCUMENTS-REUTILISABLES-01 : garantit que le fichier
     * réutilisé est exactement celui qui a été validé (pas seulement une
     * taille identique).
     */
    public function empreinte(string $chemin): ?string
    {
        if (! $this->existe($chemin)) {
            return null;
        }

        try {
            return hash('sha256', (string) Storage::disk('private')->get($chemin));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Flux de lecture du fichier (§4) : le stockage fournit les OCTETS
     * uniquement — jamais le Content-Type (déterminé par l'appelant
     * depuis le MIME validé et persisté en base).
     *
     * @return resource
     */
    public function lireFlux(string $chemin)
    {
        abort_unless($this->existe($chemin), 404, 'Document introuvable.');

        $flux = Storage::disk('private')->readStream($chemin);

        abort_unless(is_resource($flux), 404, 'Document introuvable.');

        return $flux;
    }

    /**
     * Nom de téléchargement sécurisé (§6) : basename anti path
     * traversal + suppression CR/LF/guillemets anti header injection.
     * Jamais storage_path (nom interne UUID).
     */
    public function nomTelechargementSecurise(string $nom): string
    {
        $nom = preg_replace('/[\r\n"\\\\]+/', '', basename($nom)) ?? '';
        $nom = trim($nom, ' .');

        return $nom !== '' ? $nom : 'document';
    }

    /**
     * Validation profonde serveur : le navigateur ne fait jamais foi.
     *
     * @throws RuntimeException
     */
    private function valider(UploadedFile $fichier, string $categorie): void
    {
        /** @var array<int, string> $extensions */
        $extensions = config('student-dossiers.extensions', []);
        /** @var array<string, array<int, string>> $mimes */
        $mimes = config('student-dossiers.mimes', []);
        /** @var array<int, string> $interdites */
        $interdites = config('student-dossiers.extensions_interdites', []);
        $tailleMax = (int) config('student-dossiers.taille_max', 10 * 1024 * 1024);

        if (! $fichier->isValid()) {
            throw new RuntimeException('Fichier invalide ou téléversement incomplet.');
        }

        $extension = mb_strtolower($fichier->getClientOriginalExtension() ?: '');

        if ($extension === '' || in_array($extension, $interdites, true)) {
            throw new RuntimeException("Format « {$extension} » interdit.");
        }

        if (! in_array($extension, $extensions, true)) {
            throw new RuntimeException("Format « {$extension} » non accepté (PDF, JPG, PNG, WebP uniquement).");
        }

        if ($fichier->getSize() === false || $fichier->getSize() > $tailleMax) {
            throw new RuntimeException('Fichier trop volumineux (maximum 10 Mo).');
        }

        // MIME RÉEL par contenu (finfo), jamais l'en-tête client.
        $mimeReel = (string) $fichier->getMimeType();

        if (! in_array($mimeReel, $mimes[$extension] ?? [], true)) {
            throw new RuntimeException("Contenu incohérent avec l'extension « .{$extension} » (MIME détecté : {$mimeReel}).");
        }

        // Signatures magiques : anti-polyglotte minimal.
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
