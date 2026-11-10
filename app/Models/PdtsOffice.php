<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdtsOffice extends Model
{
    protected $fillable = [
        'code',
        'name',
        'department_id',
        'parent_office_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(PdtsDepartment::class, 'department_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_office_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_office_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(PdtsEmployeeOfficeAssignment::class, 'office_id');
    }
}