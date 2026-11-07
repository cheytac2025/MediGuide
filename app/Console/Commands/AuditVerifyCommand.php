<?php

namespace App\Console\Commands;

use App\Support\Audit\AuditVerificationService;
use Illuminate\Console\Command;

/**
 * Read-only verification of the audit chain. Event context is not printed.
 */
class AuditVerifyCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mediguide:audit-verify';

    /**
     * @var string
     */
    protected $description = 'Verify the HMAC-SHA-256 audit chain';

    public function handle(AuditVerificationService $verification): int
    {
        $result = $verification->verify();

        $this->table(['Check', 'Value'], [
            ['Total events', $result['total']],
            ['Valid events', $result['valid']],
            ['Invalid events', $result['invalid']],
            ['First failing sequence', $result['first_sequence'] ?? 'none'],
            ['Chain head sequence', $result['head_sequence']],
            ['Checkpoint protection', $result['checkpoint_protection']],
            ['Checkpoint sequence', $result['checkpoint_sequence'] ?? 'none'],
            ['Events after checkpoint', $result['uncovered_events'] ?? 'none'],
            ['Verification result', $result['passed'] ? 'valid' : 'invalid'],
        ]);

        if ($result['checkpoint_protection'] === 'inactive') {
            $this->line('Checkpoint protection is inactive.');
            $this->line('Verification covered the database chain only.');
            $this->line('Deletion of a still-valid chain tail is not detected.');
        }

        if (($result['uncovered_events'] ?? null) !== null && $result['uncovered_events'] > 0) {
            $this->line('Events after the checkpoint are not covered by it.');
            $this->line('Deletion confined to that tail may remain undetected.');
        }

        if (! $result['passed']) {
            if (is_string($result['message'] ?? null) && $result['message'] !== '') {
                $this->error($result['message']);
            }

            $this->error('Category: '.($result['category'] ?? 'invalid'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
