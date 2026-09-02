<?php

namespace Tests\Feature;

use App\Domain\Forms\FormAuthoringService;
use App\Models\FormSubmission;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\PatientCase;
use App\Models\PatientFormAssignment;
use App\Models\Permission;
use App\Models\Publication;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class DoctorWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_role_hierarchy_names_and_least_privilege_permissions_are_seeded(): void
    {
        $this->assertSame('Bootstrap root', Role::where('name', 'platform_admin')->value('display_name'));
        $this->assertSame('Administrators', Role::where('name', 'administrator')->value('display_name'));
        $this->assertSame('Administratora palīgs', Role::where('name', 'organisation_admin')->value('display_name'));
        $this->assertSame('Anketu pārvaldnieks', Role::where('name', 'form_creator')->value('display_name'));

        $doctorPermissions = Role::where('name', 'doctor')->firstOrFail()->permissions()->pluck('name')->sort()->values()->all();
        $this->assertSame(['doctor.dashboard.view', 'patient.questionnaires.view', 'patients.update', 'patients.view'], $doctorPermissions);
        $this->assertFalse(Role::where('name', 'platform_admin')->firstOrFail()->permissions()->whereIn('name', $doctorPermissions)->exists());

        $admin3 = Role::where('name', 'form_creator')->firstOrFail();
        foreach (['forms.view', 'forms.create', 'forms.update', 'forms.publish', 'submissions.view'] as $permission) {
            $this->assertTrue($admin3->permissions()->where('name', $permission)->exists());
        }
        foreach (['users.manage', 'organisation.manage', 'audit.view'] as $permission) {
            $this->assertFalse($admin3->permissions()->where('name', $permission)->exists());
        }
    }

    public function test_only_admin_one_can_assign_the_doctor_role(): void
    {
        $organisation = $this->organisation();
        $admin1 = User::factory()->create(['is_active' => true]);
        $admin1->globalRoles()->attach(Role::where('name', 'platform_admin')->firstOrFail());
        [$admin2] = $this->member('organisation_admin', $organisation);
        $doctorCandidate = User::factory()->create(['is_active' => true]);
        $blockedCandidate = User::factory()->create(['is_active' => true]);
        $doctorRole = Role::where('name', 'doctor')->firstOrFail();

        $this->actingAs($admin1)->post(route('memberships.store', $organisation), [
            'email' => $doctorCandidate->email,
            'roles' => [$doctorRole->id],
        ])->assertRedirect();
        $this->assertTrue($doctorCandidate->doctorMemberships()->exists());

        $this->actingAs($admin2)->post(route('memberships.store', $organisation), [
            'email' => $blockedCandidate->email,
            'roles' => [$doctorRole->id],
        ])->assertForbidden();
        $this->assertFalse($blockedCandidate->doctorMemberships()->exists());
        $this->actingAs($admin2)->get(route('system.roles'))->assertForbidden();
        $this->actingAs($admin2)->get(route('system.users'))->assertForbidden();
    }

    public function test_doctor_dashboard_dynamically_creates_pseudonymous_patients_and_preserves_owner_isolation(): void
    {
        $organisation = $this->organisation();
        [$doctorA] = $this->member('doctor', $organisation);
        [$doctorB] = $this->member('doctor', $organisation);

        $this->actingAs($doctorA)->get(route('dashboard'))->assertRedirect(route('doctor.dashboard'));
        $this->actingAs($doctorA)->get(route('doctor.dashboard'))
            ->assertOk()
            ->assertDontSee('data-patient-row=', false)
            ->assertDontSee('data-doctor-scroll-top', false)
            ->assertSee('doctor-overview-table', false)
            ->assertSee('data-patient-select-all', false)
            ->assertSeeInOrder(['Nr.', 'Pacients', 'Pacienta ID', 'Pētījuma ID', 'Anketas', 'Statuss', 'Darbības'])
            ->assertDontSee(route('system.roles'), false)
            ->assertDontSee(route('forms.index', $organisation), false);
        $this->actingAs($doctorA)->get(route('forms.create', $organisation))->assertForbidden();

        $this->actingAs($doctorA)->post(route('doctor.patients.store', $organisation), [
            'first_name' => 'Anna',
            'last_name' => 'Bērziņa',
            'external_patient_code' => 'KL-204',
            'note' => 'Kontroles piezīme',
            'patient_code' => 'PAT-FORGED-CREATE',
        ])->assertRedirect();
        $patient = PatientCase::where('doctor_id', $doctorA->id)->where('slot_number', 1)->firstOrFail();
        $this->assertStringStartsWith('PAT-', $patient->patient_code);
        $this->assertNotSame('PAT-FORGED-CREATE', $patient->patient_code);
        $this->assertNotSame($patient->external_patient_code, $patient->patient_code);
        $this->assertNotSame((string) $patient->id, $patient->patient_code);
        $this->assertSame('Anna', $patient->first_name);
        $this->assertSame('Bērziņa', $patient->last_name);
        $this->assertSame('KL-204', $patient->external_patient_code);
        $this->assertSame('Kontroles piezīme', $patient->note);
        $this->actingAs($doctorA)->get(route('doctor.dashboard'))->assertOk()->assertSee('Anna')->assertSee('Bērziņa')->assertSee('KL-204')->assertSee($patient->patient_code);

        $this->actingAs($doctorA)->post(route('doctor.patients.store', $organisation), ['first_name' => 'Otra'])->assertRedirect();
        $this->assertDatabaseHas('patient_cases', ['doctor_id' => $doctorA->id, 'slot_number' => 2, 'first_name' => 'Otra']);

        $researchId = $patient->patient_code;
        $this->actingAs($doctorA)->put(route('doctor.patients.slots.update', [$organisation, $doctorA, 1]), ['first_name' => 'Anete', 'last_name' => 'Kalniņa', 'external_patient_code' => 'KL-205', 'note' => 'Mainīta', 'patient_code' => 'PAT-FORGED-UPDATE'])->assertRedirect();
        $this->assertSame(1, PatientCase::where('doctor_id', $doctorA->id)->where('slot_number', 1)->count());
        $patient->refresh();
        $this->assertSame(['Anete', 'Kalniņa', 'KL-205', 'Mainīta'], [$patient->first_name, $patient->last_name, $patient->external_patient_code, $patient->note]);
        $this->assertSame($researchId, $patient->patient_code);

        $this->actingAs($doctorA)->put(route('doctor.patients.slots.update', [$organisation, $doctorB, 1]), ['note' => 'Neatļauts'])->assertForbidden();
        $this->assertDatabaseMissing('patient_cases', ['doctor_id' => $doctorB->id, 'slot_number' => 1]);
    }

    public function test_only_completed_assignments_are_green_and_results_are_patient_scoped(): void
    {
        $organisation = $this->organisation();
        [$doctorA] = $this->member('doctor', $organisation);
        [$doctorB] = $this->member('doctor', $organisation);
        [$creator] = $this->member('form_creator', $organisation);
        $form = app(FormAuthoringService::class)->create($organisation->id, $creator, 'Pacienta anketa', 'blank');
        $version = $form->versions()->firstOrFail();
        $publicationA = $this->publication($form->id, $version->id, $organisation->id, 'Pirmā daļa');
        $publicationB = $this->publication($form->id, $version->id, $organisation->id, 'Otrā daļa');
        $patientA = PatientCase::create(['organisation_id' => $organisation->id, 'doctor_id' => $doctorA->id, 'slot_number' => 1, 'first_name' => 'Doctor A patient', 'last_name' => 'Own']);
        $patientB = PatientCase::create(['organisation_id' => $organisation->id, 'doctor_id' => $doctorB->id, 'slot_number' => 1, 'first_name' => 'Doctor B secret', 'last_name' => 'Private']);
        [$assignmentA, $submissionA] = $this->assignedSubmission($patientA, $publicationA, 'Pirmā daļa', 'submitted');
        [$assignmentInProgress] = $this->assignedSubmission($patientA, $publicationB, 'Otrā daļa', 'in_progress');
        [$assignmentB] = $this->assignedSubmission($patientB, $publicationA, 'Pirmā daļa', 'submitted');

        $this->actingAs($doctorA)->get(route('doctor.dashboard'))
            ->assertOk()
            ->assertSee('Pabeigtas: 1')
            ->assertSee('Procesā: 1')
            ->assertDontSee($publicationA->name)
            ->assertDontSee($publicationB->name)
            ->assertSee('Doctor A patient')
            ->assertDontSee('Doctor B secret')
            ->assertDontSee($patientB->patient_code);
        $this->actingAs($doctorA)->get(route('doctor.questionnaires.index', $patientA))
            ->assertOk()->assertSee(route('doctor.results.show', [$patientA, $assignmentA]), false);
        $this->actingAs($doctorA)->get(route('doctor.results.show', [$patientA, $assignmentA]))->assertOk()->assertSee($submissionA->status);
        $this->actingAs($doctorA)->get(route('doctor.results.show', [$patientA, $assignmentInProgress]))->assertNotFound();
        $this->actingAs($doctorA)->get(route('doctor.results.show', [$patientB, $assignmentB]))->assertForbidden();
        $this->assertTrue(Gate::forUser($doctorA)->denies('view', $patientB));
        $this->assertTrue(Gate::forUser($doctorA)->denies('update', $patientB));

        $admin1 = User::factory()->create(['is_active' => true]);
        $platformRole = Role::where('name', 'platform_admin')->firstOrFail();
        $admin1->globalRoles()->attach($platformRole);
        $platformRole->permissions()->attach(Permission::whereIn('name', [
            'doctor.dashboard.view',
            'patients.view',
            'patients.update',
            'patient.questionnaires.view',
        ])->pluck('id'));
        $this->assertFalse($admin1->hasDoctorWorkspace());
        $this->assertSame(2, PatientCase::query()->visibleTo($admin1)->count());
        $this->assertTrue(Gate::forUser($admin1)->allows('view', $patientB));
        $this->actingAs($admin1)->get(route('doctor.dashboard', ['organisation_id' => $organisation->id, 'doctor_id' => $doctorB->id]))->assertOk();
        $this->actingAs($admin1)->get(route('doctor.results.show', [$patientB, $assignmentB]))->assertOk();
        $this->actingAs($admin1)->get(route('admin.submissions.show', $submissionA))->assertOk();
        $this->actingAs($admin1)->get(route('admin.submissions.index', $organisation))
            ->assertOk()
            ->assertDontSee($submissionA->public_id)
            ->assertDontSee('Doctor A patient');
    }

    public function test_removed_or_inactive_organisations_are_not_doctor_workspaces(): void
    {
        $activeOrganisation = $this->organisation();
        [$doctor] = $this->member('doctor', $activeOrganisation);

        $deletedOrganisation = $this->organisation();
        $deletedMembership = OrganisationMembership::create([
            'organisation_id' => $deletedOrganisation->id,
            'user_id' => $doctor->id,
            'is_active' => true,
        ]);
        $deletedMembership->roles()->attach(Role::where('name', 'doctor')->firstOrFail());
        PatientCase::create([
            'organisation_id' => $deletedOrganisation->id,
            'doctor_id' => $doctor->id,
            'slot_number' => 1,
            'first_name' => 'Deleted workspace secret',
        ]);
        $deletedOrganisation->delete();

        $inactiveOrganisation = $this->organisation();
        $inactiveMembership = OrganisationMembership::create([
            'organisation_id' => $inactiveOrganisation->id,
            'user_id' => $doctor->id,
            'is_active' => true,
        ]);
        $inactiveMembership->roles()->attach(Role::where('name', 'doctor')->firstOrFail());
        PatientCase::create([
            'organisation_id' => $inactiveOrganisation->id,
            'doctor_id' => $doctor->id,
            'slot_number' => 1,
            'first_name' => 'Inactive workspace secret',
        ]);
        $inactiveOrganisation->update(['is_active' => false]);

        $this->assertTrue($doctor->hasDoctorWorkspace());
        $this->actingAs($doctor)->get(route('doctor.dashboard'))
            ->assertOk()
            ->assertSee($activeOrganisation->name)
            ->assertDontSee($deletedOrganisation->name)
            ->assertDontSee($inactiveOrganisation->name)
            ->assertDontSee('Deleted workspace secret')
            ->assertDontSee('Inactive workspace secret');
        $this->assertDatabaseHas('organisation_memberships', ['id' => $deletedMembership->id, 'is_active' => true]);
        $this->assertNotNull(Organisation::withTrashed()->find($deletedOrganisation->id));

        $activeOrganisation->update(['is_active' => false]);
        $this->assertFalse($doctor->hasDoctorWorkspace());
    }

    private function organisation(): Organisation
    {
        return Organisation::create(['name' => Str::random(8), 'slug' => Str::lower(Str::random(10)), 'is_active' => true]);
    }

    private function member(string $roleName, Organisation $organisation): array
    {
        $user = User::factory()->create(['student_id' => (string) Str::uuid(), 'is_active' => true]);
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $user->id, 'is_active' => true]);
        $membership->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return [$user, $membership];
    }

    private function publication(int $formId, int $versionId, int $organisationId, string $name): Publication
    {
        return Publication::create(['organisation_id' => $organisationId, 'form_id' => $formId, 'form_version_id' => $versionId, 'public_key' => Str::lower(Str::random(20)), 'name' => $name, 'status' => 'active', 'access_mode' => 'invitation']);
    }

    private function assignedSubmission(PatientCase $patient, Publication $publication, string $label, string $status): array
    {
        $invitation = Invitation::create(['publication_id' => $publication->id, 'token_hash' => hash('sha256', Str::random(40))]);
        $assignment = PatientFormAssignment::create(['patient_case_id' => $patient->id, 'publication_id' => $publication->id, 'invitation_id' => $invitation->id, 'label' => $label, 'display_order' => $publication->id]);
        $submission = FormSubmission::create(['public_id' => (string) Str::uuid(), 'organisation_id' => $patient->organisation_id, 'publication_id' => $publication->id, 'form_version_id' => $publication->form_version_id, 'invitation_id' => $invitation->id, 'attempt_number' => 1, 'status' => $status, 'started_at' => now(), 'submitted_at' => $status === 'in_progress' ? null : now()]);

        return [$assignment, $submission];
    }
}
