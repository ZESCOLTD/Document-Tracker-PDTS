<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class DocumentItem extends Model implements HasMedia
{
    use SoftDeletes;
    protected $guarded = [];
    use InteractsWithMedia;
}
