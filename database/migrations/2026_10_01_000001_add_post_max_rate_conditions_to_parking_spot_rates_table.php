<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parking_spot_rates', function (Blueprint $table) {
            $table->unsignedInteger('max_rate_period_minutes')->nullable()->after('max_rate_period');
            $table->unsignedInteger('post_max_rate_unit_minutes')->nullable()->after('max_rate_repeats');
            $table->unsignedInteger('post_max_rate')->nullable()->after('post_max_rate_unit_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('parking_spot_rates', function (Blueprint $table) {
            $table->dropColumn(['max_rate_period_minutes', 'post_max_rate_unit_minutes', 'post_max_rate']);
        });
    }
};
