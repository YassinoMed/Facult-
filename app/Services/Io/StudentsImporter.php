<?php

namespace App\Services\Io;

use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Schedule\Models\Group;
use App\Services\NormalisationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Import des étudiants — les règles métier sont STRICTEMENT identiques
 * à celles du formulaire interne (§38) : un fichier externe n'est jamais
 * considéré comme fiable.
 *
 * Idempotence (§50) : la clé naturelle (CIN / email unique) détecte les
 * doublons — ré-envoyer le même fichier ne crée jamais deux étudiants.
 */
class StudentsImporter
{
    /** Colonnes requises/attendues du fichier. */
    private const REGLES = [
        'cin' => ['nullable', 'regex:/^[A-Z0-9]{5,30}$/'],
        'nom' => ['required', 'string', 'max:100'],
        'prenom' => ['required', 'string', 'max:100'],
        'email' => ['required', 'email', 'max:255'],
        'sexe' => ['required', 'in:M,F'],
        'faculte' => ['required', 'string', 'max:20'],      // code faculté (ex : FST)
        'niveau' => ['required', 'in:L1,L2,L3,M1,M2,D1,D2,D3'],
        'annee_academique' => ['required', 'string', 'max:20'],
        'adresse' => ['required', 'string', 'max:500'],
        'ville' => ['required', 'string', 'max:100'],
        'code_postal' => ['required', 'string', 'max:20'],
        'annee_bac' => ['required', 'digits:4'],
        'telephone' => ['nullable', 'string', 'max:30'],
        'mention_bac' => ['nullable', 'in:Passable,Assez Bien,Bien,Très Bien,Excellent'],
        // Phase 2 (optionnel) : rattachement LMD + groupe, résolus serveur.
        'programme' => ['nullable', 'string', 'max:30'],
        'niveau_ref' => ['nullable', 'string', 'max:10'],
        'groupe' => ['nullable', 'string', 'max:20'],
    ];

    /**
     * Analyse le fichier sans écrire en base : prévisualisation (§37).
     *
     * @param  array<int, array<string, mixed>>  $lignes
     */
    public function valider(array $lignes, ?int $faculteRestreinteId = null): ImportPreview
    {
        $valides = [];
        $erreurs = [];
        $doublons = [];
        $vusDansLeFichier = []; // anti-doublon interne au fichier

        foreach ($lignes as $index => $ligne) {
            $numero = $index + 2; // +1 en-tête, +1 index 0
            $donnees = $this->normaliser($ligne);

            $validateur = Validator::make($donnees, self::REGLES);

            if ($validateur->fails()) {
                foreach ($validateur->errors()->messages() as $champ => $messages) {
                    $erreurs[] = ['ligne' => $numero, 'champ' => $champ, 'message' => $messages[0]];
                }

                continue;
            }

            // Cohérence externe : faculté par code
            $faculte = Faculte::where('code', mb_strtoupper($donnees['faculte']))->first();
            if ($faculte === null) {
                $erreurs[] = ['ligne' => $numero, 'champ' => 'faculte', 'message' => "La faculté « {$donnees['faculte']} » est introuvable."];

                continue;
            }

            // Isolation faculté : un auteur restreint ne peut importer que sa faculté.
            if ($faculteRestreinteId !== null && (int) $faculte->id !== $faculteRestreinteId) {
                $erreurs[] = ['ligne' => $numero, 'champ' => 'faculte', 'message' => "La faculté « {$donnees['faculte']} » est hors de votre périmètre."];

                continue;
            }

            // Rattachement LMD + groupe (optionnels, résolus serveur).
            if (! $this->resoudreRattachement($donnees, $faculte->id, $numero, $erreurs)) {
                continue;
            }

            // Idempotence : CIN (si renseignée) ou email déjà en base → doublon.
            // Sans CIN (étrangers), seul l'email fait foi (jamais where cin vide).
            $existant = Etudiant::query()
                ->when(filled($donnees['cin']), fn ($q) => $q->where('cin', $donnees['cin']))
                ->orWhere('email', mb_strtolower($donnees['email']))
                ->exists();

            $cleInterne = mb_strtolower($donnees['cin'] ?? '').'|'.mb_strtolower($donnees['email']);
            $sansCin = filled($donnees['cin']) ? "CIN {$donnees['cin']}" : 'email '.$donnees['email'];

            if ($existant) {
                $doublons[] = "Ligne {$numero} : {$sansCin} déjà enregistré(e) — ignorée.";

                continue;
            }

            if (in_array($cleInterne, $vusDansLeFichier, true)) {
                $doublons[] = "Ligne {$numero} : doublon interne au fichier ({$sansCin}).";

                continue;
            }

            $vusDansLeFichier[] = $cleInterne;
            $donnees['faculte_id'] = $faculte->id;
            $valides[] = $donnees;
        }

        return new ImportPreview($valides, $erreurs, $doublons);
    }

    /**
     * Exécution de l'import confirmé (§39) :
     * - mode atomique : tout ou rien (DB::transaction + rollback) ;
     * - mode partiel : uniquement les lignes valides.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array{importes: int, ignores: int, en_erreur: int, erreurs: array<int, string>}
     */
    public function importer(array $lignes, string $mode, ?int $faculteRestreinteId = null): array
    {
        $preview = $this->valider($lignes, $faculteRestreinteId);

        if ($preview->valides() === 0) {
            return ['importes' => 0, 'ignores' => $preview->doublons(), 'en_erreur' => $preview->enErreur(),
                'erreurs' => array_map(fn ($e) => "Ligne {$e['ligne']} ({$e['champ']}) : {$e['message']}", $preview->erreurs)];
        }

        if ($mode === 'atomique') {
            $importes = DB::transaction(function () use ($preview) {
                $n = 0;
                foreach ($preview->lignesValides as $ligne) {
                    $this->creerEtudiant($ligne);
                    $n++;
                }

                return $n;
            });

            return ['importes' => $importes, 'ignores' => $preview->doublons(), 'en_erreur' => $preview->enErreur(),
                'erreurs' => array_map(fn ($e) => "Ligne {$e['ligne']} ({$e['champ']}) : {$e['message']}", $preview->erreurs)];
        }

        // Mode partiel : ligne par ligne, une erreur n'arrête pas le reste
        $importes = 0;
        $erreurs = [];
        $ignores = 0;

        DB::transaction(function () use ($preview, &$importes, &$erreurs, &$ignores) {
            foreach ($preview->lignesValides as $ligne) {
                try {
                    $this->creerEtudiant($ligne);
                    $importes++;
                } catch (UniqueConstraintViolationException) {
                    // Race condition : contrainte d'unicité = dernier rempart
                    $ignores++;
                    $erreurs[] = "CIN {$ligne['cin']} : insertion concurrente détectée, ligne ignorée.";
                }
            }
        });

        foreach ($preview->erreurs as $e) {
            $erreurs[] = "Ligne {$e['ligne']} ({$e['champ']}) : {$e['message']}";
        }

        return ['importes' => $importes, 'ignores' => $ignores + $preview->doublons(), 'en_erreur' => $preview->enErreur() + count($erreurs) - count($preview->erreurs), 'erreurs' => $erreurs];
    }

    private function creerEtudiant(array $donnees): Etudiant
    {
        // `statut` n'est plus mass-assignable (audit §P3-17) : la valeur
        // par défaut SQL ('en_attente') s'applique à la création.
        return Etudiant::create([
            'cin' => filled($donnees['cin']) ? $donnees['cin'] : null,
            'nom' => $donnees['nom'],
            'prenom' => $donnees['prenom'],
            'email' => mb_strtolower($donnees['email']),
            'telephone' => $donnees['telephone'] ?? null,
            'date_naissance' => $donnees['date_naissance'] ?? null,
            'sexe' => $donnees['sexe'],
            'faculte_id' => $donnees['faculte_id'],
            'programme_id' => $donnees['programme_id'] ?? null,
            'niveau_id' => $donnees['niveau_id'] ?? null,
            'groupe_id' => $donnees['groupe_id'] ?? null,
            'niveau' => $donnees['niveau'],
            'annee_academique' => $donnees['annee_academique'],
            'adresse' => $donnees['adresse'],
            'ville' => $donnees['ville'],
            'code_postal' => $donnees['code_postal'],
            'annee_bac' => $donnees['annee_bac'],
            'mention_bac' => $donnees['mention_bac'] ?? null,
        ]);
    }

    /**
     * Résout programme/niveau/groupe par code (Phase 2). Toute référence
     * inconnue = erreur bloquante (jamais de rattachement approximatif).
     *
     * @param  array<string, mixed>  $donnees
     * @param  array<int, array<string, mixed>>  $erreurs
     */
    private function resoudreRattachement(array &$donnees, int $faculteId, int $numero, array &$erreurs): bool
    {
        if (! empty($donnees['programme'])) {
            $programme = Programme::where('code', mb_strtoupper(trim((string) $donnees['programme'])))->first();

            if ($programme === null || (int) $programme->faculte_id !== $faculteId) {
                $erreurs[] = ['ligne' => $numero, 'champ' => 'programme', 'message' => "Le programme « {$donnees['programme']} » est introuvable pour cette faculté."];

                return false;
            }

            $donnees['programme_id'] = $programme->id;
        }

        if (! empty($donnees['niveau_ref'])) {
            $niveau = isset($donnees['programme_id'])
                ? Niveau::resoudreDansProgramme($donnees['niveau_ref'], (int) $donnees['programme_id'])
                : Niveau::resoudreCodeUnique($donnees['niveau_ref']);

            if ($niveau === null) {
                $erreurs[] = ['ligne' => $numero, 'champ' => 'niveau_ref', 'message' => "Le niveau « {$donnees['niveau_ref']} » est introuvable."];

                return false;
            }

            $donnees['niveau_id'] = $niveau->id;
        }

        if (! empty($donnees['groupe'])) {
            $groupe = Group::where('code', mb_strtoupper(trim((string) $donnees['groupe'])))->first();

            if ($groupe === null || (int) $groupe->faculte_id !== $faculteId) {
                $erreurs[] = ['ligne' => $numero, 'champ' => 'groupe', 'message' => "Le groupe « {$donnees['groupe']} » est introuvable pour cette faculté."];

                return false;
            }

            $donnees['groupe_id'] = $groupe->id;
        }

        return true;
    }

    /**
     * Normalisation via NormalisationService (formes canoniques
     * partagées : email, téléphone, CIN, noms).
     */
    private function normaliser(array $ligne): array
    {
        $normalisation = app(NormalisationService::class);
        $donnees = [];
        foreach ($ligne as $cle => $valeur) {
            $donnees[$cle] = is_string($valeur) ? trim($valeur) : $valeur;
        }

        $donnees['cin'] = $normalisation->cin($donnees['cin'] ?? null) ?? '';
        $donnees['email'] = $normalisation->email($donnees['email'] ?? null) ?? '';
        $donnees['telephone'] = $normalisation->telephone($donnees['telephone'] ?? null);
        $donnees['nom'] = $normalisation->nomPropre($donnees['nom'] ?? null) ?? $donnees['nom'] ?? '';
        $donnees['prenom'] = $normalisation->nomPropre($donnees['prenom'] ?? null) ?? $donnees['prenom'] ?? '';

        return $donnees;
    }
}
