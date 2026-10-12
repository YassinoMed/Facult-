<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Préférence de notification (Phase 15 §15.1) : opt-out par catégorie.
 * Absence de ligne = tout actif. Les catégories obligatoires
 * (voir PreferenceNotificationService::OBLIGATOIRES) ignorent l'opt-out.
 */
class NotificationPreference extends Model
{
    protected $table = 'notification_preferences';

    protected $fillable = ['user_id', 'etudiant_id', 'categorie', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function etudiant(): BelongsTo
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
