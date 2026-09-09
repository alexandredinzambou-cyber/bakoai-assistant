<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeVersion extends Model
{
    protected $fillable = [
        'version',
        'statut',
        'nombre_documents',
        'nombre_chunks',
        'metadata',
        'date_activation',
    ];

    protected $casts = [
        'nombre_documents' => 'integer',
        'nombre_chunks' => 'integer',
        'metadata' => 'array',
        'date_activation' => 'datetime',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(DocumentApi::class, 'knowledge_version_id');
    }

    public function indexationJobs(): HasMany
    {
        return $this->hasMany(IndexationJob::class, 'knowledge_version_id');
    }
}
