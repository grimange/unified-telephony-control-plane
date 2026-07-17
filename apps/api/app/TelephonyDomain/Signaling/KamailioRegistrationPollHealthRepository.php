<?php

namespace App\TelephonyDomain\Signaling;

use Illuminate\Support\Facades\DB;

final class KamailioRegistrationPollHealthRepository
{
    public const ROW_ID = 'kamailio-registration-observer-poll-health';

    public function recordSuccess(): void
    {
        $this->increment('poll_success_count', 'last_success_at');
    }

    public function recordFailure(): void
    {
        $this->increment('poll_failure_count', 'last_failure_at');
    }

    /**
     * @return array{poll_success_count:int,poll_failure_count:int,last_success_at:?string,last_failure_at:?string}|null
     */
    public function current(): ?array
    {
        $row = DB::table('kamailio_registration_poll_health')->where('id', self::ROW_ID)->first();
        if ($row === null) {
            return null;
        }

        return [
            'poll_success_count' => (int) $row->poll_success_count,
            'poll_failure_count' => (int) $row->poll_failure_count,
            'last_success_at' => $row->last_success_at,
            'last_failure_at' => $row->last_failure_at,
        ];
    }

    private function increment(string $countColumn, string $timestampColumn): void
    {
        DB::transaction(function () use ($countColumn, $timestampColumn): void {
            $row = DB::table('kamailio_registration_poll_health')->where('id', self::ROW_ID)->lockForUpdate()->first();
            if ($row === null) {
                DB::table('kamailio_registration_poll_health')->insert([
                    'id' => self::ROW_ID,
                    'poll_success_count' => 0,
                    'poll_failure_count' => 0,
                    $countColumn => 1,
                    $timestampColumn => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return;
            }

            DB::table('kamailio_registration_poll_health')->where('id', self::ROW_ID)->update([
                $countColumn => ((int) $row->{$countColumn}) + 1,
                $timestampColumn => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
