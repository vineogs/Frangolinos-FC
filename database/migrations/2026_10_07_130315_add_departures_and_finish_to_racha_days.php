<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('racha_days', function (Blueprint $table) {
            $table->json('departed')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('statistics')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('racha_days', function (Blueprint $table) {
            $table->dropColumn(['departed', 'finished_at', 'statistics']);
        });
    }
};
