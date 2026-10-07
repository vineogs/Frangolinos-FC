<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('racha_days', function (Blueprint $table) {
            $table->json('paid_players')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('racha_days', function (Blueprint $table) {
            $table->dropColumn('paid_players');
        });
    }
};
