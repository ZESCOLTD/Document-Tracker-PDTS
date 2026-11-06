<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\IpaPhrisView;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncAllUsersFromPhrisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $users = User::all();

        Cache::put('phris_sync_total', $users->count());

        $count = 0;

        foreach ($users as $user) {

            $phris = IpaPhrisView::where('CON_PER_NO', $user->man_no)->first();

            if ($phris) {

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

            $count++;

            Cache::put('phris_sync_count', $count);
        }

        Cache::put('phris_sync_running', false);
    }
}
