<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('contact_id');
            $table->enum('direction', ['inbound', 'outbound'])->default('outbound');
            $table->integer('duration')->default(0)->comment('Duration in seconds');
            $table->enum('status', ['connected', 'no_answer', 'busy', 'voicemail', 'call_back'])->default('no_answer');
            $table->string('outcome')->nullable()->comment('Interested, Demo scheduled, Not interested, etc.');
            $table->text('notes')->nullable();
            $table->text('ai_summary')->nullable();
            $table->text('voice_transcript')->nullable();
            $table->string('next_action')->nullable();
            $table->timestamp('next_action_date')->nullable();
            $table->string('call_recording_url')->nullable();
            $table->enum('sentiment', ['positive', 'neutral', 'negative'])->nullable();
            $table->tinyInteger('interest_level')->nullable()->comment('1-5 scale');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
            $table->index('user_id');
            $table->index('contact_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_logs');
    }
};
