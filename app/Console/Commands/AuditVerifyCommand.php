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
            ['Verification result', $result['passed'] ? 'valid' : 'invalid'],
        ]);

        if (! $result['passed']) {
            $this->error('Category: '.($result['category'] ?? 'invalid'));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
