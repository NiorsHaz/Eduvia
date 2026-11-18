<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'app');

// SPA history fallback — every non-API path renders the admin application.
Route::get('/{path?}', fn () => view('app'))->where('path', '.*');
