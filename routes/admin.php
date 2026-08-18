<?php

use Illuminate\Support\Facades\Route;
use TwillAi\Http\Controllers\ChatController;
use TwillAi\Http\Controllers\FileController;
use TwillAi\Http\Controllers\SettingsController;
use TwillAi\Http\Controllers\TwillAiPageController;

/*
|--------------------------------------------------------------------------
| Twill AI routes
|--------------------------------------------------------------------------
|
| Loaded by TwillAiServiceProvider inside the Twill admin context:
| middleware [web, twill_auth:twill_users, ...], prefix {admin_app_path}/ai,
| route name prefix {admin_route_name_prefix}ai. (e.g. twill.ai.*).
|
*/

Route::get('/', [TwillAiPageController::class, 'index'])->name('index');
Route::get('/bootstrap', [ChatController::class, 'bootstrap'])->name('bootstrap');
Route::get('/mentionables', [ChatController::class, 'mentionables'])->name('mentionables');

// Shared file library (Uploads page + composer "+"). Files live on the private
// twill-ai disk and are never web-public.
Route::get('/files', [FileController::class, 'index'])->name('files.index');
Route::post('/files', [FileController::class, 'store'])->middleware('throttle:60,1')->name('files.store');
Route::get('/files/{file}', [FileController::class, 'show'])->name('files.show');
Route::delete('/files/{file}', [FileController::class, 'destroy'])->name('files.destroy');

// Install-wide settings (provider, API key, default model, system prompt).
Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::put('/settings/key', [SettingsController::class, 'storeKey'])->middleware('throttle:20,1')->name('settings.key');
Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
Route::post('/settings/refresh-models', [SettingsController::class, 'refreshModels'])->middleware('throttle:20,1')->name('settings.refresh');

Route::get('/chats', [ChatController::class, 'index'])->name('chats.index');
Route::post('/chats', [ChatController::class, 'store'])->name('chats.store');
Route::get('/chats/{chat}', [ChatController::class, 'show'])->name('chats.show');
Route::patch('/chats/{chat}', [ChatController::class, 'update'])->name('chats.update');
Route::delete('/chats/{chat}', [ChatController::class, 'destroy'])->name('chats.destroy');

Route::post('/chats/{chat}/messages', [ChatController::class, 'message'])
    ->middleware('throttle:30,1')
    ->name('chats.messages');

Route::get('/chats/{chat}/events', [ChatController::class, 'events'])->name('chats.events');
Route::post('/chats/{chat}/cancel', [ChatController::class, 'cancel'])->name('chats.cancel');
