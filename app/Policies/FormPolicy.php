<?php

namespace App\Policies;

use App\Models\Form;
use App\Models\User;

class FormPolicy
{
    public function before(User $user): ?bool { return $user->isPlatformAdmin() ? true : null; }
    public function view(User $user, Form $form): bool { return $user->hasOrganisationPermission($form->organisation_id, 'forms.view'); }
    public function create(User $user, int $organisationId): bool { return $user->hasOrganisationPermission($organisationId, 'forms.create'); }
    public function update(User $user, Form $form): bool { return $user->hasOrganisationPermission($form->organisation_id, 'forms.update'); }
    public function publish(User $user, Form $form): bool { return $user->hasOrganisationPermission($form->organisation_id, 'forms.publish'); }
    public function archive(User $user, Form $form): bool { return $user->hasOrganisationPermission($form->organisation_id, 'forms.archive'); }
}
