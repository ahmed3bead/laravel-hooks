<?php

use Ahmed3bead\LaravelHooks\Contracts\HookJobInterface;
use Ahmed3bead\LaravelHooks\HookableTrait;
use Ahmed3bead\LaravelHooks\HookContext;
use Ahmed3bead\LaravelHooks\HookManager;
use Ahmed3bead\LaravelHooks\HookRegistry;

// Simple hook that records calls
class TraitTestHook implements HookJobInterface
{
    public static array $calls = [];

    public function handle(HookContext $context): void
    {
        self::$calls[] = ['method' => $context->method, 'phase' => $context->phase];
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
        return [];
    }

    public function execute(HookContext $context): void
    {
        $this->handle($context);
    }
}

// Service class using the trait — exposes protected methods as public for testing
class TraitTestService
{
    use HookableTrait;

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
            throw new RuntimeException('operation failed');
        });
    }

    // Public proxies so tests can register hooks from outside the class
    public function registerSync(string $phase, string $method, string $hookClass): void
    {
        $this->addServiceSyncHook($phase, $method, $hookClass);
    }

    public function registerQueued(string $phase, string $method, string $hookClass): void
    {
        $this->addServiceQueuedHook($phase, $method, $hookClass);
    }

    public function registerDelayed(string $phase, string $method, string $hookClass, int $delay = 30): void
    {
        $this->addServiceDelayedHook($phase, $method, $hookClass, $delay);
    }

    public function registerBatched(string $phase, string $method, string $hookClass): void
    {
        $this->addServiceBatchedHook($phase, $method, $hookClass);
    }

    public function removeHooksFor(string $method, string $phase): void
    {
        $this->removeServiceHooks($method, $phase);
    }

    public function removeHookFor(string $method, string $phase, string $hookClass): void
    {
        $this->removeServiceHook($method, $phase, $hookClass);
    }

    public function toggleHooks(bool $enabled): void
    {
        $this->enableServiceHooks($enabled);
    }

    public function stats(): array
    {
        return $this->getHookStats();
    }

    public function registerForMethods(string $phase, array $methods, string $hookClass): void
    {
        $this->addHookForMethods($phase, $methods, $hookClass);
    }
}

beforeEach(function () {
    TraitTestHook::$calls = [];
    // Bind a fresh HookManager into the container
    app()->singleton(HookManager::class, fn () => new HookManager(new HookRegistry));
});

test('executeWithHooks fires before and after hooks', function () {
    $service = new TraitTestService;
    $service->registerSync('before', 'create', TraitTestHook::class);
    $service->registerSync('after', 'create', TraitTestHook::class);

    $result = $service->create();

    expect($result)->toBe('created')
        ->and(TraitTestHook::$calls)->toHaveCount(2)
        ->and(TraitTestHook::$calls[0]['phase'])->toBe('before')
        ->and(TraitTestHook::$calls[1]['phase'])->toBe('after');
});

test('executeWithHooks fires error hooks and rethrows', function () {
    $service = new TraitTestService;
    $service->registerSync('error', 'fail', TraitTestHook::class);

    expect(fn () => $service->fail())->toThrow(RuntimeException::class, 'operation failed');

    expect(TraitTestHook::$calls)->toHaveCount(1)
        ->and(TraitTestHook::$calls[0]['phase'])->toBe('error');
});

test('syncHook registers a sync hook for a given phase', function () {
    $service = new TraitTestService;
    $service->syncHook('before', 'create', TraitTestHook::class);
    $service->syncHook('after', 'create', TraitTestHook::class);
    $service->syncHook('error', 'create', TraitTestHook::class);

    $stats = $service->stats();
    expect($stats['total_service_hooks'])->toBe(3);
});

test('syncHook fires before and after like beforeHook/afterHook', function () {
    $service = new TraitTestService;
    $service->syncHook('before', 'create', TraitTestHook::class);
    $service->syncHook('after', 'create', TraitTestHook::class);

    $result = $service->create();

    expect($result)->toBe('created')
        ->and(TraitTestHook::$calls)->toHaveCount(2)
        ->and(TraitTestHook::$calls[0]['phase'])->toBe('before')
        ->and(TraitTestHook::$calls[1]['phase'])->toBe('after');
});

test('addServiceQueuedHook registers a queued hook', function () {
    $service = new TraitTestService;
    $service->registerQueued('after', 'create', TraitTestHook::class);

    $stats = $service->stats();
    expect($stats['total_service_hooks'])->toBe(1);
});

test('addServiceDelayedHook registers a delayed hook', function () {
    $service = new TraitTestService;
    $service->registerDelayed('after', 'create', TraitTestHook::class, 60);

    $stats = $service->stats();
    expect($stats['total_service_hooks'])->toBe(1);
});

test('addServiceBatchedHook registers a batched hook', function () {
    $service = new TraitTestService;
    $service->registerBatched('after', 'create', TraitTestHook::class);

    $stats = $service->stats();
    expect($stats['total_service_hooks'])->toBe(1);
});

test('removeServiceHooks clears hooks for a method', function () {
    $service = new TraitTestService;
    $service->registerSync('after', 'create', TraitTestHook::class);
    $service->removeHooksFor('create', 'after');

    $stats = $service->stats();
    expect($stats['total_hooks'])->toBe(0);
});

test('removeServiceHook removes a specific hook', function () {
    $service = new TraitTestService;
    $service->registerSync('after', 'create', TraitTestHook::class);
    $service->removeHookFor('create', 'after', TraitTestHook::class);

    $stats = $service->stats();
    expect($stats['total_hooks'])->toBe(0);
});

test('enableServiceHooks and disableHooks toggle execution', function () {
    $service = new TraitTestService;
    $service->registerSync('after', 'create', TraitTestHook::class);
    $service->toggleHooks(false);

    $service->create();

    expect(TraitTestHook::$calls)->toBeEmpty();
});

test('methodSupportsHooks returns true when hookableMethods is empty', function () {
    $service = new TraitTestService;
    // Reflection to access protected method
    $ref = new ReflectionMethod($service, 'methodSupportsHooks');
    $ref->setAccessible(true);

    expect($ref->invoke($service, 'anyMethod'))->toBeTrue();
});

test('addHookForMethods registers hook for multiple methods', function () {
    $service = new TraitTestService;
    $service->registerForMethods('after', ['create', 'update'], TraitTestHook::class);

    $stats = $service->stats();
    expect($stats['total_service_hooks'])->toBe(2);
});
