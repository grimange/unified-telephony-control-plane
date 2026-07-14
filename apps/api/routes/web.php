<?php

use App\Http\Controllers\Identity\AdminMembershipController;
use App\Http\Controllers\Identity\AdminRoleController;
use App\Http\Controllers\Identity\AdminTenantController;
use App\Http\Controllers\Identity\AdminUserController;
use App\Http\Controllers\Identity\AuthController;
use App\Http\Controllers\RuntimeRegistry\AdminRuntimeNodeController;
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
    Route::patch('/users/{userId}', [AdminUserController::class, 'update']);
    Route::post('/users/{userId}/password-reset', [AdminUserController::class, 'resetPassword']);
    Route::post('/users/{userId}/platform-roles', [AdminUserController::class, 'assignPlatformRole']);

    Route::get('/memberships', [AdminMembershipController::class, 'index']);
    Route::post('/memberships', [AdminMembershipController::class, 'store']);
    Route::patch('/memberships/{membershipId}', [AdminMembershipController::class, 'update']);

    Route::get('/roles', [AdminRoleController::class, 'index']);

    Route::get('/runtime-nodes', [AdminRuntimeNodeController::class, 'index']);
    Route::post('/runtime-nodes', [AdminRuntimeNodeController::class, 'store']);
    Route::get('/runtime-nodes/{runtimeNode}', [AdminRuntimeNodeController::class, 'show']);
    Route::patch('/runtime-nodes/{runtimeNode}', [AdminRuntimeNodeController::class, 'update']);
    Route::post('/runtime-nodes/{runtimeNode}/desired-state', [AdminRuntimeNodeController::class, 'desiredState']);
    Route::post('/runtime-nodes/{runtimeNode}/endpoints', [AdminRuntimeNodeController::class, 'addEndpoint']);
    Route::patch('/runtime-nodes/{runtimeNode}/endpoints/{endpoint}', [AdminRuntimeNodeController::class, 'updateEndpoint']);
    Route::delete('/runtime-nodes/{runtimeNode}/endpoints/{endpoint}', [AdminRuntimeNodeController::class, 'removeEndpoint']);
    Route::put('/runtime-nodes/{runtimeNode}/capabilities', [AdminRuntimeNodeController::class, 'setCapabilities']);
    Route::get('/runtime-nodes/{runtimeNode}/adapter-configuration', [AdminRuntimeNodeController::class, 'adapterConfiguration']);
    Route::put('/runtime-nodes/{runtimeNode}/adapter-configuration', [AdminRuntimeNodeController::class, 'putAdapterConfiguration']);
    Route::post('/runtime-nodes/{runtimeNode}/credentials', [AdminRuntimeNodeController::class, 'createCredential']);
    Route::post('/runtime-nodes/{runtimeNode}/credentials/{credential}/rotate', [AdminRuntimeNodeController::class, 'rotateCredential']);
    Route::post('/runtime-nodes/{runtimeNode}/credentials/{credential}/retire', [AdminRuntimeNodeController::class, 'retireCredential']);
});
