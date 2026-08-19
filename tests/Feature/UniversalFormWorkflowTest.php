<?php

namespace Tests\Feature;

use App\Domain\Exports\ExportService;
use App\Domain\Forms\FormAuthoringService;
use App\Domain\Forms\BuilderService;
use App\Domain\Submissions\SubmissionService;
use App\Domain\Submissions\ConditionalLogicService;
use App\Models\Export;
use App\Models\Attachment;
use App\Models\ConsentRecord;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\Publication;
use App\Models\Role;
use App\Models\SubmissionAnswer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class UniversalFormWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_registration_persists_identity_without_role_escalation_and_login_is_throttled(): void
    {
        $response=$this->post('/register',['name'=>'New Respondent','email'=>'new@example.test','student_id'=>'RESP-1','role'=>'platform_admin','permissions'=>['*'],'password'=>'LongPassword123','password_confirmation'=>'LongPassword123']);
        $response->assertRedirect(route('dashboard'));
        $user=User::where('email','new@example.test')->firstOrFail();
        $this->assertSame('RESP-1',$user->student_id);$this->assertSame('student',$user->role);$this->assertFalse($user->isPlatformAdmin());
        auth()->logout();
        for($i=0;$i<5;$i++)$this->post('/login',['email'=>'new@example.test','password'=>'wrong']);
        $this->post('/login',['email'=>'new@example.test','password'=>'wrong'])->assertStatus(429);
    }

    public function test_first_platform_administrator_can_be_bootstrapped_once(): void
    {
        $this->bootstrapAdmin('First Admin','first-admin@example.test')->expectsOutput('First platform administrator created. Future administrators must be managed through the authorised administration interface.')->assertExitCode(ConsoleCommand::SUCCESS);
        $user=User::where('email','first-admin@example.test')->firstOrFail();$this->assertTrue($user->isPlatformAdmin());$this->assertTrue($user->is_active);$this->assertTrue(Hash::check('BootstrapPassword123',$user->password));
    }

    public function test_second_bootstrap_invocation_is_rejected_without_changes(): void
    {
        $this->bootstrapAdmin('First Admin','first-admin@example.test')->assertExitCode(ConsoleCommand::SUCCESS);$first=User::where('email','first-admin@example.test')->firstOrFail();$password=$first->password;
        $this->artisan('app:create-admin',['--name'=>'Second Admin','--email'=>'second-admin@example.test'])->expectsOutput('A platform administrator already exists. Additional administrators must be managed through the authorised administration interface.')->assertExitCode(ConsoleCommand::FAILURE);
        $this->assertSame(1,User::count());$this->assertDatabaseMissing('users',['email'=>'second-admin@example.test']);$this->assertSame($password,$first->fresh()->password);$this->assertTrue($first->fresh()->isPlatformAdmin());
    }

    public function test_existing_respondent_cannot_be_promoted_by_bootstrap_command(): void
    {
        $respondent=User::factory()->create(['email'=>'existing@example.test','password'=>Hash::make('OriginalPassword123'),'is_active'=>false]);$password=$respondent->password;
        $this->artisan('app:create-admin',['--name'=>'Replacement Name','--email'=>'existing@example.test'])->expectsOutput('That email already belongs to an existing account. Initial bootstrap requires a new dedicated administrator account.')->assertExitCode(ConsoleCommand::FAILURE);
        $respondent->refresh();$this->assertSame($password,$respondent->password);$this->assertFalse($respondent->is_active);$this->assertFalse($respondent->isPlatformAdmin());
    }

    public function test_concurrent_bootstrap_lock_prevents_two_first_administrators(): void
    {
        $lock=Cache::lock('app:create-admin:first-platform-admin',60);$this->assertTrue($lock->get());
        try{$this->artisan('app:create-admin',['--name'=>'Racing Admin','--email'=>'race@example.test'])->expectsOutput('Another first-administrator bootstrap attempt is already in progress.')->assertExitCode(ConsoleCommand::FAILURE);}finally{$lock->release();}
        $this->assertSame(0,User::whereHas('globalRoles',fn($query)=>$query->where('name','platform_admin'))->count());$this->bootstrapAdmin('Winning Admin','winner@example.test')->assertExitCode(ConsoleCommand::SUCCESS);$this->assertSame(1,User::whereHas('globalRoles',fn($query)=>$query->where('name','platform_admin'))->count());
    }

    public function test_additional_platform_administrators_require_authenticated_audited_interface(): void
    {
        $role=Role::where('name','platform_admin')->firstOrFail();$admin=User::factory()->create(['is_active'=>true]);$admin->globalRoles()->attach($role);$candidate=User::factory()->create(['is_active'=>true]);
        $this->post(route('system.users.store'),['name'=>'Blocked','email'=>'blocked@example.test','password'=>'AnotherPassword123','password_confirmation'=>'AnotherPassword123'])->assertRedirect(route('login'));
        $this->actingAs($candidate)->put(route('system.users.roles.update',$candidate),['global_roles'=>[$role->id]])->assertForbidden();
        $this->actingAs($admin)->post(route('system.users.store'),['name'=>'Created User','email'=>'created-user@example.test','password'=>'AnotherPassword123','password_confirmation'=>'AnotherPassword123'])->assertRedirect();$created=User::where('email','created-user@example.test')->firstOrFail();$this->assertFalse($created->isPlatformAdmin());$this->assertDatabaseHas('audit_logs',['actor_id'=>$admin->id,'action'=>'user.created','subject_id'=>$created->id]);
        $this->actingAs($admin)->put(route('system.users.roles.update',$candidate),['global_roles'=>[$role->id]])->assertRedirect();$this->assertTrue($candidate->fresh()->isPlatformAdmin());$this->assertDatabaseHas('audit_logs',['actor_id'=>$admin->id,'action'=>'user.roles_updated','subject_id'=>$candidate->id]);
    }

    public function test_organisation_policies_prevent_cross_organisation_access(): void
    {
        [$user,$organisation]=$this->member('form_creator');
        $other=Organisation::create(['name'=>'Other','slug'=>'other','is_active'=>true]);
        $this->actingAs($user)->get(route('forms.index',$other))->assertForbidden();
        $this->actingAs($user)->get(route('forms.index',$organisation))->assertOk();
    }

    public function test_form_draft_publication_immutability_new_draft_and_archive_preserve_history(): void
    {
        [$creator,$organisation]=$this->member('form_creator');
        $service=app(FormAuthoringService::class);$form=$service->create($organisation->id,$creator,'Exam','test');$version=$form->versions()->first();
        $published=$service->publish($version);$this->assertSame('published',$published->status);$this->assertNotNull($published->content_hash);
        $this->expectException(LogicException::class);$published->update(['settings'=>['tampered'=>true]]);
    }

    public function test_published_form_can_create_new_draft_and_archive_without_deleting_publication(): void
    {
        [$creator,$organisation]=$this->member('form_creator');$service=app(FormAuthoringService::class);$form=$service->create($organisation->id,$creator,'Exam','test');$published=$service->publish($form->versions()->first());
        $draft=$service->createDraftFrom($published,$creator);$this->assertSame('draft',$draft->status);$this->assertSame(2,$draft->version_number);$this->assertSame($published->components()->count(),$draft->components()->count());
        $publication=$this->publication($form,$published,['access_mode'=>'authenticated']);$service->archive($form);$this->assertDatabaseHas('forms',['id'=>$form->id,'status'=>'archived']);$this->assertDatabaseHas('publications',['id'=>$publication->id,'status'=>'inactive']);$this->assertDatabaseHas('form_versions',['id'=>$published->id]);
    }

    public function test_builder_creates_copies_reorders_and_moves_components_without_losing_data(): void
    {
        [$creator,$organisation]=$this->member('form_creator');$authoring=app(FormAuthoringService::class);$builder=app(BuilderService::class);$form=$authoring->create($organisation->id,$creator,'Builder','blank');$version=$form->versions()->first();$first=$version->sections()->first();$second=$authoring->addSection($version,'Second');$component=$authoring->addComponent($version,$first,['type'=>'short_text','label'=>'Name','is_required'=>true,'settings'=>['placeholder'=>'Answer'],'options'=>[]]);$copy=$builder->copyComponent($component->load('section','options','validationRules','scoringRule'));$this->assertSame(2,$first->components()->count());$builder->moveComponent($copy,$second,'section');$this->assertSame($second->id,$copy->fresh()->form_section_id);$builder->moveSection($second,'up');$this->assertLessThan($first->fresh()->display_order,$second->fresh()->display_order);$this->assertSame('Answer',$component->fresh()->settings['placeholder']);
    }

    public function test_component_copy_keeps_base_and_latvian_label_consistent_without_changing_option_values_or_scoring(): void
    {
        [$creator,$organisation]=$this->member('form_creator');$authoring=app(FormAuthoringService::class);$builder=app(BuilderService::class);$form=$authoring->create($organisation->id,$creator,'Copy localization','blank');$version=$form->versions()->firstOrFail();$component=$authoring->addComponent($version,$version->sections()->firstOrFail(),['type'=>'single_choice','translations'=>['lv'=>['label'=>'Jautājums'],'en'=>['label'=>'Question']],'options'=>['Pirmais','Otrais'],'max_points'=>1,'scoring_strategy'=>'single_choice','scoring_rules'=>[]]);$correct=$component->options()->first()->value;$component->scoringRule()->update(['rules'=>['correct'=>$correct]]);$originalValues=$component->options()->orderBy('display_order')->pluck('value')->all();$originalKeys=$component->options()->orderBy('display_order')->pluck('stable_key')->all();
        $copy=$builder->copyComponent($component->load('section','options','validationRules','scoringRule'));
        $this->assertSame('Jautājums (copy)',$copy->label);$this->assertSame($copy->label,$copy->localizedLabel('lv'));$this->assertSame($copy->label,data_get($copy->translations,'lv.label'));$this->assertSame($originalValues,$copy->options()->orderBy('display_order')->pluck('value')->all());$this->assertNotSame($originalKeys,$copy->options()->orderBy('display_order')->pluck('stable_key')->all());$this->assertSame($correct,$copy->scoringRule()->firstOrFail()->rules['correct']);
    }

    public function test_system_generated_preset_latvian_content_is_independent_from_application_locale(): void
    {
        app()->setLocale('en');[$creator,$organisation]=$this->member('form_creator');$authoring=app(FormAuthoringService::class);$testForm=$authoring->create($organisation->id,$creator,'English admin title','test');$testVersion=$testForm->versions()->firstOrFail();$section=$testVersion->sections()->firstOrFail();$question=$testVersion->components()->with('options')->firstOrFail();
        $this->assertSame(__('messages.first_section',[],'lv'),$section->title);$this->assertSame($section->title,data_get($section->translations,'lv.title'));$this->assertSame(__('messages.sample_question',[],'lv'),$question->label);$this->assertSame($question->label,data_get($question->translations,'lv.label'));$this->assertSame([__('messages.option_a',[],'lv'),__('messages.option_b',[],'lv')],$question->options->pluck('label')->all());$this->assertSame($question->options->pluck('label')->all(),$question->options->map(fn($option)=>data_get($option->translations,'lv.label'))->all());$this->assertNotSame(__('messages.sample_question',[],'en'),$question->label);
        $patientForm=$authoring->create($organisation->id,$creator,'Patient preset','patient_questionnaire');$patientComponents=$patientForm->versions()->firstOrFail()->components()->get();$consent=$patientComponents->firstWhere('type','consent_checkbox');$prompt=$patientComponents->firstWhere('type','long_text');$this->assertSame(__('messages.consent_label',[],'lv'),$consent->label);$this->assertSame(__('messages.demo_consent_text',[],'lv'),$consent->settings['consent_text']);$this->assertSame(__('messages.sample_questionnaire_prompt',[],'lv'),$prompt->label);
    }

    public function test_test_workflow_autosaves_idempotently_resumes_and_scores_server_side(): void
    {
        [$creator,$organisation]=$this->member('form_creator');[$respondent]=$this->member('respondent',$organisation);
        $authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Exam','test');$published=$authoring->publish($form->versions()->first());$publication=$this->publication($form,$published,['access_mode'=>'authenticated','result_visibility'=>'score','correct_answers_visible'=>true,'timer_enabled'=>true,'duration_minutes'=>30]);
        $service=app(SubmissionService::class);$submission=$service->start($publication,$respondent,null,null,'unused');$deadline=$submission->deadline_at;
        $component=$published->components()->with('options')->first();$correct=$component->scoringRule->rules['correct'];$mutation=(string)Str::uuid();
        $saved=$service->autosave($submission,0,$mutation,[$component->id=>$correct]);$this->assertSame(1,$saved['revision']);
        $repeat=$service->autosave($submission->fresh(),0,$mutation,[$component->id=>$correct]);$this->assertTrue($repeat['idempotent']);$this->assertSame(1,SubmissionAnswer::count());
        $resumed=$service->start($publication,$respondent,null,null,'unused');$this->assertSame($submission->id,$resumed->id);$this->assertTrue($deadline->equalTo($resumed->deadline_at));
        $final=$service->finalize($resumed);$this->assertSame('graded',$final->status);$this->assertEquals(1.0,(float)$final->final_points);$this->assertEquals(100.0,(float)$final->percentage);$correctLabel=$component->options->firstWhere('value',$correct)->localizedLabel();$this->actingAs($respondent)->get(route('submissions.complete',$final))->assertOk()->assertSee($correctLabel)->assertDontSee($correct);
        try{$service->start($publication,$respondent,null,null,'unused');$this->fail('Expected attempt limit');}catch(ValidationException $e){$this->assertArrayHasKey('attempt',$e->errors());}
        $this->expectException(ValidationException::class);$service->autosave($final,1,(string)Str::uuid(),[$component->id=>$correct]);
    }

    public function test_revision_conflict_and_deadline_are_server_enforced(): void
    {
        [$creator,$organisation]=$this->member('form_creator');[$respondent]=$this->member('respondent',$organisation);$authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Timed','test');$published=$authoring->publish($form->versions()->first());$publication=$this->publication($form,$published,['access_mode'=>'authenticated','timer_enabled'=>true,'duration_minutes'=>1]);$service=app(SubmissionService::class);$submission=$service->start($publication,$respondent,null,null,'unused');$component=$published->components()->with('options')->first();
        $service->autosave($submission,0,(string)Str::uuid(),[$component->id=>$component->options->first()->value]);
        try{$service->autosave($submission->fresh(),0,(string)Str::uuid(),[$component->id=>$component->options->last()->value]);$this->fail('Expected revision conflict');}catch(ValidationException $e){$this->assertArrayHasKey('revision',$e->errors());}
        $this->travel(2)->minutes();try{$service->autosave($submission->fresh(),1,(string)Str::uuid(),[$component->id=>$component->options->first()->value]);$this->fail('Expected deadline rejection');}catch(ValidationException $e){$this->assertArrayHasKey('deadline',$e->errors());}
        $this->assertSame('expired',$submission->fresh()->status);$this->assertSame(1,SubmissionAnswer::count());
    }

    public function test_access_code_and_invitation_tokens_are_hashed_and_limited(): void
    {
        [$creator,$organisation]=$this->member('form_creator');$authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Survey','blank');$published=$authoring->publish($form->versions()->first());$service=app(SubmissionService::class);
        $coded=$this->publication($form,$published,['access_mode'=>'access_code','access_code_hash'=>Hash::make('secret-code'),'identified_required'=>false,'anonymous_allowed'=>true]);
        $this->expectException(ValidationException::class);$service->start($coded,null,'wrong',null,'browser-a');
    }

    public function test_access_code_success_and_publication_window_enforcement(): void
    {
        [$creator,$organisation]=$this->member('form_creator');$authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Survey','blank');$published=$authoring->publish($form->versions()->first());$service=app(SubmissionService::class);$coded=$this->publication($form,$published,['access_mode'=>'access_code','access_code_hash'=>Hash::make('secret-code'),'identified_required'=>false,'anonymous_allowed'=>true]);$submission=$service->start($coded,null,'secret-code',null,'browser-code');$this->assertSame('in_progress',$submission->status);
        $closed=$this->publication($form,$published,['access_mode'=>'public','identified_required'=>false,'anonymous_allowed'=>true,'opens_at'=>now()->addHour()]);$this->expectException(ValidationException::class);$service->start($closed,null,null,null,'browser-closed');
    }

    public function test_overdue_scheduler_command_finalizes_attempts(): void
    {
        [$creator,$organisation]=$this->member('form_creator');[$respondent]=$this->member('respondent',$organisation);$authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Timed','test');$published=$authoring->publish($form->versions()->first());$publication=$this->publication($form,$published,['access_mode'=>'authenticated','timer_enabled'=>true,'duration_minutes'=>1]);$submission=app(SubmissionService::class)->start($publication,$respondent,null,null,'unused');$this->travel(2)->minutes();$this->artisan('submissions:finalize-overdue')->assertSuccessful();$this->assertSame('expired',$submission->fresh()->status);
    }

    public function test_invitation_plain_token_is_never_stored(): void
    {
        [$creator,$organisation]=$this->member('form_creator');$authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Survey','blank');$published=$authoring->publish($form->versions()->first());$publication=$this->publication($form,$published,['access_mode'=>'invitation','identified_required'=>false]);$plain='one-time-secret';$invitation=Invitation::create(['publication_id'=>$publication->id,'token_hash'=>hash('sha256',$plain),'max_uses'=>1,'uses'=>0]);
        $submission=app(SubmissionService::class)->start($publication,null,null,$plain,'browser-b');$this->assertSame($invitation->id,$submission->invitation_id);$this->assertDatabaseMissing('invitations',['token_hash'=>$plain]);$this->assertSame(1,$invitation->fresh()->uses);
    }

    public function test_patient_questionnaire_records_exact_consent_refusal_and_removes_unrelated_answers(): void
    {
        [$creator,$organisation]=$this->member('form_creator');$authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Patient questionnaire','patient_questionnaire');$published=$authoring->publish($form->versions()->first());$publication=$this->publication($form,$published,['access_mode'=>'public','identified_required'=>false,'anonymous_allowed'=>true,'consent_required'=>true]);$service=app(SubmissionService::class);$submission=$service->start($publication,null,null,null,'anonymous-browser');$components=$published->components()->get();$consent=$components->firstWhere('type','consent_checkbox');$text=$components->firstWhere('type','long_text');
        $service->autosave($submission,0,(string)Str::uuid(),[$text->id=>'Sensitive demo answer',$consent->id=>false]);
        $this->assertDatabaseHas('consent_records',['form_submission_id'=>$submission->id,'form_version_id'=>$published->id,'decision'=>'refused']);$this->assertDatabaseMissing('submission_answers',['form_submission_id'=>$submission->id,'form_component_id'=>$text->id]);
        $this->expectException(ValidationException::class);$service->finalize($submission->fresh());
    }

    public function test_repeated_same_consent_decision_preserves_original_locale_hash_and_recorded_time(): void
    {
        [$creator,$organisation]=$this->member('form_creator');[$respondent]=$this->member('respondent',$organisation);$authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Consent evidence','patient_questionnaire');$version=$form->versions()->firstOrFail();$consent=$version->components()->where('type','consent_checkbox')->firstOrFail();$translations=$consent->translations;$translations['ru']['consent_text']='Русский текст согласия';$consent->update(['translations'=>$translations]);$published=$authoring->publish($version);$publication=$this->publication($form,$published,['consent_required'=>true]);$submission=app(SubmissionService::class)->start($publication,$respondent,null,null,'unused');$service=app(SubmissionService::class);
        app()->setLocale('lv');$service->autosave($submission,0,(string)Str::uuid(),[$consent->id=>true]);$record=ConsentRecord::where('form_submission_id',$submission->id)->where('form_component_id',$consent->id)->firstOrFail();$expectedHash=hash('sha256',(string)$consent->localizedConsentText('lv'));$recordedAt=$record->recorded_at->copy();$this->assertSame('lv',$record->content_locale);$this->assertSame($expectedHash,$record->consent_text_hash);
        $this->travel(1)->minute();app()->setLocale('ru');$service->autosave($submission->fresh(),1,(string)Str::uuid(),[$consent->id=>true]);$record->refresh();$this->assertSame('accepted',$record->decision);$this->assertSame('lv',$record->content_locale);$this->assertSame($expectedHash,$record->consent_text_hash);$this->assertTrue($recordedAt->equalTo($record->recorded_at));
    }

    public function test_consent_evidence_records_the_resolved_text_source_locale_and_exact_hash(): void
    {
        [$creator,$organisation]=$this->member('form_creator');
        [$respondent]=$this->member('respondent',$organisation);
        $authoring=app(FormAuthoringService::class);
        $service=app(SubmissionService::class);
        app()->setLocale('ru');

        $russianForm=$authoring->create($organisation->id,$creator,'Russian consent source','patient_questionnaire');
        $russianVersion=$russianForm->versions()->firstOrFail();
        $russianConsent=$russianVersion->components()->where('type','consent_checkbox')->firstOrFail();
        $russianTranslations=$russianConsent->translations ?? [];
        $russianTranslations['ru']['consent_text']='Exact Russian consent text';
        $russianConsent->update(['translations'=>$russianTranslations]);
        $russianPublished=$authoring->publish($russianVersion);
        $russianSubmission=$service->start($this->publication($russianForm,$russianPublished,['consent_required'=>true]),$respondent,null,null,'unused');
        $service->autosave($russianSubmission,0,(string)Str::uuid(),[$russianConsent->id=>true]);
        $russianRecord=ConsentRecord::where('form_submission_id',$russianSubmission->id)->where('form_component_id',$russianConsent->id)->firstOrFail();
        $this->assertSame('ru',$russianConsent->localizedConsentTextSourceLocale('ru'));
        $this->assertSame('ru',$russianRecord->content_locale);
        $this->assertSame(hash('sha256','Exact Russian consent text'),$russianRecord->consent_text_hash);

        $latvianForm=$authoring->create($organisation->id,$creator,'Latvian consent fallback','patient_questionnaire');
        $latvianVersion=$latvianForm->versions()->firstOrFail();
        $latvianConsent=$latvianVersion->components()->where('type','consent_checkbox')->firstOrFail();
        $resolvedLatvianText=(string)$latvianConsent->localizedConsentText('ru');
        $latvianPublished=$authoring->publish($latvianVersion);
        $latvianSubmission=$service->start($this->publication($latvianForm,$latvianPublished,['consent_required'=>true]),$respondent,null,null,'unused');
        $service->autosave($latvianSubmission,0,(string)Str::uuid(),[$latvianConsent->id=>true]);
        $latvianRecord=ConsentRecord::where('form_submission_id',$latvianSubmission->id)->where('form_component_id',$latvianConsent->id)->firstOrFail();
        $this->assertSame('lv',$latvianConsent->localizedConsentTextSourceLocale('ru'));
        $this->assertSame('lv',$latvianRecord->content_locale);
        $this->assertSame(hash('sha256',$resolvedLatvianText),$latvianRecord->consent_text_hash);
    }

    public function test_partial_numeric_and_manual_scoring_share_one_engine_and_grading_is_audited(): void
    {
        [$creator,$organisation]=$this->member('form_creator');[$reviewer]=$this->member('reviewer',$organisation);$authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Mixed assessment','blank');$version=$form->versions()->first();$section=$version->sections()->first();
        $multi=$authoring->addComponent($version,$section,['type'=>'multiple_choice','label'=>'Choose','is_required'=>true,'max_points'=>4,'options'=>['Alpha','Beta','Gamma'],'scoring_strategy'=>'multiple_partial','scoring_rules'=>['correct'=>[]]]);$correct=$multi->options()->whereIn('label',['Alpha','Beta'])->pluck('value')->all();$multi->scoringRule->update(['rules'=>['correct'=>$correct]]);
        $numeric=$authoring->addComponent($version,$section,['type'=>'number','label'=>'Number','is_required'=>true,'max_points'=>3,'options'=>[],'scoring_strategy'=>'numeric_tolerance','scoring_rules'=>['correct'=>10,'tolerance'=>0.5]]);
        $manual=$authoring->addComponent($version,$section,['type'=>'long_text','label'=>'Essay','is_required'=>true,'max_points'=>2,'manual_grading'=>true,'options'=>[],'scoring_strategy'=>'manual','scoring_rules'=>[]]);
        $published=$authoring->publish($version);$publication=$this->publication($form,$published,['access_mode'=>'public','identified_required'=>false,'anonymous_allowed'=>true,'result_visibility'=>'none']);$service=app(SubmissionService::class);$submission=$service->start($publication,null,null,null,'mixed-browser');$service->autosave($submission,0,(string)Str::uuid(),[$multi->id=>[$correct[0]],$numeric->id=>10.4,$manual->id=>'Essay text']);$final=$service->finalize($submission->fresh());
        $this->assertSame('awaiting_grading',$final->status);$this->assertEquals(5.0,(float)$final->automatic_points);$this->assertEquals(9.0,(float)$final->maximum_points);
        $manualAnswer=$final->answers->firstWhere('form_component_id',$manual->id);$this->actingAs($reviewer)->put(route('grading.update',$final),['scores'=>[$manualAnswer->id=>['points'=>2,'comment'=>'Reviewed']],'finalize'=>1])->assertRedirect();$final->refresh();$this->assertSame('graded',$final->status);$this->assertEquals(7.0,(float)$final->final_points);$this->assertDatabaseHas('audit_logs',['action'=>'grading.finalized','subject_id'=>$final->id]);
        $this->actingAs($reviewer)->get(route('submissions.complete',$final))->assertForbidden();
        $this->actingAs($reviewer)->get(route('admin.submissions.show',$final))->assertOk();
    }

    public function test_all_answers_correct_scores_any_response_as_full_credit(): void
    {
        [$creator,$organisation]=$this->member('form_creator');[$respondent]=$this->member('respondent',$organisation);$authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Patient scoring','blank');$version=$form->versions()->first();$section=$version->sections()->first();
        $question=$authoring->addComponent($version,$section,['type'=>'multiple_choice','label'=>'Any response counts','is_required'=>false,'max_points'=>5,'options'=>['One','Two','Three'],'scoring_strategy'=>'all_answers_correct','scoring_rules'=>[]]);
        $published=$authoring->publish($version);$publication=$this->publication($form,$published,['access_mode'=>'public','identified_required'=>false,'anonymous_allowed'=>true]);$service=app(SubmissionService::class);$submission=$service->start($publication,null,null,null,'browser-any-answer');
        $service->autosave($submission,0,(string)Str::uuid(),[$question->id=>[$question->options()->first()->value]]);$final=$service->finalize($submission->fresh());
        $this->assertEquals(5.0,(float)$final->automatic_points);$this->assertEquals(5.0,(float)$final->final_points);
    }

    public function test_repeated_finalization_is_idempotent_and_does_not_duplicate_answers(): void
    {
        [$creator,$organisation]=$this->member('form_creator');[$respondent]=$this->member('respondent',$organisation);$authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Exam','test');$published=$authoring->publish($form->versions()->first());$publication=$this->publication($form,$published,['access_mode'=>'authenticated']);$service=app(SubmissionService::class);$submission=$service->start($publication,$respondent,null,null,'unused');$component=$published->components()->with('options')->first();$service->autosave($submission,0,(string)Str::uuid(),[$component->id=>$component->options->first()->value]);$first=$service->finalize($submission->fresh());$second=$service->finalize($first);$this->assertSame($first->status,$second->status);$this->assertSame(1,SubmissionAnswer::count());$this->assertSame(1,\App\Models\AnswerScore::count());
    }

    public function test_exports_are_authorized_formula_safe_and_xlsx_has_expected_sheets(): void
    {
        [$creator,$organisation]=$this->member('organisation_admin');$authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'=FORMULA','blank');$published=$authoring->publish($form->versions()->first());$publication=$this->publication($form,$published,['access_mode'=>'public','identified_required'=>false,'anonymous_allowed'=>true]);$submission=app(SubmissionService::class)->start($publication,null,null,null,'export-browser');app(SubmissionService::class)->finalize($submission);
        foreach(['csv','xlsx'] as $format){$export=Export::create(['public_id'=>(string)Str::uuid(),'organisation_id'=>$organisation->id,'requested_by'=>$creator->id,'form_id'=>$form->id,'format'=>$format,'status'=>'pending']);app(ExportService::class)->generate($export);$export->refresh();$this->assertSame('completed',$export->status);$path=storage_path('app/private/'.$export->storage_path);$this->assertFileExists($path);if($format==='csv')$this->assertStringContainsString("'=FORMULA",file_get_contents($path));else{$zip=new \ZipArchive();$this->assertTrue($zip->open($path)===true);$workbook=$zip->getFromName('xl/workbook.xml');$this->assertStringContainsString('Submissions',$workbook);$this->assertStringContainsString('Answers',$workbook);$zip->close();}}
    }

    public function test_private_attachments_require_form_access_or_the_owning_submission(): void
    {
        Storage::fake('local');
        [$creator,$organisation]=$this->member('form_creator');
        $authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Files','blank');$published=$authoring->publish($form->versions()->first());
        Storage::disk('local')->put('attachments/demo.png','image-bytes');
        $attachment=Attachment::create(['organisation_id'=>$organisation->id,'attachable_type'=>$published->getMorphClass(),'attachable_id'=>$published->id,'uploaded_by'=>$creator->id,'disk'=>'local','storage_path'=>'attachments/demo.png','original_name'=>'demo.png','mime_type'=>'image/png','size'=>11,'sha256'=>hash('sha256','image-bytes'),'status'=>'ready']);
        $publication=$this->publication($form,$published,['access_mode'=>'public','identified_required'=>false,'anonymous_allowed'=>true]);$submission=app(SubmissionService::class)->start($publication,null,null,null,'browser-owner');
        $this->withSession(['respondent_key'=>'browser-owner'])->get(route('submissions.attachments.download',[$submission,$attachment]))->assertOk()->assertHeader('X-Content-Type-Options','nosniff');
        $this->withSession(['respondent_key'=>'someone-else'])->get(route('submissions.attachments.download',[$submission,$attachment]))->assertForbidden();
        $this->actingAs($creator)->get(route('attachments.download',$attachment))->assertOk();
        [$outsider]=$this->member('form_creator');$this->actingAs($outsider)->get(route('attachments.download',$attachment))->assertForbidden();
    }

    public function test_reviewer_can_read_but_cannot_autosave_or_finalize_another_respondent_submission(): void
    {
        [$creator,$organisation]=$this->member('form_creator');[$respondent]=$this->member('respondent',$organisation);[$reviewer]=$this->member('reviewer',$organisation);
        $authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Owned exam','test');$published=$authoring->publish($form->versions()->first());$publication=$this->publication($form,$published);$submission=app(SubmissionService::class)->start($publication,$respondent,null,null,'unused');$component=$published->components()->with('options')->first();$payload=['expected_revision'=>0,'client_mutation_id'=>(string)Str::uuid(),'answers'=>[$component->id=>$component->options->first()->value]];
        $this->actingAs($reviewer)->postJson(route('submissions.autosave',$submission),$payload)->assertForbidden();
        $payload['client_mutation_id']=(string)Str::uuid();$this->actingAs($reviewer)->postJson(route('submissions.finalize',$submission),$payload)->assertForbidden();
        $this->actingAs($reviewer)->get(route('admin.submissions.show',$submission))->assertOk();
        $this->assertSame('in_progress',$submission->fresh()->status);$this->assertSame(0,$submission->fresh()->revision);
    }

    public function test_finalization_persists_latest_snapshot_with_autosave_enabled_or_disabled(): void
    {
        foreach ([true,false] as $autosaveEnabled) {
            [$creator,$organisation]=$this->member('form_creator');[$respondent]=$this->member('respondent',$organisation);$authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Snapshot '.(int)$autosaveEnabled,'blank');$version=$form->versions()->first();$component=$authoring->addComponent($version,$version->sections()->first(),['type'=>'short_text','label'=>'Required answer','is_required'=>true,'options'=>[]]);$published=$authoring->publish($version);$publication=$this->publication($form,$published,['autosave_enabled'=>$autosaveEnabled]);$submission=app(SubmissionService::class)->start($publication,$respondent,null,null,'unused');$revision=0;
            if($autosaveEnabled){$saved=$this->actingAs($respondent)->postJson(route('submissions.autosave',$submission),['expected_revision'=>0,'client_mutation_id'=>(string)Str::uuid(),'answers'=>[$component->id=>'draft value']])->assertOk()->json();$revision=$saved['revision'];}
            $this->actingAs($respondent)->postJson(route('submissions.finalize',$submission),['expected_revision'=>$revision,'client_mutation_id'=>(string)Str::uuid(),'answers'=>[$component->id=>'latest browser value']])->assertOk();
            $this->assertSame('submitted',$submission->fresh()->status);$this->assertSame('latest browser value',$submission->answers()->where('form_component_id',$component->id)->value('value'));
        }
    }

    public function test_multisection_autosave_allows_later_required_fields_to_remain_empty(): void
    {
        [$creator,$organisation]=$this->member('form_creator');[$respondent]=$this->member('respondent',$organisation);$authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Pages','blank');$version=$form->versions()->first();$first=$version->sections()->first();$second=$authoring->addSection($version,'Later');$early=$authoring->addComponent($version,$first,['type'=>'short_text','label'=>'Early','is_required'=>true,'options'=>[]]);$late=$authoring->addComponent($version,$second,['type'=>'short_text','label'=>'Late','is_required'=>true,'options'=>[]]);$published=$authoring->publish($version);$publication=$this->publication($form,$published);$submission=app(SubmissionService::class)->start($publication,$respondent,null,null,'unused');$service=app(SubmissionService::class);
        $saved=$service->autosave($submission,0,(string)Str::uuid(),[$early->id=>'saved first page']);$this->assertSame(1,$saved['revision']);$this->assertDatabaseMissing('submission_answers',['form_submission_id'=>$submission->id,'form_component_id'=>$late->id]);
        try{$service->finalize($submission->fresh());$this->fail('Expected later required answer error');}catch(ValidationException $e){$this->assertArrayHasKey('answers.'.$late->id,$e->errors());}
        $this->assertSame('in_progress',$submission->fresh()->status);
    }

    public function test_cancelled_attempt_uses_next_maximum_attempt_number(): void
    {
        [$creator,$organisation]=$this->member('form_creator');[$respondent]=$this->member('respondent',$organisation);$authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Attempts','blank');$published=$authoring->publish($form->versions()->first());$publication=$this->publication($form,$published,['attempt_limit'=>1,'resume_enabled'=>false]);$service=app(SubmissionService::class);$first=$service->start($publication,$respondent,null,null,'unused');$first->update(['status'=>'cancelled']);$second=$service->start($publication,$respondent,null,null,'unused');
        $this->assertSame(1,$first->attempt_number);$this->assertSame(2,$second->attempt_number);$this->assertSame('in_progress',$second->status);
    }

    public function test_conditional_visibility_resets_and_hidden_answers_are_ignored_for_validation_and_scoring(): void
    {
        [$creator,$organisation]=$this->member('form_creator');[$respondent]=$this->member('respondent',$organisation);$authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Conditions','blank');$version=$form->versions()->first();$first=$version->sections()->first();$shownSection=$authoring->addSection($version,'Shown section');$hiddenSection=$authoring->addSection($version,'Hidden section');$source=$authoring->addComponent($version,$first,['type'=>'yes_no','label'=>'Source','options'=>[]]);$target=$authoring->addComponent($version,$first,['type'=>'single_choice','label'=>'Conditional score','is_required'=>true,'max_points'=>2,'options'=>['Right','Wrong'],'scoring_strategy'=>'single_choice','scoring_rules'=>[]]);$correct=$target->options()->first()->value;$target->scoringRule()->update(['rules'=>['correct'=>$correct]]);$hideTarget=$authoring->addComponent($version,$first,['type'=>'short_text','label'=>'Hide target','options'=>[]]);
        $rule=$version->conditionalRules()->create(['source_component_id'=>$source->id,'operator'=>'equals','comparison_value'=>['value'=>'1'],'priority'=>1]);$rule->actions()->createMany([['action'=>'show_component','target_component_id'=>$target->id],['action'=>'hide_component','target_component_id'=>$hideTarget->id],['action'=>'show_section','target_section_id'=>$shownSection->id],['action'=>'hide_section','target_section_id'=>$hiddenSection->id]]);
        $logic=app(ConditionalLogicService::class);$falseState=$logic->visibility($version->fresh(),[$source->id=>false]);$this->assertFalse($falseState['components'][$target->id]);$this->assertTrue($falseState['components'][$hideTarget->id]);$this->assertFalse($falseState['sections'][$shownSection->id]);$this->assertTrue($falseState['sections'][$hiddenSection->id]);$trueState=$logic->visibility($version->fresh(),[$source->id=>true]);$this->assertTrue($trueState['components'][$target->id]);$this->assertFalse($trueState['components'][$hideTarget->id]);$this->assertTrue($trueState['sections'][$shownSection->id]);$this->assertFalse($trueState['sections'][$hiddenSection->id]);
        $published=$authoring->publish($version);$publication=$this->publication($form,$published);$service=app(SubmissionService::class);$submission=$service->start($publication,$respondent,null,null,'unused');$service->autosave($submission,0,(string)Str::uuid(),[$source->id=>true,$target->id=>$correct]);$service->autosave($submission->fresh(),1,(string)Str::uuid(),[$source->id=>false]);$final=$service->finalize($submission->fresh());$this->assertSame('submitted',$final->status);$this->assertEquals(0.0,(float)$final->maximum_points);$this->assertDatabaseHas('submission_answers',['form_submission_id'=>$submission->id,'form_component_id'=>$target->id]);
    }

    public function test_choice_option_label_edit_preserves_stable_correct_value_and_invalid_rules_block_publish(): void
    {
        [$creator,$organisation]=$this->member('form_creator');[$respondent]=$this->member('respondent',$organisation);$authoring=app(FormAuthoringService::class);$builder=app(BuilderService::class);$form=$authoring->create($organisation->id,$creator,'Stable options','blank');$version=$form->versions()->first();$component=$authoring->addComponent($version,$version->sections()->first(),['type'=>'single_choice','label'=>'Choice','max_points'=>1,'options'=>['Original A','Original B'],'scoring_strategy'=>'single_choice','scoring_rules'=>[]]);$options=$component->options()->orderBy('display_order')->get();$correct=$options[0]->value;$component->scoringRule()->update(['rules'=>['correct'=>$correct]]);$builder->updateComponent($component->load('scoringRule'),['label'=>'Choice','visible'=>true,'max_points'=>1,'options'=>['existing'=>[$options[0]->id=>'Renamed A',$options[1]->id=>'Renamed B'],'new'=>[]],'scoring_strategy'=>'single_choice','scoring_rules'=>['correct'=>$correct]]);
        $this->assertSame($correct,$component->options()->where('label','Renamed A')->value('value'));$published=$authoring->publish($version);$publication=$this->publication($form,$published);$submission=app(SubmissionService::class)->start($publication,$respondent,null,null,'unused');app(SubmissionService::class)->autosave($submission,0,(string)Str::uuid(),[$component->id=>$correct]);$this->assertEquals(1.0,(float)app(SubmissionService::class)->finalize($submission->fresh())->final_points);
        $invalid=$authoring->create($organisation->id,$creator,'Invalid scoring','blank');$invalidVersion=$invalid->versions()->first();$bad=$authoring->addComponent($invalidVersion,$invalidVersion->sections()->first(),['type'=>'single_choice','label'=>'Bad','options'=>['A','B'],'scoring_strategy'=>'single_choice','scoring_rules'=>[]]);$bad->scoringRule()->update(['rules'=>['correct'=>'missing-option']]);try{$authoring->publish($invalidVersion);$this->fail('Expected invalid scoring rule');}catch(ValidationException $e){$this->assertArrayHasKey('scoring_rules',$e->errors());}
    }

    public function test_attachments_are_cloned_for_new_versions_and_form_duplicates(): void
    {
        Storage::fake('local');[$creator,$organisation]=$this->member('form_creator');$authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Version assets','blank');$version=$form->versions()->first();Storage::disk('local')->put('attachments/source.png','image-bytes');$attachment=Attachment::create(['organisation_id'=>$organisation->id,'attachable_type'=>$version->getMorphClass(),'attachable_id'=>$version->id,'uploaded_by'=>$creator->id,'disk'=>'local','storage_path'=>'attachments/source.png','original_name'=>'source.png','mime_type'=>'image/png','size'=>11,'sha256'=>hash('sha256','image-bytes'),'status'=>'ready']);$image=$authoring->addComponent($version,$version->sections()->first(),['type'=>'image','label'=>'Image','settings'=>['attachment_id'=>$attachment->id],'options'=>[]]);$published=$authoring->publish($version);$draft=$authoring->createDraftFrom($published,$creator);$clonedAttachment=$draft->attachments()->firstOrFail();$this->assertNotSame($attachment->id,$clonedAttachment->id);$this->assertNotSame($attachment->storage_path,$clonedAttachment->storage_path);Storage::disk('local')->assertExists($clonedAttachment->storage_path);$this->assertSame($clonedAttachment->id,(int)$draft->components()->where('stable_key',$image->stable_key)->firstOrFail()->settings['attachment_id']);
        $republished=$authoring->publish($draft);$publication=$this->publication($form,$republished,['access_mode'=>'public','identified_required'=>false,'anonymous_allowed'=>true]);$submission=app(SubmissionService::class)->start($publication,null,null,null,'asset-browser');$this->withSession(['respondent_key'=>'asset-browser'])->get(route('submissions.attachments.download',[$submission,$clonedAttachment]))->assertOk();$duplicate=$authoring->duplicate($form,$creator);$duplicateVersion=$duplicate->versions()->first();$this->assertSame(1,$duplicateVersion->attachments()->count());Storage::disk('local')->assertExists($duplicateVersion->attachments()->first()->storage_path);
    }

    public function test_duplicate_requires_forms_create_permission(): void
    {
        [$creator,$organisation]=$this->member('form_creator');[$reviewer]=$this->member('reviewer',$organisation);$form=app(FormAuthoringService::class)->create($organisation->id,$creator,'Duplicate source','blank');$this->actingAs($reviewer)->post(route('forms.duplicate',$form))->assertForbidden();$this->actingAs($creator)->post(route('forms.duplicate',$form))->assertRedirect();$this->assertSame(2,Form::where('organisation_id',$organisation->id)->count());
    }

    public function test_organisation_user_page_does_not_expose_platform_directory_or_other_tenant(): void
    {
        [$admin,$organisation]=$this->member('organisation_admin');[$otherAdmin,$otherOrganisation]=$this->member('organisation_admin');$outsider=User::factory()->create(['name'=>'Private Outsider','email'=>'private-outsider@example.test','is_active'=>true]);$otherMembership=OrganisationMembership::create(['organisation_id'=>$otherOrganisation->id,'user_id'=>$outsider->id,'is_active'=>true]);$otherMembership->roles()->attach(Role::where('name','respondent')->firstOrFail());
        $this->actingAs($admin)->get(route('users.index',$organisation))->assertOk()->assertDontSee('Private Outsider')->assertDontSee('private-outsider@example.test');$this->actingAs($admin)->get(route('users.index',$otherOrganisation))->assertForbidden();$this->actingAs($otherAdmin)->get(route('users.index',$otherOrganisation))->assertOk()->assertSee('Private Outsider');
    }

    public function test_publication_configuration_validation_and_archived_activation_guards(): void
    {
        [$creator,$organisation]=$this->member('form_creator');$authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Publication rules','blank');$published=$authoring->publish($form->versions()->first());$base=['form_version_id'=>$published->id,'name'=>'Configured','access_mode'=>'authenticated','attempt_limit'=>1,'result_visibility'=>'completion','status'=>'active'];
        $this->actingAs($creator)->post(route('publications.store',$form),array_merge($base,['access_mode'=>'access_code']))->assertSessionHasErrors('access_code');$this->actingAs($creator)->post(route('publications.store',$form),array_merge($base,['timer_enabled'=>1]))->assertSessionHasErrors('duration_minutes');$this->actingAs($creator)->post(route('publications.store',$form),array_merge($base,['anonymous_allowed'=>1,'identified_required'=>1]))->assertSessionHasErrors('anonymous_allowed');
        $inactive=$this->publication($form,$published,['status'=>'inactive']);$authoring->archive($form);$this->actingAs($creator)->post(route('publications.toggle',[$form,$inactive]))->assertStatus(422);$this->actingAs($creator)->post(route('publications.store',$form),$base)->assertSessionHasErrors('status');
    }

    public function test_localized_form_version_content_is_created_cloned_duplicated_and_immutable_when_published(): void
    {
        [$creator,$organisation]=$this->member('form_creator');$authoring=app(FormAuthoringService::class);$builder=app(BuilderService::class);$form=$authoring->create($organisation->id,$creator,'Internal form name','blank');$version=$form->versions()->firstOrFail();
        $this->assertSame('Internal form name',$version->title);$this->assertSame('Internal form name',data_get($version->translations,'lv.title'));
        $translations=['lv'=>['title'=>'LV virsraksts','description'=>'LV apraksts','completion_text'=>'LV pabeigts','result_text'=>'LV rezultāts'],'en'=>['title'=>'English title','description'=>'English description','completion_text'=>'English complete','result_text'=>'English result'],'ru'=>['title'=>'Русский заголовок','description'=>'Русское описание','completion_text'=>'Завершено','result_text'=>'Результат']];$builder->updateVersion($version,['translations'=>$translations]);
        $section=$version->sections()->firstOrFail();$builder->updateSection($section,['visible'=>true,'translations'=>['lv'=>['title'=>'LV sadaļa'],'en'=>['title'=>'EN section'],'ru'=>['title'=>'RU section']]]);
        $component=$authoring->addComponent($version,$section,['type'=>'single_choice','translations'=>['lv'=>['label'=>'LV jautājums'],'en'=>['label'=>'EN question'],'ru'=>['label'=>'RU question']],'options'=>[['translations'=>['lv'=>['label'=>'LV variants'],'en'=>['label'=>'EN option'],'ru'=>['label'=>'RU option']]],['translations'=>['lv'=>['label'=>'LV otrais'],'en'=>['label'=>'EN second'],'ru'=>['label'=>'RU second']]]]]);
        $published=$authoring->publish($version);$draft=$authoring->createDraftFrom($published,$creator);$duplicate=$authoring->duplicate($form,$creator);$duplicateVersion=$duplicate->versions()->firstOrFail();
        $this->assertSame($translations,$draft->translations);$this->assertSame($translations,$duplicateVersion->translations);$this->assertSame('RU section',$draft->sections()->firstOrFail()->localizedTitle('ru'));$this->assertSame('RU question',$duplicateVersion->components()->firstOrFail()->localizedLabel('ru'));$this->assertSame('RU option',$duplicateVersion->components()->firstOrFail()->options()->firstOrFail()->localizedLabel('ru'));
        $immutableModels=[$published,$published->sections()->firstOrFail(),$published->components()->firstOrFail(),$published->components()->firstOrFail()->options()->firstOrFail()];$blocked=0;foreach($immutableModels as $model){try{$model->update(['translations'=>['lv'=>['title'=>'Tampered','label'=>'Tampered']]]);}catch(LogicException){$blocked++;}}$this->assertSame(4,$blocked);
    }

    public function test_builder_saves_supported_locales_and_option_translation_edits_preserve_value_and_scoring(): void
    {
        [$creator,$organisation]=$this->member('form_creator');$authoring=app(FormAuthoringService::class);$form=$authoring->create($organisation->id,$creator,'Builder locales','blank');$version=$form->versions()->firstOrFail();$section=$version->sections()->firstOrFail();
        $this->actingAs($creator)->get(route('forms.builder',$form))->assertOk()->assertSee('data-locale-tab="lv"',false)->assertSee('data-locale-tab="en"',false)->assertSee('data-locale-tab="ru"',false)->assertSee(__('messages.translation_lv_fallback'));
        $this->actingAs($creator)->put(route('builder.versions.update',[$form,$version]),['translations'=>['lv'=>['title'=>'LV forma','description'=>'LV apraksts'],'en'=>['title'=>'EN form','description'=>'EN description'],'ru'=>['title'=>'RU form','description'=>'RU description']]])->assertRedirect();
        $this->actingAs($creator)->put(route('builder.sections.update',[$form,$section]),['visible'=>1,'translations'=>['lv'=>['title'=>'LV sadaļa','description'=>'LV sadaļas apraksts'],'en'=>['title'=>'EN section','description'=>'EN section description'],'ru'=>['title'=>'RU section','description'=>'RU section description']]])->assertRedirect();
        $component=$authoring->addComponent($version->fresh(),$section->fresh(),['type'=>'dropdown','max_points'=>1,'translations'=>['lv'=>['label'=>'LV jautājums','description'=>'LV apraksts','help_text'=>'LV palīdzība','placeholder'=>'LV vietturis'],'en'=>['label'=>'EN question','description'=>'EN description','help_text'=>'EN help','placeholder'=>'EN placeholder'],'ru'=>['label'=>'RU question','description'=>'RU description','help_text'=>'RU help','placeholder'=>'RU placeholder']],'options'=>[['translations'=>['lv'=>['label'=>'Pirmais'],'en'=>['label'=>'First'],'ru'=>['label'=>'Первый']]],['translations'=>['lv'=>['label'=>'Otrais'],'en'=>['label'=>'Second'],'ru'=>['label'=>'Второй']]]],'scoring_strategy'=>'single_choice','scoring_rules'=>[]]);$options=$component->options()->orderBy('display_order')->get();$correct=$options[0]->value;$stableKey=$options[0]->stable_key;$component->scoringRule()->update(['rules'=>['correct'=>$correct]]);
        $payload=['visible'=>1,'max_points'=>1,'translations'=>['lv'=>['label'=>'LV mainīts','description'=>'LV mainīts apraksts','help_text'=>'LV mainīta palīdzība','placeholder'=>'LV mainīts vietturis'],'en'=>['label'=>'EN changed','description'=>'EN changed description','help_text'=>'EN changed help','placeholder'=>'EN changed placeholder'],'ru'=>['label'=>'RU changed','description'=>'RU changed description','help_text'=>'RU changed help','placeholder'=>'RU changed placeholder']],'options'=>['existing'=>[$options[0]->id=>['translations'=>['lv'=>['label'=>'Pirmais mainīts'],'en'=>['label'=>'First changed'],'ru'=>['label'=>'Первый изменён']]],$options[1]->id=>['translations'=>['lv'=>['label'=>'Otrais'],'en'=>['label'=>'Second'],'ru'=>['label'=>'Второй']]]],'new'=>[]],'scoring_strategy'=>'single_choice','scoring_rules'=>['correct'=>$correct]];
        $this->actingAs($creator)->put(route('builder.components.update',[$form,$component]),$payload)->assertRedirect();$component->refresh();$first=$component->options()->findOrFail($options[0]->id);
        $this->assertSame('LV mainīts',$component->label);$this->assertSame('LV mainīts apraksts',$component->description);$this->assertSame('LV mainīta palīdzība',$component->help_text);$this->assertSame('LV mainīts vietturis',$component->settings['placeholder']);$this->assertSame('RU changed',$component->localizedLabel('ru'));$this->assertSame('Первый изменён',$first->localizedLabel('ru'));$this->assertSame($correct,$first->value);$this->assertSame($stableKey,$first->stable_key);$this->assertSame($correct,$component->scoringRule()->firstOrFail()->rules['correct']);
        $this->actingAs($creator)->from(route('forms.builder',$form))->put(route('builder.versions.update',[$form,$version]),['translations'=>['lv'=>['title'=>'Valid LV'],'de'=>['title'=>'Nicht erlaubt']]])->assertSessionHasErrors('translations');$this->assertSame('LV forma',$version->fresh()->title);
    }

    public function test_localized_preview_uses_requested_language_and_fallback_without_creating_execution_state(): void
    {
        [$creator,$organisation]=$this->member('form_creator');$authoring=app(FormAuthoringService::class);$builder=app(BuilderService::class);$form=$authoring->create($organisation->id,$creator,'Preview internal','blank');$version=$form->versions()->firstOrFail();$builder->updateVersion($version,['translations'=>['lv'=>['title'=>'LV preview title','description'=>'LV preview description','completion_text'=>'LV completion fallback'],'ru'=>['title'=>'RU preview title','description'=>'RU preview description']]]);$section=$version->sections()->firstOrFail();$builder->updateSection($section,['visible'=>true,'translations'=>['lv'=>['title'=>'LV preview section','description'=>'LV section fallback'],'ru'=>['title'=>'RU preview section']]]);$component=$authoring->addComponent($version,$section,['type'=>'single_choice','translations'=>['lv'=>['label'=>'LV preview question'],'ru'=>['label'=>'RU preview question']],'options'=>[['translations'=>['lv'=>['label'=>'LV first'],'ru'=>['label'=>'RU first']]],['translations'=>['lv'=>['label'=>'LV fallback option']]]]]);$yesNo=$authoring->addComponent($version,$section,['type'=>'yes_no','translations'=>['lv'=>['label'=>'LV yes/no'],'ru'=>['label'=>'RU yes/no']],'options'=>[]]);$fallbackText=$authoring->addComponent($version,$section,['type'=>'short_text','translations'=>['lv'=>['label'=>'LV field','description'=>'LV fallback description','help_text'=>'LV fallback help','placeholder'=>'LV fallback placeholder'],'ru'=>['label'=>'RU field']],'options'=>[]]);
        $before=json_encode([$version->fresh()->toArray(),$section->fresh()->toArray(),$component->fresh()->toArray(),$component->options()->orderBy('id')->get()->toArray(),$yesNo->fresh()->toArray(),$fallbackText->fresh()->toArray()]);$response=$this->actingAs($creator)->withSession(['locale'=>'en'])->get(route('forms.preview',$form).'?locale=ru');$response->assertOk()->assertSee('RU preview title')->assertSee('RU preview section')->assertSee('RU preview question')->assertSee('RU first')->assertSee('LV fallback option')->assertSee('LV fallback used')->assertSee(__('messages.yes',[],'ru'))->assertSee(__('messages.no',[],'ru'))->assertDontSee('> Yes',false)->assertDontSee('> No',false)->assertDontSee('LV preview question');$this->assertSame('en',session('locale'));$this->assertTrue($version->usesContentFallback('ru'));$this->assertTrue($section->usesContentFallback('ru'));$this->assertTrue($fallbackText->usesContentFallback('ru'));
        $after=json_encode([$version->fresh()->toArray(),$section->fresh()->toArray(),$component->fresh()->toArray(),$component->options()->orderBy('id')->get()->toArray(),$yesNo->fresh()->toArray(),$fallbackText->fresh()->toArray()]);$this->assertSame($before,$after);$this->assertSame(0,FormSubmission::count());$this->assertSame(0,\DB::table('attempt_grants')->count());
    }

    public function test_image_preview_ignores_the_non_rendered_generic_label_when_detecting_fallback(): void
    {
        [$creator,$organisation]=$this->member('form_creator');
        $authoring=app(FormAuthoringService::class);
        $builder=app(BuilderService::class);
        $form=$authoring->create($organisation->id,$creator,'Image fallback preview','blank');
        $version=$form->versions()->firstOrFail();
        $builder->updateVersion($version,['translations'=>['lv'=>['title'=>'LV image preview'],'ru'=>['title'=>'RU image preview']]]);
        $section=$version->sections()->firstOrFail();
        $builder->updateSection($section,['visible'=>true,'translations'=>['lv'=>['title'=>'LV image section'],'ru'=>['title'=>'RU image section']]]);
        $image=$authoring->addComponent($version,$section,[
            'type'=>'image',
            'translations'=>[
                'lv'=>['label'=>'Nerenderēta iekšējā etiķete'],
                'ru'=>['image_title'=>'RU rendered image title'],
            ],
            'options'=>[],
        ]);

        $this->assertFalse($image->usesContentFallback('ru'));
        $this->actingAs($creator)
            ->withSession(['locale'=>'en'])
            ->get(route('forms.preview',$form).'?locale=ru')
            ->assertOk()
            ->assertSee('RU rendered image title')
            ->assertDontSee(__('messages.fallback_used',[],'en'));
    }

    public function test_respondent_locale_change_keeps_submission_revision_deadline_answers_and_scoring_references(): void
    {
        [$creator,$organisation]=$this->member('form_creator');[$respondent]=$this->member('respondent',$organisation);$authoring=app(FormAuthoringService::class);$builder=app(BuilderService::class);$form=$authoring->create($organisation->id,$creator,'Respondent internal','blank');$version=$form->versions()->firstOrFail();$builder->updateVersion($version,['translations'=>['lv'=>['title'=>'LV respondent title'],'ru'=>['title'=>'RU respondent title']]]);$section=$version->sections()->firstOrFail();$builder->updateSection($section,['visible'=>true,'translations'=>['lv'=>['title'=>'LV respondent section'],'ru'=>['title'=>'RU respondent section']]]);$component=$authoring->addComponent($version,$section,['type'=>'single_choice','is_required'=>true,'max_points'=>1,'translations'=>['lv'=>['label'=>'LV respondent question'],'ru'=>['label'=>'RU respondent question']],'options'=>[['translations'=>['lv'=>['label'=>'LV answer'],'ru'=>['label'=>'RU answer']]],['translations'=>['lv'=>['label'=>'LV option fallback']]]],'scoring_strategy'=>'single_choice','scoring_rules'=>[]]);$correct=$component->options()->first()->value;$component->scoringRule()->update(['rules'=>['correct'=>$correct]]);$published=$authoring->publish($version);$publication=$this->publication($form,$published,['timer_enabled'=>true,'duration_minutes'=>30,'autosave_enabled'=>false]);$submission=app(SubmissionService::class)->start($publication,$respondent,null,null,'unused');app(SubmissionService::class)->autosave($submission,0,(string)Str::uuid(),[$component->id=>$correct]);$submission->refresh();$deadline=$submission->deadline_at->copy();$revision=$submission->revision;$answer=$submission->answers()->firstOrFail()->value;$scoring=$component->scoringRule()->firstOrFail()->rules;
        $take=$this->actingAs($respondent)->withSession(['locale'=>'ru'])->get(route('submissions.take',$submission));$take->assertOk()->assertSee('RU respondent title')->assertSee('RU respondent section')->assertSee('RU respondent question')->assertSee('RU answer')->assertSee('LV option fallback')->assertSee('data-locale-form',false)->assertSee('data-status-saving="'.__('messages.saving',[],'ru').'"',false)->assertSee('data-status-saved="'.__('messages.saved_state',[],'ru').'"',false)->assertSee('data-status-offline="'.__('messages.offline',[],'ru').'"',false)->assertSee('data-status-save-error="'.__('messages.save_error',[],'ru').'"',false)->assertSee($submission->public_id);
        $this->actingAs($respondent)->withSession(['locale'=>'ru'])->from(route('submissions.take',$submission))->post(route('locale','en'))->assertRedirect(route('submissions.take',$submission));$submission->refresh();
        $this->assertSame(1,FormSubmission::count());$this->assertSame($revision,$submission->revision);$this->assertTrue($deadline->equalTo($submission->deadline_at));$this->assertSame($answer,$submission->answers()->firstOrFail()->value);$this->assertSame($scoring,$component->scoringRule()->firstOrFail()->rules);$javascript=file_get_contents(resource_path('js/app.js'));$this->assertStringContainsString('saveNow(true)',$javascript);$this->assertStringNotContainsString('document.documentElement.lang',$javascript);
    }

    public function test_form_version_localization_migration_backfills_legacy_data_without_changing_sources_or_legacy_tables(): void
    {
        [$creator,$organisation]=$this->member('form_creator');$form=app(FormAuthoringService::class)->create($organisation->id,$creator,'Internal legacy name','blank');$version=$form->versions()->firstOrFail();$legacyTranslations=['lv'=>['name'=>'Latviešu virsraksts','description'=>'LV apraksts','completion_text'=>'LV pabeigts','result_text'=>'LV rezultāts'],'en'=>['name'=>'English title','description'=>'English description','completion_text'=>'English complete','result_text'=>'English result'],'ru'=>['name'=>'Русский заголовок','description'=>'Русское описание','completion_text'=>'Завершено','result_text'=>'Результат']];$form->update(['translations'=>$legacyTranslations]);\DB::table('tests')->insert(['title'=>'Preserved legacy test','description'=>'Must remain','duration_minutes'=>25,'is_active'=>true,'created_at'=>now(),'updated_at'=>now()]);$sourceName=$form->fresh()->name;$sourceTranslations=\DB::table('forms')->where('id',$form->id)->value('translations');$legacyCount=\DB::table('tests')->count();$migration=require database_path('migrations/2026_08_02_000100_add_localized_content_to_form_versions.php');
        $migration->down();$this->assertFalse(Schema::hasColumn('form_versions','title'));$migration->up();$row=\DB::table('form_versions')->where('id',$version->id)->first();$backfilled=json_decode($row->translations,true);
        $this->assertSame($sourceName,$row->title);$this->assertSame('Latviešu virsraksts',data_get($backfilled,'lv.title'));$this->assertSame('English title',data_get($backfilled,'en.title'));$this->assertSame('Русский заголовок',data_get($backfilled,'ru.title'));foreach(['lv','en','ru'] as $locale){foreach(['description','completion_text','result_text'] as $field)$this->assertSame(data_get($legacyTranslations,$locale.'.'.$field),data_get($backfilled,$locale.'.'.$field));}$this->assertSame($sourceName,\DB::table('forms')->where('id',$form->id)->value('name'));$this->assertSame($sourceTranslations,\DB::table('forms')->where('id',$form->id)->value('translations'));$this->assertSame($legacyCount,\DB::table('tests')->count());$this->assertDatabaseHas('tests',['title'=>'Preserved legacy test']);
    }

    public function test_legacy_tables_remain_and_broken_legacy_routes_are_retired(): void
    {
        foreach(['tests','questions','options','submissions','answers'] as $table)$this->assertTrue(Schema::hasTable($table));
        $this->assertTrue(Schema::hasTable('form_submissions'));$this->assertFalse(collect(app('router')->getRoutes())->contains(fn($route)=>in_array($route->getName(),['test.submit','test.auto-submit','admin.submissions.export'],true)));
    }

    private function member(string $roleName, ?Organisation $organisation=null): array
    {
        $organisation??=Organisation::create(['name'=>Str::random(8),'slug'=>Str::lower(Str::random(10)),'is_active'=>true]);$user=User::factory()->create(['student_id'=>Str::uuid()->toString(),'is_active'=>true]);$membership=OrganisationMembership::create(['organisation_id'=>$organisation->id,'user_id'=>$user->id,'is_active'=>true]);$membership->roles()->attach(Role::where('name',$roleName)->firstOrFail());return [$user,$organisation];
    }

    private function bootstrapAdmin(string $name, string $email)
    {
        return $this->artisan('app:create-admin',['--name'=>$name,'--email'=>$email])
            ->expectsQuestion('Password (minimum 12 characters)','BootstrapPassword123')
            ->expectsQuestion('Confirm password','BootstrapPassword123');
    }

    private function publication(Form $form,$version,array $overrides=[]): Publication
    {
        return Publication::create(array_merge(['organisation_id'=>$form->organisation_id,'form_id'=>$form->id,'form_version_id'=>$version->id,'public_key'=>Str::lower(Str::random(20)),'name'=>'Publication','status'=>'active','access_mode'=>'authenticated','attempt_limit'=>1,'timer_enabled'=>false,'result_visibility'=>'completion','correct_answers_visible'=>false,'anonymous_allowed'=>false,'identified_required'=>true,'consent_required'=>false,'autosave_enabled'=>true,'resume_enabled'=>true],$overrides));
    }
}
