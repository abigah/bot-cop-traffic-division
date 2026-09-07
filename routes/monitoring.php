<?php

use Abigah\BotCopTrafficDivision\Http\Controllers\MuteIncidentController;
use Abigah\BotCopTrafficDivision\Livewire\Dashboard;
use Abigah\BotCopTrafficDivision\Livewire\Incidents;
use Abigah\BotCopTrafficDivision\Livewire\MonitorHistory;
use Abigah\BotCopTrafficDivision\Livewire\NotificationPreferences;
use Abigah\BotCopTrafficDivision\Livewire\Probers;
use Abigah\BotCopTrafficDivision\Livewire\SiteOverview;
use Abigah\BotCopTrafficDivision\Livewire\Sites;
use Illuminate\Support\Facades\Route;

/*
| Registered under the prefix and middleware from config('monitoring.*').
|
| The mute link is signed rather than authenticated: someone woken at 3am
| should not have to log in to make the phone stop.
*/

/*
| A Livewire component is routable directly. Route::livewire() would also work
| where the macro is registered, but it is not always by the time a package's
| routes load, and this is the form that works in every version.
*/
Route::get('/monitoring', Dashboard::class)->name('monitoring.dashboard');
Route::get('/monitoring/sites', Sites::class)->name('monitoring.sites');
Route::get('/monitoring/sites/{site}', SiteOverview::class)->name('monitoring.site');
Route::get('/monitoring/monitors/{monitor}', MonitorHistory::class)->name('monitoring.monitor.history');
Route::get('/monitoring/incidents', Incidents::class)->name('monitoring.incidents');
Route::get('/monitoring/preferences', NotificationPreferences::class)->name('monitoring.preferences');
Route::get('/monitoring/probers', Probers::class)->name('monitoring.probers');

Route::get('/incidents/{incident}/mute/{notifiable}', MuteIncidentController::class)
    ->middleware(['signed'])
    ->name('monitoring.incident.mute');
