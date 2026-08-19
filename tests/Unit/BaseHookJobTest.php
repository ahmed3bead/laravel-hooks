<?php

use Ahmed3bead\LaravelHooks\BaseHookJob;
use Ahmed3bead\LaravelHooks\HookContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

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
            throw new RuntimeException('Hook intentionally failed');
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
    return new HookContext($method, $phase, null, [], null, new stdClass);
}

test('execute calls handle and lifecycle methods', function () {
    $hook = new ConcreteHookJob;
    $hook->execute(makeCtx('create'));

    expect($hook->handled)->toContain('create')
        ->and($hook->beforeCalled)->toBeTrue()
        ->and($hook->afterCalled)->toBeTrue();
});

test('execute rethrows exceptions from handle', function () {
    $hook = new ConcreteHookJob;
    $hook->shouldFail = true;

    expect(fn () => $hook->execute(makeCtx()))->toThrow(RuntimeException::class);
});

test('shouldExecute returns true with no conditions', function () {
    $hook = new ConcreteHookJob;
    expect($hook->shouldExecute(makeCtx()))->toBeTrue();
});

test('shouldExecute returns false when condition fails', function () {
    $hook = new ConcreteHookJob;
    $hook->exposeAddCondition(fn ($ctx) => false, 'Always false');

    expect($hook->shouldExecute(makeCtx()))->toBeFalse();
});

test('shouldExecute returns true when all conditions pass', function () {
    $hook = new ConcreteHookJob;
    $hook->exposeAddCondition(fn ($ctx) => true, 'Always true')
        ->exposeAddCondition(fn ($ctx) => true, 'Also true');

    expect($hook->shouldExecute(makeCtx()))->toBeTrue();
});

test('onlyForMethods condition filters by method name', function () {
    $hook = new ConcreteHookJob;
    $hook->exposeOnlyForMethods(['create', 'update']);

    expect($hook->shouldExecute(makeCtx('create')))->toBeTrue()
        ->and($hook->shouldExecute(makeCtx('delete')))->toBeFalse();
});

test('onlyForPhase condition filters by phase', function () {
    $hook = new ConcreteHookJob;
    $hook->exposeOnlyForPhase('before');

    expect($hook->shouldExecute(makeCtx('create', 'before')))->toBeTrue()
        ->and($hook->shouldExecute(makeCtx('create', 'after')))->toBeFalse();
});

test('default priority is 100', function () {
    $hook = new ConcreteHookJob;
    expect($hook->getPriority())->toBe(100);
});

test('default retry attempts is 3', function () {
    $hook = new ConcreteHookJob;
    expect($hook->getRetryAttempts())->toBe(3);
});

test('default async is false', function () {
    $hook = new ConcreteHookJob;
    expect($hook->isAsync())->toBeFalse();
});

test('default queue name is default', function () {
    $hook = new ConcreteHookJob;
    expect($hook->getQueueName())->toBe('default');
});

test('getMetadata includes class, priority, async, queue', function () {
    $hook = new ConcreteHookJob;
    $meta = $hook->getMetadata();

    expect($meta)->toHaveKey('class')
        ->and($meta)->toHaveKey('priority')
        ->and($meta)->toHaveKey('async')
        ->and($meta)->toHaveKey('queue');
});

// --- handleError ---

test('handleError logs error with context', function () {
    $hook = new ConcreteHookJob;
    $hook->shouldFail = true;

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $data) {
            return $message === 'Hook execution failed'
                && isset($data['hook'])
                && isset($data['error'])
                && isset($data['context'])
                && ! isset($data['trace']); // trace only in debug mode
        });

    expect(fn () => $hook->execute(makeCtx()))->toThrow(RuntimeException::class);
});

test('handleError includes trace when debug mode is enabled', function () {
    config(['laravel-hooks.debug' => true, 'app.debug' => true]);

    $hook = new ConcreteHookJob;
    $hook->shouldFail = true;

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $data) {
            return $message === 'Hook execution failed'
                && isset($data['trace']);
        });

    expect(fn () => $hook->execute(makeCtx()))->toThrow(RuntimeException::class);

    config(['laravel-hooks.debug' => false, 'app.debug' => false]);
});

// --- Config setters ---

test('setPriority changes priority', function () {
    $hook = new class extends BaseHookJob
    {
        public function handle(HookContext $context): void {}

        public function exposePriority(int $p): static
        {
            return $this->setPriority($p);
        }
    };

    $hook->exposePriority(50);
    expect($hook->getPriority())->toBe(50);
});

test('setRetryConfig changes retry attempts and delay', function () {
    $hook = new class extends BaseHookJob
    {
        public function handle(HookContext $context): void {}

        public function exposeRetryConfig(int $attempts, int $delay): static
        {
            return $this->setRetryConfig($attempts, $delay);
        }
    };

    $hook->exposeRetryConfig(5, 60);
    expect($hook->getRetryAttempts())->toBe(5)
        ->and($hook->getRetryDelay())->toBe(60);
});

test('setTimeout changes timeout', function () {
    $hook = new class extends BaseHookJob
    {
        public function handle(HookContext $context): void {}

        public function exposeTimeout(int $t): static
        {
            return $this->setTimeout($t);
        }
    };

    $hook->exposeTimeout(120);
    expect($hook->getTimeout())->toBe(120);
});

test('setAsync changes async and queue name', function () {
    $hook = new class extends BaseHookJob
    {
        public function handle(HookContext $context): void {}

        public function exposeAsync(bool $async, string $queue): static
        {
            return $this->setAsync($async, $queue);
        }
    };

    $hook->exposeAsync(true, 'high-priority');
    expect($hook->isAsync())->toBeTrue()
        ->and($hook->getQueueName())->toBe('high-priority');
});

test('addMetadata adds to metadata', function () {
    $hook = new class extends BaseHookJob
    {
        public function handle(HookContext $context): void {}

        public function exposeMeta(string $key, mixed $value): static
        {
            return $this->addMetadata($key, $value);
        }
    };

    $hook->exposeMeta('custom_key', 'custom_value');
    $meta = $hook->getMetadata();
    expect($meta['custom_key'])->toBe('custom_value');
});

// --- Condition helpers ---

test('onlyForUser condition works', function () {
    $hook = new class extends BaseHookJob
    {
        public function handle(HookContext $context): void {}

        public function exposeOnlyForUser(callable $condition): static
        {
            return $this->onlyForUser($condition);
        }
    };

    $hook->exposeOnlyForUser(fn ($user) => $user->role === 'admin');

    // No user
    $ctxNoUser = new HookContext('create', 'after', null, [], null, new stdClass);
    expect($hook->shouldExecute($ctxNoUser))->toBeFalse();

    // Admin user
    $adminUser = new stdClass;
    $adminUser->id = 1;
    $adminUser->role = 'admin';
    $ctxAdmin = new HookContext('create', 'after', null, [], null, new stdClass, null, $adminUser);
    expect($hook->shouldExecute($ctxAdmin))->toBeTrue();

    // Non-admin user
    $regularUser = new stdClass;
    $regularUser->id = 2;
    $regularUser->role = 'user';
    $ctxRegular = new HookContext('create', 'after', null, [], null, new stdClass, null, $regularUser);
    expect($hook->shouldExecute($ctxRegular))->toBeFalse();
});

test('onlyForModel condition works', function () {
    $hook = new class extends BaseHookJob
    {
        public function handle(HookContext $context): void {}

        public function exposeOnlyForModel(string $class): static
        {
            return $this->onlyForModel($class);
        }
    };

    $hook->exposeOnlyForModel(Model::class);

    $model = new class extends Model
    {
        protected $guarded = [];
    };

    $ctxWithModel = new HookContext('create', 'after', null, [], null, new stdClass, $model);
    expect($hook->shouldExecute($ctxWithModel))->toBeTrue();

    $ctxNoModel = new HookContext('create', 'after', null, [], null, new stdClass);
    expect($hook->shouldExecute($ctxNoModel))->toBeFalse();
});

test('default retry delay is 30', function () {
    $hook = new ConcreteHookJob;
    expect($hook->getRetryDelay())->toBe(30);
});

test('default timeout is 300', function () {
    $hook = new ConcreteHookJob;
    expect($hook->getTimeout())->toBe(300);
});
