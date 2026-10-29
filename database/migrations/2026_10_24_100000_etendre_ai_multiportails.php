<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('ai_conversations')) {
            Schema::table('ai_conversations', function (Blueprint $table) {
                if (! Schema::hasColumn('ai_conversations', 'etudiant_id')) {
                    $table->foreignId('etudiant_id')->nullable()->after('user_id')->constrained('etudiants')->cascadeOnDelete();
                }
                if (! Schema::hasColumn('ai_conversations', 'teacher_id')) {
                    $table->foreignId('teacher_id')->nullable()->after('etudiant_id')->constrained('teachers')->cascadeOnDelete();
                }
                if (! Schema::hasColumn('ai_conversations', 'portail')) {
                    $table->string('portail', 30)->default('admin')->after('faculte_id')->index();
                }
            });

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE ai_conversations MODIFY user_id BIGINT UNSIGNED NULL');
            }
        }

        if (Schema::hasTable('ai_propositions_actions')) {
            Schema::table('ai_propositions_actions', function (Blueprint $table) {
                if (! Schema::hasColumn('ai_propositions_actions', 'etudiant_id')) {
                    $table->foreignId('etudiant_id')->nullable()->after('user_id')->constrained('etudiants')->cascadeOnDelete();
                }
                if (! Schema::hasColumn('ai_propositions_actions', 'teacher_id')) {
                    $table->foreignId('teacher_id')->nullable()->after('etudiant_id')->constrained('teachers')->cascadeOnDelete();
                }
                if (! Schema::hasColumn('ai_propositions_actions', 'portail')) {
                    $table->string('portail', 30)->default('admin')->after('faculte_id')->index();
                }
            });

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE ai_propositions_actions MODIFY user_id BIGINT UNSIGNED NULL');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ai_propositions_actions')) {
            Schema::table('ai_propositions_actions', function (Blueprint $table) {
                if (Schema::hasColumn('ai_propositions_actions', 'portail')) {
                    $table->dropColumn('portail');
                }
                if (Schema::hasColumn('ai_propositions_actions', 'teacher_id')) {
                    $table->dropConstrainedForeignId('teacher_id');
                }
                if (Schema::hasColumn('ai_propositions_actions', 'etudiant_id')) {
                    $table->dropConstrainedForeignId('etudiant_id');
                }
            });
        }

        if (Schema::hasTable('ai_conversations')) {
            Schema::table('ai_conversations', function (Blueprint $table) {
                if (Schema::hasColumn('ai_conversations', 'portail')) {
                    $table->dropColumn('portail');
                }
                if (Schema::hasColumn('ai_conversations', 'teacher_id')) {
                    $table->dropConstrainedForeignId('teacher_id');
                }
                if (Schema::hasColumn('ai_conversations', 'etudiant_id')) {
                    $table->dropConstrainedForeignId('etudiant_id');
                }
            });
        }
    }
};
