<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Question extends Model
{
    protected $fillable = [
        'developpeur_id',
        'conversation_id',
        'texte',
        'clean_text',
        'intent',
        'detected_payment_method',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function developpeur(): BelongsTo
    {
        return $this->belongsTo(DeveloppeurPartenaire::class, 'developpeur_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function reponse(): HasOne
    {
        return $this->hasOne(Reponse::class, 'question_id');
    }

    public function ticket(): HasOne
    {
        return $this->hasOne(Ticket::class, 'question_id');
    }
}
