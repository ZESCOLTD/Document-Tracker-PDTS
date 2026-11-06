<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IpaPhrisView extends Model
{
    use HasFactory;

    protected $connection = 'phris_connection';
    protected $table = 'ipa_phris_view';

    public $timestamps = false;
    protected $primaryKey = null;
    public $incrementing = false;
}

