<!DOCTYPE html>
<html>
<head>
    <title>Quiz App</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
        }

        .container {
            max-width: 600px;
            margin: 60px auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 15px rgba(0,0,0,.1);
        }

        h1 {
            text-align: center;
            color: #2c3e50;
        }

        .question-header {
            color: #7f8c8d;
            font-size: 14px;
            margin-top: 30px;
        }

        .question-text {
            font-size: 22px;
            color: #2c3e50;
            margin: 15px 0 25px 0;
        }

        .option-btn {
            display: block;
            width: 100%;
            padding: 15px;
            margin-top: 10px;
            cursor: pointer;
            font-size: 16px;
            text-align: left;
            background: #ecf0f1;
            border: 2px solid #bdc3c7;
            border-radius: 8px;
            transition: all 0.3s;
        }

        .option-btn:hover {
            background: #3498db;
            color: white;
            border-color: #2980b9;
        }

        .option-btn.correct {
            background: #2ecc71;
            border-color: #27ae60;
            color: white;
        }

        .option-btn.wrong {
            background: #e74c3c;
            border-color: #c0392b;
            color: white;
        }

        .option-btn:disabled {
            cursor: not-allowed;
            opacity: 0.7;
        }

        .next-btn {
            background: #3498db;
            color: white;
            padding: 15px;
            font-size: 18px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            width: 100%;
            margin-top: 30px;
            transition: background 0.3s;
        }

        .next-btn:hover {
            background: #2980b9;
        }

        .next-btn:disabled {
            background: #bdc3c7;
            cursor: not-allowed;
        }

        .score {
            text-align: center;
            font-size: 18px;
            color: #2c3e50;
            margin-top: 20px;
            font-weight: bold;
        }

        .result-box {
            text-align: center;
            padding: 30px 0;
        }

        .result-box h2 {
            font-size: 32px;
            color: #2c3e50;
        }

        .result-box .score-number {
            font-size: 48px;
            color: #3498db;
            font-weight: bold;
        }

        .restart-btn {
            background: #2ecc71;
            color: white;
            padding: 15px 30px;
            font-size: 18px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 20px;
        }

        .restart-btn:hover {
            background: #27ae60;
        }
    </style>
</head>
<body>

<div class="container" id="app">
    @if(!isset($showResult) || !$showResult)
        <h1>🎯 Quiz Master</h1>

        <div class="question-header">
            Question <span id="currentQuestionNum">1</span> of {{ count($questions) }}
        </div>

        <div class="question-text" id="questionText">
            {{ $questions[0]['question'] }}
        </div>

        <div id="optionsContainer">
            @foreach($questions[0]['options'] as $index => $option)
                <button class="option-btn" data-option-index="{{ $index }}" onclick="selectOption({{ $index }})">
                    {{ $option }}
                </button>
            @endforeach
        </div>

        <button class="next-btn" id="nextBtn" onclick="nextQuestion()" disabled>
            Next Question →
        </button>

        <div class="score">
            Score: <span id="scoreDisplay">0</span> / {{ count($questions) }}
        </div>
    @else
        <!-- Results View -->
        <div class="result-box">
            <h1>🎉 Quiz Complete!</h1>
            <h2>Your Score</h2>
            <div class="score-number">{{ $score }} / {{ count($questions) }}</div>
            <p style="font-size: 18px; margin-top: 10px;">
                {{ $score === count($questions) ? 'Perfect! 🌟' : ($score >= count($questions)/2 ? 'Good job! 👍' : 'Keep practicing! 📚') }}
            </p>
            <button class="restart-btn" onclick="location.reload()">🔄 Play Again</button>
        </div>
    @endif
</div>

<script>
    // Quiz data from Laravel
    const questions = @json($questions);
    let currentQuestion = 0;
    let score = 0;
    let answered = false;

    // DOM elements
    const questionText = document.getElementById('questionText');
    const optionsContainer = document.getElementById('optionsContainer');
    const nextBtn = document.getElementById('nextBtn');
    const scoreDisplay = document.getElementById('scoreDisplay');
    const currentQuestionNum = document.getElementById('currentQuestionNum');

    function loadQuestion(index) {
        const question = questions[index];
        questionText.textContent = question.question;

        // Update options
        const optionButtons = optionsContainer.querySelectorAll('.option-btn');
        optionButtons.forEach((btn, i) => {
            btn.textContent = question.options[i];
            btn.className = 'option-btn'; // Reset classes
            btn.disabled = false;
            btn.dataset.optionIndex = i;
            btn.onclick = () => selectOption(i);
        });

        currentQuestionNum.textContent = index + 1;
        nextBtn.disabled = true;
        answered = false;
    }

    function selectOption(selectedIndex) {
        if (answered) return;

        const question = questions[currentQuestion];
        const isCorrect = selectedIndex === question.correct;

        if (isCorrect) {
            score++;
            scoreDisplay.textContent = score;
        }

        // Highlight correct/wrong answers
        const optionButtons = optionsContainer.querySelectorAll('.option-btn');
        optionButtons.forEach((btn, i) => {
            btn.disabled = true;
            if (i === question.correct) {
                btn.classList.add('correct');
            } else if (i === selectedIndex && !isCorrect) {
                btn.classList.add('wrong');
            }
        });

        answered = true;
        nextBtn.disabled = false;
    }

    function nextQuestion() {
        currentQuestion++;

        if (currentQuestion < questions.length) {
            loadQuestion(currentQuestion);
        } else {
            // Show results
            // Redirect to same page with result parameters
            window.location.href = `/?score=${score}`;
        }
    }

    // Check if we're showing results
    const urlParams = new URLSearchParams(window.location.search);
    const scoreParam = urlParams.get('score');

    if (scoreParam !== null) {
        // Show results
        document.querySelector('.container').innerHTML = `
            <div class="result-box">
                <h1>🎉 Quiz Complete!</h1>
                <h2>Your Score</h2>
                <div class="score-number">${scoreParam} / ${questions.length}</div>
                <p style="font-size: 18px; margin-top: 10px;">
                    ${parseInt(scoreParam) === questions.length ? 'Perfect! 🌟' : 
                      (parseInt(scoreParam) >= questions.length/2 ? 'Good job! 👍' : 'Keep practicing! 📚')}
                </p>
                <button class="restart-btn" onclick="location.href='/'">🔄 Play Again</button>
            </div>
        `;
    }
</script>

</body>
</html>