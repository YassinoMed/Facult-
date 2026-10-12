<?php

namespace Database\Seeders;

use App\Models\AnneeUniversitaire;
use App\Models\Etudiant;
use App\Models\Faculte;
use App\Modules\Schedule\Models\Group;
use App\Modules\Schedule\Models\Room;
use App\Modules\Schedule\Models\Teacher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Volumes de démonstration (Phase 7 §Qualité) : ~2 000 étudiants,
 * ~100 enseignants, ~60 salles, ~150 groupes. Local/testing uniquement,
 * idempotent (CIN/matricule/code préfixés VOL-), mot de passe unique
 * pré-haché (jamais 2 000 bcrypt).
 */
class VolumeSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('VolumeSeeder ignoré (environnement non local).');

            return;
        }

        $facultes = Faculte::where('active', true)->orderBy('id')->get();

        if ($facultes->isEmpty()) {
            $this->command?->warn('VolumeSeeder : aucune faculté active.');

            return;
        }

        $hash = Hash::make('Etudiant@123');
        $maintenant = now();

        // ── 2 000 étudiants (insert par lots, CIN déterministes) ──
        // Idempotent : aucun étudiant au-delà du plafond VOL-.
        $existants = Etudiant::where('cin', 'like', 'VOL%')->count();
        $aCreer = max(0, 2000 - $existants);

        for ($lot = 0; $lot * 500 < $aCreer; $lot++) {
            $lignes = [];
            $debut = $existants + $lot * 500 + 1;
            $fin = min($debut + 499, $existants + $aCreer);

            for ($n = $debut; $n <= $fin; $n++) {
                $faculte = $facultes[($n - 1) % $facultes->count()];

                $lignes[] = [
                    'cin' => sprintf('VOL%06d', $n),
                    'nom' => 'VolumeNom'.$n,
                    'prenom' => 'VolumePrenom'.$n,
                    'email' => sprintf('vol%06d@example.test', $n),
                    'telephone' => sprintf('+2126%08d', $n),
                    'date_naissance' => '2003-05-17',
                    'sexe' => $n % 2 === 0 ? 'F' : 'M',
                    'faculte_id' => $faculte->id,
                    'niveau' => ['L1', 'L2', 'L3'][($n - 1) % 3],
                    'annee_academique' => '2026-2027',
                    'adresse' => 'Rue volume '.$n,
                    'ville' => 'Casablanca',
                    'code_postal' => '20000',
                    'annee_bac' => (string) (2022 + ($n % 3)),
                    'password' => $hash,
                    'statut' => 'valide',
                    'num_inscription' => sprintf('VOL-2026-%05d', $n),
                    'created_at' => $maintenant,
                    'updated_at' => $maintenant,
                ];
            }

            $doublons = Etudiant::whereIn('cin', array_column($lignes, 'cin'))->pluck('cin')->all();

            if ($doublons !== []) {
                $lignes = array_values(array_filter(
                    $lignes, fn ($l) => ! in_array($l['cin'], $doublons, true)
                ));
            }

            if ($lignes !== []) {
                DB::table('etudiants')->insert($lignes);
            }
        }

        // Inscriptions académiques pour l'année universitaire active
        $annee = AnneeUniversitaire::where('libelle', '2026-2027')->orWhere('active', true)->first();
        if ($annee) {
            $volEtudiants = Etudiant::where('cin', 'like', 'VOL%')
                ->whereDoesntHave('inscriptionsAcademiques', fn ($q) => $q->where('annee_universitaire_id', $annee->id))
                ->get();

            if ($volEtudiants->isNotEmpty()) {
                $inscriptionsLignes = [];
                $etudiantParId = [];
                foreach ($volEtudiants as $e) {
                    $etudiantParId[$e->id] = $e;
                    $inscriptionsLignes[] = [
                        'etudiant_id' => $e->id,
                        'annee_universitaire_id' => $annee->id,
                        'programme_id' => $e->programme_id,
                        'niveau_id' => $e->niveau_id,
                        'niveau' => $e->niveau ?: 'L1',
                        'filiere' => $e->filiere,
                        'statut' => 'valide',
                        'type' => 'premiere',
                        'regime' => 'initiale',
                        'created_at' => $maintenant,
                        'updated_at' => $maintenant,
                    ];
                }

                foreach (array_chunk($inscriptionsLignes, 500) as $chunk) {
                    DB::table('inscriptions_academiques')->insert($chunk);
                }

                $createdIds = DB::table('inscriptions_academiques')
                    ->where('annee_universitaire_id', $annee->id)
                    ->whereIn('etudiant_id', array_keys($etudiantParId))
                    ->pluck('id');

                $semestresLignes = [];
                foreach ($createdIds as $inscrId) {
                    $semestresLignes[] = [
                        'inscription_academique_id' => $inscrId,
                        'numero' => 1,
                        'created_at' => $maintenant,
                        'updated_at' => $maintenant,
                    ];
                    $semestresLignes[] = [
                        'inscription_academique_id' => $inscrId,
                        'numero' => 2,
                        'created_at' => $maintenant,
                        'updated_at' => $maintenant,
                    ];
                }

                foreach (array_chunk($semestresLignes, 1000) as $chunk) {
                    DB::table('semestres')->insert($chunk);
                }
            }
        }

        // ── 100 enseignants ──
        for ($i = 1; $i <= 100; $i++) {
            Teacher::firstOrCreate(
                ['matricule' => sprintf('VENS%04d', $i)],
                [
                    'nom' => 'EnseignantVol'.$i, 'prenom' => 'Prof',
                    'email' => sprintf('vens%04d@example.test', $i),
                    'actif' => true, 'statut' => 'actif',
                ],
            );
        }

        // ── 60 salles (réparties sur les facultés) ──
        for ($i = 1; $i <= 60; $i++) {
            $faculte = $facultes[($i - 1) % $facultes->count()];

            Room::firstOrCreate(
                ['code' => sprintf('VS%03d', $i)],
                [
                    'nom' => 'Salle volume '.$i, 'capacite' => 20 + (($i * 7) % 60),
                    'actif' => true, 'faculte_id' => $faculte->id,
                ],
            );
        }

        // ── 150 groupes ──
        for ($i = 1; $i <= 150; $i++) {
            $faculte = $facultes[($i - 1) % $facultes->count()];

            Group::firstOrCreate(
                ['code' => sprintf('VG%03d', $i)],
                [
                    'nom' => 'Groupe volume '.$i, 'faculte_id' => $faculte->id,
                    'niveau' => ['L1', 'L2', 'L3'][($i - 1) % 3],
                    'capacite' => 30, 'actif' => true,
                ],
            );
        }

        $this->command?->info('VolumeSeeder : volumes de démonstration prêts.');
    }
}
