<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Gabarit de notification versionné (Phase 15 §15.1) : variables
 * {nom} échappées au rendu, jamais de HTML brut stocké.
 */
class NotificationTemplate extends Model
{
    protected $table = 'notification_templates';

    protected $fillable = ['code', 'titre', 'message', 'version', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }
}
