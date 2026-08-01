<?php

namespace App\Http\Controllers;

use App\Models\FormSubmission;
use App\Models\Organisation;
use App\Models\Publication;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $user = auth()->user();
        $organisationIds = $user->isPlatformAdmin() ? Organisation::pluck('id') : $user->memberships()->where('is_active', true)->pluck('organisation_id');
        return view('dashboard', [
            'organisations' => Organisation::whereIn('id', $organisationIds)->get(),
            'available' => Publication::with('form')->whereIn('organisation_id', $organisationIds)->where('status', 'active')->where('access_mode', 'authenticated')->get()->filter->isOpen(),
            'activeSubmissions' => FormSubmission::with('publication.form')->where('user_id', $user->id)->where('status', 'in_progress')->get(),
            'completed' => FormSubmission::with('publication.form')->where('user_id', $user->id)->whereIn('status', ['submitted', 'graded', 'awaiting_grading', 'expired'])->latest()->limit(10)->get(),
        ]);
    }
}
