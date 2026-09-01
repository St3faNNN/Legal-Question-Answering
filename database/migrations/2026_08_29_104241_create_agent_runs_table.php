<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The trajectory of one escalation, kept beside the message it produced so a
     * run can be inspected after the fact rather than only in the CLI.
     *
     * Its own table rather than a column on `messages` because the two are read
     * on different schedules: every conversation load reads message metadata,
     * while a trace is read only when someone opens it — and a trace is large
     * (tens of KB of passages). One row per message, deleted with it.
     */
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->unique()->constrained()->cascadeOnDelete();
            // The agent's own run id (agent/runs/<runId>.json), so a web run and
            // the on-disk trace the CLI inspector serves are the same record.
            $table->string('run_id')->index();
            $table->text('question');
            $table->string('outcome');
            $table->string('model')->nullable();
            $table->unsignedInteger('steps_count')->default(0);
            $table->unsignedInteger('api_calls')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            // The per-step trajectory (tool, query, citations returned) and the
            // distinct passages the run retrieved.
            $table->json('steps');
            $table->json('passages');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
