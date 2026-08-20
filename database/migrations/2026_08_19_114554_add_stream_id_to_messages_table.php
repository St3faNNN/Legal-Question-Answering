<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The stream id makes a streamed reply's assistant message queryable by the
     * job that produced it: StreamChatReply checks for an existing completed
     * message before generating, so a job executed twice (a retry, a manual
     * requeue, an operator re-dispatch) never calls the generation API — and
     * re-bills it — a second time. Nullable because only streamed replies carry
     * one; the synchronous send() path leaves it null. Indexed for the guard's
     * point lookup.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->uuid('stream_id')->nullable()->after('metadata')->index();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex(['stream_id']);
            $table->dropColumn('stream_id');
        });
    }
};
