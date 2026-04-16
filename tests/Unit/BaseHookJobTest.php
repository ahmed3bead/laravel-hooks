<?php

use Ahmed3bead\LaravelHooks\BaseHookJob;
use Ahmed3bead\LaravelHooks\HookContext;

// Concrete implementation for testing
class ConcreteHookJob extends BaseHookJob
{
    public array $handled = [];
    public bool $shouldFail = false;
    public bool $beforeCalled = false;
    public bool $afterCalled = false;

    public function handle(HookContext $context): void
    {
        if ($this->shouldFail) {
            throw new \RuntimeException('Hook intentionally failed');
        }
        $this->handled[] = $context->method;
    }

    protected function beforeExecution(HookContext $context): void
    {
        $this->beforeCalled = true;
    }

    protected function afterExecution(HookContext $context): void
    {
        $this->afterCalled = true;
    }

    // Expose protected methods for testing
    public function exposeAddCondition(callable $condition, string $desc = ''): static
    {
        return $this->addCondition($condition, $desc);
    }

    public function exposeOnlyForMethods(array $methods): static
    {
        return $this->onlyForMethods($methods);
    }

    public function exposeOnlyForPhase(string $phase): static
    {
        return $this->onlyForPhase($phase);
    }
}

function makeCtx(string $method = 'create', string $phase = 'after'): HookContext
{
    return new HookContext($method, $phase, null, [], null, new stdClass());
}

test('execute calls handle and lifecycle methods', function () {
    $hook = new ConcreteHookJob();
    $hook->execute(makeCtx('create'));

    expect($hook->handled)->toContain('create')
        ->and($hook->beforeCalled)->toBeTrue()
        ->and($hook->afterCalled)->toBeTrue();
});

test('execute rethrows exceptions from handle', function () {
    $hook = new ConcreteHookJob();
    $hook->shouldFail = true;

    expect(fn () => $hook->execute(makeCtx()))->toThrow(RuntimeException::class);
});

test('shouldExecute returns true with no conditions', function () {
    $hook = new ConcreteHookJob();
    expect($hook->shouldExecute(makeCtx()))->toBeTrue();
});

test('shouldExecute returns false when condition fails', function () {
    $hook = new ConcreteHookJob();
    $hook->exposeAddCondition(fn ($ctx) => false, 'Always false');

    expect($hook->shouldExecute(makeCtx()))->toBeFalse();
});

test('shouldExecute returns true when all conditions pass', function () {
    $hook = new ConcreteHookJob();
    $hook->exposeAddCondition(fn ($ctx) => true, 'Always true')
         ->exposeAddCondition(fn ($ctx) => true, 'Also true');

    expect($hook->shouldExecute(makeCtx()))->toBeTrue();
});

test('onlyForMethods condition filters by method name', function () {
    $hook = new ConcreteHookJob();
    $hook->exposeOnlyForMethods(['create', 'update']);

    expect($hook->shouldExecute(makeCtx('create')))->toBeTrue()
        ->and($hook->shouldExecute(makeCtx('delete')))->toBeFalse();
});

test('onlyForPhase condition filters by phase', function () {
    $hook = new ConcreteHookJob();
    $hook->exposeOnlyForPhase('before');

    expect($hook->shouldExecute(makeCtx('create', 'before')))->toBeTrue()
        ->and($hook->shouldExecute(makeCtx('create', 'after')))->toBeFalse();
});

test('default priority is 100', function () {
    $hook = new ConcreteHookJob();
    expect($hook->getPriority())->toBe(100);
});

test('default retry attempts is 3', function () {
    $hook = new ConcreteHookJob();
    expect($hook->getRetryAttempts())->toBe(3);
});

test('default async is false', function () {
    $hook = new ConcreteHookJob();
    expect($hook->isAsync())->toBeFalse();
});

test('default queue name is default', function () {
    $hook = new ConcreteHookJob();
    expect($hook->getQueueName())->toBe('default');
});

test('getMetadata includes class, priority, async, queue', function () {
    $hook = new ConcreteHookJob();
    $meta = $hook->getMetadata();

    expect($meta)->toHaveKey('class')
        ->and($meta)->toHaveKey('priority')
        ->and($meta)->toHaveKey('async')
        ->and($meta)->toHaveKey('queue');
});
