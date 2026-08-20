<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invitation codes gate registration: open self-service signup is closed,
     * so an account can only be created by redeeming a single-use code. Existing
     * accounts are unaffected — the gate is only at the registration endpoint.
     */
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            // Optionally bind a code to one address; null means any email may
            // redeem it (a general invite).
            $table->string('email')->nullable();
            // Free-text reminder of who the code was minted for.
            $table->string('note')->nullable();
            // Who minted it (null when minted from the console) and who redeemed
            // it. Both null-on-delete so removing a user never deletes the audit
            // row.
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->foreignId('used_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
