<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_templates', function (Blueprint $table) {
            $table->foreignId('faculte_id')->nullable()->after('is_active')->constrained('facultes')->nullOnDelete();
            $table->index(['faculte_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_templates', function (Blueprint $table) {
            $table->dropForeign(['faculte_id']);
            $table->dropIndex(['faculte_id', 'is_active']);
            $table->dropColumn('faculte_id');
        });
    }
};
