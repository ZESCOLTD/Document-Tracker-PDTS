<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdtsDepartment extends Model
{
    protected $fillable = [
        'code',
        'name',
        'directorate_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function directorate(): BelongsTo
    {
        return $this->belongsTo(PdtsDirectorate::class, 'directorate_id');
    }

    public function offices(): HasMany
    {
        return $this->hasMany(PdtsOffice::class, 'department_id');
    }
}
