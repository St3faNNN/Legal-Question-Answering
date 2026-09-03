<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which user message an escalation actually answered.
     *
     * Until now the run stored only the question *text*, and the text was chosen
     * by walking back from the escalated answer to "the nearest preceding user
     * message". That walk read through `Conversation::messages()`, whose relation
     * carries `orderBy('created_at')`, so the `orderByDesc('id')` layered on top
     * became a tiebreaker rather than the sort — the query resolved to
     * `ORDER BY created_at ASC, id DESC` and returned the *oldest* user message in
     * the conversation. One run escalated a procurement question and was handed
     * the conversation's first question instead, which was out of corpus scope,
     * so the agent refused without searching.
     *
     * Recording the id makes the choice explicit and auditable: the question is
     * now an identifier the client supplies and the server validates, not
     * something re-derived from position afterwards.
     *
     * Nullable with ON DELETE SET NULL (the invitations pattern): a run is a
     * historical record of what happened and must survive the deletion of the
     * message it refers to, rather than being cascaded away with it. Existing
     * rows stay null — the correct question for the one affected run cannot be
     * recovered, and guessing it would put a wrong id in an audit column.
     */
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->foreignId('question_message_id')
                ->nullable()
                ->after('message_id')
                ->constrained('messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('question_message_id');
        });
    }
};
