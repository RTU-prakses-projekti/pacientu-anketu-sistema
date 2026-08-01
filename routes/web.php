<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AttemptAdministrationController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AutosaveController;
use App\Http\Controllers\BuilderController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FinalizeSubmissionController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\FormSubmissionController;
use App\Http\Controllers\GradingController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OrganisationController;
use App\Http\Controllers\PublicationController;
use App\Http\Controllers\RespondentController;
use App\Http\Controllers\SystemAdministrationController;
use App\Http\Controllers\UserAdministrationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:registration');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::post('/locale/{locale}', function (Request $request, string $locale) {
    abort_unless(in_array($locale, ['lv', 'en', 'ru'], true), 404);
    $request->session()->put('locale', $locale);
    if ($request->user()) $request->user()->update(['locale' => $locale]);
    return back();
})->name('locale');

Route::get('/f/{publication}', [RespondentController::class, 'show'])->name('publications.show');
Route::post('/f/{publication}/start', [RespondentController::class, 'start'])->name('publications.start');
Route::get('/respond/{submission}', [RespondentController::class, 'take'])->name('submissions.take');
Route::post('/respond/{submission}/autosave', AutosaveController::class)->middleware('throttle:autosave')->name('submissions.autosave');
Route::post('/respond/{submission}/finalize', FinalizeSubmissionController::class)->name('submissions.finalize');
Route::get('/respond/{submission}/complete', [RespondentController::class, 'complete'])->name('submissions.complete');
Route::get('/respond/{submission}/attachments/{attachment}', [AttachmentController::class, 'respondentDownload'])->name('submissions.attachments.download');

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/organisations', [OrganisationController::class, 'index'])->name('organisations.index');
    Route::get('/organisations/create', [OrganisationController::class, 'create'])->name('organisations.create');
    Route::post('/organisations', [OrganisationController::class, 'store'])->name('organisations.store');
    Route::get('/organisations/{organisation}/edit', [OrganisationController::class, 'edit'])->name('organisations.edit');
    Route::put('/organisations/{organisation}', [OrganisationController::class, 'update'])->name('organisations.update');
    Route::get('/system/audit', [AuditLogController::class, 'index'])->name('audit.system');
    Route::get('/system/users', [SystemAdministrationController::class, 'users'])->name('system.users');
    Route::post('/system/platform-admins', [SystemAdministrationController::class, 'createPlatformAdmin'])->name('system.platform-admins.store');
    Route::post('/system/platform-admins/{user}/promote', [SystemAdministrationController::class, 'promotePlatformAdmin'])->name('system.platform-admins.promote');
    Route::get('/system/roles', [SystemAdministrationController::class, 'roles'])->name('system.roles');
    Route::put('/system/roles/{role}', [SystemAdministrationController::class, 'updateRole'])->name('system.roles.update');
    Route::post('/users/{user}/toggle', [UserAdministrationController::class, 'toggleUser'])->name('users.toggle');

    Route::get('/organisations/{organisation}/forms', [FormController::class, 'index'])->name('forms.index');
    Route::get('/organisations/{organisation}/forms/create', [FormController::class, 'create'])->name('forms.create');
    Route::post('/forms', [FormController::class, 'store'])->name('forms.store');
    Route::get('/forms/{form}', [FormController::class, 'show'])->name('forms.show');
    Route::put('/forms/{form}', [FormController::class, 'update'])->name('forms.update');
    Route::get('/forms/{form}/builder', [BuilderController::class, 'edit'])->name('forms.builder');
    Route::get('/forms/{form}/preview', [BuilderController::class, 'preview'])->name('forms.preview');
    Route::post('/forms/{form}/versions/{version}/publish', [FormController::class, 'publish'])->name('forms.publish');
    Route::post('/forms/{form}/versions/{version}/new-draft', [FormController::class, 'newDraft'])->name('forms.new-draft');
    Route::post('/forms/{form}/duplicate', [FormController::class, 'duplicate'])->name('forms.duplicate');
    Route::post('/forms/{form}/archive', [FormController::class, 'archive'])->name('forms.archive');

    Route::post('/forms/{form}/sections', [BuilderController::class, 'addSection'])->name('builder.sections.store');
    Route::put('/forms/{form}/sections/{section}', [BuilderController::class, 'updateSection'])->name('builder.sections.update');
    Route::post('/forms/{form}/sections/{section}/move', [BuilderController::class, 'moveSection'])->name('builder.sections.move');
    Route::delete('/forms/{form}/sections/{section}', [BuilderController::class, 'deleteSection'])->name('builder.sections.destroy');
    Route::post('/forms/{form}/components', [BuilderController::class, 'addComponent'])->name('builder.components.store');
    Route::put('/forms/{form}/components/{component}', [BuilderController::class, 'updateComponent'])->name('builder.components.update');
    Route::post('/forms/{form}/components/{component}/copy', [BuilderController::class, 'copyComponent'])->name('builder.components.copy');
    Route::post('/forms/{form}/components/{component}/move', [BuilderController::class, 'moveComponent'])->name('builder.components.move');
    Route::delete('/forms/{form}/components/{component}', [BuilderController::class, 'deleteComponent'])->name('builder.components.destroy');
    Route::post('/forms/{form}/conditions', [BuilderController::class, 'addCondition'])->name('builder.conditions.store');
    Route::delete('/forms/{form}/conditions/{condition}', [BuilderController::class, 'deleteCondition'])->name('builder.conditions.destroy');

    Route::post('/forms/{form}/publications', [PublicationController::class, 'store'])->name('publications.store');
    Route::post('/forms/{form}/publications/{publication}/toggle', [PublicationController::class, 'toggle'])->name('publications.toggle');
    Route::post('/forms/{form}/publications/{publication}/invitations', [InvitationController::class, 'store'])->name('invitations.store');
    Route::delete('/forms/{form}/publications/{publication}/invitations/{invitation}', [InvitationController::class, 'revoke'])->name('invitations.revoke');

    Route::post('/forms/{form}/versions/{version}/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'download'])->name('attachments.download');
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

    Route::get('/organisations/{organisation}/submissions', [FormSubmissionController::class, 'index'])->name('admin.submissions.index');
    Route::get('/submissions/{submission}', [FormSubmissionController::class, 'show'])->name('admin.submissions.show');
    Route::put('/submissions/{submission}/grade', [GradingController::class, 'update'])->name('grading.update');
    Route::post('/submissions/{submission}/grant-attempt', [AttemptAdministrationController::class, 'grant'])->name('attempts.grant');
    Route::post('/submissions/{submission}/extend', [AttemptAdministrationController::class, 'extend'])->name('attempts.extend');
    Route::post('/submissions/{submission}/invalidate', [AttemptAdministrationController::class, 'invalidate'])->name('attempts.invalidate');

    Route::get('/organisations/{organisation}/exports', [ExportController::class, 'index'])->name('exports.index');
    Route::post('/organisations/{organisation}/exports', [ExportController::class, 'store'])->name('exports.store');
    Route::get('/exports/{export}/download', [ExportController::class, 'download'])->name('exports.download');
    Route::get('/organisations/{organisation}/audit', [AuditLogController::class, 'index'])->name('audit.index');
    Route::get('/organisations/{organisation}/users', [UserAdministrationController::class, 'index'])->name('users.index');
    Route::post('/organisations/{organisation}/memberships', [UserAdministrationController::class, 'storeMembership'])->name('memberships.store');
});
