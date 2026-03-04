<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company');
            $table->string('designation')->nullable();
            $table->string('email')->nullable();
            $table->string('phone');
            $table->string('phone_alt')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->boolean('linkedin_connected')->default(false);
            $table->string('website')->nullable();
            $table->string('industry')->nullable();
            $table->string('company_size')->nullable();
            $table->string('location')->nullable();
            $table->string('source')->nullable()->comment('LinkedIn, Referral, Website, Cold Call, etc.');
            $table->enum('status', [
                'new', 'contacted', 'interested', 'qualified',
                'hot', 'proposal', 'closed_won', 'closed_lost', 'not_interested'
            ])->default('new');
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->text('notes')->nullable();
            $table->integer('ai_score')->default(0)->comment('AI lead scoring 0-100');
            $table->json('ai_analysis')->nullable();
            $table->json('tags')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('next_followup_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
            $table->index('status');
            $table->index('priority');
            $table->index('assigned_to');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
