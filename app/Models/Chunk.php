<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Chunk extends Model
{
    protected $fillable = [
        'document_id',
        'contenu',
        'search_text',
        'section',
        'section_slug',
        'content_type',
        'position',
        'token_count',
        'payment_method',
        'operation',
        'environment',
        'http_method',
        'endpoint',
        'error_code',
        'http_status',
        'status',
        'metadata',
        'index_terms',
    ];

    protected $casts = [
        'position' => 'integer',
        'token_count' => 'integer',
        'http_status' => 'integer',
        'metadata' => 'array',
        'index_terms' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(DocumentApi::class, 'document_id');
    }

    public function embedding(): HasOne
    {
        return $this->hasOne(Embedding::class, 'chunk_id');
    }

    public function reponses(): BelongsToMany
    {
        return $this->belongsToMany(Reponse::class, 'chunk_reponse', 'chunk_id', 'reponse_id');
    }
}
