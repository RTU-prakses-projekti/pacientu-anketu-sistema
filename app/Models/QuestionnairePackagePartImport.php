<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionnairePackagePartImport extends Model
{
    protected $guarded = [];

    public function organisation() { return $this->belongsTo(Organisation::class); }
    public function form() { return $this->belongsTo(Form::class); }
    public function formVersion() { return $this->belongsTo(FormVersion::class); }
    public function importer() { return $this->belongsTo(User::class, 'imported_by'); }
}
