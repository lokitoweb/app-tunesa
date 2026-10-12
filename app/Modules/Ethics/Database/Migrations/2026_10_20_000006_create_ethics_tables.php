<?php

use App\Modules\Core\Models\Permission;
use App\Modules\Core\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canal ético: denuncias anónimas con código y token de seguimiento.
 *
 * Ninguna tabla guarda quién denunció: sin user_id, IP ni user agent, y las
 * fechas del denunciante van redondeadas al día. El token se guarda solo como
 * SHA-256. Los textos van cifrados con la APP_KEY.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ethics_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('public_code', 12)->unique();
            $table->char('token_hash', 64);
            $table->string('category', 40);
            $table->text('subject');
            $table->text('body');
            $table->string('status', 20)->default('received');
            $table->string('push_channel', 64)->nullable();
            $table->date('reported_on');
            $table->boolean('reporter_unread')->default(false);
            $table->boolean('investigator_unread')->default(true);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('resolution')->nullable();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('ethics_case_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('case_id')->constrained('ethics_cases')->cascadeOnDelete();
            $table->string('author_type', 20);
            $table->foreignId('investigator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->date('sent_on');
        });

        Schema::create('ethics_case_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('case_id')->constrained('ethics_cases')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('ethics_case_messages')->cascadeOnDelete();
            $table->string('path');
            $table->string('mime', 50);
            $table->unsignedInteger('size');
        });

        // Permiso para las bases existentes (mismo patrón que add_onboarding_permissions)
        if (Schema::hasTable('permissions')) {
            [$name, $group] = RolesAndPermissionsSeeder::PERMISSIONS['ethics.investigate'];
            $id = Permission::updateOrCreate(['slug' => 'ethics.investigate'], ['name' => $name, 'group' => $group])->id;

            foreach (RolesAndPermissionsSeeder::ROLES as $roleSlug => [, , $grants]) {
                if (in_array('ethics.investigate', RolesAndPermissionsSeeder::expand($grants), true)) {
                    Role::whereNull('tenant_id')->where('slug', $roleSlug)->get()
                        ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$id]));
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ethics_case_attachments');
        Schema::dropIfExists('ethics_case_messages');
        Schema::dropIfExists('ethics_cases');
        Permission::where('slug', 'ethics.investigate')->get()->each(function (Permission $p) {
            $p->roles()->detach();
            $p->delete();
        });
    }
};
