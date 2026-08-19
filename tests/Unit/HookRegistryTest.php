<?php

use Ahmed3bead\LaravelHooks\Contracts\HookExecutionStrategy;
use Ahmed3bead\LaravelHooks\Contracts\HookJobInterface;
use Ahmed3bead\LaravelHooks\HookContext;
use Ahmed3bead\LaravelHooks\HookRegistry;

// Minimal hook for testing
function makeHookClass(string $name = 'TestHook'): string
{
    $class = 'TestHook_'.$name.'_'.uniqid();
    eval("
        class {$class} implements ".HookJobInterface::class.' {
            public static array $calls = [];
            public function handle('.HookContext::class.' $ctx): void { self::$calls[] = $ctx; }
            public function shouldExecute('.HookContext::class." \$ctx): bool { return true; }
            public function getPriority(): int { return 100; }
            public function getRetryAttempts(): int { return 1; }
            public function getRetryDelay(): int { return 0; }
            public function getTimeout(): int { return 30; }
            public function isAsync(): bool { return false; }
            public function getQueueName(): string { return 'default'; }
            public function getMetadata(): array { return []; }
            public function execute(".HookContext::class.' $ctx): void { $this->handle($ctx); }
        }
    ');

    return $class;
}

function makeContext(string $method = 'create', string $phase = 'after'): HookContext
{
    $service = new stdClass;

    return new HookContext($method, $phase, null, [], null, $service);
}

test('registerHook stores a hook', function () {
    $registry = new HookRegistry;
    $hookClass = makeHookClass('Store');

    $registry->registerHook('App\\MyService', 'create', 'after', $hookClass);
    $hooks = $registry->getHooks('App\\MyService', 'create', 'after');

    expect($hooks)->toHaveCount(1)
        ->and($hooks[0]['class'])->toBe($hookClass);
});

test('registerGlobalHook stores a global hook', function () {
    $registry = new HookRegistry;
    $hookClass = makeHookClass('Global');

    $registry->registerGlobalHook('create', 'after', $hookClass);
    $hooks = $registry->getHooks('App\\AnyService', 'create', 'after');

    expect($hooks)->toHaveCount(1)
        ->and($hooks[0]['class'])->toBe($hookClass);
});

test('getHooks returns empty when disabled', function () {
    $registry = new HookRegistry;
    $hookClass = makeHookClass('Disabled');

    $registry->registerHook('App\\MyService', 'create', 'after', $hookClass);
    $registry->setEnabled(false);

    expect($registry->getHooks('App\\MyService', 'create', 'after'))->toBeEmpty();
});

test('hooks are sorted by priority', function () {
    $registry = new HookRegistry;
    $highClass = makeHookClass('High');
    $lowClass = makeHookClass('Low');

    $registry->registerHook('App\\MyService', 'create', 'after', $lowClass, 'sync', ['priority' => 200]);
    $registry->registerHook('App\\MyService', 'create', 'after', $highClass, 'sync', ['priority' => 10]);

    $hooks = $registry->getHooks('App\\MyService', 'create', 'after');

    expect($hooks[0]['class'])->toBe($highClass)
        ->and($hooks[1]['class'])->toBe($lowClass);
});

test('disabled hooks are filtered out', function () {
    $registry = new HookRegistry;
    $hookClass = makeHookClass('Filterable');

    $registry->registerHook('App\\MyService', 'create', 'after', $hookClass, 'sync', ['enabled' => false]);

    $hooks = $registry->getHooks('App\\MyService', 'create', 'after');
    expect($hooks)->toBeEmpty();
});

test('removeHooks removes all hooks for a key', function () {
    $registry = new HookRegistry;
    $hookClass = makeHookClass('Remove');

    $registry->registerHook('App\\MyService', 'create', 'after', $hookClass);
    $registry->removeHooks('App\\MyService', 'create', 'after');

    expect($registry->getHooks('App\\MyService', 'create', 'after'))->toBeEmpty();
});

test('removeHook removes specific hook', function () {
    $registry = new HookRegistry;
    $hookA = makeHookClass('A');
    $hookB = makeHookClass('B');

    $registry->registerHook('App\\MyService', 'create', 'after', $hookA);
    $registry->registerHook('App\\MyService', 'create', 'after', $hookB);
    $registry->removeHook('App\\MyService', 'create', 'after', $hookA);

    $hooks = $registry->getHooks('App\\MyService', 'create', 'after');
    expect($hooks)->toHaveCount(1)
        ->and($hooks[0]['class'])->toBe($hookB);
});

test('clearAll removes all hooks', function () {
    $registry = new HookRegistry;
    $hookClass = makeHookClass('ClearAll');

    $registry->registerHook('App\\MyService', 'create', 'after', $hookClass);
    $registry->registerGlobalHook('create', 'after', $hookClass);
    $registry->clearAll();

    $all = $registry->getAllHooks();
    expect($all['target_hooks'])->toBeEmpty()
        ->and($all['global_hooks'])->toBeEmpty();
});

test('getStats returns correct counts', function () {
    $registry = new HookRegistry;
    $hookClass = makeHookClass('Stats');

    $registry->registerHook('App\\MyService', 'create', 'after', $hookClass);
    $registry->registerGlobalHook('update', 'after', $hookClass);

    $stats = $registry->getStats();

    expect($stats['total_target_hooks'])->toBe(1)
        ->and($stats['total_global_hooks'])->toBe(1)
        ->and($stats['total_hooks'])->toBe(2)
        ->and($stats['enabled'])->toBeTrue();
});

test('setEnabled toggles hook execution', function () {
    $registry = new HookRegistry;
    expect($registry->isEnabled())->toBeTrue();
    $registry->setEnabled(false);
    expect($registry->isEnabled())->toBeFalse();
});

test('registerStrategy adds custom strategy', function () {
    $registry = new HookRegistry;

    $customStrategy = new class implements HookExecutionStrategy
    {
        public function execute(HookJobInterface $hook, HookContext $context): void {}

        public function getName(): string
        {
            return 'custom';
        }

        public function supportsRetry(): bool
        {
            return false;
        }
    };

    $registry->registerStrategy('custom', $customStrategy);

    $stats = $registry->getStats();
    expect($stats['registered_strategies'])->toContain('custom');
});

// --- Wildcard method hooks ---

test('wildcard method hooks fire for any method', function () {
    $registry = new HookRegistry;
    $hookClass = makeHookClass('Wildcard');

    $registry->registerHook('App\\MyService', '*', 'after', $hookClass);

    $hooks = $registry->getHooks('App\\MyService', 'create', 'after');
    expect($hooks)->toHaveCount(1)
        ->and($hooks[0]['class'])->toBe($hookClass);

    $hooks2 = $registry->getHooks('App\\MyService', 'update', 'after');
    expect($hooks2)->toHaveCount(1);
});

test('specificity sorting: target-specific hooks come before wildcard method hooks', function () {
    $registry = new HookRegistry;
    $specificHook = makeHookClass('Specific');
    $wildcardHook = makeHookClass('Wildcard');

    // Register wildcard first, specific second (same priority)
    $registry->registerHook('App\\MyService', '*', 'after', $wildcardHook, 'sync', ['priority' => 100]);
    $registry->registerHook('App\\MyService', 'create', 'after', $specificHook, 'sync', ['priority' => 100]);

    $hooks = $registry->getHooks('App\\MyService', 'create', 'after');
    expect($hooks)->toHaveCount(2)
        ->and($hooks[0]['class'])->toBe($specificHook)
        ->and($hooks[1]['class'])->toBe($wildcardHook);
});

test('specificity sorting: target-specific hooks come before global hooks at same priority', function () {
    $registry = new HookRegistry;
    $specificHook = makeHookClass('Specific');
    $globalHook = makeHookClass('Global');

    $registry->registerGlobalHook('create', 'after', $globalHook, 'sync', ['priority' => 100]);
    $registry->registerHook('App\\MyService', 'create', 'after', $specificHook, 'sync', ['priority' => 100]);

    $hooks = $registry->getHooks('App\\MyService', 'create', 'after');
    expect($hooks)->toHaveCount(2)
        ->and($hooks[0]['class'])->toBe($specificHook)
        ->and($hooks[1]['class'])->toBe($globalHook);
});

test('priority overrides specificity when different', function () {
    $registry = new HookRegistry;
    $specificHook = makeHookClass('Specific');
    $globalHook = makeHookClass('Global');

    // Global hook with higher priority (lower number)
    $registry->registerGlobalHook('create', 'after', $globalHook, 'sync', ['priority' => 10]);
    $registry->registerHook('App\\MyService', 'create', 'after', $specificHook, 'sync', ['priority' => 200]);

    $hooks = $registry->getHooks('App\\MyService', 'create', 'after');
    expect($hooks)->toHaveCount(2)
        ->and($hooks[0]['class'])->toBe($globalHook)
        ->and($hooks[1]['class'])->toBe($specificHook);
});

test('global wildcard method hooks fire for any class and method', function () {
    $registry = new HookRegistry;
    $hookClass = makeHookClass('GlobalWildcard');

    $registry->registerGlobalHook('*', 'after', $hookClass);

    $hooks = $registry->getHooks('App\\AnyService', 'anyMethod', 'after');
    expect($hooks)->toHaveCount(1);
});

// --- executeHooks ---

test('executeHooks runs hooks via strategy', function () {
    $registry = new HookRegistry;
    $hookClass = makeHookClass('Execute');
    $registry->registerHook(stdClass::class, 'create', 'after', $hookClass);

    $ctx = makeContext('create', 'after');
    $registry->executeHooks(stdClass::class, 'create', 'after', $ctx);

    expect($hookClass::$calls)->toHaveCount(1);
});

test('executeHooks continues on failure when stop_on_failure is not set', function () {
    $registry = new HookRegistry;

    $failingClass = 'FailingHook_'.uniqid();
    eval("
        class {$failingClass} implements ".HookJobInterface::class.' {
            public static array $calls = [];
            public function handle('.HookContext::class.' $ctx): void { throw new RuntimeException("fail"); }
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

    $successClass = makeHookClass('SuccessAfterFail');

    $registry->registerHook(stdClass::class, 'create', 'after', $failingClass);
    $registry->registerHook(stdClass::class, 'create', 'after', $successClass);

    $ctx = makeContext('create', 'after');
    $registry->executeHooks(stdClass::class, 'create', 'after', $ctx);

    expect($successClass::$calls)->toHaveCount(1);
});

test('executeHooks stops on failure when stop_on_failure is true', function () {
    $registry = new HookRegistry;

    $failingClass = 'StopOnFailHook_'.uniqid();
    eval("
        class {$failingClass} implements ".HookJobInterface::class.' {
            public function handle('.HookContext::class.' $ctx): void { throw new RuntimeException("stop"); }
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

    $registry->registerHook(stdClass::class, 'create', 'after', $failingClass, 'sync', ['stop_on_failure' => true]);

    $ctx = makeContext('create', 'after');
    expect(fn () => $registry->executeHooks(stdClass::class, 'create', 'after', $ctx))
        ->toThrow(RuntimeException::class, 'stop');
});

// --- createHookInstance validation ---

test('createHookInstance rejects class names with invalid characters', function () {
    $registry = new HookRegistry;
    $registry->registerHook(stdClass::class, 'create', 'after', 'Invalid<Class>Name', 'sync', ['stop_on_failure' => true]);

    $ctx = makeContext('create', 'after');
    expect(fn () => $registry->executeHooks(stdClass::class, 'create', 'after', $ctx))
        ->toThrow(InvalidArgumentException::class, 'invalid characters');
});

test('createHookInstance rejects class that does not implement HookJobInterface', function () {
    $registry = new HookRegistry;
    $registry->registerHook(stdClass::class, 'create', 'after', stdClass::class, 'sync', ['stop_on_failure' => true]);

    $ctx = makeContext('create', 'after');
    expect(fn () => $registry->executeHooks(stdClass::class, 'create', 'after', $ctx))
        ->toThrow(InvalidArgumentException::class, 'must implement HookJobInterface');
});

test('createHookInstance rejects non-existent class that is not bound in container', function () {
    $registry = new HookRegistry;
    $registry->registerHook(stdClass::class, 'create', 'after', 'NonExistent\\FakeHookClass', 'sync', ['stop_on_failure' => true]);

    $ctx = makeContext('create', 'after');
    expect(fn () => $registry->executeHooks(stdClass::class, 'create', 'after', $ctx))
        ->toThrow(InvalidArgumentException::class, 'does not exist');
});

// --- debugTarget ---

test('debugTarget returns hooks for a specific class', function () {
    $registry = new HookRegistry;
    $hookClass = makeHookClass('Debug');
    $registry->registerHook('App\\MyService', 'create', 'after', $hookClass);

    $debug = $registry->debugTarget('App\\MyService');
    expect($debug)->not->toBeEmpty()
        ->and($debug)->toHaveKey('App\\MyService::create::after');
});

test('debugTarget includes global hooks', function () {
    $registry = new HookRegistry;
    $hookClass = makeHookClass('DebugGlobal');
    $registry->registerGlobalHook('create', 'after', $hookClass);

    $debug = $registry->debugTarget('App\\MyService');
    expect(array_keys($debug))->toContain('global::*::create::after');
});

// --- validatePositiveInt ---

test('getStrategy throws on non-positive batch_size', function () {
    $registry = new HookRegistry;
    $hookClass = makeHookClass('InvalidBatchSize');
    $registry->registerHook(stdClass::class, 'create', 'after', $hookClass, 'batch', ['batch_size' => 0, 'stop_on_failure' => true]);

    $ctx = makeContext('create', 'after');
    expect(fn () => $registry->executeHooks(stdClass::class, 'create', 'after', $ctx))
        ->toThrow(InvalidArgumentException::class, 'batch_size');
});

test('getStrategy throws on non-positive delay', function () {
    $registry = new HookRegistry;
    $hookClass = makeHookClass('InvalidDelay');
    $registry->registerHook(stdClass::class, 'create', 'after', $hookClass, 'delay', ['delay' => -1, 'stop_on_failure' => true]);

    $ctx = makeContext('create', 'after');
    expect(fn () => $registry->executeHooks(stdClass::class, 'create', 'after', $ctx))
        ->toThrow(InvalidArgumentException::class, 'delay');
});

// --- Deprecated method ---

test('debugService triggers deprecation and delegates to debugTarget', function () {
    $registry = new HookRegistry;
    $hookClass = makeHookClass('Deprecated');
    $registry->registerHook('App\\MyService', 'create', 'after', $hookClass);

    $result = @$registry->debugService('App\\MyService');
    expect($result)->not->toBeEmpty()
        ->and($result)->toHaveKey('App\\MyService::create::after');
});
