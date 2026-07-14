<?php

namespace App\Simulator;

use App\RuntimeEngine\Events\RuntimeEventReceiptRepository;
use Illuminate\Support\Facades\Log;

final class SimulatorEventSourceWorker
{
    public function __construct(
        private readonly SimulatorScheduledEventRepository $events,
        private readonly RuntimeEventReceiptRepository $receipts,
    ) {}

    public function workOnce(string $workerId, int $batchSize = 10): int
    {
        $published = 0;
        foreach ($this->events->claimDue($workerId, $batchSize) as $event) {
            try {
                $payload = json_decode((string) $event->payload, true, 512, JSON_THROW_ON_ERROR);
                $externalKey = is_array($payload) && isset($payload['external_event_key']) && is_string($payload['external_event_key'])
                    ? $payload['external_event_key']
                    : 'simulator:'.$event->runtime_node_id.':'.$event->connection_epoch_id.':'.$event->event_sequence;
                if (is_array($payload)) {
                    unset($payload['external_event_key']);
                }

                $this->receipts->ingest(
                    (string) $event->tenant_id,
                    (string) $event->runtime_node_id,
                    (string) config('simulator.adapter_key', 'simulator-deterministic'),
                    (string) $event->connection_epoch_id,
                    $externalKey,
                    (string) $event->event_type,
                    (int) $event->event_version,
                    is_array($payload) ? $payload : [],
                );
                if (! $this->events->markPublished((string) $event->id, (string) $event->lease_token)) {
                    throw new \RuntimeException('simulator scheduled-event fencing token was superseded');
                }
                if ($event->event_type === config('simulator.event_types.connection_closed')) {
                    $this->receipts->closeEpoch((string) $event->connection_epoch_id, 'simulator-event-source');
                }

                Log::info('simulator event published', [
                    'component' => 'simulator-event-source',
                    'event_type' => (string) $event->event_type,
                    'result' => 'published',
                ]);
                $published++;
            } catch (\Throwable) {
                $this->events->markFailed((string) $event->id, (string) $event->lease_token, ((int) $event->attempt_count) < 3);
            }
        }

        return $published;
    }
}
