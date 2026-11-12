<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdtsDirectorate extends Model
{
    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function departments(): HasMany
    {
        return $this->hasMany(PdtsDepartment::class, 'directorate_id');
    }

    /** Offices directly under the directorate, such as the Director's office. */
    public function offices(): HasMany
    {
        return $this->hasMany(PdtsOffice::class, 'directorate_id');
    }
}
