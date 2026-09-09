<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/assistant');
});

Route::view('/assistant', 'assistant');
Route::view('/mistral-raw', 'mistral-raw');
