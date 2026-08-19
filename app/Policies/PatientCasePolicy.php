<?php

namespace App\Policies;

use App\Models\PatientCase;
use App\Models\User;

class PatientCasePolicy
{
    public function view(User $user, PatientCase $patientCase): bool
    {
        return $this->ownsWithPermission($user, $patientCase, 'patients.view');
    }

    public function update(User $user, PatientCase $patientCase): bool
    {
        return $this->ownsWithPermission($user, $patientCase, 'patients.update');
    }

    public function viewQuestionnaires(User $user, PatientCase $patientCase): bool
    {
        return $this->ownsWithPermission($user, $patientCase, 'patient.questionnaires.view');
    }

    private function ownsWithPermission(User $user, PatientCase $patientCase, string $permission): bool
    {
        return $patientCase->doctor_id === $user->id
            && $user->hasDoctorPermission($patientCase->organisation_id, $permission);
    }
}
