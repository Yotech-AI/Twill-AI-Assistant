<?php

use Illuminate\Support\Facades\Route;
use TwillAi\PluginPage\Http\Controllers\PluginsController;

Route::get('plugins', [PluginsController::class, 'index'])->name('plugins.index');
