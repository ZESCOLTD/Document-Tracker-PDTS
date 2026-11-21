<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdtsSmsLog extends Model
{
    protected $fillable = [
        'to',
        'message',
        'purpose',
        'pdts_document_id',
        'user_id',
        'driver',
        'status',
        'error',
        'ip',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(PdtsDocument::class, 'pdts_document_id');
    }
}
