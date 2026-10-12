<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('enseignements') && ! Schema::hasColumn('enseignements', 'code')) {
            Schema::table('enseignements', function (Blueprint $table) {
                $table->string('code', 50)->nullable()->unique()->after('id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('enseignements') && Schema::hasColumn('enseignements', 'code')) {
            Schema::table('enseignements', function (Blueprint $table) {
                $table->dropColumn('code');
            });
        }
    }
};
