<?php

namespace Tests\Feature;

use App\Domain\Forms\FormAuthoringService;
use App\Models\AuditLog;
use App\Models\Form;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\PatientAccessPackage;
use App\Models\PatientCase;
use App\Models\PatientFormAssignment;
use App\Models\Publication;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DoctorWorkspaceRedesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_single_availability_and_backend_share_all_assignment_rules(): void
    {
        $organisation = $this->organisation();
        [$doctor] = $this->member('doctor', $organisation);
        $patient = $this->patient($organisation, $doctor, 1);
        $eligible = $this->publication($organisation, 'Eligible');
        $alreadyAssigned = $this->publication($organisation, 'Already assigned');
        $this->assignDirectly($patient, $alreadyAssigned, 1);
        $inactive = $this->publication($organisation, 'Inactive');
        $inactive->update(['status' => 'inactive']);
        $closed = $this->publication($organisation, 'Closed');
        $closed->update(['closes_at' => now()->subMinute()]);
        $unpublished = $this->draftPublication($organisation, 'Unpublished');
        $archived = $this->publication($organisation, 'Archived form');
        $archived->form()->update(['status' => 'archived']);
        $otherOrganisation = $this->organisation();
        $other = $this->publication($otherOrganisation, 'Other organisation');

        $response = $this->actingAs($doctor)->get(route('doctor.questionnaires.index', $patient));
        $response->assertOk()->assertSee('<option value="'.$eligible->id.'">', false);
        foreach ([$alreadyAssigned, $inactive, $closed, $unpublished, $archived, $other] as $unavailable) {
            $response->assertDontSee('<option value="'.$unavailable->id.'">', false);
            $this->actingAs($doctor)->post(route('doctor.questionnaires.store', $patient), [
                'publication_id' => $unavailable->id,
                'label' => 'Forged',
                'display_order' => 2,
            ])->assertSessionHasErrors('publication_id');
        }

        $this->actingAs($doctor)->post(route('doctor.questionnaires.store', $patient), [
            'publication_id' => $eligible->id,
            'label' => 'Valid part',
            'display_order' => 2,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('patient_form_assignments', [
            'patient_case_id' => $patient->id,
            'publication_id' => $eligible->id,
            'label' => 'Valid part',
            'display_order' => 2,
        ]);
    }

    public function test_bulk_availability_is_the_intersection_for_every_selected_patient(): void
    {
        $organisation = $this->organisation();
        [$doctor] = $this->member('doctor', $organisation);
        $patientA = $this->patient($organisation, $doctor, 1, 'Patient A');
        $patientB = $this->patient($organisation, $doctor, 2, 'Patient B');
        $availableToBoth = $this->publication($organisation, 'Available to both');
        $assignedToB = $this->publication($organisation, 'Already assigned to B');
        $this->assignDirectly($patientB, $assignedToB, 1);

        $this->actingAs($doctor)->post(route('doctor.questionnaires.bulk.create'), [
            'patient_case_ids' => [$patientA->id, $patientB->id],
        ])->assertOk()
            ->assertSee($availableToBoth->name)
            ->assertDontSee($assignedToB->name)
            ->assertSee($patientA->patient_code)
            ->assertSee($patientB->patient_code);
    }

    public function test_bulk_assignment_is_atomic_and_creates_distinct_ordered_patient_records_with_individual_links(): void
    {
        $organisation = $this->organisation();
        [$doctor] = $this->member('doctor', $organisation);
        $patientA = $this->patient($organisation, $doctor, 1, 'Patient A');
        $patientB = $this->patient($organisation, $doctor, 2, 'Patient B');
        $previous = $this->publication($organisation, 'Previous');
        $this->assignDirectly($patientA, $previous, 4);
        $publication = $this->publication($organisation, 'Bulk questionnaire');

        $response = $this->actingAs($doctor)->post(route('doctor.questionnaires.bulk.store'), [
            'patient_case_ids' => [$patientA->id, $patientB->id],
            'publication_id' => $publication->id,
            'expires_in_days' => 30,
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')->assertSee($patientA->patient_code)->assertSee($patientB->patient_code);

        $created = PatientFormAssignment::where('publication_id', $publication->id)->orderBy('patient_case_id')->get();
        $this->assertCount(2, $created);
        $this->assertSame([$patientA->id, $patientB->id], $created->pluck('patient_case_id')->all());
        $this->assertSame([5, 1], $created->pluck('display_order')->all());
        $this->assertNotSame($created[0]->public_id, $created[1]->public_id);
        $this->assertNotSame($created[0]->invitation_id, $created[1]->invitation_id);
        $this->assertNotSame($created[0]->invitation->token_hash, $created[1]->invitation->token_hash);
        $this->assertSame($patientA->public_id, $created[0]->invitation->recipient_reference);
        $this->assertSame($patientB->public_id, $created[1]->invitation->recipient_reference);
        $this->assertSame(2, PatientAccessPackage::count());
        $this->assertNotSame(PatientAccessPackage::first()->token_hash, PatientAccessPackage::latest('id')->first()->token_hash);
        preg_match_all('#/patient-access/([A-Za-z0-9]+)#', $response->getContent(), $matches);
        $this->assertCount(2, $matches[1]);
        $this->assertNotSame($matches[1][0], $matches[1][1]);
        foreach ($matches[1] as $token) {
            $this->assertDatabaseMissing('patient_access_packages', ['token_hash' => $token]);
            $package = PatientAccessPackage::where('token_hash', hash('sha256', $token))->firstOrFail();
            $this->get(route('patient.access', $token))->assertRedirect(route('patient.portal', $package));
            $this->assertStringNotContainsString($token, AuditLog::query()->get()->pluck('metadata')->toJson());
        }
    }

    public function test_bulk_assignment_rejects_foreign_and_duplicate_patient_ids_without_partial_writes(): void
    {
        $organisation = $this->organisation();
        [$doctorA] = $this->member('doctor', $organisation);
        [$doctorB] = $this->member('doctor', $organisation);
        $patientA = $this->patient($organisation, $doctorA, 1, 'Owned');
        $patientB = $this->patient($organisation, $doctorB, 1, 'Foreign');
        $publication = $this->publication($organisation, 'Protected bulk');

        $this->actingAs($doctorA)->post(route('doctor.questionnaires.bulk.store'), [
            'patient_case_ids' => [$patientA->id, $patientB->id],
            'publication_id' => $publication->id,
        ])->assertForbidden();
        $this->assertSame(0, PatientFormAssignment::where('publication_id', $publication->id)->count());

        $this->actingAs($doctorA)->post(route('doctor.questionnaires.bulk.store'), [
            'patient_case_ids' => [$patientA->id, $patientA->id],
            'publication_id' => $publication->id,
        ])->assertSessionHasErrors('patient_case_ids.1');
        $this->assertSame(0, PatientFormAssignment::where('publication_id', $publication->id)->count());
    }

    public function test_doctor_cannot_create_a_patient_in_another_doctors_workspace(): void
    {
        $ownOrganisation = $this->organisation();
        [$doctorA] = $this->member('doctor', $ownOrganisation);
        $otherOrganisation = $this->organisation();
        $this->member('doctor', $otherOrganisation);

        $this->actingAs($doctorA)->post(route('doctor.patients.store', $otherOrganisation), [
            'first_name' => 'Forged patient',
        ])->assertForbidden();
        $this->assertDatabaseMissing('patient_cases', [
            'organisation_id' => $otherOrganisation->id,
            'doctor_id' => $doctorA->id,
        ]);
    }

    public function test_active_workspace_does_not_enable_clinical_writes_in_an_inactive_organisation(): void
    {
        $activeOrganisation = $this->organisation();
        [$doctor] = $this->member('doctor', $activeOrganisation);
        $inactiveOrganisation = $this->organisation();
        $membership = OrganisationMembership::create([
            'organisation_id' => $inactiveOrganisation->id,
            'user_id' => $doctor->id,
            'is_active' => true,
        ]);
        $membership->roles()->attach(Role::where('name', 'doctor')->firstOrFail());
        $patient = $this->patient($inactiveOrganisation, $doctor, 1, 'Retained patient');
        $publication = $this->publication($inactiveOrganisation, 'Inactive workspace questionnaire');
        $inactiveOrganisation->update(['is_active' => false]);

        $this->assertTrue($doctor->hasDoctorWorkspace());
        $this->assertFalse($doctor->hasMembershipPermission($inactiveOrganisation->id, 'patients.update'));
        $this->assertFalse($doctor->hasDoctorPermission($inactiveOrganisation->id, 'patients.update'));
        $this->assertFalse($doctor->can('update', $patient));
        $this->assertFalse($doctor->can('viewQuestionnaires', $patient));

        $this->actingAs($doctor)->post(route('doctor.patients.store', $inactiveOrganisation), [
            'first_name' => 'Forged create',
        ])->assertForbidden();
        $this->actingAs($doctor)->put(route('doctor.patients.slots.update', [$inactiveOrganisation, $doctor, $patient->slot_number]), [
            'first_name' => 'Forged update',
        ])->assertForbidden();
        $this->actingAs($doctor)->post(route('doctor.questionnaires.store', $patient), [
            'publication_id' => $publication->id,
            'label' => 'Forged single assignment',
            'display_order' => 1,
        ])->assertForbidden();
        $this->actingAs($doctor)->post(route('doctor.questionnaires.bulk.store'), [
            'patient_case_ids' => [$patient->id],
            'publication_id' => $publication->id,
        ])->assertForbidden();
        $this->actingAs($doctor)->post(route('doctor.patient-link.issue', $patient), [
            'expires_in_days' => 30,
        ])->assertForbidden();
        $this->actingAs($doctor)->get(route('doctor.questionnaires.index', $patient))->assertForbidden();

        $this->assertSame('Retained patient', $patient->fresh()->first_name);
        $this->assertSame(1, PatientCase::where('organisation_id', $inactiveOrganisation->id)->count());
        $this->assertSame(0, PatientFormAssignment::where('patient_case_id', $patient->id)->count());
        $this->assertSame(0, PatientAccessPackage::where('patient_case_id', $patient->id)->count());
    }

    public function test_bulk_duplicate_questionnaire_is_denied_for_every_patient(): void
    {
        $organisation = $this->organisation();
        [$doctor] = $this->member('doctor', $organisation);
        $patientA = $this->patient($organisation, $doctor, 1);
        $patientB = $this->patient($organisation, $doctor, 2);
        $publication = $this->publication($organisation, 'No duplicates');
        $this->assignDirectly($patientB, $publication, 1);

        $this->actingAs($doctor)->post(route('doctor.questionnaires.bulk.store'), [
            'patient_case_ids' => [$patientA->id, $patientB->id],
            'publication_id' => $publication->id,
        ])->assertSessionHasErrors('publication_id');
        $this->assertDatabaseMissing('patient_form_assignments', ['patient_case_id' => $patientA->id, 'publication_id' => $publication->id]);
        $this->assertSame(1, PatientFormAssignment::where('publication_id', $publication->id)->count());
    }

    private function organisation(): Organisation
    {
        return Organisation::create(['name' => Str::random(8), 'slug' => Str::lower(Str::random(10)), 'is_active' => true]);
    }

    private function member(string $roleName, Organisation $organisation): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $user->id, 'is_active' => true]);
        $membership->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return [$user, $membership];
    }

    private function patient(Organisation $organisation, User $doctor, int $slot, ?string $firstName = null): PatientCase
    {
        return PatientCase::create(['organisation_id' => $organisation->id, 'doctor_id' => $doctor->id, 'slot_number' => $slot, 'first_name' => $firstName]);
    }

    private function publication(Organisation $organisation, string $name): Publication
    {
        [$creator] = $this->member('form_creator', $organisation);
        $form = app(FormAuthoringService::class)->create($organisation->id, $creator, $name, 'blank');
        $published = app(FormAuthoringService::class)->publish($form->versions()->firstOrFail());

        return $this->makePublication($organisation, $form, $published->id, $name);
    }

    private function draftPublication(Organisation $organisation, string $name): Publication
    {
        [$creator] = $this->member('form_creator', $organisation);
        $form = app(FormAuthoringService::class)->create($organisation->id, $creator, $name, 'blank');

        return $this->makePublication($organisation, $form, $form->versions()->firstOrFail()->id, $name);
    }

    private function makePublication(Organisation $organisation, Form $form, int $versionId, string $name): Publication
    {
        return Publication::create([
            'organisation_id' => $organisation->id,
            'form_id' => $form->id,
            'form_version_id' => $versionId,
            'public_key' => Str::lower(Str::random(20)),
            'name' => $name,
            'status' => 'active',
            'access_mode' => 'invitation',
            'anonymous_allowed' => false,
            'identified_required' => true,
            'attempt_limit' => 1,
            'autosave_enabled' => true,
            'resume_enabled' => true,
        ]);
    }

    private function assignDirectly(PatientCase $patient, Publication $publication, int $order): PatientFormAssignment
    {
        $invitation = Invitation::create([
            'publication_id' => $publication->id,
            'token_hash' => hash('sha256', Str::random(64)),
            'recipient_reference' => $patient->public_id,
            'max_uses' => 1,
        ]);

        return PatientFormAssignment::create([
            'patient_case_id' => $patient->id,
            'publication_id' => $publication->id,
            'invitation_id' => $invitation->id,
            'label' => $publication->name,
            'display_order' => $order,
        ]);
    }
}
