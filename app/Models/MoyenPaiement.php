<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MoyenPaiement extends Model
{
    protected $table = 'moyens_paiement';

    protected $fillable = ['nom', 'type'];

    public function documents(): HasMany
    {
        return $this->hasMany(DocumentApi::class, 'moyen_paiement_id');
    }

    public function openApiSpecs(): HasMany
    {
        return $this->hasMany(OpenApiSpec::class, 'moyen_paiement_id');
    }
}
