<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('evaluation_deliberations') && ! Schema::hasColumn('evaluation_deliberations', 'code')) {
            Schema::table('evaluation_deliberations', function (Blueprint $table) {
                $table->string('code', 50)->nullable()->index()->after('id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('evaluation_deliberations') && Schema::hasColumn('evaluation_deliberations', 'code')) {
            Schema::table('evaluation_deliberations', function (Blueprint $table) {
                $table->dropColumn('code');
            });
        }
    }
};
