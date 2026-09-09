<?php

use App\Http\Controllers\AssistantController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\KnowledgeVersionController;
use App\Http\Controllers\MistralRawController;
use Illuminate\Support\Facades\Route;

Route::post('/ask', [AssistantController::class, 'ask'])->middleware('throttle:30,1');
Route::post('/mistral-raw/ask', [MistralRawController::class, 'ask'])->middleware('throttle:30,1');
Route::post('/ticket', [AssistantController::class, 'ticket'])->middleware('throttle:10,1');
Route::get('/status', [AssistantController::class, 'status']);

Route::prefix('knowledge')->middleware(['throttle:10,1', 'knowledge.admin'])->group(function (): void {
    Route::get('/sources', [KnowledgeController::class, 'index']);
    Route::post('/sources', [KnowledgeController::class, 'store']);
    Route::get('/versions', [KnowledgeVersionController::class, 'index']);
    Route::post('/versions', [KnowledgeVersionController::class, 'store']);
    Route::post('/versions/rollback', [KnowledgeVersionController::class, 'rollback']);
    Route::post('/versions/{knowledgeVersion}/validate', [KnowledgeVersionController::class, 'validateVersion']);
    Route::post('/versions/{knowledgeVersion}/activate', [KnowledgeVersionController::class, 'activate']);
});
