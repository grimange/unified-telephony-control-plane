<?php

namespace App\Providers;

use App\RuntimeEngine\Commands\RuntimeAdapterRegistry;
use App\RuntimeEngine\Commands\RuntimeOperationHandlerRegistry;
use App\RuntimeEngine\Events\EventNormalizerRegistry;
use App\RuntimeEngine\Reconciliation\ReconcilerRegistry;
use App\Simulator\Commands\SimulatorApplyConfigurationHandler;
use App\Simulator\Commands\SimulatorInspectHandler;
use App\Simulator\Commands\SimulatorRuntimeAdapter;
use App\Simulator\Events\SimulatorEventNormalizer;
use App\Simulator\Reconciliation\SimulatorRuntimeNodeReconciler;
use App\Simulator\SimulatorCatalog;
use App\Support\Health\ConfiguredReadinessChecker;
use App\Support\Health\ReadinessChecker;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ReadinessChecker::class, ConfiguredReadinessChecker::class);
        $this->app->singleton(RuntimeAdapterRegistry::class, fn ($app): RuntimeAdapterRegistry => new RuntimeAdapterRegistry([
            $app->make(SimulatorRuntimeAdapter::class),
        ]));
        $this->app->singleton(RuntimeOperationHandlerRegistry::class, fn ($app): RuntimeOperationHandlerRegistry => new RuntimeOperationHandlerRegistry([
            $app->make(SimulatorInspectHandler::class),
            $app->make(SimulatorApplyConfigurationHandler::class),
        ]));
        $this->app->singleton(EventNormalizerRegistry::class, function ($app): EventNormalizerRegistry {
            $catalog = $app->make(SimulatorCatalog::class);

            return new EventNormalizerRegistry([
                new SimulatorEventNormalizer($catalog, $catalog->eventType('connection_opened')),
                new SimulatorEventNormalizer($catalog, $catalog->eventType('connection_closed')),
                new SimulatorEventNormalizer($catalog, $catalog->eventType('readiness_changed')),
                new SimulatorEventNormalizer($catalog, $catalog->eventType('capabilities_observed')),
                new SimulatorEventNormalizer($catalog, $catalog->eventType('configuration_observed')),
            ]);
        });
        $this->app->singleton(ReconcilerRegistry::class, fn ($app): ReconcilerRegistry => new ReconcilerRegistry([
            $app->make(SimulatorRuntimeNodeReconciler::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
