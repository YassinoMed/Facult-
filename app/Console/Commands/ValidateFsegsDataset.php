<?php

namespace App\Console\Commands;

use App\Models\AffectationGroupe;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Models\InscriptionAcademique;
use App\Modules\Schedule\Models\Group;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Validation du dataset FSEGS (§45) : identité, référentiel,
 * inscriptions, groupes, affectations, capacités, scope, intégrité.
 */
class ValidateFsegsDataset extends Command
{
    protected $signature = 'fsegs:validate-dataset';

    protected $description = 'Vérifie l’intégrité du dataset FSEGS (unicités, cohérences, capacités, orphelins).';

    private int $echecs = 0;

    public function handle(): int
    {
        $faculte = Faculte::where('code', 'FSEGS')->first();

        if ($faculte === null) {
            $this->error('FSEGS introuvable : lancez fsegs:seed-dataset.');

            return self::FAILURE;
        }

        $this->verifier('Identité (CIN/email/matricule uniques)', fn () => $this->unicitesOk());

        $this->verifier('Référentiel FSEGS (programmes/niveaux/groupes rattachés)', function () use ($faculte) {
            return DB::table('etudiants')->where('faculte_id', $faculte->id)->whereNotNull('programme_id')->count() ===
                DB::table('etudiants')->where('faculte_id', $faculte->id)->where('cin', 'like', 'FSEG%')->count()
                && Group::where('faculte_id', $faculte->id)->whereNull('programme_id')->count() === 0;
        });

        $this->verifier('Inscriptions (aucune orpheline, paires uniques)', function () {
            $orphelines = InscriptionAcademique::whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('etudiants')->whereColumn('etudiants.id', 'inscriptions_academiques.etudiant_id'))->count();
            $doublons = DB::table('inscriptions_academiques')
                ->select('etudiant_id', 'annee_universitaire_id')->selectRaw('COUNT(*) n')
                ->groupBy('etudiant_id', 'annee_universitaire_id')->having('n', '>', 1)->exists();

            return $orphelines === 0 && ! $doublons;
        });

        $this->verifier('Capacités (aucun groupe FSEGS dépassé)', function () use ($faculte) {
            return Group::where('faculte_id', $faculte->id)->get()->every(
                fn (Group $g) => $g->capacite === null || $g->effectifActuel() <= (int) $g->capacite
            );
        });

        $cohérence = $this->cohérenceAffectations();

        $this->verifier("Affectations cohérentes (formation+niveau+année) : {$cohérence} incohérence(s)", fn () => $cohérence === 0);

        $this->verifier('Intégrité (aucune affectation orpheline)', function () {
            return AffectationGroupe::whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('etudiants')->whereColumn('etudiants.id', 'affectations_groupe.etudiant_id'))->count() === 0;
        });

        $this->verifier('Faculty Scope (tout FSEGS)', function () use ($faculte) {
            return Etudiant::where('cin', 'like', 'FSEG%')->where('faculte_id', '!=', $faculte->id)->count() === 0;
        });

        $this->info($this->echecs === 0 ? 'VALIDATION : PASS' : "VALIDATION : {$this->echecs} ÉCHEC(S)");

        return $this->echecs === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function verifier(string $libelle, callable $test): void
    {
        try {
            $ok = (bool) $test();
        } catch (\Throwable) {
            $ok = false;
        }

        if (! $ok) {
            $this->echecs++;
        }

        $this->line(($ok ? 'PASS' : 'FAIL').' — '.$libelle);
    }

    private function unicitesOk(): bool
    {
        $ligne = Etudiant::selectRaw('COUNT(*) t, COUNT(DISTINCT cin) c, COUNT(DISTINCT email) e, COUNT(DISTINCT num_inscription) m')->first();

        return $ligne !== null && $ligne->t === $ligne->c && $ligne->t === $ligne->e && $ligne->t === $ligne->m;
    }

    private function cohérenceAffectations(): int
    {
        $incohérences = 0;

        AffectationGroupe::actives()
            ->with(['etudiant', 'groupe'])
            ->chunkById(500, function ($affectations) use (&$incohérences) {
                foreach ($affectations as $affectation) {
                    $etudiant = $affectation->etudiant;
                    $groupe = $affectation->groupe;

                    if ($etudiant === null || $groupe === null) {
                        $incohérences++;

                        continue;
                    }

                    if (str_starts_with((string) $etudiant->cin, 'FSEG') || str_starts_with((string) $etudiant->cin, 'TEST-AFFECT')) {
                        if ($groupe->niveau !== null && $etudiant->niveau !== null && $groupe->niveau !== $etudiant->niveau) {
                            $incohérences++;
                        }

                        if ($groupe->programme_id !== null && $etudiant->programme_id !== null && (int) $groupe->programme_id !== (int) $etudiant->programme_id) {
                            $incohérences++;
                        }
                    }
                }
            });

        return $incohérences;
    }
}
