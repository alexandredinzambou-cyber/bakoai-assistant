<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\Vector;

class Embedding extends Model
{
    protected $fillable = ['chunk_id', 'modele', 'vecteur'];

    protected $casts = [
        'vecteur' => Vector::class,
    ];

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(Chunk::class, 'chunk_id');
    }
}
