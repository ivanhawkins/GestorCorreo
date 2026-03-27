<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiConfig extends Model
{
    use HasFactory;

    protected $table = 'ai_config';

    /**
     * ai_config only has updated_at (no created_at).
     */
    public $timestamps = false;

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'api_url',
        'api_key',
        'primary_model',
        'secondary_model',
        'updated_at',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
    ];
}
