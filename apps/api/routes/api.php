<?php

use App\Http\Controllers\Platform\LivenessController;
use App\Http\Controllers\Platform\ReadinessController;
use App\Http\Controllers\Platform\VersionController;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', LivenessController::class);
Route::get('/health/ready', ReadinessController::class);
Route::get('/version', VersionController::class);
