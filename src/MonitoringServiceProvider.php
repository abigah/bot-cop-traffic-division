<?php

namespace Abigah\BotCopTrafficDivision;

use Abigah\BotCopTrafficDivision\Console\AggregateMonitorChecks;
use Abigah\BotCopTrafficDivision\Console\BackfillSites;
use Abigah\BotCopTrafficDivision\Console\GenerateProberSecret;
use Abigah\BotCopTrafficDivision\Console\ImportLegacyMonitoring;
use Abigah\BotCopTrafficDivision\Console\SweepHeartbeats;
use Abigah\BotCopTrafficDivision\Http\Middleware\VerifyProberSignature;
use Abigah\BotCopTrafficDivision\Listeners\MonitorEventSubscriber;
use Abigah\BotCopTrafficDivision\Livewire\ActiveIncidentBanner;
use Abigah\BotCopTrafficDivision\Livewire\Dashboard;
use Abigah\BotCopTrafficDivision\Livewire\Incidents;
use Abigah\BotCopTrafficDivision\Livewire\MonitorForgeSites;
use Abigah\BotCopTrafficDivision\Livewire\MonitorHistory;
use Abigah\BotCopTrafficDivision\Livewire\NotificationPreferences;
use Abigah\BotCopTrafficDivision\Livewire\Probers;
use Abigah\BotCopTrafficDivision\Livewire\SiteOverview;
use Abigah\BotCopTrafficDivision\Livewire\Sites;
use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Models\MonitorHeartbeat;
use Abigah\BotCopTrafficDivision\Models\MonitorSiteSetting;
use Abigah\BotCopTrafficDivision\Observers\DeclarationObserver;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/monitoring.php', 'monitoring');

        $this->app->singleton(Monitoring::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        /*
         | Registers the views and, with them, `<x-monitoring::...>` — Laravel
         | resolves those from `components/` under this namespace, so a host
         | that publishes the views can restyle them without touching any PHP.
         */
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'monitoring');

        $this->registerRoutes();
        $this->registerLivewireComponents();
        $this->registerObservers();

        Event::subscribe(MonitorEventSubscriber::class);
        $this->registerCommands();
        $this->registerPublishing();
    }

    /**
     * A prober is told what to check by pulling a manifest. These say "pull
     * again" the moment the answer changes, so a new monitor is being checked
     * seconds later rather than at the next reconcile.
     */
    protected function registerLivewireComponents(): void
    {
        Livewire::component('monitoring-dashboard', Dashboard::class);
        Livewire::component('monitoring-sites', Sites::class);
        Livewire::component('monitoring-site-overview', SiteOverview::class);
        Livewire::component('monitoring-monitor-history', MonitorHistory::class);
        Livewire::component('monitoring-forge-sites', MonitorForgeSites::class);
        Livewire::component('monitoring-incidents', Incidents::class);
        Livewire::component('monitoring-preferences', NotificationPreferences::class);
        Livewire::component('monitoring-probers', Probers::class);

        // Meant to be dropped into a host's own layout, so it is named plainly.
        Livewire::component('monitoring-incident-banner', ActiveIncidentBanner::class);
    }

    protected function registerObservers(): void
    {
        Monitor::observe(DeclarationObserver::class);
        MonitorHeartbeat::observe(DeclarationObserver::class);
        MonitorSiteSetting::observe(DeclarationObserver::class);
    }

    protected function registerRoutes(): void
    {
        if (! config('monitoring.register_routes', true)) {
            return;
        }

        /*
         | A prober has no session, no cookies and no CSRF token. These routes
         | authenticate by HMAC over the raw request body, so they must not
         | inherit the web stack.
         */
        Route::group([
            'prefix' => config('monitoring.api_route_prefix', 'monitoring'),
            'middleware' => [VerifyProberSignature::class],
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/prober.php');
        });

        Route::group([
            'prefix' => config('monitoring.route_prefix', ''),
            'middleware' => ['signed', SubstituteBindings::class],
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/deployments.php');
        });

        /*
         | The screens, behind whatever the host authenticates with. Route
         | model binding is appended rather than assumed: the host's stack has
         | no reason to include it, and {site} and {monitor} need it.
         */
        Route::group([
            'prefix' => config('monitoring.route_prefix', ''),
            'middleware' => [...config('monitoring.middleware', ['web']), SubstituteBindings::class],
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/monitoring.php');
        });

        /*
         | The mute link, signed rather than authenticated, and registered apart
         | from the screens because route-level middleware adds to a group's
         | rather than replacing it — inside that group it would inherit the
         | auth stack it exists to avoid.
         */
        Route::group([
            'prefix' => config('monitoring.route_prefix', ''),
            'middleware' => ['signed', SubstituteBindings::class],
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/mute.php');
        });

        Route::group([
            'prefix' => config('monitoring.api_route_prefix', 'monitoring'),
            'middleware' => ['throttle:'.config('monitoring.ingest_rate_limit', '60,1')],
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/ingest.php');
        });
    }

    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            AggregateMonitorChecks::class,
            BackfillSites::class,
            GenerateProberSecret::class,
            ImportLegacyMonitoring::class,
            SweepHeartbeats::class,
        ]);
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/monitoring.php' => config_path('monitoring.php'),
        ], 'monitoring-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/monitoring'),
        ], 'monitoring-views');
    }
}
