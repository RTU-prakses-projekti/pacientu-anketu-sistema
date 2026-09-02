<?php

namespace App\Policies;

use App\Models\FormSubmission;
use App\Models\User;

class FormSubmissionPolicy
{
    public function view(User $user, FormSubmission $submission): bool
    {
        return $user->isBootstrapRoot() || (!$submission->isPatientLinked()
            && ($submission->user_id === $user->id
                || $user->canAdministerSystem()
                || $user->hasOrganisationPermission($submission->organisation_id, 'submissions.view')));
    }

    public function grade(User $user, FormSubmission $submission): bool
    {
        return $user->isBootstrapRoot();
    }

    public function manage(User $user, FormSubmission $submission): bool
    {
        return $user->isBootstrapRoot();
    }
}
