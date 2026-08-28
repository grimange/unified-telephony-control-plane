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
use App\RuntimeAdapters\FreeSwitch\FreeSwitchCatalog;
use App\RuntimeAdapters\FreeSwitch\FreeSwitchEslEventListener;
use App\RuntimeAdapters\FreeSwitch\FreeSwitchEslEventTransport;
use App\RuntimeAdapters\FreeSwitch\FreeSwitchEslTransport;
use App\RuntimeAdapters\FreeSwitch\FreeSwitchEventNormalizer;
use App\RuntimeAdapters\FreeSwitch\FreeSwitchRuntimeAdapter;
use App\RuntimeAdapters\FreeSwitch\FreeSwitchRuntimeNodeReconciler;
use App\RuntimeAdapters\FreeSwitch\SocketFreeSwitchEslTransport;
use App\RuntimeEngine\Commands\GenericRuntimeNodeInspectHandler;
use App\RuntimeEngine\Commands\RuntimeAdapterRegistry;
use App\RuntimeEngine\Commands\RuntimeOperationHandlerRegistry;
use App\RuntimeEngine\Events\EventNormalizerRegistry;
use App\RuntimeEngine\Events\RuntimeEventReceiptRepository;
use App\RuntimeEngine\Listeners\RuntimeListenerLeaseRepository;
use App\RuntimeEngine\Reconciliation\ReconcilerRegistry;
use App\RuntimeEngine\Reconciliation\RuntimeNodeDrainCoordinator;
use App\RuntimeEngine\Reconciliation\RuntimeNodeReconciler;
use App\RuntimeProvisioning\ManagedAsteriskDeprovisioningOperationHandler;
use App\RuntimeProvisioning\ManagedAsteriskProvisioningOperationHandler;
use App\RuntimeProvisioning\ManagedFreeSwitchProvisioningOperationHandler;
use App\RuntimeProvisioning\ManagedRuntimeDeprovisioningOperationHandler;
use App\RuntimeProvisioning\ManagedRuntimeProvisioningOperationHandler;
use App\RuntimeProvisioning\ManagedRuntimeWorkloadConvergenceOperationHandler;
use App\RuntimeRegistry\AdapterConfiguration\AdapterConfigurationRegistry;
use App\RuntimeRegistry\RuntimeNodeDecommissionOperationHandler;
use App\RuntimeRegistry\RuntimeRegistryService;
use App\Simulator\Commands\SimulatorApplyConfigurationHandler;
use App\Simulator\Commands\SimulatorCallOperationHandler;
use App\Simulator\Commands\SimulatorRuntimeAdapter;
use App\Simulator\Events\SimulatorEventNormalizer;
use App\Simulator\Reconciliation\SimulatorRuntimeNodeReconciler;
use App\Simulator\SimulatorAdapterConfigurationHandler;
use App\Simulator\SimulatorCatalog;
use App\Support\Health\ConfiguredReadinessChecker;
use App\Support\Health\ReadinessChecker;
use App\TelephonyDomain\CallOperationCatalog;
use App\TelephonyDomain\Reconciliation\CallOriginationReconciler;
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
        $this->app->bind(FreeSwitchEslTransport::class, fn ($app): FreeSwitchEslTransport => $app->make(SocketFreeSwitchEslTransport::class));
        $this->app->bind(FreeSwitchEslEventTransport::class, fn ($app): FreeSwitchEslEventTransport => $app->make(SocketFreeSwitchEslTransport::class));
        $this->app->bind(FreeSwitchEslEventListener::class, fn ($app): FreeSwitchEslEventListener => new FreeSwitchEslEventListener(
            $app->make(FreeSwitchCatalog::class),
            $app->make(RuntimeEventReceiptRepository::class),
            $app->make(RuntimeListenerLeaseRepository::class),
            $app->make(FreeSwitchEslEventTransport::class),
        ));
        $this->app->bind(ReadinessChecker::class, ConfiguredReadinessChecker::class);
        $this->app->bind(KubernetesWorkloadClient::class, HttpKubernetesWorkloadClient::class);
        $this->app->singleton(InfrastructureAdapterRegistry::class, fn ($app): InfrastructureAdapterRegistry => new InfrastructureAdapterRegistry([
            $app->make(KubernetesRuntimeFenceAdapter::class),
        ]));
        $this->app->singleton(RuntimeAdapterRegistry::class, fn ($app): RuntimeAdapterRegistry => new RuntimeAdapterRegistry([
            $app->make(SimulatorRuntimeAdapter::class),
            $app->make(AsteriskRuntimeAdapter::class),
            $app->make(FreeSwitchRuntimeAdapter::class),
        ]));
        $this->app->singleton(RuntimeOperationHandlerRegistry::class, function ($app): RuntimeOperationHandlerRegistry {
            $handlers = [
                $app->make(GenericRuntimeNodeInspectHandler::class),
                $app->make(SimulatorApplyConfigurationHandler::class),
            ];
            foreach (array_keys(CallOperationCatalog::all()) as $operationType) {
                $handlers[] = new SimulatorCallOperationHandler($operationType);
            }

            return new RuntimeOperationHandlerRegistry(array_merge($handlers, [
                new ConferenceOperationHandler((string) config('telephony_domain.operation_types.conference_ensure'), (string) config('telephony_domain.runtime_capabilities.conference_lifecycle')),
                new ConferenceOperationHandler((string) config('telephony_domain.operation_types.conference_close'), (string) config('telephony_domain.runtime_capabilities.conference_lifecycle')),
                new ConferenceOperationHandler((string) config('telephony_domain.operation_types.participant_ensure'), (string) config('telephony_domain.runtime_capabilities.conference_participation')),
                new ConferenceOperationHandler((string) config('telephony_domain.operation_types.participant_remove'), (string) config('telephony_domain.runtime_capabilities.conference_participation')),
                new ConferenceOperationHandler((string) config('telephony_domain.operation_types.verify_conference_absent'), (string) config('telephony_domain.runtime_capabilities.conference_lifecycle')),
                $app->make(RuntimeFenceOperationHandler::class),
                $app->make(RuntimeNodeRestoreOperationHandler::class),
                $app->make(RuntimeNodeDecommissionOperationHandler::class),
                new ManagedRuntimeProvisioningOperationHandler([
                    $app->make(ManagedAsteriskProvisioningOperationHandler::class),
                    $app->make(ManagedFreeSwitchProvisioningOperationHandler::class),
                ]),
                new ManagedRuntimeWorkloadConvergenceOperationHandler(
                    $app->make(KubernetesWorkloadClient::class),
                    $app->make(RuntimeRegistryService::class),
                    [
                    $app->make(ManagedAsteriskProvisioningOperationHandler::class),
                    $app->make(ManagedFreeSwitchProvisioningOperationHandler::class),
                    ],
                ),
                new ManagedRuntimeDeprovisioningOperationHandler([
                    $app->make(ManagedAsteriskDeprovisioningOperationHandler::class),
                    $app->make(ManagedFreeSwitchProvisioningOperationHandler::class),
                ]),
            ]));
        });
        $this->app->singleton(EventNormalizerRegistry::class, function ($app): EventNormalizerRegistry {
            $catalog = $app->make(SimulatorCatalog::class);
            $asterisk = $app->make(AsteriskCatalog::class);
            $freeswitch = $app->make(FreeSwitchCatalog::class);

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
                new SimulatorEventNormalizer($catalog, $catalog->eventType('call_observation')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('connection_opened')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('connection_closed')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('runtime_info_observed')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('authentication_failed')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('event_listener_degraded')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('event_listener_recovered')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('unknown_event_observed')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('bridge_created')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('bridge_destroyed')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('channel_entered_bridge')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('channel_left_bridge')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('channel_destroyed')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('stasis_start')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('stasis_end')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('channel_state_change')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('channel_dtmf_received')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('playback_started')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('playback_finished')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('recording_started')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('recording_finished')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('channel_hold')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('channel_unhold')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('channel_mute')),
                new AsteriskAriEventNormalizer($asterisk, $asterisk->eventType('channel_unmute')),
                new KamailioRegistrationEventNormalizer('kamailio.registration.accepted'),
                new KamailioRegistrationEventNormalizer('kamailio.registration.refreshed'),
                new KamailioRegistrationEventNormalizer('kamailio.registration.replaced'),
                new KamailioRegistrationEventNormalizer('kamailio.registration.removed'),
                new KamailioRegistrationEventNormalizer('kamailio.registration.expired'),
                new FreeSwitchEventNormalizer($freeswitch, 'CHANNEL_CREATE'),
                new FreeSwitchEventNormalizer($freeswitch, 'CHANNEL_ANSWER'),
                new FreeSwitchEventNormalizer($freeswitch, 'CHANNEL_HOLD'),
                new FreeSwitchEventNormalizer($freeswitch, 'CHANNEL_UNHOLD'),
                new FreeSwitchEventNormalizer($freeswitch, 'CHANNEL_BRIDGE'),
                new FreeSwitchEventNormalizer($freeswitch, 'CHANNEL_UNBRIDGE'),
                new FreeSwitchEventNormalizer($freeswitch, 'CHANNEL_HANGUP_COMPLETE'),
                new FreeSwitchEventNormalizer($freeswitch, 'DTMF'),
                new FreeSwitchEventNormalizer($freeswitch, 'PLAYBACK_START'),
                new FreeSwitchEventNormalizer($freeswitch, 'PLAYBACK_STOP'),
                new FreeSwitchEventNormalizer($freeswitch, 'runtime.readiness.observed'),
            ]);
        });
        $this->app->singleton(ReconcilerRegistry::class, fn ($app): ReconcilerRegistry => new ReconcilerRegistry([
            new RuntimeNodeReconciler([
                new AsteriskRuntimeNodeReconciler(
                    $app->make(AsteriskCatalog::class),
                    $app->make(RuntimeRegistryService::class),
                ),
                new FreeSwitchRuntimeNodeReconciler(
                    $app->make(FreeSwitchCatalog::class),
                    $app->make(RuntimeRegistryService::class),
                ),
                $app->make(SimulatorRuntimeNodeReconciler::class),
            ]),
            $app->make(RuntimeNodeDrainCoordinator::class),
            $app->make(ConferenceReconciler::class),
            $app->make(ConferenceParticipantReconciler::class),
            $app->make(SignalingRegistrationReconciler::class),
            $app->make(CallOriginationReconciler::class),
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
