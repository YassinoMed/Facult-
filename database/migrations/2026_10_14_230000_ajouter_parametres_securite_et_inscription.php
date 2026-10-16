<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Paramètres configurables de sécurité globale et du module d'inscription.
     */
    private const PARAMETRES = [
        [
            'cle' => 'securite.mfa_obligatoire_admin',
            'valeur' => '0',
            'type' => 'booleen',
            'categorie' => 'securite',
            'description' => 'Exiger l’authentification à deux facteurs (MFA/TOTP) pour tous les comptes administrateurs.',
        ],
        [
            'cle' => 'securite.duree_session_minutes',
            'valeur' => '120',
            'type' => 'entier',
            'categorie' => 'securite',
            'description' => 'Durée de vie maximale d’une session inactive en minutes (15 à 720).',
        ],
        [
            'cle' => 'securite.mot_de_passe_min_longueur',
            'valeur' => '8',
            'type' => 'entier',
            'categorie' => 'securite',
            'description' => 'Longueur minimale requise pour les mots de passe (8 à 32 caractères).',
        ],
        [
            'cle' => 'securite.connexion_tentatives_max',
            'valeur' => '5',
            'type' => 'entier',
            'categorie' => 'securite',
            'description' => 'Nombre maximal de tentatives de connexion échouées avant blocage temporaire (3 à 15).',
        ],
        [
            'cle' => 'inscription.ouverte',
            'valeur' => '1',
            'type' => 'booleen',
            'categorie' => 'inscription',
            'description' => 'Ouverture du portail public de dépôt des dossiers d’inscription.',
        ],
        [
            'cle' => 'inscription.prefill_cin_actif',
            'valeur' => '1',
            'type' => 'booleen',
            'categorie' => 'inscription',
            'description' => 'Activer le pré-remplissage automatique des données étudiantes par CIN.',
        ],
        [
            'cle' => 'inscription.scan_ocr_actif',
            'valeur' => '1',
            'type' => 'booleen',
            'categorie' => 'inscription',
            'description' => 'Activer la reconnaissance optique (OCR) des pièces d’identité lors de l’inscription.',
        ],
    ];

    public function up(): void
    {
        foreach (self::PARAMETRES as $parametre) {
            if (! DB::table('parametres')->where('cle', $parametre['cle'])->exists()) {
                DB::table('parametres')->insert(
                    $parametre + ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('parametres')
            ->whereIn('cle', array_column(self::PARAMETRES, 'cle'))
            ->delete();
    }
};
