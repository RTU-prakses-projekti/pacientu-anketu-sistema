@extends('layouts.app')

@section('title', 'Submission Details')

@section('content')
<div class="bg-white rounded-lg shadow p-6">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold">📄 Submission Details</h2>
            <p class="text-gray-600">Test: {{ $submission->test->title }}</p>
        </div>
        <a href="{{ route('admin.submissions.index') }}" 
           class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600 transition">
            ← Back
        </a>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-gray-50 p-4 rounded">
            <div class="text-sm text-gray-600">Student</div>
            <div class="font-medium">{{ $submission->student_name }}</div>
            <div class="text-sm text-gray-500">{{ $submission->student_id }}</div>
        </div>
        <div class="bg-gray-50 p-4 rounded">
            <div class="text-sm text-gray-600">Score</div>
            <div class="font-medium text-lg">{{ $submission->score ?? 'N/A' }}</div>
        </div>
        <div class="bg-gray-50 p-4 rounded">
            <div class="text-sm text-gray-600">Total Possible</div>
            <div class="font-medium text-lg">{{ $submission->total_possible ?? 'N/A' }}</div>
        </div>
        <div class="bg-gray-50 p-4 rounded">
            <div class="text-sm text-gray-600">Submitted</div>
            <div class="font-medium">{{ $submission->submitted_at ? $submission->submitted_at->format('M d, Y H:i') : 'N/A' }}</div>
            <div class="text-sm text-gray-500">{{ $submission->is_auto_submitted ? '(Auto-submitted)' : '' }}</div>
        </div>
    </div>

    <div class="border-t pt-6">
        <h3 class="font-semibold text-lg mb-4">📝 Answers</h3>
        @foreach($submission->answers as $index => $answer)
            <div class="mb-4 p-4 border rounded {{ $answer->is_correct ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200' }}">
                <div class="flex justify-between">
                    <span class="font-medium">Question {{ $index + 1 }}</span>
                    <span class="{{ $answer->is_correct ? 'text-green-600' : 'text-red-600' }}">
                        {{ $answer->is_correct ? '✅ Correct' : '❌ Incorrect' }}
                    </span>
                </div>
                <p class="mt-2">{{ $answer->question->question_text }}</p>
                @if($answer->option)
                    <p class="text-sm mt-1">
                        <span class="text-gray-500">Student's answer:</span> 
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
</div>
@endsection