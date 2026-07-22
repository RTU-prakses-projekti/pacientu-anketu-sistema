<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request; // ← This was missing!

class HomeController extends Controller
{
    public function index(Request $request) // ← Now $request will work
    {
        // Quiz questions data
        $questions = [
            [
                'id' => 1,
                'question' => 'What is the capital of France?',
                'options' => ['London', 'Paris', 'Berlin', 'Madrid'],
                'correct' => 1
            ],
            [
                'id' => 2,
                'question' => 'Which planet is known as the Red Planet?',
                'options' => ['Venus', 'Mars', 'Jupiter', 'Saturn'],
                'correct' => 1
            ],
            [
                'id' => 3,
                'question' => 'What is the largest ocean on Earth?',
                'options' => ['Atlantic Ocean', 'Indian Ocean', 'Arctic Ocean', 'Pacific Ocean'],
                'correct' => 3
            ],
            [
                'id' => 4,
                'question' => 'Who painted the Mona Lisa?',
                'options' => ['Michelangelo', 'Leonardo da Vinci', 'Raphael', 'Donatello'],
                'correct' => 1
            ],
            [
                'id' => 5,
                'question' => 'What is the chemical symbol for water?',
                'options' => ['H2O', 'CO2', 'NaCl', 'HCl'],
                'correct' => 0
            ]
        ];

        // Check if we're showing results
        $score = $request->query('score');
        
        if ($score !== null) {
            return view('home', [
                'questions' => $questions,
                'showResult' => true,
                'score' => $score
            ]);
        }

        return view('home', [
            'questions' => $questions,
            'showResult' => false
        ]);
    }
}

