<?php

namespace Tests\Feature;

use App\Domain\Forms\BuilderService;
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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DynamicChoiceOptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_create_accepts_dynamic_option_counts_for_all_choice_types(): void
    {
        [$creator, $organisation] = $this->member('form_creator');
        $form = $this->form($organisation, $creator, 'Dynamic create');
        $section = $form->versions()->firstOrFail()->sections()->firstOrFail();

        foreach (['single_choice' => 4, 'multiple_choice' => 5, 'dropdown' => 4] as $type => $count) {
            $response = $this->actingAs($creator)->post(route('builder.components.store', $form), $this->createPayload($section->id, $type, $count));
            $response->assertRedirect()->assertSessionHasNoErrors();
            $component = $form->versions()->firstOrFail()->components()->where('type', $type)->firstOrFail();
            $this->assertSame($count, $component->options()->count());
            $this->assertSame($count, $component->options()->pluck('stable_key')->unique()->count());
            $this->assertSame($count, $component->options()->pluck('value')->unique()->count());
            $component->options()->each(function ($option): void {
                $this->assertTrue(Str::isUuid($option->stable_key));
                $this->assertTrue(Str::isUuid($option->value));
            });
        }
    }

    public function test_edit_adds_multiple_options_and_preserves_existing_identifiers(): void
    {
        [$creator, $organisation] = $this->member('form_creator');
        $form = $this->form($organisation, $creator, 'Dynamic edit');
        $version = $form->versions()->firstOrFail();
        $component = app(FormAuthoringService::class)->addComponent($version, $version->sections()->firstOrFail(), [
            'type' => 'single_choice', 'label' => 'Choice', 'options' => ['One', 'Two'],
        ]);
        $before = $component->options()->orderBy('id')->get()->keyBy('id');
        $payload = $this->updatePayload($component, $before->map(fn ($option) => $option->label)->all());
        $payload['options']['new'] = [
            3 => ['translations' => ['lv' => ['label' => 'Three'], 'en' => ['label' => 'Third']]],
            8 => ['translations' => ['lv' => ['label' => 'Four'], 'ru' => ['label' => 'Четвёртый']]],
        ];

        $this->actingAs($creator)->put(route('builder.components.update', [$form, $component]), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $after = $component->options()->orderBy('id')->get();
        $this->assertCount(4, $after);
        foreach ($before as $id => $option) {
            $persisted = $after->firstWhere('id', $id);
            $this->assertSame($option->stable_key, $persisted->stable_key);
            $this->assertSame($option->value, $persisted->value);
        }
        $new = $after->whereNotIn('id', $before->keys());
        $this->assertSame(2, $new->pluck('stable_key')->unique()->count());
        $this->assertSame(2, $new->pluck('value')->unique()->count());
        $new->each(fn ($option) => $this->assertTrue(Str::isUuid($option->stable_key) && Str::isUuid($option->value)));
    }

    public function test_existing_option_can_be_removed_and_scoring_reference_is_replaced_atomically(): void
    {
        [$creator, $organisation] = $this->member('form_creator');
        $form = $this->form($organisation, $creator, 'Scored delete');
        $version = $form->versions()->firstOrFail();
        $component = app(FormAuthoringService::class)->addComponent($version, $version->sections()->firstOrFail(), [
            'type' => 'single_choice', 'label' => 'Choice', 'max_points' => 1, 'options' => ['One', 'Two', 'Three'],
            'scoring_strategy' => 'single_choice', 'scoring_rules' => [],
        ]);
        $options = $component->options()->orderBy('id')->get();
        $component->scoringRule()->update(['rules' => ['correct' => $options[0]->value]]);
        $remaining = $options->slice(1)->keyBy('id');
        $payload = $this->updatePayload($component, $remaining->map(fn ($option) => $option->label)->all());
        $payload['scoring_strategy'] = 'single_choice';
        $payload['scoring_rules'] = ['correct' => $options[1]->value];

        $this->actingAs($creator)->put(route('builder.components.update', [$form, $component]), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('component_options', ['id' => $options[0]->id]);
        $this->assertSame($options[1]->value, $component->scoringRule()->firstOrFail()->rules['correct']);
    }

    public function test_option_used_by_conditional_visibility_cannot_be_removed(): void
    {
        [$creator, $organisation] = $this->member('form_creator');
        $form = $this->form($organisation, $creator, 'Conditional delete');
        $version = $form->versions()->firstOrFail();
        $section = $version->sections()->firstOrFail();
        $choice = app(FormAuthoringService::class)->addComponent($version, $section, ['type' => 'dropdown', 'label' => 'Choice', 'options' => ['One', 'Two']]);
        $target = app(FormAuthoringService::class)->addComponent($version, $section, ['type' => 'short_text', 'label' => 'Target', 'options' => []]);
        $options = $choice->options()->orderBy('id')->get();
        $rule = $version->conditionalRules()->create(['source_component_id' => $choice->id, 'operator' => 'equals', 'comparison_value' => ['value' => $options[0]->value], 'priority' => 1]);
        $rule->actions()->create(['action' => 'show_component', 'target_component_id' => $target->id]);

        $payload = $this->updatePayload($choice, [$options[1]->id => $options[1]->label]);
        $this->actingAs($creator)->put(route('builder.components.update', [$form, $choice]), $payload)
            ->assertRedirect()->assertSessionHasErrors('options');
        $this->assertSame(2, $choice->options()->count());
    }

    public function test_option_limits_and_label_length_are_enforced_on_create_and_edit(): void
    {
        [$creator, $organisation] = $this->member('form_creator');
        $form = $this->form($organisation, $creator, 'Limits');
        $section = $form->versions()->firstOrFail()->sections()->firstOrFail();

        $this->actingAs($creator)->post(route('builder.components.store', $form), $this->createPayload($section->id, 'single_choice', 100))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($creator)->post(route('builder.components.store', $form), $this->createPayload($section->id, 'multiple_choice', 101))
            ->assertRedirect()->assertSessionHasErrors('options');

        $component = $form->versions()->firstOrFail()->components()->where('type', 'single_choice')->firstOrFail();
        $existing = $component->options()->get()->mapWithKeys(fn ($option) => [$option->id => $option->label])->all();
        $payload = $this->updatePayload($component, $existing);
        $payload['options']['new'] = [['translations' => ['lv' => ['label' => 'Overflow']]]];
        $this->actingAs($creator)->put(route('builder.components.update', [$form, $component]), $payload)
            ->assertRedirect()->assertSessionHasErrors('options');

        $tooLong = $this->createPayload($section->id, 'dropdown', 2);
        $tooLong['options'][0]['translations']['lv']['label'] = str_repeat('x', 256);
        $this->actingAs($creator)->post(route('builder.components.store', $form), $tooLong)
            ->assertRedirect()->assertSessionHasErrors('options.0.translations.lv.label');
    }

    public function test_publish_requires_two_real_options_but_allows_draft_intermediate_state(): void
    {
        [$creator, $organisation] = $this->member('form_creator');
        $authoring = app(FormAuthoringService::class);
        $emptyForm = $this->form($organisation, $creator, 'Publish empty choice');
        $emptyVersion = $emptyForm->versions()->firstOrFail();
        $authoring->addComponent($emptyVersion, $emptyVersion->sections()->firstOrFail(), ['type' => 'dropdown', 'label' => 'Empty choice', 'options' => []]);
        try {
            $authoring->publish($emptyVersion);
            $this->fail('Publishing a choice with no options should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('options', $exception->errors());
        }

        $form = $this->form($organisation, $creator, 'Publish minimum');
        $version = $form->versions()->firstOrFail();
        $component = $authoring->addComponent($version, $version->sections()->firstOrFail(), ['type' => 'single_choice', 'label' => 'Choice', 'options' => ['Only one']]);
        $this->assertSame(1, $component->options()->count());
        try {
            $authoring->publish($version);
            $this->fail('Publishing a choice with one option should fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('options', $exception->errors());
        }
        $this->assertSame('draft', $version->fresh()->status);

        app(BuilderService::class)->updateComponent($component, [
            'visible' => true, 'translations' => ['lv' => ['label' => 'Choice']],
            'options' => ['existing' => [$component->options()->firstOrFail()->id => ['translations' => ['lv' => ['label' => 'Only one']]]], 'new' => [['translations' => ['lv' => ['label' => 'Second']]]]],
            'scoring_strategy' => 'none',
        ]);
        $this->assertSame('published', $authoring->publish($version->fresh())->status);
    }

    public function test_builder_preview_runner_and_multiple_choice_resume_render_all_options(): void
    {
        [$creator, $organisation] = $this->member('form_creator');
        [$respondent] = $this->member('respondent', $organisation);
        $authoring = app(FormAuthoringService::class);
        $form = $this->form($organisation, $creator, 'Rendering');
        $version = $form->versions()->firstOrFail();
        $component = $authoring->addComponent($version, $version->sections()->firstOrFail(), [
            'type' => 'multiple_choice', 'label' => 'Many choices', 'options' => ['One', 'Two', 'Three', 'Four', 'Five'],
        ]);
        $builder = $this->actingAs($creator)->get(route('forms.builder', $form))->assertOk();
        $builder->assertSee('data-option-manager', false)
            ->assertSee('data-option-add', false)
            ->assertSee('data-option-remove', false)
            ->assertSee('options[existing]['.$component->options()->firstOrFail()->id.'][translations][lv][label]', false)
            ->assertSee('options[0][translations][lv][label]', false)
            ->assertSee('options[__INDEX__][translations][lv][label]', false)
            ->assertSee('options[new][__INDEX__][translations][lv][label]', false);

        $preview = $this->actingAs($creator)->get(route('forms.preview', $form))->assertOk();
        foreach (['One', 'Two', 'Three', 'Four', 'Five'] as $label) $preview->assertSee($label);

        $published = $authoring->publish($version);
        $publication = $this->publication($form, $published);
        $submission = app(SubmissionService::class)->start($publication, $respondent, null, null, 'unused');
        $selected = $published->components()->where('stable_key', $component->stable_key)->firstOrFail()->options()->orderBy('id')->take(3)->pluck('value')->all();
        app(SubmissionService::class)->autosave($submission, 0, (string) Str::uuid(), [$published->components()->where('stable_key', $component->stable_key)->value('id') => $selected]);
        $runner = $this->actingAs($respondent)->get(route('submissions.take', $submission->fresh()))->assertOk();
        foreach (['One', 'Two', 'Three', 'Four', 'Five'] as $label) $runner->assertSee($label);
        foreach ($selected as $value) $runner->assertSee('value="'.$value.'" checked', false);
    }

    private function createPayload(int $sectionId, string $type, int $count): array
    {
        return [
            'section_id' => $sectionId, 'type' => $type, 'visible' => 1,
            'translations' => ['lv' => ['label' => $type]],
            'options' => collect(range(1, $count))->map(fn ($number) => ['translations' => [
                'lv' => ['label' => 'Variants '.$number], 'en' => ['label' => 'Option '.$number], 'ru' => ['label' => 'Вариант '.$number],
            ]])->all(),
            'scoring_strategy' => 'none',
        ];
    }

    private function updatePayload($component, array $existing): array
    {
        return [
            'visible' => 1, 'translations' => ['lv' => ['label' => $component->label]],
            'options' => ['existing' => collect($existing)->map(fn ($label) => ['translations' => ['lv' => ['label' => $label]]])->all(), 'new' => []],
            'scoring_strategy' => 'none',
        ];
    }

    private function form(Organisation $organisation, User $creator, string $name)
    {
        return app(FormAuthoringService::class)->create($organisation->id, $creator, $name, 'blank');
    }

    private function member(string $roleName, ?Organisation $organisation = null): array
    {
        $organisation ??= Organisation::create(['name' => Str::random(8), 'slug' => Str::lower(Str::random(10)), 'is_active' => true]);
        $user = User::factory()->create(['student_id' => Str::uuid()->toString(), 'is_active' => true]);
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $user->id, 'is_active' => true]);
        $membership->roles()->attach(Role::where('name', $roleName)->firstOrFail());
        return [$user, $organisation];
    }

    private function publication($form, $version): Publication
    {
        return Publication::create([
            'organisation_id' => $form->organisation_id, 'form_id' => $form->id, 'form_version_id' => $version->id,
            'public_key' => Str::lower(Str::random(20)), 'name' => 'Publication', 'status' => 'active', 'access_mode' => 'authenticated',
            'attempt_limit' => 1, 'timer_enabled' => false, 'result_visibility' => 'completion', 'correct_answers_visible' => false,
            'anonymous_allowed' => false, 'identified_required' => true, 'consent_required' => false, 'autosave_enabled' => true, 'resume_enabled' => true,
        ]);
    }
}
