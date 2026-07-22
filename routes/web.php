<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\QuizController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\AuthController;

// Authentication Routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register', [AuthController::class, 'register']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Student Routes (no admin middleware here)
Route::middleware(['auth'])->group(function () {
    Route::get('/', [QuizController::class, 'availableTests'])->name('student.tests');
    Route::get('/test/{testId}/start', [QuizController::class, 'startTest'])->name('test.start');
    Route::post('/test/submit/{submissionId}', [QuizController::class, 'submitTest'])->name('test.submit');
    Route::post('/test/auto-submit/{submissionId}', [QuizController::class, 'autoSubmit'])->name('test.auto-submit');
    Route::get('/results/{submissionId}', [QuizController::class, 'results'])->name('test.results');
});

// Admin Routes (admin middleware applied at route level)
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/tests', [TestController::class, 'index'])->name('tests.index');
    Route::get('/tests/create', [TestController::class, 'create'])->name('tests.create');
    Route::post('/tests', [TestController::class, 'store'])->name('tests.store');
    Route::get('/tests/{test}/edit', [TestController::class, 'edit'])->name('tests.edit');
    Route::put('/tests/{test}', [TestController::class, 'update'])->name('tests.update');
    Route::delete('/tests/{test}', [TestController::class, 'destroy'])->name('tests.destroy');
    Route::post('/tests/{test}/toggle-status', [TestController::class, 'toggleStatus'])->name('tests.toggle-status');
    
    Route::get('/submissions', [SubmissionController::class, 'index'])->name('submissions.index');
    Route::get('/submissions/{submission}', [SubmissionController::class, 'show'])->name('submissions.show');
    Route::get('/submissions/export', [SubmissionController::class, 'export'])->name('submissions.export');
});