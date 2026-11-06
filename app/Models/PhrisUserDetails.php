<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhrisUserDetails extends Model
{
    protected $connection = 'phris_connection';

    // table name
    protected $table = 'ipa_phris_view';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = ['con_per_no'];
}
