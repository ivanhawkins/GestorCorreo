<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Classification extends Model
{
    use HasFactory;

    protected $table = 'classifications';

    public $timestamps = false;

    protected $fillable = [
        'message_id',
        'gpt_label',
        'gpt_confidence',
        'gpt_rationale',
        'qwen_label',
        'qwen_confidence',
        'qwen_rationale',
        'final_label',
        'final_reason',
        'decided_by',
        'decided_at',
    ];

    protected $casts = [
        'gpt_confidence'  => 'decimal:4',
        'qwen_confidence' => 'decimal:4',
        'decided_at'      => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id', 'id');
    }
}
