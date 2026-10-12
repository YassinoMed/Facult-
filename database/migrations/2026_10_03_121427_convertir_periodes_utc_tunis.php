<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lot 3 — les bornes saisies en heure locale (Tunis, UTC+1 toute
 * l'année, sans DST depuis 2009) étaient stockées telles quelles ;
 * elles sont désormais interprétées Tunis et stockées UTC.
 * Hypothèse de lecture : anciennes valeurs = heure locale de Tunis.
 * Boucle PHP portable (sqlite + mysql), idempotente par migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->decaler(-1);
    }

    public function down(): void
    {
        $this->decaler(1);
    }

    private function decaler(int $heures): void
    {
        foreach (DB::table('registration_periods')->select('id', 'opened_at', 'closed_at')->cursor() as $ligne) {
            DB::table('registration_periods')->where('id', $ligne->id)->update([
                'opened_at' => Carbon::parse($ligne->opened_at)->addHours($heures),
                'closed_at' => Carbon::parse($ligne->closed_at)->addHours($heures),
            ]);
        }
    }
};
