<?php

use Abigah\BotCopTrafficDivision\Http\Controllers\MuteIncidentController;
use Illuminate\Support\Facades\Route;

/*
| The "stop telling me about this" link in a notification.
|
| Signed and per recipient rather than authenticated, and in its own file for
| that reason: someone woken at 3am should not have to log in to make the phone
| stop. Registering it beside the screens would give it their auth stack, since
| route-level middleware adds to a group's rather than replacing it.
*/

Route::get('/incidents/{incident}/mute/{notifiable}', MuteIncidentController::class)
    ->name('monitoring.incident.mute');
