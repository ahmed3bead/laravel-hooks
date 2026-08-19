<?php

use Ahmed3bead\LaravelHooks\Contracts\HookJobInterface;
use Ahmed3bead\LaravelHooks\HookContext;
use Ahmed3bead\LaravelHooks\Jobs\BatchProcessorJob;
use Ahmed3bead\LaravelHooks\Jobs\BatchSchedulerJob;
use Ahmed3bead\LaravelHooks\Jobs\HookChainJob;
use Ahmed3bead\LaravelHooks\Jobs\QueuedHookJob;
use Ahmed3bead\LaravelHooks\Strategies\BatchedHookStrategy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

// --- Test fixtures ---

class JobsTestHook implements HookJobInterface
{
    public static array $calls = [];

    public static bool $shouldRun = true;

    public static bool $shouldFail = false;

    public function handle(HookContext $context): void
    {
        if (static::$shouldFail) {
            throw new RuntimeException('hook failed');
        }
        static::$calls[] = ['method' => $context->method, 'phase' => $context->phase];
    }

    public function shouldExecute(HookContext $context): bool
    {
        return static::$shouldRun;
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
        return 5;
    }

    public function getTimeout(): int
    {
        return 60;
    }

    public function isAsync(): bool
    {
        return false;
    }

    public function getQueueName(): string
    {
        return 'hooks';
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

function makeJobsCtx(string $method = 'create', string $phase = 'after'): HookContext
{
    return new HookContext($method, $phase, null, [], null, new stdClass);
}

beforeEach(function () {
    JobsTestHook::$calls = [];
    JobsTestHook::$shouldRun = true;
    JobsTestHook::$shouldFail = false;
});

// =====================================================
// QueuedHookJob
// =====================================================

test('QueuedHookJob constructor sets tries, timeout, backoff from hook', function () {
    $hook = new JobsTestHook;
    $ctx = makeJobsCtx();
    $job = new QueuedHookJob($hook, $ctx);

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(60)
        ->and($job->backoff)->toBe(5);
});

test('QueuedHookJob handle executes hook when shouldExecute is true', function () {
    Log::shouldReceive('info')->atLeast()->times(2);

    $hook = new JobsTestHook;
    $ctx = makeJobsCtx('create', 'after');
    $job = new QueuedHookJob($hook, $ctx);

    $job->handle();

    expect(JobsTestHook::$calls)->toHaveCount(1)
        ->and(JobsTestHook::$calls[0]['method'])->toBe('create');
});

test('QueuedHookJob handle skips hook when shouldExecute is false', function () {
    Log::shouldReceive('info')->atLeast()->once();

    JobsTestHook::$shouldRun = false;
    $hook = new JobsTestHook;
    $ctx = makeJobsCtx();
    $job = new QueuedHookJob($hook, $ctx);

    $job->handle();

    expect(JobsTestHook::$calls)->toBeEmpty();
});

test('QueuedHookJob handle rethrows exception on failure', function () {
    Log::shouldReceive('info')->atLeast()->once();
    Log::shouldReceive('error')->once();

    JobsTestHook::$shouldFail = true;
    $hook = new JobsTestHook;
    $ctx = makeJobsCtx();
    $job = new QueuedHookJob($hook, $ctx);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'hook failed');
});

test('QueuedHookJob failed logs permanently failed message', function () {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $data) {
            return $message === 'Queued hook job failed permanently'
                && isset($data['hook'])
                && isset($data['error']);
        });

    $hook = new JobsTestHook;
    $ctx = makeJobsCtx();
    $job = new QueuedHookJob($hook, $ctx);

    $job->failed(new RuntimeException('permanent failure'));
});

test('QueuedHookJob tags returns correct tags', function () {
    $hook = new JobsTestHook;
    $ctx = makeJobsCtx('update', 'before');
    $job = new QueuedHookJob($hook, $ctx);

    $tags = $job->tags();

    expect($tags)->toContain('hook:JobsTestHook')
        ->and($tags)->toContain('method:update')
        ->and($tags)->toContain('phase:before')
        ->and($tags)->toContain('target:stdClass');
});

// =====================================================
// HookChainJob
// =====================================================

test('HookChainJob handle executes all hooks in sequence', function () {
    Log::shouldReceive('info')->atLeast()->times(2);

    $hook1 = new JobsTestHook;
    $hook2 = new JobsTestHook;
    $ctx = makeJobsCtx('create', 'after');
    $job = new HookChainJob([$hook1, $hook2], $ctx);

    $job->handle();

    expect(JobsTestHook::$calls)->toHaveCount(2);
});

test('HookChainJob handle stops on failure when stopOnFailure is true', function () {
    Log::shouldReceive('info')->atLeast()->once();
    Log::shouldReceive('error')->once();

    JobsTestHook::$shouldFail = true;
    $hook1 = new JobsTestHook;
    $hook2 = new JobsTestHook;
    $ctx = makeJobsCtx();
    $job = new HookChainJob([$hook1, $hook2], $ctx, stopOnFailure: true);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'hook failed');
});

test('HookChainJob handle continues on failure when stopOnFailure is false', function () {
    Log::shouldReceive('info')->atLeast()->times(2);
    Log::shouldReceive('error')->once();

    // First hook fails, second succeeds
    $failingHook = new class implements HookJobInterface
    {
        public function handle(HookContext $ctx): void
        {
            throw new RuntimeException('fail');
        }

        public function shouldExecute(HookContext $ctx): bool { return true; }

        public function getPriority(): int { return 100; }

        public function getRetryAttempts(): int { return 1; }

        public function getRetryDelay(): int { return 0; }

        public function getTimeout(): int { return 30; }

        public function isAsync(): bool { return false; }

        public function getQueueName(): string { return 'default'; }

        public function getMetadata(): array { return []; }

        public function execute(HookContext $ctx): void { $this->handle($ctx); }
    };

    $successHook = new JobsTestHook;
    $ctx = makeJobsCtx();
    $job = new HookChainJob([$failingHook, $successHook], $ctx, stopOnFailure: false);

    $job->handle();

    expect(JobsTestHook::$calls)->toHaveCount(1);
});

test('HookChainJob handle skips hooks where shouldExecute is false', function () {
    Log::shouldReceive('info')->atLeast()->times(2);

    JobsTestHook::$shouldRun = false;
    $hook = new JobsTestHook;
    $ctx = makeJobsCtx();
    $job = new HookChainJob([$hook], $ctx);

    $job->handle();

    expect(JobsTestHook::$calls)->toBeEmpty();
});

test('HookChainJob failed logs error', function () {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $data) {
            return $message === 'Hook chain job failed'
                && isset($data['hooks_count'])
                && isset($data['error']);
        });

    $hook = new JobsTestHook;
    $ctx = makeJobsCtx();
    $job = new HookChainJob([$hook], $ctx);

    $job->failed(new RuntimeException('chain failed'));
});

test('HookChainJob tags returns correct tags', function () {
    $hook1 = new JobsTestHook;
    $hook2 = new JobsTestHook;
    $ctx = makeJobsCtx('delete', 'after');
    $job = new HookChainJob([$hook1, $hook2], $ctx);

    $tags = $job->tags();

    expect($tags)->toContain('hook-chain')
        ->and($tags)->toContain('method:delete')
        ->and($tags)->toContain('phase:after')
        ->and($tags)->toContain('count:2');
});

test('HookChainJob has correct defaults', function () {
    $hook = new JobsTestHook;
    $ctx = makeJobsCtx();
    $job = new HookChainJob([$hook], $ctx);

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(600);
});

// =====================================================
// BatchSchedulerJob
// =====================================================

test('BatchSchedulerJob handle calls BatchedHookStrategy processNow', function () {
    Queue::fake();
    Log::shouldReceive('debug')->once();

    $job = new BatchSchedulerJob('test_batch_key', 10);
    $job->handle();

    // processNow was called - if there's nothing in cache, no job dispatched
    Queue::assertNothingPushed();
});

test('BatchSchedulerJob tags returns correct tags', function () {
    $job = new BatchSchedulerJob('my_batch', 5);

    $tags = $job->tags();

    expect($tags)->toContain('batch-scheduler')
        ->and($tags)->toContain('key:my_batch');
});

test('BatchSchedulerJob has correct defaults', function () {
    $job = new BatchSchedulerJob('key', 0);

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(60);
});

// =====================================================
// BatchProcessorJob
// =====================================================

test('BatchProcessorJob handle processes batch items successfully', function () {
    Log::shouldReceive('info')->atLeast()->times(2);

    $hook = new JobsTestHook;
    $ctx = makeJobsCtx('create', 'after');
    $batch = [
        ['hook' => $hook, 'context' => $ctx],
        ['hook' => $hook, 'context' => $ctx],
    ];

    $job = new BatchProcessorJob($batch);
    $job->handle();

    expect(JobsTestHook::$calls)->toHaveCount(2);
});

test('BatchProcessorJob handle continues on individual item failure', function () {
    Log::shouldReceive('info')->atLeast()->times(2);
    Log::shouldReceive('error')->once();

    $failingHook = new class implements HookJobInterface
    {
        public function handle(HookContext $ctx): void
        {
            throw new RuntimeException('batch item fail');
        }

        public function shouldExecute(HookContext $ctx): bool { return true; }

        public function getPriority(): int { return 100; }

        public function getRetryAttempts(): int { return 1; }

        public function getRetryDelay(): int { return 0; }

        public function getTimeout(): int { return 30; }

        public function isAsync(): bool { return false; }

        public function getQueueName(): string { return 'default'; }

        public function getMetadata(): array { return []; }

        public function execute(HookContext $ctx): void { $this->handle($ctx); }
    };

    $successHook = new JobsTestHook;
    $ctx = makeJobsCtx();
    $batch = [
        ['hook' => $failingHook, 'context' => $ctx],
        ['hook' => $successHook, 'context' => $ctx],
    ];

    $job = new BatchProcessorJob($batch);
    $job->handle();

    expect(JobsTestHook::$calls)->toHaveCount(1);
});

test('BatchProcessorJob handle skips items where shouldExecute is false', function () {
    Log::shouldReceive('info')->atLeast()->times(2);
    Log::shouldReceive('debug')->once();

    JobsTestHook::$shouldRun = false;
    $hook = new JobsTestHook;
    $ctx = makeJobsCtx();
    $batch = [['hook' => $hook, 'context' => $ctx]];

    $job = new BatchProcessorJob($batch);
    $job->handle();

    expect(JobsTestHook::$calls)->toBeEmpty();
});

test('BatchProcessorJob resolveHook resolves cache-backed format', function () {
    Log::shouldReceive('info')->atLeast()->times(2);

    $ctx = makeJobsCtx();
    $batch = [
        ['hook_class' => JobsTestHook::class, 'context' => $ctx],
    ];

    $job = new BatchProcessorJob($batch);
    $job->handle();

    expect(JobsTestHook::$calls)->toHaveCount(1);
});

test('BatchProcessorJob resolveHook throws for non-existent class', function () {
    Log::shouldReceive('info')->atLeast()->once();
    Log::shouldReceive('error')->once();

    $ctx = makeJobsCtx();
    $batch = [
        ['hook_class' => 'NonExistent\\FakeHook', 'context' => $ctx],
    ];

    $job = new BatchProcessorJob($batch);
    // Should not throw - batch processor catches exceptions per item
    $job->handle();
});

test('BatchProcessorJob resolveHook throws for non-HookJobInterface class', function () {
    Log::shouldReceive('info')->atLeast()->once();
    Log::shouldReceive('error')->once();

    $ctx = makeJobsCtx();
    $batch = [
        ['hook_class' => stdClass::class, 'context' => $ctx],
    ];

    $job = new BatchProcessorJob($batch);
    $job->handle();
});

test('BatchProcessorJob resolveHook throws for non-string hook_class', function () {
    Log::shouldReceive('info')->atLeast()->once();
    Log::shouldReceive('error')->once();

    $ctx = makeJobsCtx();
    $batch = [
        ['hook_class' => 123, 'context' => $ctx],
    ];

    $job = new BatchProcessorJob($batch);
    $job->handle();
});

test('BatchProcessorJob resolveHook uses legacy format with hook object', function () {
    Log::shouldReceive('info')->atLeast()->times(2);

    $hook = new JobsTestHook;
    $ctx = makeJobsCtx();
    $batch = [
        ['hook' => $hook, 'context' => $ctx],
    ];

    $job = new BatchProcessorJob($batch);
    $job->handle();

    expect(JobsTestHook::$calls)->toHaveCount(1);
});

test('BatchProcessorJob failed logs error with batch size', function () {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $data) {
            return $message === 'Batch processor job failed'
                && $data['batch_size'] === 3
                && isset($data['error']);
        });

    $ctx = makeJobsCtx();
    $batch = [
        ['hook' => new JobsTestHook, 'context' => $ctx],
        ['hook' => new JobsTestHook, 'context' => $ctx],
        ['hook' => new JobsTestHook, 'context' => $ctx],
    ];

    $job = new BatchProcessorJob($batch);
    $job->failed(new RuntimeException('batch failed'));
});

test('BatchProcessorJob tags returns correct tags', function () {
    $ctx = makeJobsCtx();
    $batch = [
        ['hook' => new JobsTestHook, 'context' => $ctx],
        ['hook' => new JobsTestHook, 'context' => $ctx],
    ];

    $job = new BatchProcessorJob($batch);
    $tags = $job->tags();

    expect($tags)->toContain('batch-processor')
        ->and($tags)->toContain('size:2');
});

test('BatchProcessorJob has correct defaults', function () {
    $job = new BatchProcessorJob([]);

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(300);
});
