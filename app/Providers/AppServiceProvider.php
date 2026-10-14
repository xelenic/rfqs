<?php

namespace App\Providers;

use App\LiveVersion;
use App\Models\WorkingSchedule;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        LiveVersion::watch();
        WorkingSchedule::watch();
    }
}
