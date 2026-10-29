<?php

namespace App\Modules\AiAssistant\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Approbation d'une action sensible (Lot 9, table ai_approvals).
 * Le modèle IA ne JAMAIS créer ni approuver une demande lui-même :
 * demande par outil préparé, décision par HUMAIN permissionné.
 */
class AiApprobation extends Model
{
    public const ETAT_PENDING = 'pending_approval';

    public const ETAT_APPROVED = 'approved';

    public const ETAT_REJECTED = 'rejected';

    public const ETAT_EXPIRED = 'expired';

    public const ETAT_EXECUTING = 'executing';

    public const ETAT_COMPLETED = 'completed';

    public const ETAT_FAILED = 'failed';

    public const ETAT_CANCELLED = 'cancelled';

    protected $table = 'ai_approvals';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'identifiant', 'demandeur_id', 'approbateur_id', 'conversation_id',
        'action', 'description', 'cibles', 'parametres', 'impacts_resume',
        'niveau_risque', 'expire_le', 'etat', 'empreinte', 'journal',
        'decide_le', 'resultat',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cibles' => 'array',
            'parametres' => 'array',
            'journal' => 'array',
            'expire_le' => 'datetime',
            'decide_le' => 'datetime',
        ];
    }
}
