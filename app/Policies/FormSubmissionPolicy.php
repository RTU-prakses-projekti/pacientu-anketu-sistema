<?php

namespace App\Policies;

use App\Models\FormSubmission;
use App\Models\User;

class FormSubmissionPolicy
{
    public function before(User $user): ?bool { return $user->isPlatformAdmin() ? true : null; }
    public function view(User $user, FormSubmission $submission): bool { return $submission->user_id === $user->id || $user->hasOrganisationPermission($submission->organisation_id, 'submissions.view'); }
    public function grade(User $user, FormSubmission $submission): bool { return $user->hasOrganisationPermission($submission->organisation_id, 'submissions.grade'); }
    public function manage(User $user, FormSubmission $submission): bool { return $user->hasOrganisationPermission($submission->organisation_id, 'submissions.manage'); }
}
