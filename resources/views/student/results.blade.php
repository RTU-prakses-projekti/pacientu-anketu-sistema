@extends('layouts.app')

@section('title', 'Test Results')

@section('content')
<div class="bg-white rounded-lg shadow p-6">
    <div class="text-center mb-8">
        <h2 class="text-3xl font-bold text-gray-800">🎉 Test Complete!</h2>
        <p class="text-gray-600 mt-2">{{ $submission->test->title }}</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="bg-blue-50 p-6 rounded-lg text-center">
            <div class="text-sm text-gray-600">Score</div>
            <div class="text-3xl font-bold text-blue-600">{{ $submission->score ?? 0 }}</div>
        </div>
        <div class="bg-green-50 p-6 rounded-lg text-center">
            <div class="text-sm text-gray-600">Total Possible</div>
            <div class="text-3xl font-bold text-green-600">{{ $submission->total_possible ?? 0 }}</div>
        </div>
        <div class="bg-purple-50 p-6 rounded-lg text-center">
            <div class="text-sm text-gray-600">Percentage</div>
            <div class="text-3xl font-bold text-purple-600">
                {{ $submission->total_possible > 0 ? round(($submission->score / $submission->total_possible) * 100, 2) : 0 }}%
            </div>
        </div>
    </div>

    @if($submission->is_auto_submitted)
        <div class="bg-yellow-50 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-6">
            ⏰ This test was auto-submitted when time ran out.
        </div>
    @endif

    <div class="border-t pt-6">
        <h3 class="font-semibold text-lg mb-4">📝 Answer Review</h3>
        @foreach($submission->answers as $index => $answer)
            <div class="mb-4 p-4 border rounded {{ $answer->is_correct ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200' }}">
                <div class="flex justify-between">
                    <span class="font-medium">Question {{ $index + 1 }}</span>
                    <span class="{{ $answer->is_correct ? 'text-green-600' : 'text-red-600' }}">
                        {{ $answer->is_correct ? '✅ Correct' : '❌ Incorrect' }}
                    </span>
                </div>
                <p class="mt-2 text-gray-700">{{ $answer->question->question_text }}</p>
                @if($answer->option)
                    <p class="text-sm mt-1">
                        <span class="text-gray-500">Your answer:</span> 
                        <span class="{{ $answer->is_correct ? 'text-green-600' : 'text-red-600' }}">
                            {{ $answer->option->option_text }}
                        </span>
                    </p>
                    @if(!$answer->is_correct && $answer->question->getCorrectOption())
                        <p class="text-sm text-green-600 mt-1">
                            Correct answer: {{ $answer->question->getCorrectOption()->option_text }}
                        </p>
                    @endif
                @else
                    <p class="text-sm text-red-600 mt-1">No answer provided</p>
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-8 text-center">
        <a href="{{ route('student.tests') }}" 
           class="bg-blue-500 text-white px-6 py-3 rounded-lg hover:bg-blue-600 transition inline-block">
            ← Back to Available Tests
        </a>
    </div>
</div>
@endsection