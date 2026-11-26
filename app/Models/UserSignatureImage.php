<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A signature image someone uploaded. ICT verifies it before it can be used.
 * No image is ever deleted, so every signed document can be traced to the
 * exact image used and who verified it.
 */
class UserSignatureImage extends Model
{
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const REJECTED = 'rejected';

    public const SUSPENDED = 'suspended';

    public const WITHDRAWN = 'withdrawn';

    public const RETIRED = 'retired';

    protected $fillable = ['user_id', 'storage_path', 'sha256', 'width', 'height', 'status', 'reviewed_by', 'reviewed_at', 'review_note', 'uploaded_at', 'retired_at'];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'retired_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
