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
