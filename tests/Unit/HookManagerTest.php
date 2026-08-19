<?php

use Ahmed3bead\LaravelHooks\Contracts\HookJobInterface;
use Ahmed3bead\LaravelHooks\HookContext;
use Ahmed3bead\LaravelHooks\HookManager;
use Ahmed3bead\LaravelHooks\HookRegistry;

// Concrete hook class for manager tests
class ManagerTestHook implements HookJobInterface
{
    public static array $handled = [];

    public function handle(HookContext $context): void
    {
        self::$handled[] = $context->method;
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

beforeEach(function () {
    ManagerTestHook::$handled = [];
});

test('addSyncHook registers a sync hook', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->addSyncHook('App\\Service', 'create', 'after', ManagerTestHook::class);

    $stats = $manager->getStats();
    expect($stats['total_target_hooks'])->toBe(1);
});

test('addQueuedHook registers a queued hook', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->addQueuedHook('App\\Service', 'create', 'after', ManagerTestHook::class);

    $stats = $manager->getStats();
    expect($stats['total_target_hooks'])->toBe(1);
});

test('addDelayedHook registers a delayed hook with delay option', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->addDelayedHook('App\\Service', 'create', 'after', ManagerTestHook::class, 60);

    $stats = $manager->getStats();
    expect($stats['total_target_hooks'])->toBe(1);
});

test('addBatchedHook registers a batched hook', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->addBatchedHook('App\\Service', 'create', 'after', ManagerTestHook::class);

    $stats = $manager->getStats();
    expect($stats['total_target_hooks'])->toBe(1);
});

test('addHook throws on invalid phase', function () {
    $manager = new HookManager(new HookRegistry);
    expect(fn () => $manager->addHook('App\\Service', 'create', 'invalid', ManagerTestHook::class))
        ->toThrow(InvalidArgumentException::class);
});

test('addHook throws on invalid strategy', function () {
    $manager = new HookManager(new HookRegistry);
    expect(fn () => $manager->addHook('App\\Service', 'create', 'after', ManagerTestHook::class, 'unknown'))
        ->toThrow(InvalidArgumentException::class);
});

test('executeHooks calls the registered hook', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->addSyncHook(stdClass::class, 'create', 'after', ManagerTestHook::class);

    $ctx = new HookContext('create', 'after', null, [], null, new stdClass);
    $manager->executeHooks($ctx);

    expect(ManagerTestHook::$handled)->toContain('create');
});

test('executeHooks does nothing when disabled', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->addSyncHook(stdClass::class, 'create', 'after', ManagerTestHook::class);
    $manager->disable();

    $ctx = new HookContext('create', 'after', null, [], null, new stdClass);
    $manager->executeHooks($ctx);

    expect(ManagerTestHook::$handled)->toBeEmpty();
});

test('enable and disable toggle hook execution', function () {
    $manager = new HookManager(new HookRegistry);
    expect($manager->isEnabled())->toBeTrue();

    $manager->disable();
    expect($manager->isEnabled())->toBeFalse();

    $manager->enable();
    expect($manager->isEnabled())->toBeTrue();
});

test('addMiddleware applies to context before execution', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->addSyncHook(stdClass::class, 'create', 'after', ManagerTestHook::class);

    $middlewareCalled = false;
    $manager->addMiddleware(function (HookContext $ctx) use (&$middlewareCalled) {
        $middlewareCalled = true;

        return $ctx;
    });

    $ctx = new HookContext('create', 'after', null, [], null, new stdClass);
    $manager->executeHooks($ctx);

    expect($middlewareCalled)->toBeTrue();
});

test('middleware must return HookContext', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->addSyncHook(stdClass::class, 'create', 'after', ManagerTestHook::class);
    $manager->addMiddleware(fn ($ctx) => 'not-a-context');

    $ctx = new HookContext('create', 'after', null, [], null, new stdClass);
    expect(fn () => $manager->executeHooks($ctx))->toThrow(RuntimeException::class);
});

test('clearMiddleware removes all middleware', function () {
    $manager = new HookManager(new HookRegistry);
    $called = false;
    $manager->addMiddleware(function ($ctx) use (&$called) {
        $called = true;

        return $ctx;
    });
    $manager->clearMiddleware();

    $ctx = new HookContext('create', 'after', null, [], null, new stdClass);
    $manager->executeHooks($ctx);

    expect($called)->toBeFalse();
});

test('createBeforeContext creates correct context', function () {
    $manager = new HookManager(new HookRegistry);
    $service = new stdClass;
    $ctx = $manager->createBeforeContext('create', ['data'], [], $service);

    expect($ctx->method)->toBe('create')
        ->and($ctx->phase)->toBe('before')
        ->and($ctx->result)->toBeNull()
        ->and($ctx->target)->toBe($service);
});

test('createAfterContext creates correct context', function () {
    $manager = new HookManager(new HookRegistry);
    $service = new stdClass;
    $ctx = $manager->createAfterContext('update', ['data'], [], 'result', $service);

    expect($ctx->method)->toBe('update')
        ->and($ctx->phase)->toBe('after')
        ->and($ctx->result)->toBe('result');
});

test('addHooks registers multiple hooks at once', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->addHooks([
        ['target' => 'App\\Service', 'method' => 'create', 'phase' => 'after', 'hook' => ManagerTestHook::class],
        ['target' => 'App\\Service', 'method' => 'update', 'phase' => 'after', 'hook' => ManagerTestHook::class],
    ]);

    $stats = $manager->getStats();
    expect($stats['total_target_hooks'])->toBe(2);
});

test('clearAll removes all registered hooks', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->addSyncHook('App\\Service', 'create', 'after', ManagerTestHook::class);
    $manager->clearAll();

    $stats = $manager->getStats();
    expect($stats['total_hooks'])->toBe(0);
});

test('getStats includes middleware count and debug mode', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->addMiddleware(fn ($c) => $c);
    $manager->setDebugMode(true);

    $stats = $manager->getStats();
    expect($stats['middleware_count'])->toBe(1)
        ->and($stats['debug_mode'])->toBeTrue();
});

// --- addHooks validation ---

test('addHooks throws on non-array definition', function () {
    $manager = new HookManager(new HookRegistry);
    expect(fn () => $manager->addHooks(['not_an_array']))
        ->toThrow(InvalidArgumentException::class, 'must be an array');
});

test('addHooks throws on missing required keys', function () {
    $manager = new HookManager(new HookRegistry);
    expect(fn () => $manager->addHooks([['target' => 'App\\Service']]))
        ->toThrow(InvalidArgumentException::class, 'missing required keys');
});

test('addHooks accepts service key as alias for target', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->addHooks([
        ['service' => 'App\\Service', 'method' => 'create', 'phase' => 'after', 'hook' => ManagerTestHook::class],
    ]);

    $stats = $manager->getStats();
    expect($stats['total_target_hooks'])->toBe(1);
});

// --- loadFromConfig ---

test('loadFromConfig registers hooks from config array', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->loadFromConfig([
        'App\\Service' => [
            'hooks' => [
                ['method' => 'create', 'phase' => 'after', 'hook' => ManagerTestHook::class],
                ['method' => 'update', 'phase' => 'before', 'hook' => ManagerTestHook::class],
            ],
        ],
    ]);

    $stats = $manager->getStats();
    expect($stats['total_target_hooks'])->toBe(2);
});

test('loadFromConfig registers global hooks', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->loadFromConfig([
        'global' => [
            'global_hooks' => [
                ['method' => 'create', 'phase' => 'after', 'hook' => ManagerTestHook::class],
            ],
        ],
    ]);

    $stats = $manager->getStats();
    expect($stats['total_global_hooks'])->toBe(1);
});

// --- Global hooks ---

test('addGlobalHook registers global hook', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->addGlobalHook('create', 'after', ManagerTestHook::class);

    $stats = $manager->getStats();
    expect($stats['total_global_hooks'])->toBe(1);
});

test('addGlobalHook throws on invalid phase', function () {
    $manager = new HookManager(new HookRegistry);
    expect(fn () => $manager->addGlobalHook('create', 'invalid', ManagerTestHook::class))
        ->toThrow(InvalidArgumentException::class);
});

// --- removeHooks and removeHook ---

test('removeHooks removes hooks for a target method', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->addSyncHook('App\\Service', 'create', 'after', ManagerTestHook::class);
    $manager->removeHooks('App\\Service', 'create', 'after');

    $stats = $manager->getStats();
    expect($stats['total_target_hooks'])->toBe(0);
});

test('removeHook removes a specific hook', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->addSyncHook('App\\Service', 'create', 'after', ManagerTestHook::class);
    $manager->removeHook('App\\Service', 'create', 'after', ManagerTestHook::class);

    $stats = $manager->getStats();
    expect($stats['total_target_hooks'])->toBe(0);
});

// --- registerStrategy ---

test('registerStrategy allows using custom strategy', function () {
    $manager = new HookManager(new HookRegistry);
    $customStrategy = new \Ahmed3bead\LaravelHooks\Strategies\SyncHookStrategy;
    $manager->registerStrategy('my_sync', $customStrategy);

    // Should not throw — strategy is now valid
    $manager->addHook('App\\Service', 'create', 'after', ManagerTestHook::class, 'my_sync');

    $stats = $manager->getStats();
    expect($stats['total_target_hooks'])->toBe(1);
});

// --- debugTarget ---

test('debugTarget returns hooks for a target class', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->addSyncHook('App\\Service', 'create', 'after', ManagerTestHook::class);

    $debug = $manager->debugTarget('App\\Service');
    expect($debug)->not->toBeEmpty();
});

// --- debugService deprecated ---

test('debugService triggers deprecation and delegates to debugTarget', function () {
    $manager = new HookManager(new HookRegistry);
    $manager->addSyncHook('App\\Service', 'create', 'after', ManagerTestHook::class);

    $result = @$manager->debugService('App\\Service');
    expect($result)->not->toBeEmpty();
});

// --- getRegistry ---

test('getRegistry returns the HookRegistry instance', function () {
    $registry = new HookRegistry;
    $manager = new HookManager($registry);

    expect($manager->getRegistry())->toBe($registry);
});

// --- executeHooks rethrows exception ---

test('executeHooks rethrows exception from hook execution', function () {
    $manager = new HookManager(new HookRegistry);

    $failingHookClass = 'ManagerFailHook_'.uniqid();
    eval("
        class {$failingHookClass} implements ".Ahmed3bead\LaravelHooks\Contracts\HookJobInterface::class.' {
            public function handle('.HookContext::class.' $ctx): void { throw new RuntimeException("test error"); }
            public function shouldExecute('.HookContext::class.' $ctx): bool { return true; }
            public function getPriority(): int { return 100; }
            public function getRetryAttempts(): int { return 1; }
            public function getRetryDelay(): int { return 0; }
            public function getTimeout(): int { return 30; }
            public function isAsync(): bool { return false; }
            public function getQueueName(): string { return "default"; }
            public function getMetadata(): array { return []; }
            public function execute('.HookContext::class.' $ctx): void { $this->handle($ctx); }
        }
    ');

    $manager->addSyncHook(stdClass::class, 'create', 'after', $failingHookClass, ['stop_on_failure' => true]);

    $ctx = new HookContext('create', 'after', null, [], null, new stdClass);
    expect(fn () => $manager->executeHooks($ctx))->toThrow(RuntimeException::class, 'test error');
});
