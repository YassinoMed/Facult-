<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('batiments')) {
            Schema::create('batiments', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50)->unique();
                $table->string('nom', 255);
                $table->foreignId('faculte_id')->nullable()->constrained('facultes')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('batiments');
    }
};
