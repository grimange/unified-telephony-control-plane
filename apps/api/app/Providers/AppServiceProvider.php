<?php

namespace App\Providers;

use App\Infrastructure\RuntimeFencing\HttpKubernetesWorkloadClient;
use App\Infrastructure\RuntimeFencing\InfrastructureAdapterRegistry;
use App\Infrastructure\RuntimeFencing\KubernetesRuntimeFenceAdapter;
use App\Infrastructure\RuntimeFencing\KubernetesWorkloadClient;
use App\Infrastructure\RuntimeFencing\RuntimeFenceOperationHandler;
use App\Infrastructure\RuntimeFencing\RuntimeNodeRestoreOperationHandler;
use App\RuntimeAdapters\Asterisk\AsteriskAdapterConfigurationHandler;
use App\RuntimeAdapters\Asterisk\AsteriskAriEventNormalizer;
use App\RuntimeAdapters\Asterisk\AsteriskCatalog;
use App\RuntimeAdapters\Asterisk\AsteriskRuntimeAdapter;
use App\RuntimeAdapters\Asterisk\AsteriskRuntimeNodeReconciler;
use App\RuntimeEngine\Commands\GenericRuntimeNodeInspectHandler;
use App\RuntimeEngine\Commands\RuntimeAdapterRegistry;
use App\RuntimeEngine\Commands\RuntimeOperationHandlerRegistry;
use App\RuntimeEngine\Events\EventNormalizerRegistry;
use App\RuntimeEngine\Reconciliation\ReconcilerRegistry;
use App\RuntimeEngine\Reconciliation\RuntimeNodeReconciler;
use App\RuntimeRegistry\AdapterConfiguration\AdapterConfigurationRegistry;
use App\Simulator\Commands\SimulatorApplyConfigurationHandler;
use App\Simulator\Commands\SimulatorRuntimeAdapter;
use App\Simulator\Events\SimulatorEventNormalizer;
use App\Simulator\Reconciliation\SimulatorRuntimeNodeReconciler;
use App\Simulator\SimulatorAdapterConfigurationHandler;
use App\Simulator\SimulatorCatalog;
use App\Support\Health\ConfiguredReadinessChecker;
use App\Support\Health\ReadinessChecker;
use App\TelephonyDomain\Reconciliation\ConferenceParticipantReconciler;
use App\TelephonyDomain\Reconciliation\ConferenceReconciler;
use App\TelephonyDomain\Reconciliation\SignalingRegistrationReconciler;
use App\TelephonyDomain\Runtime\ConferenceOperationHandler;
use App\TelephonyDomain\Signaling\KamailioRegistrationEventNormalizer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ReadinessChecker::class, ConfiguredReadinessChecker::class);
        $this->app->bind(KubernetesWorkloadClient::class, HttpKubernetesWorkloadClient::class);
        $this->app->singleton(InfrastructureAdapterRegistry::class, fn ($app): InfrastructureAdapterRegistry => new InfrastructureAdapterRegistry([
            $app->make(KubernetesRuntimeFenceAdapter::class),
        ]));
        $this->app->singleton(RuntimeAdapterRegistry::class, fn ($app): RuntimeAdapterRegistry => new RuntimeAdapterRegistry([
            $app->make(SimulatorRuntimeAdapter::class),
            $app->make(AsteriskRuntimeAdapter::class),
        ]));
        $this->app->singleton(RuntimeOperationHandlerRegistry::class, fn ($app): RuntimeOperationHandlerRegistry => new RuntimeOperationHandlerRegistry([
            $app->make(GenericRuntimeNodeInspectHandler::class),
            $app->make(SimulatorApplyConfigurationHandler::class),
            new ConferenceOperationHandler((string) config('telephony_domain.operation_types.conference_ensure'), (string) config('telephony_domain.runtime_capabilities.conference_lifecycle')),
            new ConferenceOperationHandler((string) config('telephony_domain.operation_types.conference_close'), (string) config('telephony_domain.runtime_capabilities.conference_lifecycle')),
            new ConferenceOperationHandler((string) config('telephony_domain.operation_types.participant_ensure'), (string) config('telephony_domain.runtime_capabilities.conference_participation')),
            new ConferenceOperationHandler((string) config('telephony_domain.operation_types.participant_remove'), (string) config('telephony_domain.runtime_capabilities.conference_participation')),
            new ConferenceOperationHandler((string) config('telephony_domain.operation_types.verify_conference_absent'), (string) config('telephony_domain.runtime_capabilities.conference_lifecycle')),
            $app->make(RuntimeFenceOperationHandler::class),
            $app->make(RuntimeNodeRestoreOperationHandler::class),
        ]));
        $this->app->singleton(EventNormalizerRegistry::class, function ($app): EventNormalizerRegistry {
            $catalog = $app->make(SimulatorCatalog::class);
            $asterisk = $app->make(AsteriskCatalog::class);

            return new EventNormalizerRegistry([
                new SimulatorEventNormalizer($catalog, $catalog->eventType('connection_opened')),
                new SimulatorEventNormalizer($catalog, $catalog->eventType('connection_closed')),
                new SimulatorEventNormalizer($catalog, $catalog->eventType('readiness_changed')),
                new SimulatorEventNormalizer($catalog, $catalog->eventType('capabilities_observed')),
                new SimulatorEventNormalizer($catalog, $catalog->eventType('configuration_observed')),
                new SimulatorEventNormalizer($catalog, $catalog->eventType('conference_ready')),
                new SimulatorEventNormalizer($catalog, $catalog->eventType('conference_closed')),
                new SimulatorEventNormalizer($catalog, $catalog->eventType('conference_degraded')),
                new SimulatorEventNormalizer($catalog, $catalog->eventType('participant_joined')),
                new SimulatorEventNormalizer($catalog, $catalog->eventType('participant_left')),
                new SimulatorEventNormalizer($catalog, $catalog->eventType('participant_failed')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('connection_opened')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('connection_closed')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('runtime_info_observed')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('authentication_failed')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('unknown_event_observed')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('bridge_created')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('bridge_destroyed')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('channel_entered_bridge')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('channel_left_bridge')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('channel_destroyed')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('stasis_start')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('stasis_end')),
                new KamailioRegistrationEventNormalizer('kamailio.registration.accepted'),
                new KamailioRegistrationEventNormalizer('kamailio.registration.refreshed'),
                new KamailioRegistrationEventNormalizer('kamailio.registration.replaced'),
                new KamailioRegistrationEventNormalizer('kamailio.registration.removed'),
                new KamailioRegistrationEventNormalizer('kamailio.registration.expired'),
            ]);
        });
        $this->app->singleton(ReconcilerRegistry::class, fn ($app): ReconcilerRegistry => new ReconcilerRegistry([
            new RuntimeNodeReconciler([
                $app->make(AsteriskRuntimeNodeReconciler::class),
                $app->make(SimulatorRuntimeNodeReconciler::class),
            ]),
            $app->make(ConferenceReconciler::class),
            $app->make(ConferenceParticipantReconciler::class),
            $app->make(SignalingRegistrationReconciler::class),
        ]));
        $this->app->singleton(AdapterConfigurationRegistry::class, fn ($app): AdapterConfigurationRegistry => new AdapterConfigurationRegistry([
            $app->make(SimulatorAdapterConfigurationHandler::class),
            $app->make(AsteriskAdapterConfigurationHandler::class),
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
