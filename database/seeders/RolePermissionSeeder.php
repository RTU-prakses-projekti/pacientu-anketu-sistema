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
        ];
        foreach ($permissions as $name) Permission::updateOrCreate(['name'=>$name],['display_name'=>ucwords(str_replace(['.','_'],' ',$name))]);
        $roles = [
            'platform_admin' => ['scope'=>'global','permissions'=>$permissions],
            'organisation_admin' => ['scope'=>'organisation','permissions'=>$permissions],
            'form_creator' => ['scope'=>'organisation','permissions'=>['organisation.view','forms.view','forms.create','forms.update','forms.publish','forms.archive','submissions.view','submissions.manage','exports.create','exports.download','audit.view','publications.respond']],
            'reviewer' => ['scope'=>'organisation','permissions'=>['organisation.view','forms.view','submissions.view','submissions.grade','exports.create','exports.download','publications.respond']],
            'respondent' => ['scope'=>'organisation','permissions'=>['organisation.view','publications.respond']],
        ];
        foreach ($roles as $name=>$config) { $role=Role::updateOrCreate(['name'=>$name],['display_name'=>ucwords(str_replace('_',' ',$name)),'scope'=>$config['scope']]);$role->permissions()->sync(Permission::whereIn('name',$config['permissions'])->pluck('id')); }
    }
}
