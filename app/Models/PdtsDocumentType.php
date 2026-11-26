<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdtsDocumentType extends Model
{
    protected $fillable = [
        'name',
        'is_active',
        'retention_years',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'retention_years' => 'integer',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(PdtsDocument::class, 'document_type_id');
    }
}
