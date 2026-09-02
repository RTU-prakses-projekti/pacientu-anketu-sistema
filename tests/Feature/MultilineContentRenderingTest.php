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

class MultilineContentRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_multiline_consent_explanatory_and_description_are_escaped_in_preview_and_runner(): void
    {
        $organisation = Organisation::create(['name' => 'Content organisation', 'slug' => 'content-'.Str::lower(Str::random(8)), 'is_active' => true]);
        $creator = $this->member('form_creator', $organisation);
        $respondent = $this->member('respondent', $organisation);
        $authoring = app(FormAuthoringService::class);
        $form = $authoring->create($organisation->id, $creator, 'Multiline form', 'blank');
        $version = $form->versions()->firstOrFail();
        $section = $version->sections()->firstOrFail();
        $unsafe = "Pirmā rinda\nOtrā rinda <script>alert(1)</script>";
        $version->update(['description' => $unsafe, 'translations' => ['lv' => ['title' => $version->title, 'description' => $unsafe]]]);
        $section->update(['description' => $unsafe, 'translations' => ['lv' => ['title' => $section->title, 'description' => $unsafe]]]);
        $authoring->addComponent($version, $section, ['type' => 'explanatory_text', 'label' => "Ievads\nTurpinājums", 'description' => $unsafe, 'options' => []]);
        $authoring->addComponent($version, $section, ['type' => 'consent_checkbox', 'label' => 'Piekrišana', 'is_required' => true, 'settings' => ['consent_text' => $unsafe], 'options' => []]);

        $preview = $this->actingAs($creator)->get(route('forms.preview', $form));
        $this->assertSafeMultilineMarkup($preview);

        $published = $authoring->publish($version);
        $publication = Publication::create([
            'organisation_id' => $organisation->id, 'form_id' => $form->id, 'form_version_id' => $published->id,
            'public_key' => Str::lower(Str::random(20)), 'name' => 'Multiline publication', 'status' => 'active',
            'access_mode' => 'authenticated', 'attempt_limit' => 1, 'autosave_enabled' => true, 'resume_enabled' => true,
        ]);
        $submission = app(SubmissionService::class)->start($publication, $respondent, null, null, 'unused');
        $this->assertSafeMultilineMarkup($this->actingAs($respondent)->get(route('submissions.take', $submission)));
    }

    public function test_russian_consent_is_saved_and_used_in_builder_heading_and_preview_with_latvian_fallback_only_when_empty(): void
    {
        $organisation = Organisation::create(['name' => 'RU organisation', 'slug' => 'ru-'.Str::lower(Str::random(8)), 'is_active' => true]);
        $creator = $this->member('form_creator', $organisation);
        $authoring = app(FormAuthoringService::class);
        $form = $authoring->create($organisation->id, $creator, 'RU form', 'blank');
        $version = $form->versions()->firstOrFail();
        $component = $authoring->addComponent($version, $version->sections()->firstOrFail(), [
            'type' => 'consent_checkbox', 'is_required' => true, 'options' => [],
            'translations' => [
                'lv' => ['label' => 'Latviešu piekrišana', 'consent_text' => 'Latviešu teksts'],
                'ru' => ['label' => 'Русское согласие', 'consent_text' => 'Русский текст согласия'],
            ],
        ]);

        $this->assertSame('Русское согласие', $component->fresh()->localizedLabel('ru'));
        $this->assertSame('Русский текст согласия', $component->fresh()->localizedConsentText('ru'));
        $this->actingAs($creator)->withSession(['locale' => 'ru'])->get(route('forms.builder', $form))
            ->assertOk()->assertSee('<strong>Русское согласие</strong>', false);
        $this->get(route('forms.preview', $form).'?locale=ru')->assertOk()->assertSee('Русский текст согласия')->assertDontSee('Latviešu teksts');

        $translations = $component->translations;
        $translations['ru']['consent_text'] = '';
        $component->update(['translations' => $translations]);
        $this->assertSame('Latviešu teksts', $component->fresh()->localizedConsentText('ru'));
    }

    private function assertSafeMultilineMarkup($response): void
    {
        $response->assertOk()->assertSee('multiline-text', false)->assertSee("Pirmā rinda\nOtrā rinda", false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    private function member(string $roleName, Organisation $organisation): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $user->id, 'is_active' => true]);
        $membership->roles()->attach(Role::where('name', $roleName === 'respondent' ? 'doctor' : $roleName)->firstOrFail());
        return $user;
    }
}
