<?php

namespace App\Services\Io;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use App\Models\Semestre;
use App\Services\AcademicCalculationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Import des résultats officiels par semestre — schéma §117 :
 * matricule, annee_universitaire, semestre, moyenne, credits_obtenus,
 * credits_total, decision.
 * Met à jour le semestre concerné puis RECALCULE l'inscription via le
 * service officiel (les données importées remplacent les valeurs
 * recalculées localement — usage : reprise d'un système externe).
 */
class ResultsImporter
{
    public function __construct(private readonly AcademicCalculationService $calculs) {}

    private const REGLES = [
        'matricule' => ['required', 'regex:/^MAT-[0-9A-Z\-]{3,30}$/'],
        'annee_universitaire' => ['required', 'regex:/^\d{4}-\d{4}$/'],
        'semestre' => ['required', 'integer', 'in:1,2'],
        'moyenne' => ['required', 'numeric', 'min:0', 'max:20'],
        'credits_obtenus' => ['required', 'integer', 'min:0', 'max:999'],
        'credits_total' => ['required', 'integer', 'min:0', 'max:999'],
        'decision' => ['required', 'in:Validé,Rattrapage,Non validé'],
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
        $inscriptions = InscriptionAcademique::with('semestres')->get();
        $semestres = Semestre::get();

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
                $erreurs[] = ['ligne' => $numero, 'champ' => 'annee_universitaire', 'message' => "L'année « {$donnees['annee_universitaire']} » est introuvable.", 'valeur' => $donnees['annee_universitaire']];

                continue;
            }

            $inscription = $inscriptions->first(fn ($i) => $i->etudiant_id === $etudiant->id
                && (int) $i->annee_universitaire_id === (int) $anneeId);
            if ($inscription === null) {
                $erreurs[] = ['ligne' => $numero, 'champ' => 'matricule', 'message' => "Aucune inscription de {$donnees['matricule']} en {$donnees['annee_universitaire']}.", 'valeur' => $donnees['matricule']];

                continue;
            }

            $semestre = $semestres->first(fn ($s) => $s->inscription_academique_id === $inscription->id
                && (int) $s->numero === (int) $donnees['semestre']);
            if ($semestre === null) {
                $erreurs[] = ['ligne' => $numero, 'champ' => 'semestre', 'message' => "Semestre S{$donnees['semestre']} introuvable pour cette inscription.", 'valeur' => $donnees['semestre']];

                continue;
            }

            $cle = $semestre->id;
            if (in_array($cle, $vus, true)) {
                $doublons[] = "Ligne {$numero} : résultat du semestre déjà fourni dans ce fichier — ignoré.";

                continue;
            }
            $vus[] = $cle;

            $donnees['_semestre_id'] = $semestre->id;
            $donnees['_inscription_id'] = $inscription->id;
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

        DB::transaction(function () use ($preview, &$importes) {
            $inscriptions = [];

            foreach ($preview->lignesValides as $ligne) {
                // Les résultats importés sont OFFICIELS (§ results : reprise d'un
                // système externe) : ils remplacent toute valeur recalculée.
                Semestre::whereKey($ligne['_semestre_id'])->update([
                    'moyenne' => $ligne['moyenne'],
                    'credits_obtenus' => $ligne['credits_obtenus'],
                    'credits_total' => $ligne['credits_total'],
                    'decision' => $ligne['decision'],
                ]);
                $inscriptions[$ligne['_inscription_id']] = true;
                $importes++;
            }

            // Agrégation de l'année à partir des valeurs semestrielles OFFICIELLES
            // (sans recalculer les semestres depuis les notes — on ne les écrase pas)
            foreach (array_keys($inscriptions) as $inscriptionId) {
                $inscription = InscriptionAcademique::findOrFail($inscriptionId);
                $semestres = $inscription->semestres()->get();
                $notees = $semestres->filter(fn ($s) => $s->moyenne !== null);

                $moyenneAnnuelle = $notees->isNotEmpty()
                    ? $this->calculs->moyennePonderee($notees->map(fn ($s) => [
                        'note' => (float) $s->moyenne,
                        'coefficient' => max(1, $s->credits_total),
                        'credits' => $s->credits_obtenus,
                    ]))
                    : null;

                $inscription->update([
                    'moyenne_annuelle' => $moyenneAnnuelle,
                    'credits_obtenus' => (int) $semestres->sum('credits_obtenus'),
                    'credits_total' => (int) $semestres->sum('credits_total'),
                    'decision' => $moyenneAnnuelle !== null
                        ? $this->calculs->decisionAnnuelle($moyenneAnnuelle, (int) $semestres->sum('credits_obtenus'), (int) $semestres->sum('credits_total'))
                        : null,
                ]);
            }
        });

        return ['importes' => $importes, 'ignores' => $ignores, 'en_erreur' => count($erreurs), 'erreurs' => $erreurs];
    }
}
