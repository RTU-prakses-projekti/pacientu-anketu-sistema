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
            'submissions.view','exports.create','exports.download','anonymized_results.view','audit.view','users.manage',
            'doctor.dashboard.view','patients.view','patients.update','patient.questionnaires.view',
        ];
        foreach ($permissions as $name) Permission::updateOrCreate(['name'=>$name],['display_name'=>ucwords(str_replace(['.','_'],' ',$name))]);
        $roles = [
            'platform_admin' => ['display_name'=>'Bootstrap root','scope'=>'global','permissions'=>array_values(array_diff($permissions,['doctor.dashboard.view','patients.view','patients.update','patient.questionnaires.view']))],
            'administrator' => ['display_name'=>'Administrators','scope'=>'global','permissions'=>array_values(array_diff($permissions,['doctor.dashboard.view','patients.view','patients.update','patient.questionnaires.view']))],
            'organisation_admin' => ['display_name'=>'Administratora palīgs','scope'=>'organisation','permissions'=>['organisation.view','organisation.manage','forms.view','forms.create','forms.update','forms.publish','forms.archive','audit.view','users.manage']],
            'form_creator' => ['display_name'=>'Anketu pārvaldnieks','scope'=>'organisation','permissions'=>['organisation.view','forms.view','forms.create','forms.update','forms.publish','forms.archive']],
            'doctor' => ['display_name'=>'Ārsts','scope'=>'organisation','permissions'=>['doctor.dashboard.view','patients.view','patients.update','patient.questionnaires.view']],
        ];
        foreach ($roles as $name=>$config) { $role=Role::updateOrCreate(['name'=>$name],['display_name'=>$config['display_name'],'scope'=>$config['scope'],'is_system'=>true]);$role->permissions()->sync(Permission::whereIn('name',$config['permissions'])->pluck('id')); }
    }
}
