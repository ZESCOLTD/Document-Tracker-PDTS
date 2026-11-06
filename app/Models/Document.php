<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Document extends Model implements HasMedia
{
    use SoftDeletes;
    protected $guarded = [];
    use InteractsWithMedia;

    protected $casts = [
        'last_review_at' => 'date:Y-m-d',
        'due_review_at' => 'date:Y-m-d',
        'publish_at' => 'date:Y-m-d',
    ];

    public function items()
    {
        return $this->hasMany(DocumentItem::class);
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class,'document_type_id','id');
    }
}
