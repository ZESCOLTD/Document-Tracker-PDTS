<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkflowDefinition extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'module',
        'description',
        'is_active',
        'is_default',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function steps()
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('step_order');
    }

    public function instances()
    {
        return $this->hasMany(WorkflowInstance::class);
    }


    public function approvers()
    {
        return $this->hasMany(WorkflowStepApprover::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class,'created_by','id');
    }

}
