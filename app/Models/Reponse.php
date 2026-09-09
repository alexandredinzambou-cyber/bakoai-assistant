<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reponse extends Model
{
    protected $table = 'reponses';

    protected $fillable = [
        'question_id',
        'texte_explicatif',
        'statut',
        'guard_reason',
        'confidence',
        'confidence_level',
        'duration_ms',
        'error_code',
        'prompt_version',
        'liens_associes',
        'metadata',
    ];

    protected $casts = [
        'liens_associes' => 'array',
        'confidence' => 'float',
        'duration_ms' => 'integer',
        'metadata' => 'array',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    public function chunks(): BelongsToMany
    {
        return $this->belongsToMany(Chunk::class, 'chunk_reponse', 'reponse_id', 'chunk_id');
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class, 'reponse_id');
    }
}
