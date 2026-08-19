<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'organisation.view','organisation.manage','forms.view','forms.create','forms.update','forms.publish','forms.archive',
            'submissions.view','submissions.grade','submissions.manage','exports.create','exports.download','audit.view','users.manage','publications.respond',
            'doctor.dashboard.view','patients.view','patients.update','patient.questionnaires.view',
        ];
        foreach ($permissions as $name) Permission::updateOrCreate(['name'=>$name],['display_name'=>ucwords(str_replace(['.','_'],' ',$name))]);
        $roles = [
            'platform_admin' => ['display_name'=>'Admin 1','scope'=>'global','permissions'=>array_values(array_diff($permissions,['doctor.dashboard.view','patients.view','patients.update','patient.questionnaires.view']))],
            'organisation_admin' => ['display_name'=>'Admin 2','scope'=>'organisation','permissions'=>array_values(array_diff($permissions,['doctor.dashboard.view','patients.view','patients.update','patient.questionnaires.view']))],
            'form_creator' => ['display_name'=>'Admin 3','scope'=>'organisation','permissions'=>['organisation.view','forms.view','forms.create','forms.update','forms.publish','forms.archive','submissions.view','publications.respond']],
            'reviewer' => ['display_name'=>'Vērtētājs','scope'=>'organisation','permissions'=>['organisation.view','forms.view','submissions.view','submissions.grade','exports.create','exports.download','publications.respond']],
            'respondent' => ['display_name'=>'Pacients','scope'=>'organisation','permissions'=>['organisation.view','publications.respond']],
            'doctor' => ['display_name'=>'Ārsts','scope'=>'organisation','permissions'=>['doctor.dashboard.view','patients.view','patients.update','patient.questionnaires.view']],
        ];
        foreach ($roles as $name=>$config) { $role=Role::updateOrCreate(['name'=>$name],['display_name'=>$config['display_name'],'scope'=>$config['scope'],'is_system'=>true]);$role->permissions()->sync(Permission::whereIn('name',$config['permissions'])->pluck('id')); }
    }
}
