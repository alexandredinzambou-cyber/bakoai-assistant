<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DocumentApi extends Model
{
    protected $table = 'documents_api';

    protected $fillable = [
        'moyen_paiement_id',
        'knowledge_version_id',
        'titre',
        'version',
        'lien_officiel',
        'checksum',
        'langue',
        'actif',
        'has_known_conflicts',
        'conflict_note',
        'metadata',
        'fetched_at',
        'last_modified_at',
        'link_verified_at',
        'date_indexation',
    ];

    protected $casts = [
        'date_indexation' => 'datetime',
        'fetched_at' => 'datetime',
        'last_modified_at' => 'datetime',
        'link_verified_at' => 'datetime',
        'actif' => 'boolean',
        'has_known_conflicts' => 'boolean',
        'metadata' => 'array',
    ];

    public function moyenPaiement(): BelongsTo
    {
        return $this->belongsTo(MoyenPaiement::class, 'moyen_paiement_id');
    }

    public function knowledgeVersion(): BelongsTo
    {
        return $this->belongsTo(KnowledgeVersion::class, 'knowledge_version_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class, 'document_id');
    }

    public function openApiSpec(): HasOne
    {
        return $this->hasOne(OpenApiSpec::class, 'document_id');
    }
}
