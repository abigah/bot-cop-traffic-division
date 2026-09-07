<?php

use Abigah\BotCopTrafficDivision\Http\Controllers\PingController;
use Abigah\BotCopTrafficDivision\Http\Controllers\ReportExceptionController;
use Illuminate\Support\Facades\Route;

/*
| What a site's own code calls.
|
| Authenticated by the token in the path, not by HMAC: these come from a queue
| worker or a deploy script, which cannot be trusted to hold a tenant secret and
| must stay fire-and-forget. A token grants nothing but the ability to say that
| one heartbeat fired or that one site threw an error. They are throttled
| instead.
|
| In remote mode a prober owns these endpoints and the client pings that. These
| are the local-mode counterparts, so heartbeats and exception reporting work on
| an extranet with no prober yet.
*/

Route::match(['get', 'post'], 'ping/{token}/{action}', PingController::class)
    ->where('action', 'start|fail')
    ->name('monitoring.ping.action');

Route::match(['get', 'post'], 'ping/{token}', PingController::class)
    ->name('monitoring.ping');

Route::post('report/{token}', ReportExceptionController::class)
    ->name('monitoring.report');
