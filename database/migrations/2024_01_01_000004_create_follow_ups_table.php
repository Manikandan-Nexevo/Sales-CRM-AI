<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('call_log_id')->nullable();
            $table->enum('type', ['call', 'email', 'whatsapp', 'linkedin', 'meeting'])->default('call');
            $table->string('subject');
            $table->text('message')->nullable();
            $table->timestamp('scheduled_at');
            $table->timestamp('completed_at')->nullable();
            $table->enum('status', ['pending', 'completed', 'cancelled', 'snoozed'])->default('pending');
            $table->boolean('ai_generated')->default(false);
            $table->boolean('email_sent')->default(false);
            $table->boolean('whatsapp_sent')->default(false);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
            $table->foreign('call_log_id')->references('id')->on('call_logs')->onDelete('set null');
            $table->index('user_id');
            $table->index('scheduled_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
