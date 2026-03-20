<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\AIController;

Route::get('/test', [AIController::class, 'test']);
Route::post('/evaluate', [AIController::class, 'evaluate']);
Route::get('/redis-test', function () {
    \Illuminate\Support\Facades\Cache::put('test', 'working', 60);
    return \Illuminate\Support\Facades\Cache::get('test');
});
Route::get('/ai-test', function () {
    $response = Http::post('http://ai-service:8000/evaluate', [
        'question' => 'What is Newtons second law?'
    ]);

    return $response->json();
});
