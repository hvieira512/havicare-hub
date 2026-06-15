<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('model');
            $table->string('protocol');
            $table->string('image_path')->default('');
            $table->timestamps();
            $table->unique(['supplier_id', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('models');
    }
};
