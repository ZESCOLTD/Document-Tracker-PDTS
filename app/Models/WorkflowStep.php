<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkflowStep extends Model
{
    protected $fillable = [
        'workflow_definition_id',
        'step_order',
        'step_name',
        'step_code',
        'approver_type',
        'approver_value',
        'allow_revert',
        'allow_reject',
        'allow_approve',
        'comment_required',
        'is_active',
    ];

    protected $casts = [
        'allow_revert' => 'boolean',
        'allow_reject' => 'boolean',
        'allow_approve' => 'boolean',
        'comment_required' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function workflowDefinition()
    {
        return $this->belongsTo(WorkflowDefinition::class);
    }

    public function approvers()
    {
        return $this->belongsToMany(User::class, 'workflow_step_approvers', 'workflow_step_id', 'user_id');
    }


}
