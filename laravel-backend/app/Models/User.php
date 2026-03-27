<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $table = 'users';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'username',
        'password_hash',
        'is_active',
        'is_admin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password_hash',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'is_admin'  => 'boolean',
        'deleted_at' => 'datetime',
    ];

    /**
     * Override the default auth password field.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /**
     * Use 'username' as the login field.
     */
    public function getAuthIdentifierName(): string
    {
        return 'username';
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function serviceWhitelists(): HasMany
    {
        return $this->hasMany(ServiceWhitelist::class);
    }

    public function senderRules(): HasMany
    {
        return $this->hasMany(SenderRule::class);
    }
}
