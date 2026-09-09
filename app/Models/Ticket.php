<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    protected $fillable = [
        'question_id',
        'developpeur_id',
        'reference',
        'resume',
        'payment_method',
        'operation',
        'environment',
        'error_code',
        'verifications_proposees',
        'documents_consultes',
        'messages_utiles',
        'priorite',
        'assigned_to_user_id',
        'statut',
        'date_creation',
        'date_resolution',
        'metadata',
    ];

    protected $casts = [
        'date_creation' => 'datetime',
        'date_resolution' => 'datetime',
        'verifications_proposees' => 'array',
        'documents_consultes' => 'array',
        'messages_utiles' => 'array',
        'metadata' => 'array',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    public function developpeur(): BelongsTo
    {
        return $this->belongsTo(DeveloppeurPartenaire::class, 'developpeur_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}
