<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AIController extends Controller
{
    public function evaluate(Request $request)
    {
        $response = Http::post('http://ai-service:8000/evaluate', [
            'question' => $request->input('question', 'What is Newtons second law?')
        ]);

        return response()->json($response->json());
    }

    public function test()
    {
        return response()->json([
            'message' => 'Laravel AI service test endpoint working!',
            'timestamp' => now()
        ]);
    }
}
