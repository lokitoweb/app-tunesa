<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capacitaciones: cursos con lecciones (texto, video, PDF, enlace), cuestionario,
 * a quién se asignan (reglas) y el avance de cada colaborador (inscripciones).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('category', 30)->default('general');
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->unsignedSmallInteger('due_days')->nullable()->comment('Días para completarlo desde que se asigna');
            $table->unsignedTinyInteger('passing_score')->default(70);
            $table->string('status', 20)->default('draft')->comment('draft | published | archived');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('training_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('title', 200);
            $table->string('type', 10)->comment('text | video | pdf | link');
            $table->text('content')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('file_disk', 30)->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->timestamps();
            $table->index(['course_id', 'position']);
        });

        Schema::create('training_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('question', 500);
            $table->json('options');
            $table->unsignedTinyInteger('correct_option');
            $table->timestamps();
            $table->index(['course_id', 'position']);
        });

        Schema::create('training_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->string('target_type', 20)->comment('all | branch | department | position | user');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_value', 150)->nullable()->comment('Cargo (position)');
            $table->timestamps();
            $table->index(['course_id', 'target_type']);
        });

        Schema::create('training_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('training_courses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('assigned_at');
            $table->date('due_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('completed_lessons')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('due_reminder_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['course_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_enrollments');
        Schema::dropIfExists('training_targets');
        Schema::dropIfExists('training_questions');
        Schema::dropIfExists('training_lessons');
        Schema::dropIfExists('training_courses');
    }
};
