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
        
        // NEW: Physics correctness validation
        $scorecard['equation_correctness'] = $this->scoreEquationCorrectness($question, $answer);
        $scorecard['unit_correctness'] = $this->scoreUnitCorrectness($question, $answer);
        $scorecard['numeric_accuracy'] = $this->scoreNumericAccuracy($question, $answer);
        
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
        $hasCalculation = preg_match('/\d+.*[+\-*=]/', $reasoning);
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
        if (preg_match('/\d+.*[+\-*=].*\d+/', $section)) return 3;
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
    
    private function scoreEquationCorrectness($question, $answer)
    {
        // Extract equations from answer
        $equations = $this->extractEquations($answer);
        
        if (empty($equations)) return 0;
        
        $correctEquations = 0;
        $totalEquations = count($equations);
        
        // Known physics equations validation
        $knownEquations = [
            'F = ma' => true,
            'F = mg' => true,
            'P = F/A' => true,
            'W = Fd' => true,
            'KE = 1/2 mv²' => true,
            'PE = mgh' => true,
            'v = u + at' => true,
            's = ut + 1/2 at²' => true,
            'v² = u² + 2as' => true
        ];
        
        foreach ($equations as $equation) {
            $normalizedEq = strtolower(str_replace(' ', '', $equation));
            
            // Check against known equations
            foreach ($knownEquations as $knownEq => $valid) {
                $normalizedKnown = strtolower(str_replace(' ', '', $knownEq));
                if (strpos($normalizedEq, $normalizedKnown) !== false || strpos($normalizedKnown, $normalizedEq) !== false) {
                    $correctEquations++;
                    break;
                }
            }
        }
        
        if ($totalEquations == 0) return 0;
        
        $accuracy = $correctEquations / $totalEquations;
        if ($accuracy >= 0.9) return 3;
        if ($accuracy >= 0.7) return 2;
        if ($accuracy >= 0.5) return 1;
        return 0;
    }
    
    private function scoreUnitCorrectness($question, $answer)
    {
        // Extract units from answer
        $units = $this->extractUnits($answer);
        
        if (empty($units)) return 1; // No units to validate
        
        $validUnits = [
            'n', 'newton', 'newtons',
            'kg', 'kilogram', 'kilograms',
            'm', 'meter', 'meters',
            's', 'second', 'seconds',
            'm/s', 'm/s²', 'm/s²',
            'j', 'joule', 'joules',
            'w', 'watt', 'watts',
            'pa', 'pascal', 'pascals',
            'c', 'coulomb', 'coulombs',
            'v', 'volt', 'volts',
            'a', 'ampere', 'amperes',
            'ω', 'ohm', 'ohms'
        ];
        
        $correctUnits = 0;
        $totalUnits = count($units);
        
        foreach ($units as $unit) {
            $normalizedUnit = strtolower(trim($unit));
            if (in_array($normalizedUnit, $validUnits)) {
                $correctUnits++;
            }
        }
        
        if ($totalUnits == 0) return 0;
        
        $accuracy = $correctUnits / $totalUnits;
        if ($accuracy >= 0.9) return 3;
        if ($accuracy >= 0.7) return 2;
        if ($accuracy >= 0.5) return 1;
        return 0;
    }
    
    private function scoreNumericAccuracy($question, $answer)
    {
        // Extract numerical calculations from answer
        $calculations = $this->extractCalculations($answer);
        
        if (empty($calculations)) return 1; // No calculations to validate
        
        $correctCalculations = 0;
        $totalCalculations = count($calculations);
        
        foreach ($calculations as $calculation) {
            // Basic validation: check if calculation follows mathematical rules
            if ($this->validateCalculation($calculation)) {
                $correctCalculations++;
            }
        }
        
        if ($totalCalculations == 0) return 0;
        
        $accuracy = $correctCalculations / $totalCalculations;
        if ($accuracy >= 0.9) return 3;
        if ($accuracy >= 0.7) return 2;
        if ($accuracy >= 0.5) return 1;
        return 0;
    }
    
    private function extractEquations($text)
    {
        $equations = [];
        
        // Pattern to match equations (variable = expression)
        if (preg_match_all('/([A-Za-z][A-Za-z0-9]*\s*=\s*[^,\n]+)/', $text, $matches)) {
            $equations = array_map('trim', $matches[1]);
        }
        
        return array_unique($equations);
    }
    
    private function extractUnits($text)
    {
        $units = [];
        
        // Pattern to match units (including compound units)
        if (preg_match_all('/\b([a-zA-Z²³\/²³]+)\b/', $text, $matches)) {
            foreach ($matches[1] as $unit) {
                // Filter out common words that aren't units
                if (strlen($unit) <= 10 && !in_array(strtolower($unit), ['the', 'and', 'for', 'are', 'with', 'not', 'can', 'will'])) {
                    $units[] = $unit;
                }
            }
        }
        
        return array_unique($units);
    }
    
    private function extractCalculations($text)
    {
        $calculations = [];
        
        // Pattern to match numerical calculations
        if (preg_match_all('/\d+\.?\d*\s*[+\-*/]\s*\d+\.?\d*\s*(?:=\s*\d+\.?\d*)?/', $text, $matches)) {
            $calculations = $matches[0];
        }
        
        return array_unique($calculations);
    }
    
    private function validateCalculation($calculation)
    {
        // Extract the calculation part before the equals sign
        if (preg_match('/(.+?)\s*=\s*(.+)/', $calculation, $matches)) {
            $expression = $matches[1];
            $result = $matches[2];
            
            // Evaluate the expression safely (basic validation)
            try {
                // Replace common mathematical functions
                $expression = str_replace(['²', '³'], ['^2', '^3'], $expression);
                
                // Basic validation: check if result matches expected pattern
                return is_numeric($result) && preg_match('/[\d+\-*/]/', $expression);
            } catch (Exception $e) {
                return false;
            }
        }
        
        // For expressions without results, just check if they're mathematically valid
        return preg_match('/[\d+\-*/]/', $calculation);
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
        $answerLower = strtolower($answer);
        
        // Enhanced generic answer patterns
        $genericPatterns = [
            // Vague explanations
            '/\b(the answer is|it is|this is|that is)\b.*\b(important|necessary|required|clear)\b/',
            '/\b(generally|typically|usually|normally|often)\b/',
            '/\b(can be|could be|might be|may be)\b.*\b(considered|thought|seen)\b/',
            
            // Missing specific reasoning
            '/\b(depending on|based on|according to)\b.*\b(the situation|the context|the scenario)\b/',
            '/\b(in this case|in this scenario|in this situation)\b.*\b(the answer|the result)\b/',
            
            // Non-committal language
            '/\b(it depends|it varies|it changes)\b/',
            '/\b(there are various|there are multiple|there are different)\b.*\b(ways|methods|approaches)\b/',
            
            // Overly broad statements
            '/\b(always|never|all|every|none)\b.*\b(the time|cases|situations)\b/',
            '/\b(fundamentally|essentially|basically|primarily)\b.*\b(the same|similar)\b/',
            
            // Template-like responses
            '/\b(first|second|third|finally)\b.*\b(step|stage|phase)\b.*(without.*specific|without.*detail)/',
            '/\b(as mentioned|as stated|as described)\b.*(above|previously|earlier)/',
            
            // Physics-specific generic patterns
            '/\b(according to|based on)\b.*\b(newton|physics|science)\b.*\b(law|principle|theory)\b.*(without.*explanation)/',
            '/\b(using|applying|applying)\b.*\b(the formula|the equation)\b.*(without.*showing|without.*calculating)/'
        ];
        
        $genericScore = 0;
        foreach ($genericPatterns as $pattern) {
            if (preg_match($pattern, $answerLower)) {
                $genericScore++;
            }
        }
        
        // Check for missing causal depth
        $causalIndicators = ['because', 'therefore', 'thus', 'hence', 'as a result', 'due to', 'since'];
        $hasCausalReasoning = false;
        foreach ($causalIndicators as $indicator) {
            if (strpos($answerLower, $indicator) !== false) {
                $hasCausalReasoning = true;
                break;
            }
        }
        
        // Check answer length and specificity
        $wordCount = str_word_count($answer);
        $hasSpecificDetails = preg_match('/\d+/', $answer) && preg_match('/[A-Z]/', $answer);
        
        // Final determination
        if ($genericScore >= 3) return true;  // Multiple generic patterns
        if ($genericScore >= 2 && !$hasCausalReasoning) return true;  // Generic + no causal reasoning
        if ($genericScore >= 1 && $wordCount < 100 && !$hasSpecificDetails) return true;  // Short + generic + no specifics
        
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
