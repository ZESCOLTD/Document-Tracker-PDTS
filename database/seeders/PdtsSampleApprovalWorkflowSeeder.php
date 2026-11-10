<?php

namespace Database\Seeders;

use App\Models\WorkflowDefinition;
use Illuminate\Database\Seeder;

class PdtsSampleApprovalWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $workflow = WorkflowDefinition::query()->firstOrCreate(
            [
                'name' => 'PDTS Sample Approval Workflow',
                'module' => 'physical_document',
            ],
            [
                'description' => 'Example only: confirm the approval chain against ZESCO policy and assign approvers per submitter.',
                'is_active' => true,
                'is_default' => true,
            ],
        );

        if ($workflow->steps()->exists()) {
            return;
        }

        $workflow->steps()->createMany([
            [
                'step_order' => 1,
                'step_name' => 'Supervisor Review',
                'step_code' => 'supervisor_review',
                'approver_type' => 'user',
                'allow_approve' => true,
                'allow_reject' => true,
                'allow_revert' => true,
                'comment_required' => true,
                'is_active' => true,
            ],
            [
                'step_order' => 2,
                'step_name' => 'Manager Approval',
                'step_code' => 'manager_approval',
                'approver_type' => 'user',
                'allow_approve' => true,
                'allow_reject' => true,
                'allow_revert' => true,
                'comment_required' => true,
                'is_active' => true,
            ],
            [
                'step_order' => 3,
                'step_name' => 'Director Approval',
                'step_code' => 'director_approval',
                'approver_type' => 'user',
                'allow_approve' => true,
                'allow_reject' => true,
                'allow_revert' => true,
                'comment_required' => true,
                'is_active' => true,
            ],
        ]);
    }
}