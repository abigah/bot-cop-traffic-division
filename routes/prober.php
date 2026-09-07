<?php

use Abigah\BotCopTrafficDivision\Http\Controllers\ExceptionsController;
use Abigah\BotCopTrafficDivision\Http\Controllers\HeartbeatsController;
use Abigah\BotCopTrafficDivision\Http\Controllers\ManifestController;
use Abigah\BotCopTrafficDivision\Http\Controllers\ResultsController;
use Illuminate\Support\Facades\Route;

/*
| The prober-facing API.
|
| These never sit behind session middleware: a prober has no session, no
| cookies and no CSRF token. It authenticates by HMAC over the raw request
| body and nothing else.
*/

Route::get('manifest', ManifestController::class)->name('monitoring.manifest');
Route::post('results', ResultsController::class)->name('monitoring.results');
Route::post('heartbeats', HeartbeatsController::class)->name('monitoring.heartbeats');
Route::post('exceptions', ExceptionsController::class)->name('monitoring.exceptions');
