<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DahuaServer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'url',
        'username',
        'password',
        'port',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function compounds()
    {
        return $this->hasMany(Compound::class);
    }
}
