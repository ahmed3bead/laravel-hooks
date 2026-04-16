<?php

use Ahmed3bead\LaravelHooks\HookRegistry;
use Ahmed3bead\LaravelHooks\HookContext;
use Ahmed3bead\LaravelHooks\Contracts\HookJobInterface;
use Ahmed3bead\LaravelHooks\Contracts\HookExecutionStrategy;

// Minimal hook for testing
function makeHookClass(string $name = 'TestHook'): string
{
    $class = 'TestHook_' . $name . '_' . uniqid();
    eval("
        class {$class} implements " . HookJobInterface::class . " {
            public static array \$calls = [];
            public function handle(" . HookContext::class . " \$ctx): void { self::\$calls[] = \$ctx; }
            public function shouldExecute(" . HookContext::class . " \$ctx): bool { return true; }
            public function getPriority(): int { return 100; }
            public function getRetryAttempts(): int { return 1; }
            public function getRetryDelay(): int { return 0; }
            public function getTimeout(): int { return 30; }
            public function isAsync(): bool { return false; }
            public function getQueueName(): string { return 'default'; }
            public function getMetadata(): array { return []; }
            public function execute(" . HookContext::class . " \$ctx): void { \$this->handle(\$ctx); }
        }
    ");
    return $class;
}

function makeContext(string $method = 'create', string $phase = 'after'): HookContext
{
    $service = new stdClass();
    return new HookContext($method, $phase, null, [], null, $service);
}

test('registerHook stores a hook', function () {
    $registry = new HookRegistry();
    $hookClass = makeHookClass('Store');

    $registry->registerHook('App\\MyService', 'create', 'after', $hookClass);
    $hooks = $registry->getHooks('App\\MyService', 'create', 'after');

    expect($hooks)->toHaveCount(1)
        ->and($hooks[0]['class'])->toBe($hookClass);
});

test('registerGlobalHook stores a global hook', function () {
    $registry = new HookRegistry();
    $hookClass = makeHookClass('Global');

    $registry->registerGlobalHook('create', 'after', $hookClass);
    $hooks = $registry->getHooks('App\\AnyService', 'create', 'after');

    expect($hooks)->toHaveCount(1)
        ->and($hooks[0]['class'])->toBe($hookClass);
});

test('getHooks returns empty when disabled', function () {
    $registry = new HookRegistry();
    $hookClass = makeHookClass('Disabled');

    $registry->registerHook('App\\MyService', 'create', 'after', $hookClass);
    $registry->setEnabled(false);

    expect($registry->getHooks('App\\MyService', 'create', 'after'))->toBeEmpty();
});

test('hooks are sorted by priority', function () {
    $registry = new HookRegistry();
    $highClass = makeHookClass('High');
    $lowClass = makeHookClass('Low');

    $registry->registerHook('App\\MyService', 'create', 'after', $lowClass, 'sync', ['priority' => 200]);
    $registry->registerHook('App\\MyService', 'create', 'after', $highClass, 'sync', ['priority' => 10]);

    $hooks = $registry->getHooks('App\\MyService', 'create', 'after');

    expect($hooks[0]['class'])->toBe($highClass)
        ->and($hooks[1]['class'])->toBe($lowClass);
});

test('disabled hooks are filtered out', function () {
    $registry = new HookRegistry();
    $hookClass = makeHookClass('Filterable');

    $registry->registerHook('App\\MyService', 'create', 'after', $hookClass, 'sync', ['enabled' => false]);

    $hooks = $registry->getHooks('App\\MyService', 'create', 'after');
    expect($hooks)->toBeEmpty();
});

test('removeHooks removes all hooks for a key', function () {
    $registry = new HookRegistry();
    $hookClass = makeHookClass('Remove');

    $registry->registerHook('App\\MyService', 'create', 'after', $hookClass);
    $registry->removeHooks('App\\MyService', 'create', 'after');

    expect($registry->getHooks('App\\MyService', 'create', 'after'))->toBeEmpty();
});

test('removeHook removes specific hook', function () {
    $registry = new HookRegistry();
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
    $registry = new HookRegistry();
    $hookClass = makeHookClass('ClearAll');

    $registry->registerHook('App\\MyService', 'create', 'after', $hookClass);
    $registry->registerGlobalHook('create', 'after', $hookClass);
    $registry->clearAll();

    $all = $registry->getAllHooks();
    expect($all['service_hooks'])->toBeEmpty()
        ->and($all['global_hooks'])->toBeEmpty();
});

test('getStats returns correct counts', function () {
    $registry = new HookRegistry();
    $hookClass = makeHookClass('Stats');

    $registry->registerHook('App\\MyService', 'create', 'after', $hookClass);
    $registry->registerGlobalHook('update', 'after', $hookClass);

    $stats = $registry->getStats();

    expect($stats['total_service_hooks'])->toBe(1)
        ->and($stats['total_global_hooks'])->toBe(1)
        ->and($stats['total_hooks'])->toBe(2)
        ->and($stats['enabled'])->toBeTrue();
});

test('setEnabled toggles hook execution', function () {
    $registry = new HookRegistry();
    expect($registry->isEnabled())->toBeTrue();
    $registry->setEnabled(false);
    expect($registry->isEnabled())->toBeFalse();
});

test('registerStrategy adds custom strategy', function () {
    $registry = new HookRegistry();

    $customStrategy = new class implements HookExecutionStrategy {
        public function execute(HookJobInterface $hook, HookContext $context): void {}
        public function getName(): string { return 'custom'; }
        public function supportsRetry(): bool { return false; }
    };

    $registry->registerStrategy('custom', $customStrategy);

    $stats = $registry->getStats();
    expect($stats['registered_strategies'])->toContain('custom');
});
