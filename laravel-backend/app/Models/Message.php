<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Message extends Model
{
    use HasFactory;

    protected $table = 'messages';

    /**
     * UUID primary key — not auto-incrementing.
     */
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Messages only have created_at (no updated_at column).
     */
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'id',
        'account_id',
        'imap_uid',
        'message_id',
        'thread_id',
        'from_name',
        'from_email',
        'to_addresses',
        'cc_addresses',
        'bcc_addresses',
        'subject',
        'date',
        'snippet',
        'folder',
        'body_text',
        'body_html',
        'has_attachments',
        'is_read',
        'is_starred',
        'created_at',
    ];

    protected $casts = [
        'to_addresses'    => 'array',
        'cc_addresses'    => 'array',
        'bcc_addresses'   => 'array',
        'has_attachments' => 'boolean',
        'is_read'         => 'boolean',
        'is_starred'      => 'boolean',
        'date'            => 'datetime',
        'created_at'      => 'datetime',
        'imap_uid'        => 'integer',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function classification(): HasOne
    {
        return $this->hasOne(Classification::class, 'message_id', 'id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'message_id', 'id');
    }
}
