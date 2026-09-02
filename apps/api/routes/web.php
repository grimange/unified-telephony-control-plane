<?php

use App\Http\Controllers\ControlPlane\AdminAuditRecordController;
use App\Http\Controllers\ControlPlane\AdminRuntimeOperationController;
use App\Http\Controllers\ControlPlane\AdminRuntimeReconciliationController;
use App\Http\Controllers\Identity\AdminMembershipController;
use App\Http\Controllers\Identity\AdminRoleController;
use App\Http\Controllers\Identity\AdminTenantController;
use App\Http\Controllers\Identity\AdminUserController;
use App\Http\Controllers\Identity\AuthController;
use App\Http\Controllers\Infrastructure\AdminKubernetesHostController;
use App\Http\Controllers\Infrastructure\AdminHostMaintenanceController;
use App\Http\Controllers\RuntimeProvisioning\AdminRuntimeProvisioningController;
use App\Http\Controllers\RuntimeRegistry\AdminRuntimeNodeController;
use App\Http\Controllers\TelephonyDomain\AdminC7aController;
use App\Http\Controllers\TelephonyDomain\AdminC7bController;
use App\Http\Controllers\TelephonyDomain\AdminConferenceController;
use App\Http\Controllers\TelephonyDomain\AdminMediaArchiveTargetController;
use App\Http\Controllers\TelephonyDomain\CallController;
use App\Http\Controllers\TelephonyDomain\ConferenceController;
use App\Http\Controllers\TelephonyDomain\ReferenceDialerController;
use App\Http\Controllers\TelephonyDomain\RecordingSessionController;
use App\Http\Controllers\TelephonyDomain\TelephonySessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => config('utcp.service.name'),
        'status' => 'ok',
    ]);
});

Route::prefix('api/v1/auth')->group(function (): void {
    Route::get('/csrf', [AuthController::class, 'csrf']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware(['identity.session'])->group(function (): void {
        Route::get('/session', [AuthController::class, 'session']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/tenant-context', [AuthController::class, 'selectTenant']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });
});

Route::prefix('api/v1/admin')->middleware(['identity.session'])->group(function (): void {
    Route::get('/tenants', [AdminTenantController::class, 'index']);
    Route::post('/tenants', [AdminTenantController::class, 'store']);
    Route::patch('/tenants/{tenantId}', [AdminTenantController::class, 'update']);

    Route::get('/users', [AdminUserController::class, 'index']);
    Route::post('/users', [AdminUserController::class, 'store']);
    Route::get('/users/{userId}', [AdminUserController::class, 'show']);
    Route::patch('/users/{userId}', [AdminUserController::class, 'update']);
    Route::post('/users/{userId}/password-reset', [AdminUserController::class, 'resetPassword']);
    Route::post('/users/{userId}/platform-roles', [AdminUserController::class, 'assignPlatformRole']);
    Route::post('/users/{userId}/telephony-sessions/{telephonySession}/end', [AdminUserController::class, 'endTelephonySession']);
    Route::post('/users/{userId}/telephony-sessions/{telephonySession}/signaling-credential', [AdminUserController::class, 'issueSignalingCredential']);

    Route::get('/memberships', [AdminMembershipController::class, 'index']);
    Route::post('/memberships', [AdminMembershipController::class, 'store']);
    Route::patch('/memberships/{membershipId}', [AdminMembershipController::class, 'update']);

    Route::get('/roles', [AdminRoleController::class, 'index']);

    Route::get('/runtime-operations', [AdminRuntimeOperationController::class, 'index']);
    Route::get('/infrastructure/hosts', [AdminKubernetesHostController::class, 'index']);
    Route::get('/infrastructure/maintenances', [AdminHostMaintenanceController::class, 'index']);
    Route::post('/infrastructure/hosts/{nodeUid}/maintenance', [AdminHostMaintenanceController::class, 'store']);
    Route::get('/runtime-operations/{runtimeOperation}', [AdminRuntimeOperationController::class, 'show']);
    Route::get('/runtime-reconciliations', [AdminRuntimeReconciliationController::class, 'index']);
    Route::get('/runtime-reconciliations/{runtimeReconciliation}', [AdminRuntimeReconciliationController::class, 'show']);
    Route::get('/audit-records', [AdminAuditRecordController::class, 'index']);
    Route::get('/audit-records/{auditRecord}', [AdminAuditRecordController::class, 'show']);

    Route::get('/runtime-node-catalog', [AdminRuntimeNodeController::class, 'catalog']);
    Route::get('/deployment-targets', [AdminRuntimeProvisioningController::class, 'targets']);
    Route::get('/deployment-targets/{deploymentTarget}', [AdminRuntimeProvisioningController::class, 'target']);
    Route::post('/runtime-provisioning', [AdminRuntimeProvisioningController::class, 'store']);
    Route::get('/runtime-provisioning/{runtimeProvisioning}', [AdminRuntimeProvisioningController::class, 'show']);
    Route::get('/runtime-nodes', [AdminRuntimeNodeController::class, 'index']);
    Route::post('/runtime-nodes', [AdminRuntimeNodeController::class, 'store']);
    Route::get('/runtime-nodes/{runtimeNode}', [AdminRuntimeNodeController::class, 'show']);
    Route::get('/runtime-nodes/{runtimeNode}/placement', [AdminRuntimeNodeController::class, 'placement']);
    Route::patch('/runtime-nodes/{runtimeNode}', [AdminRuntimeNodeController::class, 'update']);
    Route::post('/runtime-nodes/{runtimeNode}/desired-state', [AdminRuntimeNodeController::class, 'desiredState']);
    Route::post('/runtime-nodes/{runtimeNode}/decommission', [AdminRuntimeNodeController::class, 'decommission']);
    Route::post('/runtime-nodes/{runtimeNode}/endpoints', [AdminRuntimeNodeController::class, 'addEndpoint']);
    Route::patch('/runtime-nodes/{runtimeNode}/endpoints/{endpoint}', [AdminRuntimeNodeController::class, 'updateEndpoint']);
    Route::delete('/runtime-nodes/{runtimeNode}/endpoints/{endpoint}', [AdminRuntimeNodeController::class, 'removeEndpoint']);
    Route::put('/runtime-nodes/{runtimeNode}/capabilities', [AdminRuntimeNodeController::class, 'setCapabilities']);
    Route::get('/runtime-nodes/{runtimeNode}/adapter-configuration', [AdminRuntimeNodeController::class, 'adapterConfiguration']);
    Route::put('/runtime-nodes/{runtimeNode}/adapter-configuration', [AdminRuntimeNodeController::class, 'putAdapterConfiguration']);
    Route::get('/runtime-nodes/{runtimeNode}/runtime-evidence', [AdminRuntimeNodeController::class, 'runtimeEvidence']);
    Route::get('/runtime-nodes/{runtimeNode}/history', [AdminRuntimeNodeController::class, 'history']);
    Route::post('/runtime-nodes/{runtimeNode}/credentials', [AdminRuntimeNodeController::class, 'createCredential']);
    Route::post('/runtime-nodes/{runtimeNode}/credentials/{credential}/rotate', [AdminRuntimeNodeController::class, 'rotateCredential']);
    Route::post('/runtime-nodes/{runtimeNode}/credentials/{credential}/retire', [AdminRuntimeNodeController::class, 'retireCredential']);

    Route::get('/conferences', [AdminConferenceController::class, 'index']);
    Route::post('/conferences', [AdminConferenceController::class, 'store']);
    Route::get('/conferences/{conference}', [AdminConferenceController::class, 'show']);
    Route::post('/conferences/{conference}/desired-state', [AdminConferenceController::class, 'desiredState']);
    Route::post('/conferences/{conference}/runtime-binding', [AdminConferenceController::class, 'runtimeBinding']);
    Route::get('/conferences/{conference}/participants', [AdminConferenceController::class, 'participants']);
    Route::post('/conferences/{conference}/participants/{participant}/remove', [AdminConferenceController::class, 'removeParticipant']);

    Route::get('/external-trunks', [AdminC7aController::class, 'trunks']);
    Route::post('/external-trunks', [AdminC7aController::class, 'createTrunk']);
    Route::get('/external-trunks/{trunk}', [AdminC7aController::class, 'trunk']);
    Route::patch('/external-trunks/{trunk}', [AdminC7aController::class, 'updateTrunk']);
    Route::post('/external-trunks/{trunk}/desired-state', [AdminC7aController::class, 'trunkState']);
    Route::post('/external-trunks/{trunk}/endpoints', [AdminC7aController::class, 'endpoint']);
    Route::post('/external-trunks/{trunk}/endpoints/{endpoint}/desired-state', [AdminC7aController::class, 'endpointState']);
    Route::post('/external-trunks/{trunk}/addresses', [AdminC7aController::class, 'attachAddress']);
    Route::post('/external-trunks/{trunk}/credentials', [AdminC7aController::class, 'credential']);
    Route::get('/telephony-addresses', [AdminC7aController::class, 'addresses']);
    Route::post('/telephony-addresses', [AdminC7aController::class, 'createAddress']);
    Route::post('/telephony-addresses/{address}/desired-state', [AdminC7aController::class, 'addressState']);
    Route::get('/caller-identities', [AdminC7aController::class, 'callerIdentities']);
    Route::post('/caller-identities', [AdminC7aController::class, 'createCallerIdentity']);
    Route::post('/caller-identities/{identity}/desired-state', [AdminC7aController::class, 'callerIdentityState']);
    Route::post('/caller-identities/{identity}/policies', [AdminC7aController::class, 'callerIdentityPolicy']);

    Route::get('/recording-archive-targets', [AdminMediaArchiveTargetController::class, 'index']);
    Route::post('/recording-archive-targets', [AdminMediaArchiveTargetController::class, 'store']);
    Route::get('/recording-archive-targets/{target}', [AdminMediaArchiveTargetController::class, 'show']);
    Route::patch('/recording-archive-targets/{target}', [AdminMediaArchiveTargetController::class, 'update']);
    Route::post('/recording-archive-targets/{target}/desired-state', [AdminMediaArchiveTargetController::class, 'desiredState']);
    Route::put('/recording-archive-targets/{target}/credential', [AdminMediaArchiveTargetController::class, 'credential']);
    Route::get('/inbound-routes', [AdminC7bController::class, 'inbound']);
    Route::post('/inbound-routes', [AdminC7bController::class, 'createInbound']);
    Route::get('/outbound-routes', [AdminC7bController::class, 'outbound']);
    Route::post('/outbound-routes', [AdminC7bController::class, 'createOutbound']);
    Route::post('/{kind}-routes/{route}/desired-state', [AdminC7bController::class, 'state'])->whereIn('kind', ['inbound', 'outbound']);
});

Route::prefix('api/v1')->middleware(['identity.session'])->group(function (): void {
    Route::get('/calls', [CallController::class, 'index']);
    Route::post('/calls', [CallController::class, 'store']);
    Route::get('/calls/{call}', [CallController::class, 'show']);
    Route::get('/calls/{call}/legs', [CallController::class, 'legs']);
    Route::post('/calls/{call}/legs', [CallController::class, 'storeLeg']);
    Route::post('/calls/{call}/operations', [CallController::class, 'storeOperation']);
    Route::get('/calls/{call}/operations', [CallController::class, 'operations']);
    Route::get('/calls/{call}/timeline', [CallController::class, 'timeline']);
    Route::get('/calls/{call}/recordings', [RecordingSessionController::class, 'index']);
    Route::post('/calls/{call}/recordings', [RecordingSessionController::class, 'store']);
    Route::get('/calls/{call}/recordings/{recordingSession}', [RecordingSessionController::class, 'show']);
    Route::post('/calls/{call}/recordings/{recordingSession}/stop', [RecordingSessionController::class, 'stop']);
    Route::get('/reference-dialer/bootstrap', [ReferenceDialerController::class, 'bootstrap']);
    Route::post('/telephony/sessions', [TelephonySessionController::class, 'store']);
    Route::get('/telephony/sessions/current', [TelephonySessionController::class, 'current']);
    Route::post('/telephony/sessions/{telephonySession}/end', [TelephonySessionController::class, 'end']);
    Route::post('/telephony/sessions/{telephonySession}/signaling-credential', [TelephonySessionController::class, 'issueSignalingCredential']);
    Route::get('/telephony/sessions/{telephonySession}/signaling-credential', [TelephonySessionController::class, 'signalingCredential']);

    Route::get('/conferences', [ConferenceController::class, 'index']);
    Route::get('/conferences/{conference}', [ConferenceController::class, 'show']);
    Route::post('/conferences/{conference}/participants/self', [ConferenceController::class, 'joinSelf']);
    Route::delete('/conferences/{conference}/participants/self', [ConferenceController::class, 'removeSelf']);
});
