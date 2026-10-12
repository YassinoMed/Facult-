<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('evaluation_examens') && ! Schema::hasColumn('evaluation_examens', 'code')) {
            Schema::table('evaluation_examens', function (Blueprint $table) {
                $table->string('code', 50)->nullable()->index()->after('id');
            });
        }

        if (Schema::hasTable('evaluation_epreuves') && ! Schema::hasColumn('evaluation_epreuves', 'code')) {
            Schema::table('evaluation_epreuves', function (Blueprint $table) {
                $table->string('code', 50)->nullable()->index()->after('id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('evaluation_examens') && Schema::hasColumn('evaluation_examens', 'code')) {
            Schema::table('evaluation_examens', function (Blueprint $table) {
                $table->dropColumn('code');
            });
        }

        if (Schema::hasTable('evaluation_epreuves') && Schema::hasColumn('evaluation_epreuves', 'code')) {
            Schema::table('evaluation_epreuves', function (Blueprint $table) {
                $table->dropColumn('code');
            });
        }
    }
};
