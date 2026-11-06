<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\IpaPhrisView;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSingleUserFromPhrisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $userId)
    {
    }

    public function handle(): void
    {
        $user = User::find($this->userId);

        if (!$user) {
            return;
        }

        $phris = IpaPhrisView::where('CON_PER_NO', $user->man_no)->first();

        if (!$phris) {
            return;
        }

        $user->update([

            'name' => $phris->name,
            'email' => $phris->staff_email,
            'mobile_no' => $phris->mobile_no,
            'directorate' => $phris->directorate,
            'job_title' => $phris->job_title,
            'division' => $phris->division,
            'station' => $phris->station,
            'grade' => $phris->grade,

        ]);
    }
}
