<?php

namespace App\Services\Io;

use App\Models\Matiere;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Import du catalogue des matières — schéma = modèle officiel §117 :
 * code, nom, coefficient, credits, niveau, semestre.
 * Idempotence : code unique — doublon ignoré, jamais recréé.
 */
class SubjectsImporter
{
    private const REGLES = [
        'code' => ['required', 'regex:/^[A-Z0-9]{2,20}$/'],
        'nom' => ['required', 'string', 'max:150'],
        'coefficient' => ['required', 'numeric', 'gt:0', 'max:99'],
        'credits' => ['required', 'integer', 'min:0', 'max:60'],
        'niveau' => ['required', 'in:L1,L2,L3,M1,M2'],
        'semestre' => ['required', 'integer', 'in:1,2'],
    ];

    public function valider(array $lignes): ImportPreview
    {
        $valides = [];
        $erreurs = [];
        $doublons = [];
        $vus = [];
        $existants = Matiere::pluck('id', 'code');

        foreach ($lignes as $index => $ligne) {
            $numero = $index + 2;
            $donnees = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $ligne);
            $donnees['code'] = mb_strtoupper($donnees['code'] ?? '');

            $validateur = Validator::make($donnees, self::REGLES);
            if ($validateur->fails()) {
                foreach ($validateur->errors()->messages() as $champ => $messages) {
                    $erreurs[] = ['ligne' => $numero, 'champ' => $champ, 'message' => $messages[0], 'valeur' => (string) ($donnees[$champ] ?? '')];
                }

                continue;
            }

            if ($existants->has($donnees['code'])) {
                $doublons[] = "Ligne {$numero} : le code « {$donnees['code']} } » existe déjà — ignoré.";

                continue;
            }
            if (in_array($donnees['code'], $vus, true)) {
                $doublons[] = "Ligne {$numero} : doublon interne au fichier ({$donnees['code']}).";

                continue;
            }
            $vus[] = $donnees['code'];
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
                    Matiere::create([
                        'code' => $ligne['code'],
                        'nom' => $ligne['nom'],
                        'coefficient' => $ligne['coefficient'],
                        'credits' => $ligne['credits'],
                        'niveau' => $ligne['niveau'],
                        'filiere' => null,
                        'semestre' => $ligne['semestre'],
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
