<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportTicket extends Model
{
    protected $fillable = [
        'user_id',
        'subject',
        'status',
    ];

    public function messages()
    {
        return $this->hasMany(SupportMessage::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
