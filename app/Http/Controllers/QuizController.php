<?php

namespace App\Http\Controllers;

use App\Models\Test;
use App\Models\Submission;
use App\Models\Answer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class QuizController extends Controller
{
    public function availableTests()
    {
        $tests = Test::where('is_active', true)
            ->where(function($query) {
                $query->whereNull('available_from')
                    ->orWhere('available_from', '<=', now());
            })
            ->where(function($query) {
                $query->whereNull('available_until')
                    ->orWhere('available_until', '>=', now());
            })
            ->withCount('questions')
            ->get();

        return view('student.available-tests', compact('tests'));
    }

    public function startTest($testId)
    {
        $test = Test::with('questions.options')->findOrFail($testId);
        
        // Check if test is available
        if (!$test->isAvailable()) {
            return redirect()->route('student.tests')->with('error', 'This test is not available.');
        }

        // Check if student already submitted
        $existingSubmission = Submission::where('test_id', $testId)
            ->where('student_id', Auth::user()->student_id)
            ->first();

        if ($existingSubmission && $existingSubmission->submitted_at) {
            return redirect()->route('student.tests')->with('error', 'You have already completed this test.');
        }

        // Create or get submission
        $submission = $existingSubmission ?? Submission::create([
            'test_id' => $testId,
            'student_id' => Auth::user()->student_id,
            'student_name' => Auth::user()->name,
            'started_at' => now()
        ]);

        return view('student.take-test', compact('test', 'submission'));
    }

    public function submitTest(Request $request, $submissionId)
    {
        $submission = Submission::with('test.questions.options')->findOrFail($submissionId);
        
        // Verify ownership
        if ($submission->student_id !== Auth::user()->student_id) {
            abort(403);
        }

        // Check if already submitted
        if ($submission->submitted_at) {
            return response()->json(['error' => 'Already submitted'], 400);
        }

        $answers = $request->input('answers', []);
        $totalPoints = 0;
        $earnedPoints = 0;

        // Process each answer
        foreach ($submission->test->questions as $question) {
            $totalPoints += $question->points;
            
            if (isset($answers[$question->id])) {
                $selectedOptionId = $answers[$question->id];
                $option = $question->options()->find($selectedOptionId);
                $isCorrect = $option && $option->is_correct;
                
                if ($isCorrect) {
                    $earnedPoints += $question->points;
                }

                Answer::create([
                    'submission_id' => $submission->id,
                    'question_id' => $question->id,
                    'option_id' => $selectedOptionId,
                    'is_correct' => $isCorrect
                ]);
            } else {
                // No answer selected
                Answer::create([
                    'submission_id' => $submission->id,
                    'question_id' => $question->id,
                    'is_correct' => false
                ]);
            }
        }

        // Update submission
        $submission->update([
            'submitted_at' => now(),
            'score' => $earnedPoints,
            'total_possible' => $totalPoints,
            'is_auto_submitted' => false
        ]);

        return response()->json([
            'success' => true,
            'score' => $earnedPoints,
            'total' => $totalPoints,
            'percentage' => round(($earnedPoints / $totalPoints) * 100, 2)
        ]);
    }

    public function autoSubmit(Request $request, $submissionId)
    {
        $submission = Submission::with('test.questions.options')->findOrFail($submissionId);
        
        if ($submission->student_id !== Auth::user()->student_id) {
            abort(403);
        }

        if ($submission->submitted_at) {
            return response()->json(['error' => 'Already submitted'], 400);
        }

        // Process existing answers or create blank ones
        foreach ($submission->test->questions as $question) {
            $existingAnswer = Answer::where('submission_id', $submission->id)
                ->where('question_id', $question->id)
                ->first();
                
            if (!$existingAnswer) {
                Answer::create([
                    'submission_id' => $submission->id,
                    'question_id' => $question->id,
                    'is_correct' => false
                ]);
            }
        }

        // Calculate score
        $submission->calculateScore();
        
        $submission->update([
            'submitted_at' => now(),
            'is_auto_submitted' => true
        ]);

        return response()->json(['success' => true]);
    }

    public function results($submissionId)
    {
        $submission = Submission::with('test', 'answers.question.options')
            ->where('student_id', Auth::user()->student_id)
            ->findOrFail($submissionId);

        return view('student.results', compact('submission'));
    }
}