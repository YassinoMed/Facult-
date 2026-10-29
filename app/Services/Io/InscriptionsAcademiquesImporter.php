<?php

namespace App\Services\Io;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\Semestre;
use App\Services\InscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Import des inscriptions académiques — schéma = modèle officiel §117 :
 * matricule, annee_universitaire, filiere, niveau, groupe, statut, observation.
 * Crée l'inscription ET ses semestres S1/S2 (cohérence avec ArchiveService).
 * Idempotence : unicité (etudiant, année) — un doublon est ignoré, jamais réimporté.
 */
class InscriptionsAcademiquesImporter
{
    private const REGLES = [
        'matricule' => ['required', 'regex:/^MAT-[0-9A-Z\-]{3,30}$/'],
        'annee_universitaire' => ['required', 'regex:/^\d{4}-\d{4}$/'],
        'niveau' => ['required', 'in:L1,L2,L3,M1,M2,D1,D2,D3'],
        'groupe' => ['nullable', 'string', 'max:20'],
        'statut' => ['required', 'in:en_cours,valide,ajourne,abandon'],
        'observation' => ['nullable', 'string', 'max:1000'],
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
        $existantes = InscriptionAcademique::get()
            ->map(fn ($i) => $i->etudiant_id.'|'.$i->annee_universitaire_id);

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

            $anneeId = $annees[$donnees['annee_universitaire']] ?? null;
            if ($anneeId === null) {
                $erreurs[] = ['ligne' => $numero, 'champ' => 'annee_universitaire', 'message' => "L'année « {$donnees['annee_universitaire']} } » est introuvable.", 'valeur' => $donnees['annee_universitaire']];

                continue;
            }

            if ($existantes->contains($etudiant->id.'|'.$anneeId)) {
                $doublons[] = "Ligne {$numero} : inscription de {$donnees['matricule']} en {$donnees['annee_universitaire']} déjà enregistrée — ignorée.";

                continue;
            }

            $cle = $etudiant->id.'|'.$anneeId;
            if (in_array($cle, $vus, true)) {
                $doublons[] = "Ligne {$numero} : doublon interne au fichier ({$donnees['matricule']} {$donnees['annee_universitaire']}).";

                continue;
            }
            $vus[] = $cle;

            $donnees['_etudiant_id'] = $etudiant->id;
            $donnees['_annee_id'] = $anneeId;
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
        $inscriptions = app(InscriptionService::class);

        DB::transaction(function () use ($preview, $mode, $inscriptions, &$importes, &$ignores, &$erreurs) {
            foreach ($preview->lignesValides as $ligne) {
                try {
                    // Création sous la règle d'unicité annuelle CENTRALISÉE
                    // (InscriptionService) — la garde ou le conflit
                    // concurrent lèvent la même DomainException, traduite
                    // ici en « ligne ignorée » (import semi-automatique).
                    $inscription = $inscriptions->creerInscriptionAnnuelle(
                        (int) $ligne['_etudiant_id'],
                        (int) $ligne['_annee_id'],
                        [
                            'niveau' => $ligne['niveau'],
                            'filiere' => $ligne['filiere'] ?? null,
                            'groupe' => $ligne['groupe'] ?? null,
                            'statut' => $ligne['statut'],
                            'observation' => $ligne['observation'] ?? null,
                        ],
                    );

                    foreach ([1, 2] as $numero) {
                        Semestre::create(['inscription_academique_id' => $inscription->id, 'numero' => $numero]);
                    }
                    $importes++;
                } catch (\DomainException $e) {
                    $ignores++;
                    $erreurs[] = "Ligne {$ligne['matricule']} : {$e->getMessage()} — ignorée.";
                }
            }

            if ($mode === 'atomique' && $importes !== count($preview->lignesValides)) {
                throw new \RuntimeException('Import atomique interrompu.');
            }
        });

        return ['importes' => $importes, 'ignores' => $ignores, 'en_erreur' => count($erreurs), 'erreurs' => $erreurs];
    }
}
