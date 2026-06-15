<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_payloads', function (Blueprint $table) {
            $table->id();
            $table->string('imei');
            $table->text('payload');
            $table->timestamp('recorded_at');
            $table->index('imei');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_payloads');
    }
};
