<?php

namespace App\Services\Identity;

use App\Models\Etudiant;
use App\Services\StudentLookupService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Service central du scan de documents d'identité (§17).
 *
 * Parcours : uploadDocument() → extractData() → normalizeData() →
 * validateData() → matchExistingStudent() → buildPrefillData().
 *
 * Règles absolues :
 * - §13 option A (décision de confidentialité) : le document est
 *   TEMPORAIRE — upload → OCR → SUPPRESSION SYSTÉMATIQUE. Aucune pièce
 *   d'identité n'est archivée dans le dossier étudiant ;
 * - stockage PRIVÉ uniquement (storage/app/private), nom aléatoire —
 *   jamais le nom fourni par l'utilisateur, jamais public/ ;
 * - validation profonde (§14) : extension + MIME réel + signature +
 *   image/PDF valide + taille — l'extension seule n'est jamais crue ;
 * - recherche BD fail-closed (audit §P0-4) : la correspondance exige
 *   le numéro ET la date de naissance extraite — un numéro seul ne
 *   révèle JAMAIS l'existence d'un dossier (anti-énumération) ;
 * - JAMAIS de mot de passe ni de hash dans la moindre réponse (§12/§18) ;
 * - l'OCR n'est pas une preuve d'identité : aucun compte n'est créé
 *   ici, la validation humaine puis l'inscription finale (InscriptionRequest,
 *   transaction, unicité CIN) restent les seules voies d'écriture.
 */
class IdentityDocumentService
{
    public function __construct(
        private readonly OcrServiceInterface $ocr,
        private readonly StudentLookupService $lookup,
        private readonly IdentityMatcher $matcher,
        private readonly IdentityPrefillService $prefill,
    ) {}

    /**
     * Valide en profondeur puis stocke le document en zone temporaire
     * PRIVÉE (nom aléatoire). Retourne le chemin de stockage.
     *
     * @throws ValidationException fichier rejeté (extension trompeuse,
     *                             MIME réel inattendu, corrompu, trop
     *                             volumineux, polyglot…)
     */
    public function uploadDocument(UploadedFile $fichier, string $documentType): string
    {
        $this->validerFichier($fichier, $documentType);

        $extension = mb_strtolower($fichier->getClientOriginalExtension() ?: 'bin');
        $nomInterne = Str::uuid()->toString().'.'.$extension;

        // Disque « local » = storage/app/private — rien dans public/
        $chemin = $fichier->storeAs(
            (string) config('identity.repertoire_temporaire'),
            $nomInterne,
            'local',
        );

        if ($chemin === false) {
            throw ValidationException::withMessages([
                'document' => 'Le document n\'a pas pu être enregistré pour l\'analyse. Réessayez.',
            ]);
        }

        return $chemin;
    }

    /**
     * Validation serveur en profondeur (§14) — ne JAMAIS faire confiance
     * à l'extension déclarée :
     * 1. extension dans la whitelist ;
     * 2. MIME RÉEL du contenu (finfo) dans la whitelist ;
     * 3. image réellement décodable (getimagesize) OU PDF dont la
     *    signature commence par %PDF- ;
     * 4. taille ≤ limite configurée ;
     * 5. cohérence extension/MIME (anti-polyglot : un .jpg dont le
     *    contenu est un PDF est rejeté, et réciproquement).
     */
    public function validerFichier(UploadedFile $fichier, string $documentType): void
    {
        $tailleMax = (int) config('identity.upload.taille_max');
        $extensions = (array) config('identity.upload.extensions');
        $mimes = (array) config('identity.upload.mimes');

        if (! $fichier->isValid()) {
            throw ValidationException::withMessages([
                'document' => 'Le transfert du document a échoué. Réessayez.',
            ]);
        }

        if ($fichier->getSize() > $tailleMax) {
            throw ValidationException::withMessages([
                'document' => 'Le document dépasse la taille maximale autorisée ('.round($tailleMax / 1048576).' Mo).',
            ]);
        }

        $extension = mb_strtolower($fichier->getClientOriginalExtension());

        if ($extension === '' || ! in_array($extension, $extensions, true)) {
            throw ValidationException::withMessages([
                'document' => 'Format non autorisé. Formats acceptés : JPG, JPEG, PNG, PDF.',
            ]);
        }

        // MIME RÉEL (détection de contenu, jamais l'en-tête du client)
        $mimeReel = $fichier->getMimeType() ?: '';

        if (! in_array($mimeReel, $mimes, true)) {
            throw ValidationException::withMessages([
                'document' => 'Le contenu du fichier ne correspond à aucun format accepté (JPG, PNG, PDF).',
            ]);
        }

        // Anti-polyglot : cohérence stricte extension ↔ contenu réel
        $attendu = match ($extension) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            default => 'image/jpeg',
        };

        if ($mimeReel !== $attendu) {
            throw ValidationException::withMessages([
                'document' => 'Le contenu du fichier ne correspond pas à son extension.',
            ]);
        }

        // Intégrité : image décodable ou PDF dont la signature est %PDF-
        if ($mimeReel === 'application/pdf') {
            $contenu = $fichier->getContent();

            if (! str_starts_with($contenu, '%PDF-')) {
                throw ValidationException::withMessages([
                    'document' => 'Le fichier PDF est corrompu ou invalide.',
                ]);
            }
        } elseif (@getimagesize($fichier->getRealPath()) === false) {
            throw ValidationException::withMessages([
                'document' => 'L\'image est corrompue ou illisible.',
            ]);
        }

        // Anti-path-traversal : le nom client ne sert JAMAIS de chemin —
        // un nom malveillant (../..) est refusé ici par simple garde.
        if (str_contains($fichier->getClientOriginalName() ?? '', '..')) {
            throw ValidationException::withMessages([
                'document' => 'Nom de fichier invalide.',
            ]);
        }
    }

    /**
     * Extraction OCR depuis le chemin de stockage temporaire.
     */
    public function extractData(string $cheminStockage, string $documentType): OcrResult
    {
        $cheminAbsolu = Storage::disk('local')->path($cheminStockage);

        return $this->ocr->extract($cheminAbsolu, $documentType);
    }

    /**
     * Contrôles de cohérence (§16) — retournent des alertes SANS jamais
     * bloquer : l'anomalie appelle une vérification humaine.
     *
     * @return array<int, string>
     */
    public function validateData(OcrResult $resultat): array
    {
        $alertes = [];

        if ($resultat->documentNumber === null) {
            $alertes[] = 'Aucun numéro de document exploitable n\'a été extrait.';
        }

        if ($resultat->dateOfBirth === null) {
            $alertes[] = 'Aucune date de naissance exploitable n\'a été extraite.';
        }

        if ($resultat->documentType === 'passeport') {
            if ($resultat->mrz === 'invalide') {
                $alertes[] = 'Le checksum de la zone MRZ est invalide : vérifiez la qualité du scan.';
            }

            if ($resultat->expiryDate !== null && $resultat->expiryDate < date('Y-m-d')) {
                $alertes[] = 'Le passeport semble expiré.';
            }
        }

        // §20 — champs sous le seuil de validation → avertissement
        $seuil = (float) config('identity.confiance.seuil_validation', 0.85);
        $confiances = $resultat->confidenceFormulaire();

        $libelles = [
            'cin' => 'le numéro de document',
            'nom' => 'le nom',
            'prenom' => 'le prénom',
            'date_naissance' => 'la date de naissance',
            'lieu_naissance' => 'le lieu de naissance',
        ];

        foreach ($libelles as $cle => $libelle) {
            $niveau = $confiances[$cle] ?? null;

            if ($niveau !== null && $niveau < $seuil) {
                $alertes[] = "La confiance {$libelle} est insuffisante ({$niveau}) : vérification manuelle nécessaire.";
            }
        }

        return $alertes;
    }

    /**
     * Recherche BD fail-closed (§10 + audit §P0-4) : le numéro extrait
     * doit être accompagné de la date de naissance extraite — un numéro
     * seul ne révèle JAMAIS l'existence d'un dossier.
     */
    public function matchExistingStudent(OcrResult $resultat): ?Etudiant
    {
        if ($resultat->documentNumber === null || $resultat->dateOfBirth === null) {
            return null;
        }

        return $this->lookup->trouverPourPrefill(
            $resultat->documentNumber,
            $resultat->dateOfBirth,
        );
    }

    /**
     * Divergences OCR ↔ dossier existant (§11, §15-16) : comparaison
     * INTELLIGENTE via IdentityMatcher — l'arabe est translittéré avant
     * comparaison (بن علي ≡ BEN ALI → aucune divergence), les variantes
     * d'écriture (محمّد ≡ محمد) sont neutralisées, une tolérance OCR
     * borne les fautes de lecture. Toute valeur non strictement
     * identique est SIGNALÉE — jamais écrasée automatiquement : la
     * donnée validée en base fait foi (§20).
     *
     * @return array<int, array{champ: string, extrait: ?string, enregistre: ?string, verdict: string}>
     */
    public function comparerAvecExistant(OcrResult $resultat, Etudiant $etudiant): array
    {
        $divergences = [];

        $ocr = [
            'nom' => $resultat->lastName,
            'prenom' => $resultat->firstName,
            'date_naissance' => $resultat->dateOfBirth,
            'sexe' => $resultat->sex,
        ];

        $base = [
            'nom' => $etudiant->nom,
            'prenom' => $etudiant->prenom,
            'date_naissance' => $etudiant->date_naissance?->format('Y-m-d'),
            'sexe' => $etudiant->sexe,
        ];

        foreach ($ocr as $champ => $valeurOcr) {
            if ($valeurOcr === null) {
                continue;
            }

            $valeurBase = $base[$champ];

            if ($valeurBase === null) {
                continue;
            }

            $verdict = $this->matcher->comparer($valeurOcr, $valeurBase);

            if ($verdict !== IdentityMatcher::IDENTIQUE) {
                $divergences[] = [
                    'champ' => $champ,
                    'extrait' => $valeurOcr,
                    'enregistre' => $valeurBase,
                    'verdict' => $verdict,
                ];
            }
        }

        return $divergences;
    }

    /**
     * Charge utile de réponse (§18) : UNIQUEMENT les champs autorisés au
     * pré-remplissage. Jamais password, hash, token, identifiant
     * interne ou contenu OCR brut. Délégation au IdentityPrefillService
     * (§24) — inclut langue + originaux arabes (§9/§12).
     *
     * @return array<string, mixed>
     */
    public function buildPrefillData(OcrResult $resultat, ?Etudiant $existant, array $divergences, array $alertes): array
    {
        return $this->prefill->construire($resultat, $existant, $divergences, $alertes);
    }

    /**
     * §13 option A : suppression SYSTÉMATIQUE du fichier temporaire,
     * même en cas d'échec du traitement OCR.
     */
    public function supprimerDocument(string $cheminStockage): void
    {
        Storage::disk('local')->delete($cheminStockage);
    }
}
