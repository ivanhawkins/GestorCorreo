<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasFactory;

    protected $table = 'accounts';

    protected $fillable = [
        'user_id',
        'email_address',
        'imap_host',
        'smtp_host',
        'imap_port',
        'smtp_port',
        'username',
        'encrypted_password',
        'is_active',
        'ssl_verify',
        'connection_timeout',
        'auto_classify',
        'auto_sync_interval',
        'custom_classification_prompt',
        'custom_review_prompt',
        'owner_profile',
        'last_sync_error',
        'is_deleted',
        'protocol',
        'mailbox_storage_bytes',
        'mailbox_storage_limit',
    ];

    protected $hidden = [
        'encrypted_password',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'ssl_verify'           => 'boolean',
        'auto_classify'        => 'boolean',
        'is_deleted'           => 'boolean',
        'imap_port'            => 'integer',
        'smtp_port'            => 'integer',
        'connection_timeout'   => 'integer',
        'auto_sync_interval'   => 'integer',
        'mailbox_storage_bytes' => 'integer',
        'mailbox_storage_limit' => 'integer',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
