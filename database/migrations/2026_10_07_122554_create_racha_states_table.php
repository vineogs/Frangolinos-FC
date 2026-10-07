<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('racha_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('revision')->default(0);
            $table->json('data');
        });
        DB::table('racha_states')->insert([
            'id' => 1,
            'revision' => 0,
            'data' => json_encode(['players' => [], 'matches' => [], 'current' => null]),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('racha_states');
    }
};
