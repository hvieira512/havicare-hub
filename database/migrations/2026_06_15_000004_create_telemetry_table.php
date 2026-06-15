<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry', function (Blueprint $table) {
            $table->id();
            $table->string('imei');
            $table->string('type');
            $table->text('payload');
            $table->timestamp('recorded_at');
            $table->index('imei');
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry');
    }
};
