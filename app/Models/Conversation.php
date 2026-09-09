<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = [
        'developpeur_id',
        'reference',
        'titre',
        'contexte',
        'langue',
        'statut',
        'date_cloture',
    ];

    protected $casts = [
        'contexte' => 'array',
        'date_cloture' => 'datetime',
    ];

    public function developpeur(): BelongsTo
    {
        return $this->belongsTo(DeveloppeurPartenaire::class, 'developpeur_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'conversation_id');
    }
}
