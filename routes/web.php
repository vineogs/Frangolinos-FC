<?php

use App\Http\Controllers\DayPaymentController;
use App\Http\Controllers\MatchLifecycleController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\PlayerProfileController;
use App\Http\Controllers\RachaController;
use App\Http\Controllers\RachaDayController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::get('/racha', [RachaController::class, 'show'])->name('racha.show');
Route::put('/racha', [RachaController::class, 'update'])->name('racha.update');

Route::get('/days', [RachaDayController::class, 'index'])->name('days.index');
Route::post('/days', [RachaDayController::class, 'store'])->name('days.store');
Route::put('/days/{day}/attendance', [RachaDayController::class, 'update'])->whereUuid('day')->name('days.attendance');

Route::post('/racha/finish', [MatchLifecycleController::class, 'finish']);
Route::post('/racha/penalties', [MatchLifecycleController::class, 'penalties']);
Route::post('/racha/next', [MatchLifecycleController::class, 'next']);
Route::post('/racha/substitutions', [MatchLifecycleController::class, 'substitute']);
Route::post('/days/{day}/availability', [MatchLifecycleController::class, 'availability'])->whereUuid('day');
Route::post('/days/{day}/finish', [MatchLifecycleController::class, 'closeDay'])->whereUuid('day');
Route::get('/days/{day}/statistics', [MatchLifecycleController::class, 'statistics'])->whereUuid('day');

Route::put('/days/{day}/payments', [DayPaymentController::class, 'update'])->whereUuid('day')->name('days.payments');

Route::post('/players', [PlayerController::class, 'store'])->name('players.store');
Route::get('/profile', [PlayerProfileController::class, 'show'])->name('profile.show');
Route::put('/profile', [PlayerProfileController::class, 'update'])->name('profile.update');
Route::put('/profile/days/{day}/attendance', [PlayerProfileController::class, 'attendance'])->whereUuid('day')->name('profile.attendance');

Route::put('/profile/days/{day}/payments', [PlayerProfileController::class, 'payment'])->whereUuid('day')->name('profile.payments');

Route::put('/days/{day}/schedule', [RachaDayController::class, 'schedule'])->whereUuid('day');
Route::get('/profile/notifications', [PlayerProfileController::class, 'notifications']);
Route::put('/profile/notifications/{notification}/read', [PlayerProfileController::class, 'readNotification'])->whereUuid('notification');

Route::post('/days/{day}/arrival', [MatchLifecycleController::class, 'arrival'])->whereUuid('day');
