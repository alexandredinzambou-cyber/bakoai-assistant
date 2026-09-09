<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeveloppeurPartenaire extends Model
{
    protected $table = 'developpeurs_partenaires';

    protected $fillable = ['nom', 'entreprise'];

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'developpeur_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'developpeur_id');
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class, 'developpeur_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'developpeur_id');
    }
}
