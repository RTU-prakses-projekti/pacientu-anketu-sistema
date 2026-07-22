@extends('layouts.app')

@section('title', 'Create Test')

@section('content')
<div class="bg-white rounded-lg shadow p-6">
    <h2 class="text-2xl font-bold mb-6">📝 Create New Test</h2>

    <form action="{{ route('admin.tests.store') }}" method="POST" id="testForm">
        @csrf
        
        <!-- Test Details -->
        <div class="grid grid-cols-2 gap-4 mb-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Test Title *</label>
                <input type="text" name="title" required 
                       class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="e.g., Midterm Exam 2024">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Duration (minutes) *</label>
                <input type="number" name="duration_minutes" required min="1" max="180"
                       class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="30" value="30">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Available From</label>
                <input type="datetime-local" name="available_from"
                       class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Available Until</label>
                <input type="datetime-local" name="available_until"
                       class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
        </div>

        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
            <textarea name="description" rows="3"
                      class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                      placeholder="Describe the test..."></textarea>
        </div>

        <hr class="my-6">

        <!-- Questions -->
        <div class="mb-4 flex justify-between items-center">
            <h3 class="text-lg font-semibold">Questions</h3>
            <button type="button" onclick="addQuestion()" 
                    class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600 transition">
                + Add Question
            </button>
        </div>

        <div id="questionsContainer"></div>

        <div class="mt-6 flex gap-4">
            <button type="submit" class="bg-blue-500 text-white px-6 py-2 rounded hover:bg-blue-600 transition">
                Create Test
            </button>
            <a href="{{ route('admin.tests.index') }}" class="bg-gray-500 text-white px-6 py-2 rounded hover:bg-gray-600 transition">
                Cancel
            </a>
        </div>
    </form>
</div>

@push('scripts')
<script>
    let questionCount = 0;

    function addQuestion() {
        const container = document.getElementById('questionsContainer');
        const questionDiv = document.createElement('div');
        questionDiv.className = 'border p-4 rounded mb-4 bg-gray-50';
        questionDiv.id = `question-${questionCount}`;
        
        questionDiv.innerHTML = `
            <div class="flex justify-between items-center mb-3">
                <h4 class="font-semibold">Question ${questionCount + 1}</h4>
                <button type="button" onclick="removeQuestion(${questionCount})" 
                        class="text-red-500 hover:text-red-700">Remove</button>
            </div>
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">Question Text *</label>
                <input type="text" name="questions[${questionCount}][text]" required
                       class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="Enter question">
            </div>
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">Points *</label>
                <input type="number" name="questions[${questionCount}][points]" required min="1"
                       class="w-full px-3 py-2 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="1" value="1">
            </div>
            <div class="mb-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Options</label>
                <div id="options-${questionCount}">
                    ${addOptionFields(questionCount, 0)}
                    ${addOptionFields(questionCount, 1)}
                </div>
                <button type="button" onclick="addOption(${questionCount})" 
                        class="text-sm text-blue-500 hover:text-blue-700 mt-1">
                    + Add Option
                </button>
            </div>
        `;
        
        container.appendChild(questionDiv);
        questionCount++;
    }

    function addOptionFields(questionIndex, optionIndex) {
        return `
            <div class="flex items-center gap-2 mb-2" id="option-${questionIndex}-${optionIndex}">
                <input type="text" name="questions[${questionIndex}][options][${optionIndex}][text]" required
                       class="flex-1 px-3 py-1 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="Option ${String.fromCharCode(65 + optionIndex)}">
                <label class="flex items-center">
                    <input type="radio" name="questions[${questionIndex}][correct_option]" value="${optionIndex}" required>
                    <span class="ml-1 text-sm text-gray-600">Correct</span>
                </label>
                <button type="button" onclick="removeOption(${questionIndex}, ${optionIndex})" 
                        class="text-red-400 hover:text-red-600">×</button>
            </div>
        `;
    }

    function addOption(questionIndex) {
        const optionsContainer = document.getElementById(`options-${questionIndex}`);
        const optionCount = optionsContainer.children.length;
        const optionDiv = document.createElement('div');
        optionDiv.className = 'flex items-center gap-2 mb-2';
        optionDiv.id = `option-${questionIndex}-${optionCount}`;
        
        optionDiv.innerHTML = `
            <input type="text" name="questions[${questionIndex}][options][${optionCount}][text]" required
                   class="flex-1 px-3 py-1 border rounded focus:outline-none focus:ring-2 focus:ring-blue-500"
                   placeholder="Option ${String.fromCharCode(65 + optionCount)}">
            <label class="flex items-center">
                <input type="radio" name="questions[${questionIndex}][correct_option]" value="${optionCount}" required>
                <span class="ml-1 text-sm text-gray-600">Correct</span>
            </label>
            <button type="button" onclick="removeOption(${questionIndex}, ${optionCount})" 
                    class="text-red-400 hover:text-red-600">×</button>
        `;
        
        optionsContainer.appendChild(optionDiv);
    }

    function removeOption(questionIndex, optionIndex) {
        const optionElement = document.getElementById(`option-${questionIndex}-${optionIndex}`);
        if (optionElement) {
            optionElement.remove();
        }
    }

    function removeQuestion(questionIndex) {
        const questionElement = document.getElementById(`question-${questionIndex}`);
        if (questionElement && confirm('Remove this question?')) {
            questionElement.remove();
        }
    }

    // Add first question automatically
    addQuestion();
</script>
@endpush