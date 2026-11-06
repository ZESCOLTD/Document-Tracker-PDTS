<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrganogramMappingView extends Model
{
    use HasFactory;

    protected $connection = 'phris_connection';
    protected $table = 'organogram_mapping_view';

    public $timestamps = false;
    protected $primaryKey = null;
    public $incrementing = false;
}

