<?php

namespace Tests\Feature;

use App\Domain\Forms\FormAuthoringService;
use App\Domain\Submissions\SubmissionService;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\Publication;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RangeScaleRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_linear_scale_renders_responsive_accessible_1_to_120_range_in_preview_and_runner(): void
    {
        $organisation = Organisation::create(['name' => 'Scale organisation', 'slug' => 'scale-'.Str::lower(Str::random(8)), 'is_active' => true]);
        $creator = $this->member('form_creator', $organisation);
        $respondent = $this->member('respondent', $organisation);
        $authoring = app(FormAuthoringService::class);
        $form = $authoring->create($organisation->id, $creator, 'Range form', 'blank');
        $version = $form->versions()->firstOrFail();
        $component = $authoring->addComponent($version, $version->sections()->firstOrFail(), [
            'type' => 'linear_scale',
            'label' => 'Scale from one to one hundred twenty',
            'settings' => ['minimum' => 1, 'maximum' => 120, 'minimum_label' => 'Minimum', 'maximum_label' => 'Maximum'],
            'options' => [],
        ]);

        $preview = $this->actingAs($creator)->get(route('forms.preview', $form));
        $this->assertRangeMarkup($preview, $component->id, 1);
        $preview->assertSee('Minimum')->assertSee('Maximum');

        $published = $authoring->publish($version);
        $publication = Publication::create([
            'organisation_id' => $organisation->id, 'form_id' => $form->id, 'form_version_id' => $published->id,
            'public_key' => Str::lower(Str::random(20)), 'name' => 'Range publication', 'status' => 'active',
            'access_mode' => 'authenticated', 'attempt_limit' => 1, 'autosave_enabled' => true, 'resume_enabled' => true,
        ]);
        $submission = app(SubmissionService::class)->start($publication, $respondent, null, null, 'unused');
        app(SubmissionService::class)->autosave($submission, 0, (string) Str::uuid(), [$component->id => 57]);

        $runner = $this->actingAs($respondent)->get(route('submissions.take', $submission));
        $this->assertRangeMarkup($runner, $component->id, 57);
        $runner->assertSee(__('messages.selected_value').':', false);
    }

    public function test_rating_scale_uses_the_same_range_contract(): void
    {
        $organisation = Organisation::create(['name' => 'Rating organisation', 'slug' => 'rating-'.Str::lower(Str::random(8)), 'is_active' => true]);
        $creator = $this->member('form_creator', $organisation);
        $form = app(FormAuthoringService::class)->create($organisation->id, $creator, 'Rating form', 'blank');
        $version = $form->versions()->firstOrFail();
        $component = app(FormAuthoringService::class)->addComponent($version, $version->sections()->firstOrFail(), [
            'type' => 'rating_scale', 'label' => 'Rating', 'settings' => ['minimum' => 1, 'maximum' => 120], 'options' => [],
        ]);

        $this->assertRangeMarkup($this->actingAs($creator)->get(route('forms.preview', $form)), $component->id, 1);
    }

    private function assertRangeMarkup($response, int $componentId, int $value): void
    {
        $rangeId = 'range-'.$componentId;
        $outputId = $rangeId.'-value';
        $response->assertOk()
            ->assertSee('data-range-control', false)
            ->assertSee('data-range-input', false)
            ->assertSee('class="scale-range"', false)
            ->assertSee('id="'.$rangeId.'"', false)
            ->assertSee('min="1"', false)
            ->assertSee('max="120"', false)
            ->assertSee('step="1"', false)
            ->assertSee('value="'.$value.'"', false)
            ->assertSee('aria-describedby="'.$outputId.'"', false)
            ->assertSee('id="'.$outputId.'"', false)
            ->assertSee('data-range-output', false)
            ->assertSee('for="'.$rangeId.'"', false);
    }

    private function member(string $roleName, Organisation $organisation): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $user->id, 'is_active' => true]);
        $membership->roles()->attach(Role::where('name', $roleName)->firstOrFail());
        return $user;
    }
}
