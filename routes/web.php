<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/mistral-raw');

// The "Bureau d'intégration" page was removed: old links land on PVIT Assistant.
Route::redirect('/assistant', '/mistral-raw');
Route::view('/mistral-raw', 'mistral-raw');
