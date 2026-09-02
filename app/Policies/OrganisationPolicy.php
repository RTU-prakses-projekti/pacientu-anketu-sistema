<?php

namespace App\Policies;

use App\Models\Organisation;
use App\Models\User;

class OrganisationPolicy
{
    public function before(User $user): ?bool { return $user->canAdministerSystem() ? true : null; }
    public function view(User $user, Organisation $organisation): bool { return $user->hasOrganisationPermission($organisation->id, 'organisation.view'); }
    public function update(User $user, Organisation $organisation): bool { return $user->hasOrganisationPermission($organisation->id, 'organisation.manage'); }
}
