<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            if (! Schema::hasColumn('teachers', 'charge_statutaire')) {
                $table->decimal('charge_statutaire', 5, 2)->nullable()->default(192.00)->after('statut');
            }
            if (! Schema::hasColumn('teachers', 'charge_hebdo_max')) {
                $table->decimal('charge_hebdo_max', 4, 2)->nullable()->default(18.00)->after('charge_statutaire');
            }
            if (! Schema::hasColumn('teachers', 'charge_hebdo_min')) {
                $table->decimal('charge_hebdo_min', 4, 2)->nullable()->default(4.00)->after('charge_hebdo_max');
            }
            if (! Schema::hasColumn('teachers', 'qualifications')) {
                $table->json('qualifications')->nullable()->after('charge_hebdo_min');
            }

            $table->index(['actif', 'charge_statutaire'], 'idx_teachers_charge');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropIndex('idx_teachers_charge');
            $table->dropColumn([
                'charge_statutaire',
                'charge_hebdo_max',
                'charge_hebdo_min',
                'qualifications',
            ]);
        });
    }
};
