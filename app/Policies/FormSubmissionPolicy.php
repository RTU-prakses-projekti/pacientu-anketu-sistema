<?php

namespace App\Policies;

use App\Models\FormSubmission;
use App\Models\User;

class FormSubmissionPolicy
{
    public function view(User $user, FormSubmission $submission): bool
    {
        return !$submission->isPatientLinked()
            && ($submission->user_id === $user->id
                || $user->isPlatformAdmin()
                || $user->hasOrganisationPermission($submission->organisation_id, 'submissions.view'));
    }

    public function grade(User $user, FormSubmission $submission): bool
    {
        return !$submission->isPatientLinked()
            && ($user->isPlatformAdmin() || $user->hasOrganisationPermission($submission->organisation_id, 'submissions.grade'));
    }

    public function manage(User $user, FormSubmission $submission): bool
    {
        return !$submission->isPatientLinked()
            && ($user->isPlatformAdmin() || $user->hasOrganisationPermission($submission->organisation_id, 'submissions.manage'));
    }
}
