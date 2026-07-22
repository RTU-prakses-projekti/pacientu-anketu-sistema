<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_id')->constrained()->onDelete('cascade');
            $table->string('student_id');
            $table->string('student_name')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->integer('score')->nullable();
            $table->integer('total_possible')->nullable();
            $table->boolean('is_auto_submitted')->default(false);
            $table->timestamps();
            
            $table->unique(['test_id', 'student_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('submissions');
    }
};