<?php

namespace App\Modules\OnlineRequests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module OnlineRequests : catalogue des types (schéma dynamique inclus).
 */
class RequestType extends Model
{
    protected $table = 'request_types';

    protected $fillable = [
        'code', 'name', 'description', 'active',
        'requires_attachment', 'generates_document',
        'processing_days', 'form_schema',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'requires_attachment' => 'boolean',
            'generates_document' => 'boolean',
            'processing_days' => 'integer',
            'form_schema' => 'array',
        ];
    }

    /**
     * @return HasMany<OnlineRequest, $this>
     */
    public function demandes(): HasMany
    {
        return $this->hasMany(OnlineRequest::class, 'request_type_id');
    }
}
