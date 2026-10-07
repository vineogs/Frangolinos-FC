<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 60);
            $table->string('position')->default('outfield');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        $data = json_decode(DB::table('racha_states')->where('id', 1)->value('data') ?? '{}', true);
        foreach ($data['players'] ?? [] as $player) {
            DB::table('players')->insert(['id' => $player['id'], 'name' => $player['name'], 'position' => $player['position'] ?? 'outfield', 'active' => $player['active'] ?? true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
