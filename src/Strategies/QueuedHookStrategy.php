<?php

namespace Ahmed3bead\LaravelHooks\Strategies;

use Ahmed3bead\LaravelHooks\Contracts\HookExecutionStrategy;
use Ahmed3bead\LaravelHooks\Contracts\HookJobInterface;
use Ahmed3bead\LaravelHooks\HookContext;
use Ahmed3bead\LaravelHooks\Jobs\QueuedHookJob;
use Illuminate\Support\Facades\Queue;

/**
 * Queued Hook Execution Strategy
 *
 * Executes hooks asynchronously using Laravel's queue system
 */
class QueuedHookStrategy implements HookExecutionStrategy
{
    public function getName(): string
    {
        return 'queue';
    }

    public function supportsRetry(): bool
    {
        return true;
    }

    public function execute(HookJobInterface $hook, HookContext $context): void
    {
        $job = new QueuedHookJob($hook, $context);
        Queue::connection(config('laravel-hooks.queue_connection', null))
            ->pushOn($hook->getQueueName(), $job);
    }
}
