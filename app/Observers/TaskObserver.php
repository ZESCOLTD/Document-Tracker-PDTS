<?php

namespace App\Observers;

use App\Mail\TaskCreatedMail;
use App\Models\Task;
use Illuminate\Support\Facades\Mail;

class TaskObserver
{
    public function created(Task $task): void
    {

        if (app()->isProduction()) {
            Mail::to([$task->responsible_email])
                ->bcc(
                    [
                        'ccmoonde@zesco.co.zm',
                    ]
                )
                ->send(new TaskCreatedMail($task));
        } else {
            Mail::to(
                [
                    'ccmoonde@zesco.co.zm',

                ]
            )
                ->send(new TaskCreatedMail($task));
        }

    }

    public function updated(Task $task): void {}

    public function deleted(Task $task): void {}

    public function restored(Task $task): void {}
}
