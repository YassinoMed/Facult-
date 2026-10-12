<?php

namespace Database\Seeders;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\InscriptionAcademique;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Continuité des inscriptions (données démo) : tout dossier `valide`
 * de niveau couvert par des groupes (L1/L2/L3/M1/M2), SANS inscription
 * 2026-2027, reçoit une inscription 2026-2027 `en_cours` — au même
 * niveau que la dernière inscription valide (`reinscription`), ou au
 * niveau du dossier s'il n'a jamais été inscrit (`premiere`).
 * Aucune décision/progression inventée.
 *
 * Idempotent (firstOrCreate sur [etudiant, annee]). Exclus : dossiers
 * rejetés, D1 (sans groupe), CIN `TEST-*` (cas volontaires). Les
 * suspensions existantes sont conservées (blocage à l'affectation).
 */
class ContinuiteInscriptionsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('ContinuiteInscriptionsSeeder ignoré (environnement non local).');

            return;
        }

        $annee = AnneeUniversitaire::where('libelle', '2026-2027')->firstOrFail();
        $creees = 0;

        $candidats = Etudiant::where('statut', 'valide')
            ->where('cin', 'not like', 'TEST-%')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('inscriptions_academiques')
                ->whereColumn('inscriptions_academiques.etudiant_id', 'etudiants.id')
                ->where('annee_universitaire_id', $annee->id))
            ->whereIn('niveau', ['L1', 'L2', 'L3', 'M1', 'M2'])
            ->with(['inscriptionsAcademiques' => fn ($q) => $q->orderByDesc('annee_universitaire_id')])
            ->get();

        foreach ($candidats as $etudiant) {
            $derniere = $etudiant->inscriptionsAcademiques->first();
            $sansHistorique = $derniere === null;

            if (! $sansHistorique && ! in_array($derniere->statut, ['valide', 'en_cours'], true)) {
                continue;
            }

            InscriptionAcademique::firstOrCreate(
                ['etudiant_id' => $etudiant->id, 'annee_universitaire_id' => $annee->id],
                [
                    'programme_id' => $derniere?->programme_id,
                    'niveau_id' => $derniere?->niveau_id,
                    'niveau' => $etudiant->niveau,
                    'filiere' => $derniere?->filiere,
                    'statut' => 'en_cours',
                    'type' => $sansHistorique ? 'premiere' : 'reinscription',
                    'regime' => $derniere?->regime ?? 'initiale',
                ]
            );
            $creees++;
        }

        $this->command?->info("ContinuiteInscriptionsSeeder : {$creees} inscription(s) 2026-2027 créée(s).");
    }
}
