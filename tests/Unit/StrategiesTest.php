<?php

use Ahmed3bead\LaravelHooks\Contracts\HookJobInterface;
use Ahmed3bead\LaravelHooks\HookContext;
use Ahmed3bead\LaravelHooks\Jobs\BatchProcessorJob;
use Ahmed3bead\LaravelHooks\Jobs\BatchSchedulerJob;
use Ahmed3bead\LaravelHooks\Jobs\QueuedHookJob;
use Ahmed3bead\LaravelHooks\Strategies\BatchedHookStrategy;
use Ahmed3bead\LaravelHooks\Strategies\ConditionalHookStrategy;
use Ahmed3bead\LaravelHooks\Strategies\DelayedHookStrategy;
use Ahmed3bead\LaravelHooks\Strategies\QueuedHookStrategy;
use Ahmed3bead\LaravelHooks\Strategies\SyncHookStrategy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

// Spy hook for strategy tests
class SpyHook implements HookJobInterface
{
    public array $executed = [];

    public bool $shouldRun = true;

    public function handle(HookContext $context): void
    {
        $this->executed[] = $context->method;
    }

    public function shouldExecute(HookContext $context): bool
    {
        return $this->shouldRun;
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
        return [];
    }

    public function execute(HookContext $context): void
    {
        $this->handle($context);
    }
}

function makeStrategyCtx(string $method = 'create'): HookContext
{
    return new HookContext($method, 'after', null, [], null, new stdClass);
}

// --- SyncHookStrategy ---

test('SyncHookStrategy getName returns sync', function () {
    expect((new SyncHookStrategy)->getName())->toBe('sync');
});

test('SyncHookStrategy supportsRetry returns true', function () {
    expect((new SyncHookStrategy)->supportsRetry())->toBeTrue();
});

test('SyncHookStrategy executes the hook', function () {
    $strategy = new SyncHookStrategy;
    $hook = new SpyHook;

    $strategy->execute($hook, makeStrategyCtx('create'));

    expect($hook->executed)->toContain('create');
});

test('SyncHookStrategy skips hook when shouldExecute is false', function () {
    $strategy = new SyncHookStrategy;
    $hook = new SpyHook;
    $hook->shouldRun = false;

    $strategy->execute($hook, makeStrategyCtx('create'));

    expect($hook->executed)->toBeEmpty();
});

test('SyncHookStrategy retries on failure', function () {
    $strategy = new SyncHookStrategy;
    $attempts = 0;

    $hook = new class($attempts) implements HookJobInterface
    {
        public int $callCount = 0;

        public function __construct(public int &$ref) {}

        public function handle(HookContext $ctx): void
        {
            $this->callCount++;
            $this->ref++;
            if ($this->callCount < 2) {
                throw new RuntimeException('fail');
            }
        }

        public function shouldExecute(HookContext $ctx): bool
        {
            return true;
        }

        public function getPriority(): int
        {
            return 100;
        }

        public function getRetryAttempts(): int
        {
            return 3;
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
            return [];
        }

        public function execute(HookContext $ctx): void
        {
            $this->handle($ctx);
        }
    };

    $strategy->execute($hook, makeStrategyCtx());
    expect($attempts)->toBe(2);
});

// --- QueuedHookStrategy ---

test('QueuedHookStrategy getName returns queue', function () {
    expect((new QueuedHookStrategy)->getName())->toBe('queue');
});

test('QueuedHookStrategy pushes job to queue', function () {
    Queue::fake();

    $strategy = new QueuedHookStrategy;
    $hook = new SpyHook;

    $strategy->execute($hook, makeStrategyCtx());

    Queue::assertPushed(QueuedHookJob::class);
});

// --- DelayedHookStrategy ---

test('DelayedHookStrategy getName returns delay', function () {
    expect((new DelayedHookStrategy)->getName())->toBe('delay');
});

test('DelayedHookStrategy setDelay and getDelay work', function () {
    $strategy = new DelayedHookStrategy(30);
    $strategy->setDelay(120);
    expect($strategy->getDelay())->toBe(120);
});

test('DelayedHookStrategy pushes job to queue with delay', function () {
    Queue::fake();

    $strategy = new DelayedHookStrategy(60);
    $hook = new SpyHook;

    $strategy->execute($hook, makeStrategyCtx());

    Queue::assertPushed(QueuedHookJob::class);
});

// --- BatchedHookStrategy ---

test('BatchedHookStrategy getName returns batch', function () {
    expect((new BatchedHookStrategy)->getName())->toBe('batch');
});

test('BatchedHookStrategy supportsRetry returns true', function () {
    expect((new BatchedHookStrategy)->supportsRetry())->toBeTrue();
});

test('BatchedHookStrategy collects items and processes when batch size reached', function () {
    Queue::fake();

    $strategy = new BatchedHookStrategy(batchSize: 2, batchDelay: 0);
    $hook = new SpyHook;
    $ctx = makeStrategyCtx();

    $strategy->execute($hook, $ctx);
    $strategy->execute($hook, $ctx); // triggers batch

    Queue::assertPushed(BatchProcessorJob::class);
});

test('BatchedHookStrategy flushBatches dispatches remaining items', function () {
    Queue::fake();

    $strategy = new BatchedHookStrategy(batchSize: 100);
    $hook = new SpyHook;
    $ctx = makeStrategyCtx();

    $strategy->execute($hook, $ctx);

    // Build the batch key the same way the strategy does internally
    $batchKey = 'default_'.SpyHook::class.'_'.$ctx->method;
    BatchedHookStrategy::flushBatches([$batchKey]);

    Queue::assertPushed(BatchProcessorJob::class);
});

test('BatchedHookStrategy setBatchSize and setBatchDelay work', function () {
    $strategy = new BatchedHookStrategy;
    $strategy->setBatchSize(5)->setBatchDelay(30)->setBatchKey('custom');

    // No public getters - just verify method chaining returns self
    expect($strategy)->toBeInstanceOf(BatchedHookStrategy::class);
});

// --- ConditionalHookStrategy ---

test('ConditionalHookStrategy getName includes wrapped strategy name', function () {
    $inner = new SyncHookStrategy;
    $strategy = new ConditionalHookStrategy($inner);
    expect($strategy->getName())->toBe('conditional_sync');
});

test('ConditionalHookStrategy delegates execution when conditions pass', function () {
    $inner = new SyncHookStrategy;
    $strategy = new ConditionalHookStrategy($inner);
    $strategy->addCondition(fn () => true);

    $hook = new SpyHook;
    $strategy->execute($hook, makeStrategyCtx('update'));

    expect($hook->executed)->toContain('update');
});

test('ConditionalHookStrategy skips execution when condition fails', function () {
    $inner = new SyncHookStrategy;
    $strategy = new ConditionalHookStrategy($inner);
    $strategy->addCondition(fn () => false);

    $hook = new SpyHook;
    $strategy->execute($hook, makeStrategyCtx('update'));

    expect($hook->executed)->toBeEmpty();
});

test('ConditionalHookStrategy supportsRetry delegates to wrapped strategy', function () {
    $inner = new SyncHookStrategy;
    $strategy = new ConditionalHookStrategy($inner);
    expect($strategy->supportsRetry())->toBeTrue();
});

test('ConditionalHookStrategy onlyInEnvironment works', function () {
    $inner = new SyncHookStrategy;
    $strategy = new ConditionalHookStrategy($inner);
    $strategy->onlyInEnvironment('testing');

    $hook = new SpyHook;
    $strategy->execute($hook, makeStrategyCtx());

    // In Orchestra Testbench the environment is 'testing'
    expect($hook->executed)->toHaveCount(1);
});

test('ConditionalHookStrategy onlyInEnvironment blocks wrong environment', function () {
    $inner = new SyncHookStrategy;
    $strategy = new ConditionalHookStrategy($inner);
    $strategy->onlyInEnvironment('production');

    $hook = new SpyHook;
    $strategy->execute($hook, makeStrategyCtx());

    expect($hook->executed)->toBeEmpty();
});

test('ConditionalHookStrategy onlyWhenConfigEnabled works', function () {
    config(['features.test_hook' => true]);

    $inner = new SyncHookStrategy;
    $strategy = new ConditionalHookStrategy($inner);
    $strategy->onlyWhenConfigEnabled('features.test_hook');

    $hook = new SpyHook;
    $strategy->execute($hook, makeStrategyCtx());

    expect($hook->executed)->toHaveCount(1);
});

test('ConditionalHookStrategy onlyWhenConfigEnabled blocks when disabled', function () {
    config(['features.disabled_hook' => false]);

    $inner = new SyncHookStrategy;
    $strategy = new ConditionalHookStrategy($inner);
    $strategy->onlyWhenConfigEnabled('features.disabled_hook');

    $hook = new SpyHook;
    $strategy->execute($hook, makeStrategyCtx());

    expect($hook->executed)->toBeEmpty();
});

test('ConditionalHookStrategy supports multiple conditions', function () {
    $inner = new SyncHookStrategy;
    $strategy = new ConditionalHookStrategy($inner);
    $strategy->addCondition(fn () => true);
    $strategy->addCondition(fn () => true);

    $hook = new SpyHook;
    $strategy->execute($hook, makeStrategyCtx());

    expect($hook->executed)->toHaveCount(1);
});

test('ConditionalHookStrategy fails if any condition fails', function () {
    $inner = new SyncHookStrategy;
    $strategy = new ConditionalHookStrategy($inner);
    $strategy->addCondition(fn () => true);
    $strategy->addCondition(fn () => false);

    $hook = new SpyHook;
    $strategy->execute($hook, makeStrategyCtx());

    expect($hook->executed)->toBeEmpty();
});

// --- SyncHookStrategy retry cap and backoff ---

test('SyncHookStrategy caps retries at MAX_SYNC_RETRIES = 3', function () {
    $strategy = new SyncHookStrategy;
    $callCount = 0;

    $hook = new class($callCount) implements HookJobInterface
    {
        public function __construct(private int &$count) {}

        public function handle(HookContext $ctx): void
        {
            $this->count++;
            throw new RuntimeException('always fails');
        }

        public function shouldExecute(HookContext $ctx): bool
        {
            return true;
        }

        public function getPriority(): int
        {
            return 100;
        }

        public function getRetryAttempts(): int
        {
            return 10;
        } // Requests 10 but should be capped at 3

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
            return [];
        }

        public function execute(HookContext $ctx): void
        {
            $this->handle($ctx);
        }
    };

    try {
        $strategy->execute($hook, makeStrategyCtx());
    } catch (RuntimeException) {
        // Expected
    }

    // Should be capped at 3 (MAX_SYNC_RETRIES), not 10
    expect($callCount)->toBe(3);
});

test('SyncHookStrategy uses hook retry attempts when less than cap', function () {
    $strategy = new SyncHookStrategy;
    $callCount = 0;

    $hook = new class($callCount) implements HookJobInterface
    {
        public function __construct(private int &$count) {}

        public function handle(HookContext $ctx): void
        {
            $this->count++;
            throw new RuntimeException('always fails');
        }

        public function shouldExecute(HookContext $ctx): bool
        {
            return true;
        }

        public function getPriority(): int
        {
            return 100;
        }

        public function getRetryAttempts(): int
        {
            return 2;
        } // Less than cap of 3

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
            return [];
        }

        public function execute(HookContext $ctx): void
        {
            $this->handle($ctx);
        }
    };

    try {
        $strategy->execute($hook, makeStrategyCtx());
    } catch (RuntimeException) {
        // Expected
    }

    expect($callCount)->toBe(2);
});

// --- BatchedHookStrategy edge cases ---

test('BatchedHookStrategy makeCacheKey returns deterministic sha256 hash', function () {
    $key1 = BatchedHookStrategy::makeCacheKey('test_key');
    $key2 = BatchedHookStrategy::makeCacheKey('test_key');
    $key3 = BatchedHookStrategy::makeCacheKey('different_key');

    expect($key1)->toBe($key2)
        ->and($key1)->not->toBe($key3)
        ->and($key1)->toStartWith('laravel-hooks:batch:');
});

test('BatchedHookStrategy processNow does nothing for empty batch', function () {
    Queue::fake();

    BatchedHookStrategy::processNow('nonexistent_batch_key');

    Queue::assertNothingPushed();
});

// --- DelayedHookStrategy ---

test('DelayedHookStrategy defaults to 30 second delay', function () {
    $strategy = new DelayedHookStrategy;
    expect($strategy->getDelay())->toBe(30);
});

test('DelayedHookStrategy supportsRetry returns true', function () {
    expect((new DelayedHookStrategy)->supportsRetry())->toBeTrue();
});

// --- QueuedHookStrategy ---

test('QueuedHookStrategy supportsRetry returns true', function () {
    expect((new QueuedHookStrategy)->supportsRetry())->toBeTrue();
});

// --- ConditionalHookStrategy exception handling ---

test('ConditionalHookStrategy catches exception from condition and skips execution', function () {
    $inner = new SyncHookStrategy;
    $strategy = new ConditionalHookStrategy($inner);
    $strategy->addCondition(fn () => throw new RuntimeException('condition failed'));

    $hook = new SpyHook;
    $strategy->execute($hook, makeStrategyCtx('create'));

    // Hook should NOT have executed — the throwing condition should be caught
    expect($hook->executed)->toBeEmpty();
});

test('ConditionalHookStrategy logs error when condition throws', function () {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $message === 'Conditional hook strategy condition threw an exception'
                && $context['error'] === 'boom';
        });

    $inner = new SyncHookStrategy;
    $strategy = new ConditionalHookStrategy($inner);
    $strategy->addCondition(fn () => throw new RuntimeException('boom'));

    $hook = new SpyHook;
    $strategy->execute($hook, makeStrategyCtx());
});

// --- BatchedHookStrategy atomic scheduling ---

test('BatchedHookStrategy does not schedule duplicate jobs for same batch key', function () {
    Queue::fake();

    $strategy = new BatchedHookStrategy(batchSize: 100, batchDelay: 60);
    $hook = new SpyHook;
    $ctx = makeStrategyCtx();

    // First execution should schedule
    $strategy->execute($hook, $ctx);

    // Second execution with same batch key should NOT schedule again (Cache::add returns false)
    $strategy->execute($hook, $ctx);

    // Only one BatchSchedulerJob should be pushed (the second is deduplicated)
    Queue::assertPushed(BatchSchedulerJob::class, 1);
});
