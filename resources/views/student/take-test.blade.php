@extends('layouts.app')

@section('title', $test->title)

@section('content')
<div class="bg-white rounded-lg shadow p-6">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-2xl font-bold">{{ $test->title }}</h2>
            <p class="text-gray-600 text-sm">{{ $test->description }}</p>
        </div>
        <div class="text-right">
            <div class="text-sm text-gray-600">Time Remaining</div>
            <div id="timer" class="text-4xl font-bold text-blue-600">25:00</div>
        </div>
    </div>

    <!-- Progress Bar -->
    <div class="mb-6">
        <div class="flex justify-between text-sm text-gray-600 mb-1">
            <span>Progress</span>
            <span id="progressText">0%</span>
        </div>
        <div class="w-full bg-gray-200 rounded-full h-2.5">
            <div id="progressBar" class="bg-blue-600 h-2.5 rounded-full transition-all duration-300" style="width: 0%"></div>
        </div>
    </div>

    <form id="quizForm">
        @csrf
        <div class="space-y-8" id="questionsContainer">
            @foreach($test->questions as $index => $question)
                <div class="border-b pb-6 question-item" data-question-id="{{ $question->id }}">
                    <div class="flex justify-between mb-3">
                        <h4 class="font-semibold text-lg">
                            Question {{ $index + 1 }}
                        </h4>
                        <span class="text-sm text-gray-500">{{ $question->points }} point(s)</span>
                    </div>
                    <p class="mb-4">{{ $question->question_text }}</p>
                    
                    <div class="space-y-2">
                        @foreach($question->options as $option)
                            <label class="flex items-center p-3 border rounded hover:bg-gray-50 cursor-pointer transition">
                                <input type="radio" 
                                       name="answers[{{ $question->id }}]" 
                                       value="{{ $option->id }}"
                                       class="mr-3 answer-radio"
                                       data-question="{{ $question->id }}">
                                <span>{{ $option->option_text }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-8 flex gap-4">
            <button type="button" 
                    onclick="window.scrollTo({top: 0, behavior: 'smooth'})"
                    class="bg-gray-500 text-white px-6 py-2 rounded hover:bg-gray-600 transition">
                ↑ Back to Top
            </button>
            <button type="submit" 
                    class="flex-1 bg-green-500 text-white py-3 rounded-lg hover:bg-green-600 transition font-semibold">
                Submit Test
            </button>
        </div>
    </form>
</div>

@push('scripts')
<script>
    let timeLeft = {{ $test->duration_minutes * 60 }};
    const timerElement = document.getElementById('timer');
    const form = document.getElementById('quizForm');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    let timerInterval;
    const totalQuestions = {{ $test->questions->count() }};

    function updateTimer() {
        const minutes = Math.floor(timeLeft / 60);
        const seconds = timeLeft % 60;
        timerElement.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        
        // Color warnings
        if (timeLeft < 300) { // 5 minutes
            timerElement.className = 'text-4xl font-bold text-red-600 animate-pulse';
        } else if (timeLeft < 600) { // 10 minutes
            timerElement.className = 'text-4xl font-bold text-yellow-600';
        }
        
        if (timeLeft <= 0) {
            clearInterval(timerInterval);
            autoSubmit();
        }
        timeLeft--;
    }

    function autoSubmit() {
        if (confirm('Time is up! Your test will be auto-submitted.')) {
            fetch('{{ route("test.auto-submit", $submission->id) }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = '{{ route("test.results", $submission->id) }}';
                }
            });
        }
    }

    // Update progress when answers change
    document.querySelectorAll('.answer-radio').forEach(radio => {
        radio.addEventListener('change', updateProgress);
    });

    function updateProgress() {
        const answered = document.querySelectorAll('.answer-radio:checked').length;
        const percentage = Math.round((answered / totalQuestions) * 100);
        progressBar.style.width = percentage + '%';
        progressText.textContent = percentage + '%';
    }

    timerInterval = setInterval(updateTimer, 1000);

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const answered = document.querySelectorAll('.answer-radio:checked').length;
        if (answered < totalQuestions) {
            if (!confirm(`You have only answered ${answered} out of ${totalQuestions} questions. Are you sure you want to submit?`)) {
                return;
            }
        } else {
            if (!confirm('Are you sure you want to submit your test?')) {
                return;
            }
        }

        const formData = new FormData(this);
        const answers = {};
        for (let [key, value] of formData.entries()) {
            if (key !== '_token') {
                answers[key] = value;
            }
        }

        const submissionId = {{ $submission->id }};
        
        fetch('{{ route("test.submit", $submission->id) }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ answers: answers })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.href = '{{ route("test.results", $submission->id) }}';
            }
        })
        .catch(error => {
            alert('An error occurred while submitting your test. Please try again.');
            console.error(error);
        });
    });

    // Warn before leaving the page
    window.addEventListener('beforeunload', function(e) {
        e.preventDefault();
        e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
    });

    // Remove warning on submit
    form.addEventListener('submit', function() {
        window.removeEventListener('beforeunload', function(e) {});
    });
</script>
@endpush