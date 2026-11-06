<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;


class DocumentType extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name'
    ];


}
