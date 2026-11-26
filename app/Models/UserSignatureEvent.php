<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to someone's electronic signature: set up, image replaced or
 * removed, PIN/password set, changed or reset (by them or an administrator).
 */
class UserSignatureEvent extends Model
{
    public const LABELS = [
        'image_uploaded' => 'Signature uploaded, waiting for ICT to verify it',
        'image_replaced' => 'New signature uploaded, waiting for ICT to verify it',
        'image_approved' => 'Signature verified and approved by ICT',
        'image_rejected' => 'Signature rejected by ICT',
        'signature_suspended' => 'Signature suspended by ICT',
        'signature_reinstated' => 'Signature reinstated by ICT',
        'image_removed' => 'Signature removed',
        'secret_set' => 'Signature PIN/password set',
        'secret_changed' => 'Signature PIN/password changed',
        'secret_reset' => 'Forgotten PIN/password reset with the login password',
        'secret_reset_by_admin' => 'PIN/password reset by an administrator',
        'preparer_granted' => 'Someone authorised to prepare documents for you',
        'preparer_revoked' => 'Authorisation to prepare documents ended',
        'preparer_gave_up' => 'Authorisation to prepare documents given up',
    ];

    protected $fillable = ['user_id', 'actor_id', 'action', 'details', 'ip_address', 'occurred_at'];

    protected $casts = [
        'details' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }

    public function label(): string
    {
        $label = self::LABELS[$this->action] ?? $this->action;
        $who = $this->details['preparer'] ?? null;

        return $who && str_starts_with($this->action, 'preparer_') ? $label.': '.$who : $label;
    }
}
