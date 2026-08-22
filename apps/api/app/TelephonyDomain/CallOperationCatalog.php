<?php

namespace App\TelephonyDomain;

final class CallOperationCatalog
{
    /** @return array<string, array{target:string, capability:string}> */
    public static function all(): array
    {
        return [
            'call.leg.originate' => ['target' => 'call_leg', 'capability' => 'call.origination'],
            'call.leg.cancel_origination' => ['target' => 'call_leg', 'capability' => 'call.origination'],
            'call.leg.answer' => ['target' => 'call_leg', 'capability' => 'call.control'],
            'call.leg.hangup' => ['target' => 'call_leg', 'capability' => 'call.control'],
            'call.hangup' => ['target' => 'call', 'capability' => 'call.control'],
            'call.leg.hold' => ['target' => 'call_leg', 'capability' => 'call.hold'],
            'call.leg.resume' => ['target' => 'call_leg', 'capability' => 'call.hold'],
            'call.legs.bridge' => ['target' => 'relationship', 'capability' => 'call.control'],
            'call.legs.unbridge' => ['target' => 'relationship', 'capability' => 'call.control'],
            'call.leg.blind_transfer' => ['target' => 'call_leg', 'capability' => 'call.transfer'],
            'call.leg.attended_transfer' => ['target' => 'relationship', 'capability' => 'call.transfer'],
            'call.leg.redirect' => ['target' => 'call_leg', 'capability' => 'call.transfer'],
            'call.leg.mute' => ['target' => 'call_leg', 'capability' => 'call.control'],
            'call.leg.unmute' => ['target' => 'call_leg', 'capability' => 'call.control'],
            'call.leg.send_dtmf' => ['target' => 'call_leg', 'capability' => 'call.dtmf.send'],
            'call.leg.play_media' => ['target' => 'call_leg', 'capability' => 'media.playback'],
            'call.leg.stop_media' => ['target' => 'call_leg', 'capability' => 'media.playback'],
            'call.leg.start_recording' => ['target' => 'call_leg', 'capability' => 'recording'],
            'call.leg.stop_recording' => ['target' => 'call_leg', 'capability' => 'recording'],
        ];
    }
}
