<?php

namespace App\Providers;

use App\LiveVersion;
use App\Models\Setting;
use App\Models\WorkingSchedule;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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

        $this->registerBusinessTimezoneMacro();
    }

    /**
     * Times are stored in UTC; toBusinessTimezone() gives a copy in the
     * working hours' time zone (Setting::timezone()) for showing to people.
     */
    private function registerBusinessTimezoneMacro(): void
    {
        foreach ([Carbon::class, CarbonImmutable::class] as $carbonClass) {
            $carbonClass::macro('toBusinessTimezone', function (): CarbonInterface {
                /** @var CarbonInterface $this */
                return $this->copy()->setTimezone(Setting::timezone());
            });
        }
    }
}
