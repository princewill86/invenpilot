<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('platform_admin')->default(false);
            $table->timestamp('legal_accepted_at')->nullable();
            $table->string('legal_version', 64)->nullable();
        });
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->json('settings');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['platform_admin', 'legal_accepted_at', 'legal_version']));
    }
};
