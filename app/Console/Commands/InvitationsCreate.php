<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use Illuminate\Console\Command;

/**
 * Mint registration invitation codes from the console. This is the only way to
 * let someone register now that open signup is closed — there is deliberately no
 * self-service or public endpoint for it.
 */
class InvitationsCreate extends Command
{
    protected $signature = 'invitations:create
        {--email= : Bind the code to a single email address (optional)}
        {--note= : A reminder of who the code is for (optional)}
        {--expires= : Days until the code expires (optional, never expires if omitted)}
        {--count=1 : How many codes to mint}';

    protected $description = 'Mint single-use registration invitation code(s)';

    public function handle(): int
    {
        $count = max(1, (int) $this->option('count'));
        $email = $this->option('email') ?: null;
        $note = $this->option('note') ?: null;
        $expiresDays = $this->option('expires');
        $expiresAt = $expiresDays !== null ? now()->addDays((int) $expiresDays) : null;

        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $invitation = Invitation::create([
                'code' => Invitation::generateCode(),
                'email' => $email,
                'note' => $note,
                'expires_at' => $expiresAt,
            ]);

            $rows[] = [$invitation->code, $email ?? '(any)', $expiresAt?->toDateString() ?? '(never)'];
        }

        $this->newLine();
        $this->table(['Code', 'Email', 'Expires'], $rows);
        $this->info($count === 1 ? 'Minted 1 invitation code.' : "Minted {$count} invitation codes.");

        return self::SUCCESS;
    }
}
