<?php

namespace Database\Seeders;

use App\Domain\Forms\FormAuthoringService;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\Publication;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $organisation=Organisation::firstOrCreate(['slug'=>'demo-organisation'],['name'=>'Demonstrācijas organizācija','is_active'=>true]);
        $creator=User::firstOrCreate(['email'=>'demo.creator@example.test'],['name'=>'Demo Creator','student_id'=>'DEMO-CREATOR','password'=>Hash::make(Str::random(48)),'is_active'=>true,'locale'=>'lv']);
        $membership=OrganisationMembership::firstOrCreate(['organisation_id'=>$organisation->id,'user_id'=>$creator->id],['is_active'=>true]);
        $membership->roles()->syncWithoutDetaching([Role::where('name','form_creator')->value('id')]);
        if($organisation->forms()->exists())return;
        $service=app(FormAuthoringService::class);
        $test=$service->create($organisation->id,$creator,'Demonstrācijas tests','test');$testVersion=$service->publish($test->versions()->first());
        Publication::create(['organisation_id'=>$organisation->id,'form_id'=>$test->id,'form_version_id'=>$testVersion->id,'public_key'=>Str::lower(Str::random(20)),'name'=>'Demo test publication','status'=>'active','access_mode'=>'authenticated','attempt_limit'=>1,'timer_enabled'=>true,'duration_minutes'=>30,'result_visibility'=>'score','identified_required'=>true,'autosave_enabled'=>true,'resume_enabled'=>true]);
        $patient=$service->create($organisation->id,$creator,'Demonstrācijas pacienta anketa','patient_questionnaire');$patientVersion=$service->publish($patient->versions()->first());
        Publication::create(['organisation_id'=>$organisation->id,'form_id'=>$patient->id,'form_version_id'=>$patientVersion->id,'public_key'=>Str::lower(Str::random(20)),'name'=>'Demo questionnaire publication','status'=>'active','access_mode'=>'public','attempt_limit'=>1,'result_visibility'=>'completion','anonymous_allowed'=>true,'identified_required'=>false,'consent_required'=>true,'autosave_enabled'=>true,'resume_enabled'=>true]);
    }
}
