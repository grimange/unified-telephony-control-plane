<?php

namespace App\RuntimeAdapters\Asterisk;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class AsteriskConferenceParticipantBindingRetryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const RETRY_DELAYS_SECONDS = [1, 2, 3, 5, 8, 10, 10, 10, 10, 10, 10];

    public int $tries = 1;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $runtimeNodeId,
        public readonly string $channelId,
        public readonly string $admissionReference,
        public readonly int $attempt = 1,
    ) {}

    public function uniqueId(): string
    {
        return implode(':', [$this->tenantId, $this->runtimeNodeId, $this->channelId, $this->admissionReference, $this->attempt]);
    }

    public function uniqueFor(): int
    {
        return 120;
    }

    public function handle(AsteriskConferenceParticipantBinder $binder, AsteriskAriClient $client): void
    {
        $node = DB::table('runtime_nodes')
            ->where('id', $this->runtimeNodeId)
            ->where('tenant_id', $this->tenantId)
            ->first();
        if ($node === null || $this->attempt > count(self::RETRY_DELAYS_SECONDS) + 1) {
            return;
        }

        try {
            if (! $client->inboundConferenceChannelExists($this->tenantId, $this->runtimeNodeId, $this->channelId)) {
                return;
            }
        } catch (AsteriskAriException $exception) {
            if (! $exception->retryable) {
                return;
            }

            $this->dispatchNext();

            return;
        }

        $result = $binder->bind($node, [
            'channel_id' => $this->channelId,
            'application_args' => [$this->admissionReference],
        ]);
        if ($result === AsteriskConferenceParticipantBindResult::RETRYABLE) {
            $this->dispatchNext();
        }
    }

    private function dispatchNext(): void
    {
        if ($this->attempt >= count(self::RETRY_DELAYS_SECONDS) + 1) {
            return;
        }

        self::dispatch(
            $this->tenantId,
            $this->runtimeNodeId,
            $this->channelId,
            $this->admissionReference,
            $this->attempt + 1,
        )->delay(now()->addSeconds(self::RETRY_DELAYS_SECONDS[$this->attempt - 1]));
    }
}
