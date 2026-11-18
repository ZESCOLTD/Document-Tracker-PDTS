<?php

namespace App\Providers;

use App\Listeners\LogNotificationOutcome;
use App\Services\Documents\WorkCounts;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per request, so the sidebar badges query once per page.
        $this->app->scoped(WorkCounts::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! config('app.authorization_enabled')) {
            Gate::before(fn ($user) => true);
        }

        $this->configureDefaults();

        // Phase 8: audit every notification dispatch outcome (sent/failed) to email_log.
        Event::listen(
            NotificationSent::class,
            [LogNotificationOutcome::class, 'sent'],
        );
        Event::listen(
            NotificationFailed::class,
            [LogNotificationOutcome::class, 'failed'],
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
