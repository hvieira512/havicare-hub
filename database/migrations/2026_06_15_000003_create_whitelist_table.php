<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whitelist', function (Blueprint $table) {
            $table->string('imei')->primary();
            $table->string('supplier');
            $table->string('model');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whitelist');
    }
};
