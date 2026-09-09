<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Feedback extends Model
{
    protected $table = 'feedbacks';

    protected $fillable = [
        'reponse_id',
        'developpeur_id',
        'evaluation',
        'commentaire',
    ];

    public function reponse(): BelongsTo
    {
        return $this->belongsTo(Reponse::class, 'reponse_id');
    }

    public function developpeur(): BelongsTo
    {
        return $this->belongsTo(DeveloppeurPartenaire::class, 'developpeur_id');
    }
}
