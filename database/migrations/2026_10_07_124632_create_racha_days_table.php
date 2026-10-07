<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('racha_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('date')->index();
            $table->string('time', 5);
            $table->string('location', 120);
            $table->json('attendees');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('racha_days');
    }
};
