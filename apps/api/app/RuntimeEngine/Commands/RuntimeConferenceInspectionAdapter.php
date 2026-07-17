<?php

namespace App\RuntimeEngine\Commands;

interface RuntimeConferenceInspectionAdapter
{
    public function inspectConferenceRuntime(string $tenantId, string $runtimeNodeId, string $conferenceId, ?string $participantId = null): RuntimeConferenceInspectionResult;

    public function recordConferenceRuntimeInspectionEvidence(string $tenantId, string $runtimeNodeId, string $conferenceId, ?string $participantId = null): bool;
}
