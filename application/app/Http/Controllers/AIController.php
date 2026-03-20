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
        // Quality-based scoring implementation (0-3 per section)
        $scorecard = [];
        
        // Check for required sections with quality scoring
        $sections = [
            'Concept Overview:' => $this->scoreConceptOverview($answer),
            'Relevant Equation(s):' => $this->scoreRelevantEquations($answer),
            'Define symbols used:' => $this->scoreSymbolDefinitions($answer),
            'Step-by-step Reasoning:' => $this->scoreStepByStepReasoning($answer),
            'Causal Chain Summary:' => $this->scoreCausalChain($answer),
            'Substitution:' => $this->scoreSubstitution($answer),
            'Unit check:' => $this->scoreUnitCheck($answer),
            'Final Answer:' => $this->scoreFinalAnswer($answer),
            'Key Physics Terminology Used:' => $this->scorePhysicsTerminology($answer),
            'Exam Marker Note' => $this->scoreExamMarkerNote($answer)
        ];
        
        $scorecard = array_merge($scorecard, $sections);
        
        // Global quality checks
        $scorecard['contains_therefore'] = $this->scoreThereforeUsage($answer);
        $scorecard['proper_unit_check'] = $this->scoreUnitCheckQuality($answer);
        $scorecard['reasoning_depth'] = $this->scoreReasoningDepth($answer);
        $scorecard['physics_accuracy'] = $this->scorePhysicsAccuracy($question, $answer);
        
        return $scorecard;
    }
    
    private function scoreConceptOverview($answer)
    {
        if (strpos($answer, 'Concept Overview:') === false) return 0;
        
        $overview = $this->extractSection($answer, 'Concept Overview:');
        
        // 3 = Clear, comprehensive explanation
        if (strlen($overview) > 200 && strpos($overview, 'law') !== false) return 3;
        // 2 = Correct but basic explanation  
        if (strlen($overview) > 100) return 2;
        // 1 = Minimal explanation
        return 1;
    }
    
    private function scoreRelevantEquations($answer)
    {
        if (strpos($answer, 'Relevant Equation(s):') === false) return 0;
        
        $section = $this->extractSection($answer, 'Relevant Equation(s):');
        
        // Count equations (look for = signs and physics notation)
        $equationCount = substr_count($section, '=');
        $hasPhysicsVars = preg_match('/[a-z][A-Z]|[Fmav]/', $section);
        
        if ($equationCount >= 2 && $hasPhysicsVars) return 3;
        if ($equationCount >= 1) return 2;
        return 1;
    }
    
    private function scoreSymbolDefinitions($answer)
    {
        if (strpos($answer, 'Define symbols used:') === false) return 0;
        
        $section = $this->extractSection($answer, 'Define symbols used:');
        
        // Count symbol definitions (look for "symbol = definition" pattern)
        $definitionCount = preg_match_all('/[a-zA-Z]\s*=\s*[^,\n]+/', $section);
        
        if ($definitionCount >= 4) return 3;
        if ($definitionCount >= 2) return 2;
        if ($definitionCount >= 1) return 1;
        return 0;
    }
    
    private function scoreStepByStepReasoning($answer)
    {
        if (strpos($answer, 'Step-by-step Reasoning:') === false) return 0;
        
        $reasoning = $this->extractSection($answer, 'Step-by-step Reasoning:');
        
        // Quality indicators
        $stepCount = preg_match_all('/\d+\.|First|Second|Third|Finally/', $reasoning);
        $hasCalculation = preg_match('/\d+.*[+\-*/=]/', $reasoning);
        $hasExplanation = strlen($reasoning) > 150;
        
        if ($stepCount >= 3 && $hasCalculation && $hasExplanation) return 3;
        if ($stepCount >= 2 && $hasExplanation) return 2;
        if ($hasExplanation) return 1;
        return 0;
    }
    
    private function scoreCausalChain($answer)
    {
        if (strpos($answer, 'Causal Chain Summary:') === false) return 0;
        
        $section = $this->extractSection($answer, 'Causal Chain Summary:');
        
        // Look for causal indicators
        $causalWords = ['because', 'therefore', 'hence', 'thus', 'as a result', 'due to'];
        $causalCount = 0;
        foreach ($causalWords as $word) {
            $causalCount += substr_count(strtolower($section), $word);
        }
        
        if ($causalCount >= 3) return 3;
        if ($causalCount >= 2) return 2;
        if ($causalCount >= 1) return 1;
        return 0;
    }
    
    private function scoreSubstitution($answer)
    {
        if (strpos($answer, 'Substitution:') === false) return 0;
        
        $section = $this->extractSection($answer, 'Substitution:');
        
        if (strtolower(trim($section)) === 'not applicable for conceptual question') {
            return 2; // Appropriate for conceptual questions
        }
        
        // Look for numerical substitution
        if (preg_match('/\d+.*[+\-*/].*=.*\d+/', $section)) return 3;
        if (preg_match('/\d+.*=/', $section)) return 2;
        return 1;
    }
    
    private function scoreUnitCheck($answer)
    {
        if (strpos($answer, 'Unit check:') === false) return 0;
        
        $section = $this->extractSection($answer, 'Unit check:');
        
        if (strtolower(trim($section)) === 'unit check: not required (conceptual explanation)') {
            return 2; // Appropriate for conceptual
        }
        
        // Look for proper unit analysis
        if (preg_match('/kg.*m\/s.*=.*n|joule|watt|coulomb/', strtolower($section))) return 3;
        if (strpos(strtolower($section), 'unit check:') === 0) return 2;
        return 1;
    }
    
    private function scoreFinalAnswer($answer)
    {
        if (strpos($answer, 'Final Answer:') === false) return 0;
        
        $section = $this->extractSection($answer, 'Final Answer:');
        
        $hasTherefore = strpos(strtolower($section), 'therefore') !== false;
        $hasNumericalResult = preg_match('/\d+\.?\d*\s*[a-zA-Z²³]*$/', trim($section));
        
        if ($hasTherefore && $hasNumericalResult) return 3;
        if ($hasTherefore) return 2;
        if (strlen($section) > 20) return 1;
        return 0;
    }
    
    private function scorePhysicsTerminology($answer)
    {
        if (strpos($answer, 'Key Physics Terminology Used:') === false) return 0;
        
        $section = $this->extractSection($answer, 'Key Physics Terminology Used:');
        
        // Count physics terms
        $physicsTerms = ['force', 'acceleration', 'velocity', 'momentum', 'energy', 'power', 'resistance', 'current', 'voltage'];
        $termCount = 0;
        foreach ($physicsTerms as $term) {
            $termCount += substr_count(strtolower($section), $term);
        }
        
        if ($termCount >= 4) return 3;
        if ($termCount >= 2) return 2;
        if ($termCount >= 1) return 1;
        return 0;
    }
    
    private function scoreExamMarkerNote($answer)
    {
        if (strpos($answer, 'Exam Marker Note') === false) return 0;
        
        $section = $this->extractSection($answer, 'Exam Marker Note');
        
        $hasText = strlen($section) > 20;
        $hasCommonMistake = strpos(strtolower($section), 'common mistake') !== false;
        
        if ($hasText && $hasCommonMistake) return 3;
        if ($hasText) return 2;
        return 1;
    }
    
    private function scoreThereforeUsage($answer)
    {
        $count = substr_count(strtolower($answer), 'therefore');
        if ($count >= 2) return 3;
        if ($count >= 1) return 2;
        return 0;
    }
    
    private function scoreUnitCheckQuality($answer)
    {
        if (preg_match('/unit check:\s*[^,\n]+/i', $answer)) return 3;
        if (strpos(strtolower($answer), 'unit check') !== false) return 2;
        return 0;
    }
    
    private function scoreReasoningDepth($answer)
    {
        $wordCount = str_word_count($answer);
        $sentenceCount = preg_match('/[.!?]+/', $answer);
        
        if ($wordCount > 300 && $sentenceCount > 10) return 3;
        if ($wordCount > 200) return 2;
        if ($wordCount > 100) return 1;
        return 0;
    }
    
    private function scorePhysicsAccuracy($question, $answer)
    {
        // Basic physics sanity checks
        $answerLower = strtolower($answer);
        
        // Check for common physics falsehoods
        $falsehoods = [
            'energy is created from nothing',
            'anti-gravity exists',
            'negative mass produces free energy',
            'current is stored in resistors'
        ];
        
        foreach ($falsehoods as $falsehood) {
            if (strpos($answerLower, $falsehood) !== false) return 0;
        }
        
        // Check for correct physics concepts
        $correctConcepts = [
            'newton' => ['force', 'law'],
            'acceleration' => ['velocity', 'change'],
            'force' => ['mass', 'acceleration']
        ];
        
        $conceptScore = 1;
        foreach ($correctConcepts as $concept => $related) {
            if (strpos($question, $concept) !== false) {
                foreach ($related as $term) {
                    if (strpos($answerLower, $term) !== false) {
                        $conceptScore = 3;
                        break 2;
                    }
                }
                $conceptScore = max($conceptScore, 2);
            }
        }
        
        return $conceptScore;
    }
    
    private function extractSection($answer, $sectionHeader)
    {
        $startPos = strpos($answer, $sectionHeader);
        if ($startPos === false) return '';
        
        $startPos += strlen($sectionHeader);
        $nextSection = strpos($answer, "\n\n", $startPos);
        
        if ($nextSection === false) {
            return substr($answer, $startPos);
        }
        
        return substr($answer, $startPos, $nextSection - $startPos);
    }
    
    private function calculateTotalScore($scorecard)
    {
        $maxScore = count($scorecard) * 3; // Each section now scored 0-3
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
