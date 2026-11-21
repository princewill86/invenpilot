<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'businesses'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->timestamp('suspended_at')->nullable()->index());
        }
        Schema::create('platform_activity', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->string('target_type', 12);
            $table->unsignedBigInteger('target_id');
            $table->text('reason');
            $table->json('details');
            $table->timestamp('created_at');
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_activity');
        foreach (['users', 'businesses'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['suspended_at']);
                $table->dropColumn('suspended_at');
            });
        }
    }
};
