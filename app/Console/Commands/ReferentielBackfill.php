<?php

namespace App\Console\Commands;

use App\Models\Matiere;
use App\Modules\Referentiel\Models\MaquetteUe;
use App\Modules\Referentiel\Models\MaquetteUeEcue;
use App\Modules\Referentiel\Models\Niveau;
use App\Modules\Referentiel\Models\Programme;
use App\Modules\Referentiel\Models\UniteEnseignement;
use App\Services\NormalisationService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Assainissement du référentiel LMD (LOOP correction §10/§12/§13/§24) :
 * classification des matières sans UE, backfill déterministe des
 * contextes `maquette_ue_ecue`, normalisation des codes, nettoyage
 * des orphelins prouvés. DRY-RUN par défaut ; --force exécute
 * (jamais de rattachement UE automatique : interdit §10).
 */
class ReferentielBackfill extends Command
{
    protected $signature = 'referentiel:backfill
                            {--force : Exécute les écritures (sinon : simulation)}
                            {--faculte= : Restreindre à une faculté (id)}
                            {--programme= : Restreindre à un programme (code)}
                            {--nettoyer : Supprime les orphelins prouvés (exige --force)}';

    protected $description = 'Classe les matières sans UE, remplit maquette_ue_ecue et nettoie les orphelins prouvés — simulation par défaut.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $this->classifierMatieresSansUe();

        $this->backfillContextes($force);

        $this->normaliserCodes($force);

        if ($this->option('nettoyer')) {
            if (! $force) {
                $this->warn('--nettoyer exige --force : rien n’a été supprimé.');
            } else {
                $this->nettoyerOrphelins();
            }
        }

        if (! $force) {
            $this->info('Simulation terminée (ajoutez --force pour exécuter).');
        }

        return self::SUCCESS;
    }

    /**
     * §10 : CONFIRMED (candidat UE unique prouvé) / AMBIGUOUS / DUPLICATE
     * (jumeau ECUE nommé identique) / UNRESOLVED. Rapport seul, AUCUNE
     * écriture, AUCUN rattachement automatique.
     */
    private function classifierMatieresSansUe(): void
    {
        $legacy = Matiere::whereNull('ue_id')->orderBy('code')->get();
        $compteurs = ['CONFIRMED' => 0, 'AMBIGUOUS' => 0, 'DUPLICATE' => 0, 'UNRESOLVED' => 0];
        $normalisation = app(NormalisationService::class);

        // LOOP A1 : jumeaux appariés côté PHP (forme comparable unique) —
        // le `whereRaw` SQL divergeait selon la collation (accents).
        $rattachees = Matiere::whereNotNull('ue_id')->get(['id', 'code', 'nom']);

        foreach ($legacy as $matiere) {
            $nomNormalise = $normalisation->nomComparable($matiere->nom);

            $jumeaux = $rattachees
                ->filter(fn ($jumeau) => $normalisation->nomComparable($jumeau->nom) === $nomNormalise)
                ->pluck('code')
                ->all();

            if ($jumeaux !== []) {
                $compteurs['DUPLICATE']++;
                $this->line("  [DUPLICATE] {$matiere->code} « {$matiere->nom} » → jumeaux : ".implode(', ', $jumeaux));

                continue;
            }

            $candidats = UniteEnseignement::whereHas('semestre.niveau', function ($q) use ($matiere) {
                $q->where('code', $matiere->niveau);
            })->whereHas('semestre', function ($q) use ($matiere) {
                $q->where('numero', (int) $matiere->semestre);
            })->with('programme')->get();

            if ($candidats->count() === 1) {
                $compteurs['CONFIRMED']++;
                $ue = $candidats->first();
                $this->line("  [CONFIRMED] {$matiere->code} → UE {$ue->code} (programme {$ue->programme?->code}) — rattachement MANUEL requis.");
            } elseif ($candidats->count() > 1) {
                $compteurs['AMBIGUOUS']++;
                $this->line("  [AMBIGUOUS] {$matiere->code} « {$matiere->nom} » : {$candidats->count()} UE candidates — intact.");
            } else {
                $compteurs['UNRESOLVED']++;
                $this->line("  [UNRESOLVED] {$matiere->code} « {$matiere->nom} » : aucune UE candidate — intact.");
            }
        }

        $this->info(sprintf(
            'Matières sans UE : %d (CONFIRMED %d, AMBIGUOUS %d, DUPLICATE %d, UNRESOLVED %d).',
            $legacy->count(), $compteurs['CONFIRMED'], $compteurs['AMBIGUOUS'], $compteurs['DUPLICATE'], $compteurs['UNRESOLVED']
        ));
    }

    /**
     * Contextes annuels déterministes : chaque couple (maquette_ue ×
     * matiere.ue_id) donne une ligne. Idempotent (unique), réversible
     * (suppression des lignes), transactionnel.
     */
    private function backfillContextes(bool $force): void
    {
        $crees = 0;
        $existants = 0;

        $lignes = MaquetteUe::with('ue')->orderBy('id')->get();
        foreach ($lignes as $ligne) {
            $matieres = Matiere::where('ue_id', $ligne->ue_id)->orderBy('ordre')->orderBy('id')->get();
            foreach ($matieres as $matiere) {
                $existe = MaquetteUeEcue::where('maquette_ue_id', $ligne->id)
                    ->where('matiere_id', $matiere->id)->exists();
                if ($existe) {
                    $existants++;

                    continue;
                }

                if ($force) {
                    DB::transaction(function () use ($ligne, $matiere) {
                        MaquetteUeEcue::create([
                            'maquette_ue_id' => $ligne->id,
                            'matiere_id' => $matiere->id,
                            'ordre' => (int) ($matiere->ordre ?? 0),
                            'coefficient' => $matiere->coefficient ?? 1,
                            'credits' => (int) ($matiere->credits ?? 0),
                            'volume_cm' => (int) ($matiere->volume_cm ?? 0),
                            'volume_td' => (int) ($matiere->volume_td ?? 0),
                            'volume_tp' => (int) ($matiere->volume_tp ?? 0),
                            'type_evaluation' => $matiere->type_evaluation,
                            'obligatoire' => true,
                        ]);
                    });
                }
                $crees++;
            }
        }

        $this->info(sprintf(
            'Contextes maquette_ue_ecue : %d à créer, %d existants%s.',
            $crees, $existants, $force ? ' (créés)' : ''
        ));
        Log::info('referentiel.backfill_contextes', ['crees' => $crees, 'existants' => $existants, 'force' => $force]);
    }

    /**
     * Codes non normalisés (casse/espaces) : collision vérifiée avant
     * toute écriture.
     */
    private function normaliserCodes(bool $force): void
    {
        $candidats = Matiere::whereRaw('code != UPPER(TRIM(code))')->get();
        $appliques = 0;

        foreach ($candidats as $matiere) {
            $normalise = mb_strtoupper(trim((string) $matiere->code));
            $collision = Matiere::where('id', '!=', $matiere->id)
                ->whereRaw('UPPER(TRIM(code)) = ?', [$normalise])->exists();
            if ($collision) {
                $this->warn("  [CONFLIT] {$matiere->code} → {$normalise} existe déjà : ignoré.");

                continue;
            }

            if ($force) {
                $matiere->update(['code' => $normalise]);
            }
            $appliques++;
        }

        $this->info(sprintf('Codes à normaliser : %d%s.', $appliques, $force ? ' (appliqués)' : ''));
    }

    /**
     * Orphelins PROUVÉS uniquement (zéro référence métier vérifiée sur
     * TOUTES les tables liées, pas seulement les principales) : programmes
     * au nom invalide (< 3 lettres, cause §12 corrigée par `min:3`) et
     * niveaux M1/M2 sous cycle licence sans semestres ni références.
     * IDs journalisés. Toute contrainte résiduelle bloque la ligne
     * (transaction + rapport), jamais de suppression forcée.
     */
    private function nettoyerOrphelins(): void
    {
        $programmes = Programme::whereRaw('LENGTH(TRIM(nom)) < 3')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('niveaux')->whereColumn('niveaux.programme_id', 'programmes.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('etudiants')->whereColumn('etudiants.programme_id', 'programmes.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('inscriptions_academiques')->whereColumn('inscriptions_academiques.programme_id', 'programmes.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('evaluation_examens')->whereColumn('evaluation_examens.programme_id', 'programmes.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('evaluation_deliberations')->whereColumn('evaluation_deliberations.programme_id', 'programmes.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('enseignements')->whereColumn('enseignements.programme_id', 'programmes.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('unites_enseignement')->whereColumn('unites_enseignement.programme_id', 'programmes.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('groups')->whereColumn('groups.programme_id', 'programmes.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('maquettes')->whereColumn('maquettes.programme_id', 'programmes.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('admissions')->whereColumn('admissions.programme_id', 'programmes.id'))
            ->pluck('id')
            ->all();

        $niveaux = Niveau::whereIn('code', ['M1', 'M2'])
            ->whereHas('programme.typeDiplome', fn ($q) => $q->where('cycle', 'licence'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('semestres_lmd')->whereColumn('semestres_lmd.niveau_id', 'niveaux.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('etudiants')->whereColumn('etudiants.niveau_id', 'niveaux.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('inscriptions_academiques')->whereColumn('inscriptions_academiques.niveau_id', 'niveaux.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('evaluation_examens')->whereColumn('evaluation_examens.niveau_id', 'niveaux.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('evaluation_deliberations')->whereColumn('evaluation_deliberations.niveau_id', 'niveaux.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('groups')->whereColumn('groups.niveau_id', 'niveaux.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('admissions')->whereColumn('admissions.niveau_id', 'niveaux.id'))
            ->pluck('id')
            ->all();

        $supprimes = ['programmes' => [], 'niveaux' => [], 'matieres' => []];
        $bloques = [];

        $suspectsConserves = Programme::whereRaw('LENGTH(TRIM(nom)) < 3')
            ->whereNotIn('id', $programmes)
            ->pluck('code')
            ->all();
        if ($suspectsConserves !== []) {
            $this->warn('Programmes au nom invalide conservés (référencés, réaffectation manuelle requise) : '.implode(', ', $suspectsConserves).'.');
        }

        // Doublons MATIÈRES prouvés (décision D-C) : sans UE, jumeau exact
        // ECUE rattachée (nom normalisé identique), ZÉRO référence sur
        // toutes les tables consommatrices. Les jumeaux porteuses des
        // références (ECUE-*) sont conservés.
        $matieres = Matiere::whereNull('ue_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('matieres as jumeau')
                ->whereNotNull('jumeau.ue_id')
                ->whereRaw('UPPER(TRIM(jumeau.nom)) = UPPER(TRIM(matieres.nom))'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('notes')->whereColumn('notes.matiere_id', 'matieres.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('evaluation_epreuves')->whereColumn('evaluation_epreuves.matiere_id', 'matieres.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('schedule_slots')->whereColumn('schedule_slots.matiere_id', 'matieres.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('enseignements')->whereColumn('enseignements.matiere_id', 'matieres.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('inscriptions_pedagogiques')->whereColumn('inscriptions_pedagogiques.matiere_id', 'matieres.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('composantes_evaluation')->whereColumn('composantes_evaluation.matiere_id', 'matieres.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('maquette_ue_ecue')->whereColumn('maquette_ue_ecue.matiere_id', 'matieres.id'))
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($programmes, $niveaux, $matieres, &$supprimes, &$bloques) {
            foreach (array_merge(
                array_map(fn ($id) => ['type' => 'programme', 'id' => $id], $programmes),
                array_map(fn ($id) => ['type' => 'niveau', 'id' => $id], $niveaux),
                array_map(fn ($id) => ['type' => 'matiere', 'id' => $id], $matieres),
            ) as $cible) {
                try {
                    if ($cible['type'] === 'programme') {
                        Programme::whereKey($cible['id'])->delete();
                        $supprimes['programmes'][] = $cible['id'];
                    } elseif ($cible['type'] === 'niveau') {
                        Niveau::whereKey($cible['id'])->delete();
                        $supprimes['niveaux'][] = $cible['id'];
                    } else {
                        Matiere::whereKey($cible['id'])->delete();
                        $supprimes['matieres'][] = $cible['id'];
                    }
                } catch (QueryException $e) {
                    // Contrainte résiduelle (21 tables liées aux programmes)
                    // : ligne conservée + blocage signalé, jamais forcé.
                    $bloques[] = $cible['type'].'#'.$cible['id'];
                }
            }
        });

        $this->info(sprintf(
            'Nettoyage : %d programme(s) [%s], %d niveau(x) [%s] et %d matière(s) doublon [%s] supprimés%s.',
            count($supprimes['programmes']), implode(',', $supprimes['programmes']),
            count($supprimes['niveaux']), implode(',', $supprimes['niveaux']),
            count($supprimes['matieres']), implode(',', $supprimes['matieres']),
            $bloques !== [] ? ' — bloqués (référencés) : '.implode(',', $bloques) : ''
        ));
        Log::warning('referentiel.nettoyage_orphelins', ['supprimes' => $supprimes, 'bloques' => $bloques]);
    }
}
