<?php

namespace Tests\Feature;

use App\Domain\Forms\FormAuthoringService;
use App\Domain\Forms\QuestionnairePackageService;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RussianConsentQuestionnairePackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_corrected_russian_consent_package_validates_and_imports_in_both_modes(): void
    {
        $this->seed(RolePermissionSeeder::class);
        config(['questionnaire_packages.root' => base_path('questionnaires')]);
        $packages = app(QuestionnairePackageService::class);
        $package = collect($packages->discover())->first(fn (array $item) => str_starts_with($item['package_name'], 'informeta-piekrisana-dalibai-petijuma--'));
        $this->assertNotNull($package);
        $manifest = $packages->validatePackage($package['package_name']);
        $this->assertSame([], $manifest['conditional_rules']);

        $organisation = Organisation::create(['name' => 'RU import organisation', 'slug' => 'ru-import-'.Str::lower(Str::random(8)), 'is_active' => true]);
        $creator = User::factory()->create(['is_active' => true]);
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $creator->id, 'is_active' => true]);
        $membership->roles()->attach(Role::where('name', 'form_creator')->firstOrFail());

        $importedForm = $packages->import($package['package_name'], $organisation, $creator);
        $importedVersion = $importedForm->versions()->firstOrFail();
        $importedConsent = $importedVersion->components()->where('type', 'consent_checkbox')->firstOrFail();
        $this->assertSame(data_get($importedConsent->translations, 'ru.label'), $importedConsent->localizedConsentText('ru'));
        $this->actingAs($creator)->get(route('forms.preview', $importedForm).'?locale=ru')
            ->assertOk()->assertSee($importedConsent->localizedConsentText('ru'))->assertDontSee($importedConsent->localizedConsentText('lv'));

        $targetForm = app(FormAuthoringService::class)->create($organisation->id, $creator, 'RU part target', 'blank');
        $targetVersion = $targetForm->versions()->firstOrFail();
        $before = $targetVersion->sections()->count();
        $packages->importInto($package['package_name'], $targetVersion, $creator);
        $this->assertGreaterThan($before, $targetVersion->sections()->count());
        $this->assertGreaterThan(0, $targetVersion->components()->where('type', 'consent_checkbox')->count());
    }
}
