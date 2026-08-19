<?php

namespace Ahmed3bead\LaravelHooks\Strategies;

use Ahmed3bead\LaravelHooks\Contracts\HookExecutionStrategy;
use Ahmed3bead\LaravelHooks\Contracts\HookJobInterface;
use Ahmed3bead\LaravelHooks\HookContext;
use Illuminate\Support\Facades\Log;

/**
 * Synchronous Hook Execution Strategy
 *
 * Executes hooks immediately in the same request cycle
 */
class SyncHookStrategy implements HookExecutionStrategy
{
    public function getName(): string
    {
        return 'sync';
    }

    public function supportsRetry(): bool
    {
        return true;
    }

    /**
     * Maximum retry wait in seconds to prevent blocking requests too long.
     */
    private const MAX_RETRY_WAIT_SECONDS = 5;

    /**
     * Hard cap on sync retries to prevent request-blocking DoS.
     */
    private const MAX_SYNC_RETRIES = 3;

    public function execute(HookJobInterface $hook, HookContext $context): void
    {
        $attempts = 0;
        $maxAttempts = min($hook->getRetryAttempts(), self::MAX_SYNC_RETRIES);

        while ($attempts < $maxAttempts) {
            try {
                if ($hook->shouldExecute($context)) {
                    $hook->execute($context);

                    return;
                }

                return; // Hook chose not to execute
            } catch (\Exception $e) {
                $attempts++;

                if ($attempts >= $maxAttempts) {
                    Log::error('Sync hook execution failed after retries', [
                        'hook' => get_class($hook),
                        'attempts' => $attempts,
                        'context' => $context->toLogArray(),
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }

                // Exponential backoff: base delay * 2^(attempt-1), capped
                $baseDelay = $hook->getRetryDelay();
                $backoff = min($baseDelay * (2 ** ($attempts - 1)), self::MAX_RETRY_WAIT_SECONDS);
                if ($backoff > 0) {
                    usleep((int) ($backoff * 1_000_000));
                }
            }
        }
    }
}
