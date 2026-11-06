<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowInstance extends Model
{
    protected $fillable = [
        'workflow_definition_id',
        'workflowable_id',
        'workflowable_type',

        'current_step_id',
        'current_step_order',
        'status',
        'approval_round',
        'workflow_snapshot',
        'current_approver_ids',
        'initiated_by',
        'initiated_at',
        'completed_by',
        'completed_at',
    ];

    protected $casts = [
        'initiated_at' => 'datetime',
        'completed_at' => 'datetime',
        'workflow_snapshot' => 'array',
        'current_approver_ids' => 'array',
    ];

    public function workflowDefinition()
    {
        return $this->belongsTo(WorkflowDefinition::class);
    }

    public function workflowable()
    {
        return $this->morphTo();
    }

    public function currentStep()
    {
        return $this->belongsTo(WorkflowStep::class, 'current_step_id');
    }

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function actions()
    {
        return $this->hasMany(WorkflowAction::class);
    }
    public function workflowActions()
    {
        return $this->morphMany(WorkflowAction::class, 'workflowable')
            ->orderBy('step_order');
    }

}
