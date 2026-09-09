<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResponsableTechnique extends Model
{
    protected $table = 'responsables_techniques';

    protected $fillable = ['nom', 'fonction'];
}
