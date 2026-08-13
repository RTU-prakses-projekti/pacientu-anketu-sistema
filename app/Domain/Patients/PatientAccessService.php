<?php

namespace App\Domain\Patients;

use App\Domain\Audit\AuditService;
use App\Models\FormSubmission;
use App\Models\Invitation;
use App\Models\PatientAccessPackage;
use App\Models\PatientCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PatientAccessService
{
    public const SESSION_KEY = 'patient_access_package_id';

    public function __construct(private AuditService $audit) {}

    public function issue(PatientCase $patientCase, int $createdBy, int $days): array
    {
        return DB::transaction(function () use ($patientCase, $createdBy, $days) {
            $patientCase = PatientCase::lockForUpdate()->findOrFail($patientCase->id);
            $patientCase->accessPackages()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $plainToken = Str::random(64);
            $package = $patientCase->accessPackages()->create([
                'created_by' => $createdBy,
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addDays($days),
            ]);
            $patientCase->assignments()->with(['invitation', 'publication'])->get()->each(function ($assignment) use ($package, $patientCase): void {
                if ($assignment->publication->access_mode !== 'invitation') {
                    throw ValidationException::withMessages(['assignments' => __('messages.invalid_invitation')]);
                }
                $invitation = $assignment->invitation ?: Invitation::create([
                    'publication_id' => $assignment->publication_id,
                    'token_hash' => hash('sha256', Str::random(64)),
                    'recipient_reference' => $patientCase->public_id,
                    'max_uses' => 1,
                ]);
                $invitation->update(['expires_at' => $package->expires_at, 'revoked_at' => null]);
                $assignment->update(['invitation_id' => $invitation->id, 'patient_access_package_id' => $package->id]);
            });
            $this->audit->record('patient_access.issued', $package, $patientCase->organisation_id, ['package_id' => $package->id, 'expires_at' => $package->expires_at->toIso8601String()]);
            return [$package, $plainToken];
        });
    }

    public function revoke(PatientAccessPackage $package): void
    {
        if (!$package->revoked_at) $package->update(['revoked_at' => now()]);
        $this->audit->record('patient_access.revoked', $package, $package->patientCase->organisation_id, ['package_id' => $package->id]);
    }

    public function consumeToken(Request $request, string $plainToken): ?PatientAccessPackage
    {
        $package = PatientAccessPackage::where('token_hash', hash('sha256', $plainToken))->first();
        if (!$package?->isUsable()) return null;
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $package->id);
        return $package;
    }

    public function assertPackage(Request $request, PatientAccessPackage $package): void
    {
        abort_unless($package->isUsable() && (int) $request->session()->get(self::SESSION_KEY) === $package->id, 403);
    }

    public function packageForSubmission(Request $request, FormSubmission $submission): ?PatientAccessPackage
    {
        $packageId = (int) $request->session()->get(self::SESSION_KEY);
        if (!$packageId || !$submission->invitation_id) return null;
        $package = PatientAccessPackage::find($packageId);
        if (!$package?->isUsable() || $package->consent_refused_at) return null;
        return $package->assignments()->where('invitation_id', $submission->invitation_id)->exists() ? $package : null;
    }
}
