<?php

namespace App\Http\Controllers;

use App\Models\Test;
use App\Models\Question;
use App\Models\Option;
use Illuminate\Http\Request;

class TestController extends Controller
{
    public function index()
    {
        $tests = Test::withCount('questions')->get();
        return view('admin.tests.index', compact('tests'));
    }

    public function create()
    {
        return view('admin.tests.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'duration_minutes' => 'required|integer|min:1|max:180',
            'available_from' => 'nullable|date',
            'available_until' => 'nullable|date|after:available_from',
            'questions' => 'required|array|min:1',
            'questions.*.text' => 'required|string',
            'questions.*.points' => 'required|integer|min:1',
            'questions.*.options' => 'required|array|min:2',
            'questions.*.options.*.text' => 'required|string',
            'questions.*.correct_option' => 'required|integer|min:0'
        ]);

        $test = Test::create([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'duration_minutes' => $validated['duration_minutes'],
            'available_from' => $validated['available_from'],
            'available_until' => $validated['available_until'],
            'is_active' => true
        ]);

        foreach ($validated['questions'] as $index => $qData) {
            $question = $test->questions()->create([
                'question_text' => $qData['text'],
                'points' => $qData['points'],
                'order' => $index
            ]);

            foreach ($qData['options'] as $optIndex => $optData) {
                $question->options()->create([
                    'option_text' => $optData['text'],
                    'is_correct' => $optIndex == $qData['correct_option']
                ]);
            }
        }

        return redirect()->route('admin.tests.index')
            ->with('success', 'Test created successfully!');
    }

    public function edit(Test $test)
    {
        $test->load('questions.options');
        return view('admin.tests.edit', compact('test'));
    }

    public function update(Request $request, Test $test)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'duration_minutes' => 'required|integer|min:1|max:180',
            'available_from' => 'nullable|date',
            'available_until' => 'nullable|date|after:available_from',
            'is_active' => 'boolean'
        ]);

        $test->update($validated);

        return redirect()->route('admin.tests.index')
            ->with('success', 'Test updated successfully!');
    }

    public function destroy(Test $test)
    {
        $test->delete();
        return redirect()->route('admin.tests.index')
            ->with('success', 'Test deleted successfully!');
    }

    public function toggleStatus(Test $test)
    {
        $test->update(['is_active' => !$test->is_active]);
        return back()->with('success', 'Test status updated!');
    }
}