<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whitelist')) {
            Schema::table('whitelist', function (Blueprint $table) {
                if (!Schema::hasColumn('whitelist', 'device_type')) {
                    $table->string('device_type')->default('watch')->after('model');
                }
                if (!Schema::hasColumn('whitelist', 'license_id')) {
                    $table->string('license_id')->default('0')->after('device_type');
                }
                if (!Schema::hasColumn('whitelist', 'sim_number')) {
                    $table->string('sim_number')->default('')->after('license_id');
                }
                if (!Schema::hasColumn('whitelist', 'device_id')) {
                    $table->string('device_id')->default('')->after('sim_number');
                }
            });
        }

        if (!Schema::hasTable('device_configurations')) {
            Schema::create('device_configurations', function (Blueprint $table) {
                $table->string('imei');
                $table->string('config_key');
                $table->string('protocol');
                $table->string('supplier')->default('');
                $table->string('model')->default('');
                $table->string('command')->default('');
                $table->text('desired_payload')->default('{}');
                $table->text('reported_payload')->default('{}');
                $table->string('last_status')->default('');
                $table->string('last_command_id')->default('');
                $table->string('desired_updated_at')->default('');
                $table->string('reported_at')->default('');
                $table->string('applied_at')->default('');
                $table->primary(['imei', 'config_key']);
                $table->index('imei');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_configurations');

        if (Schema::hasTable('whitelist')) {
            Schema::table('whitelist', function (Blueprint $table) {
                foreach (['device_type', 'license_id', 'sim_number', 'device_id'] as $column) {
                    if (Schema::hasColumn('whitelist', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
