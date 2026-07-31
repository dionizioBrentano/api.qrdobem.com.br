<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomAttribute extends Model
{
    protected $fillable = [
        'owner_id',
        'owner_type',
        'key',
        'value'
    ];

    public function owner()
    {
        return $this->morphTo();
    }
}
