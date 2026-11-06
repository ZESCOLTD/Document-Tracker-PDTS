<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    protected $table = 'email_log';

    protected $fillable = [
        'user_id', 'email', 'notification_class', 'channel',
        'status', 'subject', 'error', 'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shortClass(): string
    {
        return class_basename($this->notification_class);
    }
}
