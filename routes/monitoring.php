<?php

use Abigah\BotCopTrafficDivision\Livewire\Dashboard;
use Abigah\BotCopTrafficDivision\Livewire\Incidents;
use Abigah\BotCopTrafficDivision\Livewire\MonitorHistory;
use Abigah\BotCopTrafficDivision\Livewire\NotificationPreferences;
use Abigah\BotCopTrafficDivision\Livewire\Probers;
use Abigah\BotCopTrafficDivision\Livewire\SiteOverview;
use Abigah\BotCopTrafficDivision\Livewire\Sites;
use Illuminate\Support\Facades\Route;

/*
| The screens. Registered under the prefix and middleware from
| config('monitoring.*'), so they sit behind whatever authentication the host
| already uses.
|
| The mute link is deliberately not here: it is signed rather than
| authenticated, and route-level middleware adds to a group's rather than
| replacing it, so a mute route inside this group would inherit the auth stack
| it exists to avoid. It lives in mute.php.
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
