<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module OnlineRequests : catalogue des types de demandes. Le schéma
 * de formulaire dynamique (form_schema) rend le module extensible
 * sans modifier le code pour chaque nouveau type (§17).
 *
 * Données de référence insérées ici (updateOrInsert) pour que le
 * module fonctionne dès `php artisan migrate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('generates_document')->default(false);
            $table->unsignedInteger('processing_days')->default(3);
            $table->json('form_schema')->nullable();
            $table->timestamps();
        });

        $types = [
            [
                'code' => 'ATTESTATION_SCOLARITE',
                'name' => 'Attestation de scolarité',
                'description' => 'Attestation officielle d’inscription pour l’année en cours.',
                'active' => true,
                'requires_attachment' => false,
                'generates_document' => true,
                'processing_days' => 1,
                'form_schema' => null,
            ],
            [
                'code' => 'RELEVE_NOTES',
                'name' => 'Relevé de notes',
                'description' => 'Relevé officiel des notes (données académiques, sans saisie manuelle).',
                'active' => true,
                'requires_attachment' => false,
                'generates_document' => false,
                'processing_days' => 2,
                'form_schema' => json_encode([
                    ['name' => 'annee_universitaire', 'label' => 'Année universitaire', 'type' => 'text', 'required' => true],
                    ['name' => 'semestre', 'label' => 'Semestre', 'type' => 'select', 'options' => ['1', '2'], 'required' => true],
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'code' => 'CHANGEMENT_GROUPE',
                'name' => 'Changement de groupe',
                'description' => 'Demande de changement de groupe avec motif et justificatif.',
                'active' => true,
                'requires_attachment' => true,
                'generates_document' => false,
                'processing_days' => 3,
                'form_schema' => json_encode([
                    ['name' => 'groupe_actuel', 'label' => 'Groupe actuel', 'type' => 'text', 'required' => true],
                    ['name' => 'nouveau_groupe', 'label' => 'Nouveau groupe demandé', 'type' => 'text', 'required' => true],
                    ['name' => 'motif', 'label' => 'Motif', 'type' => 'textarea', 'required' => true],
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'code' => 'RECTIFICATION',
                'name' => 'Rectification d’informations',
                'description' => 'Correction d’une information du dossier (vérifiée par la scolarité).',
                'active' => true,
                'requires_attachment' => false,
                'generates_document' => false,
                'processing_days' => 5,
                'form_schema' => json_encode([
                    ['name' => 'champ_concerne', 'label' => 'Champ concerné', 'type' => 'text', 'required' => true],
                    ['name' => 'valeur_correcte', 'label' => 'Valeur correcte', 'type' => 'text', 'required' => true],
                    ['name' => 'motif', 'label' => 'Motif', 'type' => 'textarea', 'required' => true],
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'code' => 'DUPLICATA',
                'name' => 'Duplicata de document',
                'description' => 'Réédition d’un document administratif.',
                'active' => true,
                'requires_attachment' => false,
                'generates_document' => false,
                'processing_days' => 2,
                'form_schema' => json_encode([
                    ['name' => 'document_concerne', 'label' => 'Document concerné', 'type' => 'text', 'required' => true],
                    ['name' => 'motif', 'label' => 'Motif', 'type' => 'textarea', 'required' => true],
                ], JSON_UNESCAPED_UNICODE),
            ],
            [
                'code' => 'DEMANDE_LIBRE',
                'name' => 'Demande personnalisée',
                'description' => 'Toute autre demande administrative.',
                'active' => true,
                'requires_attachment' => false,
                'generates_document' => false,
                'processing_days' => 5,
                'form_schema' => null,
            ],
        ];

        foreach ($types as $type) {
            DB::table('request_types')->updateOrInsert(
                ['code' => $type['code']],
                $type + ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('request_types');
    }
};
