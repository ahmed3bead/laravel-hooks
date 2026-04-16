<?php

use Ahmed3bead\LaravelHooks\HookContext;
use Ahmed3bead\LaravelHooks\Contracts\HookJobInterface;
use Ahmed3bead\LaravelHooks\Contracts\HookExecutionStrategy;
use Ahmed3bead\LaravelHooks\Strategies\SyncHookStrategy;
use Ahmed3bead\LaravelHooks\Strategies\QueuedHookStrategy;
use Ahmed3bead\LaravelHooks\Strategies\DelayedHookStrategy;
use Ahmed3bead\LaravelHooks\Strategies\BatchedHookStrategy;
use Ahmed3bead\LaravelHooks\Strategies\ConditionalHookStrategy;
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

    public function shouldExecute(HookContext $context): bool { return $this->shouldRun; }
    public function getPriority(): int { return 100; }
    public function getRetryAttempts(): int { return 1; }
    public function getRetryDelay(): int { return 0; }
    public function getTimeout(): int { return 30; }
    public function isAsync(): bool { return false; }
    public function getQueueName(): string { return 'default'; }
    public function getMetadata(): array { return []; }
    public function execute(HookContext $context): void { $this->handle($context); }
}

function makeStrategyCtx(string $method = 'create'): HookContext
{
    return new HookContext($method, 'after', null, [], null, new stdClass());
}

// --- SyncHookStrategy ---

test('SyncHookStrategy getName returns sync', function () {
    expect((new SyncHookStrategy())->getName())->toBe('sync');
});

test('SyncHookStrategy supportsRetry returns true', function () {
    expect((new SyncHookStrategy())->supportsRetry())->toBeTrue();
});

test('SyncHookStrategy executes the hook', function () {
    $strategy = new SyncHookStrategy();
    $hook = new SpyHook();

    $strategy->execute($hook, makeStrategyCtx('create'));

    expect($hook->executed)->toContain('create');
});

test('SyncHookStrategy skips hook when shouldExecute is false', function () {
    $strategy = new SyncHookStrategy();
    $hook = new SpyHook();
    $hook->shouldRun = false;

    $strategy->execute($hook, makeStrategyCtx('create'));

    expect($hook->executed)->toBeEmpty();
});

test('SyncHookStrategy retries on failure', function () {
    $strategy = new SyncHookStrategy();
    $attempts = 0;

    $hook = new class($attempts) implements HookJobInterface {
        public int $callCount = 0;
        public function __construct(public int &$ref) {}
        public function handle(HookContext $ctx): void {
            $this->callCount++;
            $this->ref++;
            if ($this->callCount < 2) throw new \RuntimeException('fail');
        }
        public function shouldExecute(HookContext $ctx): bool { return true; }
        public function getPriority(): int { return 100; }
        public function getRetryAttempts(): int { return 3; }
        public function getRetryDelay(): int { return 0; }
        public function getTimeout(): int { return 30; }
        public function isAsync(): bool { return false; }
        public function getQueueName(): string { return 'default'; }
        public function getMetadata(): array { return []; }
        public function execute(HookContext $ctx): void { $this->handle($ctx); }
    };

    $strategy->execute($hook, makeStrategyCtx());
    expect($attempts)->toBe(2);
});

// --- QueuedHookStrategy ---

test('QueuedHookStrategy getName returns queue', function () {
    expect((new QueuedHookStrategy())->getName())->toBe('queue');
});

test('QueuedHookStrategy pushes job to queue', function () {
    Queue::fake();

    $strategy = new QueuedHookStrategy();
    $hook = new SpyHook();

    $strategy->execute($hook, makeStrategyCtx());

    Queue::assertPushed(\Ahmed3bead\LaravelHooks\Jobs\QueuedHookJob::class);
});

// --- DelayedHookStrategy ---

test('DelayedHookStrategy getName returns delay', function () {
    expect((new DelayedHookStrategy())->getName())->toBe('delay');
});

test('DelayedHookStrategy setDelay and getDelay work', function () {
    $strategy = new DelayedHookStrategy(30);
    $strategy->setDelay(120);
    expect($strategy->getDelay())->toBe(120);
});

test('DelayedHookStrategy pushes job to queue with delay', function () {
    Queue::fake();

    $strategy = new DelayedHookStrategy(60);
    $hook = new SpyHook();

    $strategy->execute($hook, makeStrategyCtx());

    Queue::assertPushed(\Ahmed3bead\LaravelHooks\Jobs\QueuedHookJob::class);
});

// --- BatchedHookStrategy ---

test('BatchedHookStrategy getName returns batch', function () {
    expect((new BatchedHookStrategy())->getName())->toBe('batch');
});

test('BatchedHookStrategy supportsRetry returns true', function () {
    expect((new BatchedHookStrategy())->supportsRetry())->toBeTrue();
});

test('BatchedHookStrategy collects items and processes when batch size reached', function () {
    Queue::fake();

    $strategy = new BatchedHookStrategy(batchSize: 2, batchDelay: 0);
    $hook = new SpyHook();
    $ctx = makeStrategyCtx();

    $strategy->execute($hook, $ctx);
    $strategy->execute($hook, $ctx); // triggers batch

    Queue::assertPushed(\Ahmed3bead\LaravelHooks\Jobs\BatchProcessorJob::class);
});

test('BatchedHookStrategy flushBatches dispatches remaining items', function () {
    Queue::fake();

    $strategy = new BatchedHookStrategy(batchSize: 100);
    $hook = new SpyHook();

    $strategy->execute($hook, makeStrategyCtx());
    BatchedHookStrategy::flushBatches();

    Queue::assertPushed(\Ahmed3bead\LaravelHooks\Jobs\BatchProcessorJob::class);
});

test('BatchedHookStrategy setBatchSize and setBatchDelay work', function () {
    $strategy = new BatchedHookStrategy();
    $strategy->setBatchSize(5)->setBatchDelay(30)->setBatchKey('custom');

    // No public getters - just verify method chaining returns self
    expect($strategy)->toBeInstanceOf(BatchedHookStrategy::class);
});

// --- ConditionalHookStrategy ---

test('ConditionalHookStrategy getName includes wrapped strategy name', function () {
    $inner = new SyncHookStrategy();
    $strategy = new ConditionalHookStrategy($inner);
    expect($strategy->getName())->toBe('conditional_sync');
});

test('ConditionalHookStrategy delegates execution when conditions pass', function () {
    $inner = new SyncHookStrategy();
    $strategy = new ConditionalHookStrategy($inner);
    $strategy->addCondition(fn () => true);

    $hook = new SpyHook();
    $strategy->execute($hook, makeStrategyCtx('update'));

    expect($hook->executed)->toContain('update');
});

test('ConditionalHookStrategy skips execution when condition fails', function () {
    $inner = new SyncHookStrategy();
    $strategy = new ConditionalHookStrategy($inner);
    $strategy->addCondition(fn () => false);

    $hook = new SpyHook();
    $strategy->execute($hook, makeStrategyCtx('update'));

    expect($hook->executed)->toBeEmpty();
});

test('ConditionalHookStrategy supportsRetry delegates to wrapped strategy', function () {
    $inner = new SyncHookStrategy();
    $strategy = new ConditionalHookStrategy($inner);
    expect($strategy->supportsRetry())->toBeTrue();
});

test('ConditionalHookStrategy onlyInEnvironment works', function () {
    $inner = new SyncHookStrategy();
    $strategy = new ConditionalHookStrategy($inner);
    $strategy->onlyInEnvironment('testing');

    $hook = new SpyHook();
    $strategy->execute($hook, makeStrategyCtx());

    // In Orchestra Testbench the environment is 'testing'
    expect($hook->executed)->toHaveCount(1);
});
