<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpenApiSpec extends Model
{
    protected $table = 'open_api_specs';

    protected $fillable = [
        'moyen_paiement_id',
        'document_id',
        'fichier_url',
        'source_key',
        'format',
        'openapi_version',
        'version',
        'checksum',
        'paths_count',
        'operations_count',
        'actif',
        'date_indexation',
    ];

    protected $casts = [
        'actif' => 'boolean',
        'date_indexation' => 'datetime',
        'paths_count' => 'integer',
        'operations_count' => 'integer',
    ];

    public function moyenPaiement(): BelongsTo
    {
        return $this->belongsTo(MoyenPaiement::class, 'moyen_paiement_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DocumentApi::class, 'document_id');
    }
}
