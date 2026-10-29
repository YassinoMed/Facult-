<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R1 (LOOP-ADMISSIONS) : garde d'unicité des actes ACTIFS au niveau base.
 *
 * `verrou_actif` vaut "etudiant-programme-annee" pour un acte actif
 * (non refusé, non consommé), NULL sinon. Les NULL multiples sont admis
 * par l'index unique (MySQL comme SQLite) : l'historique (refus,
 * consommés) coexiste, un seul actif est possible. La garde applicative
 * (`lockForUpdate`) reste première ; la contrainte tranche les courses
 * d'INSERT simultanés de façon déterministe.
 *
 * Précondition prod : aucun doublon actif existant (sinon la création de
 * l'index échoue explicitement — investiguer avant de forcer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->string('verrou_actif', 64)->nullable()->after('consumed_inscription_id');
        });

        $concat = DB::getDriverName() === 'mysql'
            ? "CONCAT(etudiant_id, '-', programme_id, '-', annee_universitaire_id)"
            : "etudiant_id || '-' || programme_id || '-' || annee_universitaire_id";

        DB::table('admissions')
            ->whereNull('consumed_at')
            ->where('decision', '!=', 'refuse')
            ->update(['verrou_actif' => DB::raw($concat)]);

        Schema::table('admissions', function (Blueprint $table): void {
            $table->unique('verrou_actif', 'uq_admissions_verrou_actif');
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->dropUnique('uq_admissions_verrou_actif');
            $table->dropColumn('verrou_actif');
        });
    }
};
