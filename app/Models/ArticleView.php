<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ArticleView extends Model
{

    protected $table = 'NON_STOCK_SERVICE_ARTICLES';

    public $timestamps = false;
    protected $primaryKey = null;
    public $incrementing = false;
}
