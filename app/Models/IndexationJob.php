<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndexationJob extends Model
{
    protected $fillable = [
        'knowledge_version_id',
        'initiated_by_user_id',
        'type',
        'statut',
        'progression',
        'nombre_reussites',
        'nombre_echecs',
        'rapport',
        'correlation_id',
        'date_debut',
        'date_fin',
    ];

    protected $casts = [
        'progression' => 'float',
        'nombre_reussites' => 'integer',
        'nombre_echecs' => 'integer',
        'rapport' => 'array',
        'date_debut' => 'datetime',
        'date_fin' => 'datetime',
    ];

    public function knowledgeVersion(): BelongsTo
    {
        return $this->belongsTo(KnowledgeVersion::class, 'knowledge_version_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
