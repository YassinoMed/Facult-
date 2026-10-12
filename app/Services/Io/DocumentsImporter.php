<?php

namespace App\Services\Io;

use App\Models\AnneeUniversitaire;
use App\Models\DocumentAcademique;
use App\Models\Etudiant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Import des MÉTADONNÉES de documents (§92) — jamais de binaire :
 * déclare des fichiers déjà présents dans le stockage privé.
 *
 * Sécurité : le chemin déclaré doit exister dans le disque privé ET
 * résider sous archives/etudiant_{id du matricule}/ — aucune écriture
 * hors du périmètre de l'étudiant, aucun téléversement direct.
 */
class DocumentsImporter
{
    public function __construct(private readonly ?User $utilisateur = null) {}

    private const REGLES = [
        'matricule' => ['required', 'regex:/^MAT-[0-9A-Z\-]{3,30}$/'],
        'annee_universitaire' => ['nullable', 'regex:/^\d{4}-\d{4}$/'],
        'type_document' => ['required', 'in:releve_notes,attestation,diplome,certificat,convention,autre'],
        'nom_document' => ['required', 'string', 'max:255'],
        'chemin_fichier' => ['required', 'string', 'max:500'],
    ];

    public function valider(array $lignes): ImportPreview
    {
        $valides = [];
        $erreurs = [];
        $doublons = [];
        $vus = [];

        $etudiants = Etudiant::whereHas('dossierAcademique')->with('dossierAcademique')->get()
            ->keyBy(fn ($e) => $e->dossierAcademique->matricule);
        $annees = AnneeUniversitaire::pluck('id', 'libelle');
        $existants = DocumentAcademique::get()->map(fn ($d) => $d->etudiant_id.'|'.$d->chemin);

        foreach ($lignes as $index => $ligne) {
            $numero = $index + 2;
            $donnees = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $ligne);

            $validateur = Validator::make($donnees, self::REGLES);
            if ($validateur->fails()) {
                foreach ($validateur->errors()->messages() as $champ => $messages) {
                    $erreurs[] = ['ligne' => $numero, 'champ' => $champ, 'message' => $messages[0], 'valeur' => (string) ($donnees[$champ] ?? '')];
                }

                continue;
            }

            $etudiant = $etudiants[$donnees['matricule']] ?? null;
            if ($etudiant === null) {
                $erreurs[] = ['ligne' => $numero, 'champ' => 'matricule', 'message' => "Le matricule « {$donnees['matricule']} » n'existe pas.", 'valeur' => $donnees['matricule']];

                continue;
            }

            // Périmètre strict : le fichier doit être sous archives/etudiant_{id} de CE matricule
            $prefixeAttendu = "archives/etudiant_{$etudiant->id}/";
            $chemin = $donnees['chemin_fichier'];

            if (! str_starts_with($chemin, $prefixeAttendu) || str_contains($chemin, '..')) {
                $erreurs[] = ['ligne' => $numero, 'champ' => 'chemin_fichier', 'message' => "Chemin refusé : doit commencer par « {$prefixeAttendu} » et ne contenir aucun « .. ».", 'valeur' => $chemin];

                continue;
            }

            if (! Storage::disk('local')->exists($chemin)) {
                $erreurs[] = ['ligne' => $numero, 'champ' => 'chemin_fichier', 'message' => 'Le fichier n\'existe pas dans le stockage privé — déposez-le d\'abord sur le serveur (via le dossier archivé).', 'valeur' => $chemin];

                continue;
            }

            if ($existants->contains($etudiant->id.'|'.$chemin)) {
                $doublons[] = "Ligne {$numero} : ce document est déjà déclaré pour {$donnees['matricule']} — ignoré.";

                continue;
            }

            $cle = $etudiant->id.'|'.$chemin;
            if (in_array($cle, $vus, true)) {
                $doublons[] = "Ligne {$numero} : doublon interne au fichier.";

                continue;
            }
            $vus[] = $cle;

            $donnees['_etudiant_id'] = $etudiant->id;
            $donnees['_annee_id'] = $annees[$donnees['annee_universitaire'] ?? ''] ?? null;
            $valides[] = $donnees;
        }

        return new ImportPreview($valides, $erreurs, $doublons);
    }

    public function importer(array $lignes, string $mode): array
    {
        $preview = $this->valider($lignes);
        $erreurs = array_map(fn ($e) => "Ligne {$e['ligne']} ({$e['champ']}) : {$e['message']}", $preview->erreurs);

        if ($preview->valides() === 0) {
            return ['importes' => 0, 'ignores' => $preview->doublons(), 'en_erreur' => $preview->enErreur(), 'erreurs' => $erreurs];
        }

        $importes = 0;
        $ignores = $preview->doublons();

        DB::transaction(function () use ($preview, &$importes, &$ignores) {
            foreach ($preview->lignesValides as $ligne) {
                try {
                    $contenu = (string) Storage::disk('local')->get($ligne['chemin_fichier']);

                    DocumentAcademique::create([
                        'etudiant_id' => $ligne['_etudiant_id'],
                        'annee_universitaire_id' => $ligne['_annee_id'],
                        'type_document' => $ligne['type_document'],
                        'nom_original' => $ligne['nom_document'],
                        'chemin' => $ligne['chemin_fichier'],
                        'mime_type' => Storage::disk('local')->mimeType($ligne['chemin_fichier']) ?? 'application/octet-stream',
                        'taille' => strlen($contenu),
                        'hash_fichier' => hash('sha256', $contenu),
                        'uploaded_by' => $this->utilisateur?->id,
                    ]);
                    $importes++;
                } catch (UniqueConstraintViolationException) {
                    $ignores++;
                }
            }
        });

        return ['importes' => $importes, 'ignores' => $ignores, 'en_erreur' => count($erreurs), 'erreurs' => $erreurs];
    }
}
