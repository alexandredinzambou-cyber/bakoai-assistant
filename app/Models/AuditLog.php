<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'ressource',
        'ressource_id',
        'details',
        'ip_address',
        'correlation_id',
        'date_action',
    ];

    protected $casts = [
        'details' => 'array',
        'date_action' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
