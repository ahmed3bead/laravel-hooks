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
        $this->addSyncHookRegistration($phase, $method, $hookClass);
    }

    public function registerQueued(string $phase, string $method, string $hookClass): void
    {
        $this->addQueuedHookRegistration($phase, $method, $hookClass);
    }

    public function registerDelayed(string $phase, string $method, string $hookClass, int $delay = 30): void
    {
        $this->addDelayedHookRegistration($phase, $method, $hookClass, $delay);
    }

    public function registerBatched(string $phase, string $method, string $hookClass): void
    {
        $this->addBatchedHookRegistration($phase, $method, $hookClass);
    }

    public function removeHooksFor(string $method, string $phase): void
    {
        $this->removeHooks($method, $phase);
    }

    public function removeHookFor(string $method, string $phase, string $hookClass): void
    {
        $this->removeHook($method, $phase, $hookClass);
    }

    public function toggleHooks(bool $enabled): void
    {
        $this->enableHooks($enabled);
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
    expect($stats['total_target_hooks'])->toBe(3);
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

test('addQueuedHookRegistration registers a queued hook', function () {
    $service = new TraitTestService;
    $service->registerQueued('after', 'create', TraitTestHook::class);

    $stats = $service->stats();
    expect($stats['total_target_hooks'])->toBe(1);
});

test('addDelayedHookRegistration registers a delayed hook', function () {
    $service = new TraitTestService;
    $service->registerDelayed('after', 'create', TraitTestHook::class, 60);

    $stats = $service->stats();
    expect($stats['total_target_hooks'])->toBe(1);
});

test('addBatchedHookRegistration registers a batched hook', function () {
    $service = new TraitTestService;
    $service->registerBatched('after', 'create', TraitTestHook::class);

    $stats = $service->stats();
    expect($stats['total_target_hooks'])->toBe(1);
});

test('removeHooks clears hooks for a method', function () {
    $service = new TraitTestService;
    $service->registerSync('after', 'create', TraitTestHook::class);
    $service->removeHooksFor('create', 'after');

    $stats = $service->stats();
    expect($stats['total_hooks'])->toBe(0);
});

test('removeHook removes a specific hook', function () {
    $service = new TraitTestService;
    $service->registerSync('after', 'create', TraitTestHook::class);
    $service->removeHookFor('create', 'after', TraitTestHook::class);

    $stats = $service->stats();
    expect($stats['total_hooks'])->toBe(0);
});

test('enableHooks and disableHooks toggle execution', function () {
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
    expect($stats['total_target_hooks'])->toBe(2);
});

test('syncHookWithLogic fires an inline callback as a before hook', function () {
    $service = new TraitTestService;
    $called = [];

    $service->syncHookWithLogic('before', 'create', function ($ctx) use (&$called) {
        $called[] = $ctx->phase;
    });

    $service->create();

    expect($called)->toBe(['before']);
});

test('syncHookWithLogic fires an inline callback as an after hook', function () {
    $service = new TraitTestService;
    $called = [];

    $service->syncHookWithLogic('after', 'create', function ($ctx) use (&$called) {
        $called[] = $ctx->method;
    });

    $result = $service->create();

    expect($result)->toBe('created')
        ->and($called)->toBe(['create']);
});

test('hookWithLogic fires inline callbacks for before and after', function () {
    $service = new TraitTestService;
    $log = [];

    $service->hookWithLogic('before', 'update', function ($ctx) use (&$log) {
        $log[] = 'before:'.$ctx->phase;
    });
    $service->hookWithLogic('after', 'update', function ($ctx) use (&$log) {
        $log[] = 'after:'.$ctx->phase;
    });

    $service->update();

    expect($log)->toBe(['before:before', 'after:after']);
});
