<?php

use Ahmed3bead\LaravelHooks\HookContext;
use Ahmed3bead\LaravelHooks\ServiceHookTrait;
use Ahmed3bead\LaravelHooks\HookManager;
use Ahmed3bead\LaravelHooks\HookRegistry;
use Ahmed3bead\LaravelHooks\Contracts\HookJobInterface;

// Simple hook that records calls
class TraitTestHook implements HookJobInterface
{
    public static array $calls = [];

    public function handle(HookContext $context): void
    {
        self::$calls[] = ['method' => $context->method, 'phase' => $context->phase];
    }

    public function shouldExecute(HookContext $context): bool { return true; }
    public function getPriority(): int { return 100; }
    public function getRetryAttempts(): int { return 1; }
    public function getRetryDelay(): int { return 0; }
    public function getTimeout(): int { return 30; }
    public function isAsync(): bool { return false; }
    public function getQueueName(): string { return 'default'; }
    public function getMetadata(): array { return []; }
    public function execute(HookContext $context): void { $this->handle($context); }
}

// Service class using the trait
class TraitTestService
{
    use ServiceHookTrait;

    public function create(): string
    {
        return $this->executeWithHooks('create', function () {
            return 'created';
        });
    }

    public function update(): string
    {
        return $this->executeWithHooks('update', function () {
            return 'updated';
        });
    }

    public function fail(): never
    {
        $this->executeWithHooks('fail', function () {
            throw new \RuntimeException('operation failed');
        });
    }
}

beforeEach(function () {
    TraitTestHook::$calls = [];
    // Bind a fresh HookManager into the container
    app()->singleton(HookManager::class, fn () => new HookManager(new HookRegistry()));
});

test('executeWithHooks fires before and after hooks', function () {
    $service = new TraitTestService();
    $service->addServiceSyncHook('before', 'create', TraitTestHook::class);
    $service->addServiceSyncHook('after', 'create', TraitTestHook::class);

    $result = $service->create();

    expect($result)->toBe('created')
        ->and(TraitTestHook::$calls)->toHaveCount(2)
        ->and(TraitTestHook::$calls[0]['phase'])->toBe('before')
        ->and(TraitTestHook::$calls[1]['phase'])->toBe('after');
});

test('executeWithHooks fires error hooks and rethrows', function () {
    $service = new TraitTestService();
    $service->addServiceSyncHook('error', 'fail', TraitTestHook::class);

    expect(fn () => $service->fail())->toThrow(\RuntimeException::class, 'operation failed');

    expect(TraitTestHook::$calls)->toHaveCount(1)
        ->and(TraitTestHook::$calls[0]['phase'])->toBe('error');
});

test('addServiceQueuedHook registers a queued hook', function () {
    $service = new TraitTestService();
    $service->addServiceQueuedHook('after', 'create', TraitTestHook::class);

    $stats = $service->getHookStats();
    expect($stats['total_service_hooks'])->toBe(1);
});

test('addServiceDelayedHook registers a delayed hook', function () {
    $service = new TraitTestService();
    $service->addServiceDelayedHook('after', 'create', TraitTestHook::class, 60);

    $stats = $service->getHookStats();
    expect($stats['total_service_hooks'])->toBe(1);
});

test('addServiceBatchedHook registers a batched hook', function () {
    $service = new TraitTestService();
    $service->addServiceBatchedHook('after', 'create', TraitTestHook::class);

    $stats = $service->getHookStats();
    expect($stats['total_service_hooks'])->toBe(1);
});

test('removeServiceHooks clears hooks for a method', function () {
    $service = new TraitTestService();
    $service->addServiceSyncHook('after', 'create', TraitTestHook::class);
    $service->removeServiceHooks('create', 'after');

    $stats = $service->getHookStats();
    expect($stats['total_hooks'])->toBe(0);
});

test('removeServiceHook removes a specific hook', function () {
    $service = new TraitTestService();
    $service->addServiceSyncHook('after', 'create', TraitTestHook::class);
    $service->removeServiceHook('create', 'after', TraitTestHook::class);

    $stats = $service->getHookStats();
    expect($stats['total_hooks'])->toBe(0);
});

test('enableServiceHooks and disableHooks toggle execution', function () {
    $service = new TraitTestService();
    $service->addServiceSyncHook('after', 'create', TraitTestHook::class);
    $service->enableServiceHooks(false);

    $service->create();

    expect(TraitTestHook::$calls)->toBeEmpty();
});

test('methodSupportsHooks returns true when hookableMethods is empty', function () {
    $service = new TraitTestService();
    // Reflection to access protected method
    $ref = new \ReflectionMethod($service, 'methodSupportsHooks');
    $ref->setAccessible(true);

    expect($ref->invoke($service, 'anyMethod'))->toBeTrue();
});

test('addHookForMethods registers hook for multiple methods', function () {
    $service = new TraitTestService();
    // Access protected method via reflection
    $ref = new \ReflectionMethod($service, 'addHookForMethods');
    $ref->setAccessible(true);
    $ref->invoke($service, 'after', ['create', 'update'], TraitTestHook::class);

    $stats = $service->getHookStats();
    expect($stats['total_service_hooks'])->toBe(2);
});
