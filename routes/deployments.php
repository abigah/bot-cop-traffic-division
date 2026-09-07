<?php

use Abigah\BotCopTrafficDivision\Http\Controllers\DeploymentController;
use Illuminate\Support\Facades\Route;

Route::get('deployments/site/{site}/start', [DeploymentController::class, 'startSite'])
    ->name('monitoring.deployment.start');

Route::get('deployments/site/{site}/finish', [DeploymentController::class, 'finishSite'])
    ->name('monitoring.deployment.finish');

Route::get('deployments/monitor/{monitor}/start', [DeploymentController::class, 'startMonitor'])
    ->name('monitoring.deployment.monitor.start');

Route::get('deployments/monitor/{monitor}/finish', [DeploymentController::class, 'finishMonitor'])
    ->name('monitoring.deployment.monitor.finish');
