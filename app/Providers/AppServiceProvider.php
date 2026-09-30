<?php

namespace App\Providers;

use App\Logging\SlowQueryLogger;
use App\Models\ParkingSpot;
use App\Models\ParkingSpotReport;
use App\Models\Review;
use App\Policies\ParkingSpotPolicy;
use App\Policies\ParkingSpotReportPolicy;
use App\Policies\ReviewPolicy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
        Gate::policy(ParkingSpot::class, ParkingSpotPolicy::class);
        Gate::policy(Review::class, ReviewPolicy::class);
        Gate::policy(ParkingSpotReport::class, ParkingSpotReportPolicy::class);

        if (config('logging.query.enabled')) {
            $queryLogger = new SlowQueryLogger(
                logger: Log::channel('query'),
                slowQueryMilliseconds: config('logging.query.slow_query_ms'),
            );

            DB::listen(fn (QueryExecuted $query) => $queryLogger($query));
        }
    }
}
