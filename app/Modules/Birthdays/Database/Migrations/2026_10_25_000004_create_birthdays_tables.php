<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cumpleaños:
 *  - users.birth_md: mes y día (MMDD, p. ej. 214 = 14 de febrero) con índice,
 *    para listar por mes o por "próximos días" en cualquier motor de BD.
 *  - birthday_greetings: a quién ya se saludó este año (el aviso sale una
 *    sola vez aunque el comando corra cada hora o en varios servidores).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('birth_md')->nullable()->after('birth_date');
            $table->index(['tenant_id', 'birth_md']);
        });

        DB::table('users')->whereNotNull('birth_date')->orderBy('id')->select('id', 'birth_date')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $date = \Illuminate\Support\Carbon::parse($row->birth_date);
                    DB::table('users')->where('id', $row->id)->update(['birth_md' => (int) $date->format('nd')]);
                }
            });

        Schema::create('birthday_greetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('colleagues_notified')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_greetings');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'birth_md']);
            $table->dropColumn('birth_md');
        });
    }
};
