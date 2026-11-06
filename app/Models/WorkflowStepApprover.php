<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowStepApprover extends Model
{
    protected $fillable = [
        'workflow_definition_id',
        'initiator_user_id',
        'workflow_step_id',
        'user_id',
        'is_primary',
        'is_active',
    ];

    public function workflowDefinition()
    {
        return $this->belongsTo(WorkflowDefinition::class);
    }

    public function workflowStep()
    {
        return $this->belongsTo(WorkflowStep::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function role()
    {
        return $this->belongsTo(\App\Models\Role::class);
    }
}
