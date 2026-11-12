<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An office sits either directly under a directorate (department_id is null,
 * e.g. the Director's office) or under a department (directorate_id is null).
 */
class PdtsOffice extends Model
{
    protected $fillable = [
        'code',
        'name',
        'department_id',
        'directorate_id',
        'parent_office_id',
        'is_head',
        'is_active',
    ];

    protected $casts = [
        'is_head' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(PdtsDepartment::class, 'department_id');
    }

    /** Set only for directorate-level offices; see owningDirectorate(). */
    public function directorate(): BelongsTo
    {
        return $this->belongsTo(PdtsDirectorate::class, 'directorate_id');
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

    public function isDirectorateLevel(): bool
    {
        return $this->department_id === null;
    }

    /** The directorate this office is in, directly or through its department. */
    public function owningDirectorate(): ?PdtsDirectorate
    {
        return $this->isDirectorateLevel() ? $this->directorate : $this->department?->directorate;
    }

    /** Whether the office and everything above it is active. */
    public function isUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if (! $this->isDirectorateLevel() && ! $this->department?->is_active) {
            return false;
        }

        return (bool) $this->owningDirectorate()?->is_active;
    }

    /** "Department · Directorate", or just the directorate for a directorate-level office. */
    public function unitLabel(): string
    {
        return collect([$this->department?->name, $this->owningDirectorate()?->name])->filter()->implode(' · ');
    }
}
