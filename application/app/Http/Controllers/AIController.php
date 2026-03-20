<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AIController extends Controller
{
    public function evaluate(Request $request)
    {
        // This is now a proper SCORER endpoint, not a proxy
        $question = $request->input('question');
        $answer = $request->input('answer');
        
        if (!$question || !$answer) {
            return response()->json([
                'error' => 'Missing required fields: question and answer'
            ], 400);
        }

        // Implement scoring logic here
        $scorecard = $this->scoreAnswer($question, $answer);
        $totalScore = $this->calculateTotalScore($scorecard);
        
        return response()->json([
            'scorecard' => $scorecard,
            'total_score' => $totalScore,
            'phase0_result' => $this->getPhase0Result($scorecard),
            'critical_hallucination' => $this->checkCriticalHallucination($answer),
            'generic_answer' => $this->checkGenericAnswer($answer)
        ]);
    }

    private function scoreAnswer($question, $answer)
    {
        // Basic scoring implementation
        $scorecard = [];
        
        // Check for required sections
        $requiredSections = [
            'Concept Overview:',
            'Relevant Equation(s):',
            'Define symbols used:',
            'Step-by-step Reasoning:',
            'Causal Chain Summary:',
            'Substitution:',
            'Unit check:',
            'Final Answer:',
            'Key Physics Terminology Used:',
            'Exam Marker Note'
        ];
        
        foreach ($requiredSections as $section) {
            $scorecard[$section] = strpos($answer, $section) !== false ? 1 : 0;
        }
        
        // Check for "therefore" in final answer
        $scorecard['contains_therefore'] = strpos(strtolower($answer), 'therefore') !== false ? 1 : 0;
        
        // Check unit check format
        $scorecard['proper_unit_check'] = strpos(strtolower($answer), 'unit check:') !== false ? 1 : 0;
        
        return $scorecard;
    }
    
    private function calculateTotalScore($scorecard)
    {
        $maxScore = count($scorecard);
        $actualScore = array_sum($scorecard);
        return $maxScore > 0 ? round(($actualScore / $maxScore) * 10, 1) : 0;
    }
    
    private function getPhase0Result($scorecard)
    {
        $totalScore = $this->calculateTotalScore($scorecard);
        
        if ($totalScore >= 8) {
            return 'PASS';
        } elseif ($totalScore >= 6) {
            return 'BORDERLINE';
        } else {
            return 'FAIL';
        }
    }
    
    private function checkCriticalHallucination($answer)
    {
        $falsehoods = [
            'energy is created from nothing',
            'anti-gravity',
            'negative mass makes free energy',
            'current is stored in a resistor'
        ];
        
        $answerLower = strtolower($answer);
        foreach ($falsehoods as $falsehood) {
            if (strpos($answerLower, $falsehood) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    private function checkGenericAnswer($answer)
    {
        // Check for generic/non-specific answers
        $genericPhrases = [
            'it depends',
            'not enough information',
            'cannot be determined',
            'more data needed'
        ];
        
        $answerLower = strtolower($answer);
        foreach ($genericPhrases as $phrase) {
            if (strpos($answerLower, $phrase) !== false) {
                return true;
            }
        }
        
        return false;
    }

    public function test()
    {
        return response()->json([
            'message' => 'Laravel AI service test endpoint working!',
            'timestamp' => now()
        ]);
    }
}
