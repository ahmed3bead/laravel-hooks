<?php

namespace Ahmed3bead\LaravelHooks\Strategies;

use Ahmed3bead\LaravelHooks\Contracts\HookExecutionStrategy;
use Ahmed3bead\LaravelHooks\Contracts\HookJobInterface;
use Ahmed3bead\LaravelHooks\HookContext;
use Ahmed3bead\LaravelHooks\Jobs\QueuedHookJob;
use Illuminate\Support\Facades\Queue;

/**
 * Delayed Hook Execution Strategy
 *
 * Executes hooks after a specified delay using Laravel's queue system
 */
class DelayedHookStrategy implements HookExecutionStrategy
{
    public function __construct(private int $delay = 30) {}

    public function getName(): string
    {
        return 'delay';
    }

    public function supportsRetry(): bool
    {
        return true;
    }

    public function execute(HookJobInterface $hook, HookContext $context): void
    {
        $job = new QueuedHookJob($hook, $context);

        Queue::connection(config('laravel-hooks.queue_connection', 'default'))
            ->laterOn($hook->getQueueName(), $this->delay, $job);
    }

    public function setDelay(int $delay): self
    {
        $this->delay = $delay;

        return $this;
    }

    public function getDelay(): int
    {
        return $this->delay;
    }
}
