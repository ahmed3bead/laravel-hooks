<?php

namespace Ahmed3bead\LaravelHooks;

use Ahmed3bead\LaravelHooks\Contracts\HookJobInterface;

/**
 * Closure Hook Job
 *
 * Wraps a plain callable/closure so it can be used as a hook without
 * creating a dedicated class. Used internally by HookableTrait's
 * hookWithLogic() / syncHookWithLogic() helpers.
 */
class ClosureHookJob implements HookJobInterface
{
    private \Closure $callback;

    public function __construct(callable $callback)
    {
        $this->callback = \Closure::fromCallable($callback);
    }

    public function handle(HookContext $context): void
    {
        ($this->callback)($context);
    }

    public function shouldExecute(HookContext $context): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function getRetryAttempts(): int
    {
        return 1;
    }

    public function getRetryDelay(): int
    {
        return 0;
    }

    public function getTimeout(): int
    {
        return 30;
    }

    public function isAsync(): bool
    {
        return false;
    }

    public function getQueueName(): string
    {
        return 'default';
    }

    public function getMetadata(): array
    {
        return ['type' => 'closure'];
    }

    public function execute(HookContext $context): void
    {
        $this->handle($context);
    }
}
