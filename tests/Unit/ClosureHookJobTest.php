<?php

use Ahmed3bead\LaravelHooks\ClosureHookJob;
use Ahmed3bead\LaravelHooks\HookContext;
use Illuminate\Support\Facades\Log;

function makeClosureCtx(string $method = 'create', string $phase = 'after'): HookContext
{
    return new HookContext($method, $phase, null, [], null, new stdClass);
}

test('ClosureHookJob handle calls the closure', function () {
    $called = false;
    $hook = new ClosureHookJob(function (HookContext $ctx) use (&$called) {
        $called = true;
    });

    $hook->handle(makeClosureCtx());

    expect($called)->toBeTrue();
});

test('ClosureHookJob handle passes context to closure', function () {
    $receivedMethod = null;
    $hook = new ClosureHookJob(function (HookContext $ctx) use (&$receivedMethod) {
        $receivedMethod = $ctx->method;
    });

    $hook->handle(makeClosureCtx('update'));

    expect($receivedMethod)->toBe('update');
});

test('ClosureHookJob shouldExecute always returns true', function () {
    $hook = new ClosureHookJob(function () {});
    expect($hook->shouldExecute(makeClosureCtx()))->toBeTrue();
});

test('ClosureHookJob getPriority returns 100', function () {
    $hook = new ClosureHookJob(function () {});
    expect($hook->getPriority())->toBe(100);
});

test('ClosureHookJob getRetryAttempts returns 1', function () {
    $hook = new ClosureHookJob(function () {});
    expect($hook->getRetryAttempts())->toBe(1);
});

test('ClosureHookJob getRetryDelay returns 0', function () {
    $hook = new ClosureHookJob(function () {});
    expect($hook->getRetryDelay())->toBe(0);
});

test('ClosureHookJob getTimeout returns 30', function () {
    $hook = new ClosureHookJob(function () {});
    expect($hook->getTimeout())->toBe(30);
});

test('ClosureHookJob isAsync returns false', function () {
    $hook = new ClosureHookJob(function () {});
    expect($hook->isAsync())->toBeFalse();
});

test('ClosureHookJob getQueueName returns default', function () {
    $hook = new ClosureHookJob(function () {});
    expect($hook->getQueueName())->toBe('default');
});

test('ClosureHookJob getMetadata includes type closure', function () {
    $hook = new ClosureHookJob(function () {});
    expect($hook->getMetadata())->toBe(['type' => 'closure']);
});

test('ClosureHookJob execute calls handle and logs error on failure', function () {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $data) {
            return $message === 'Closure hook execution failed'
                && isset($data['error'])
                && isset($data['context']);
        });

    $hook = new ClosureHookJob(function () {
        throw new RuntimeException('closure failed');
    });

    expect(fn () => $hook->execute(makeClosureCtx()))->toThrow(RuntimeException::class, 'closure failed');
});

test('ClosureHookJob execute runs successfully without error', function () {
    $executed = false;
    $hook = new ClosureHookJob(function () use (&$executed) {
        $executed = true;
    });

    $hook->execute(makeClosureCtx());

    expect($executed)->toBeTrue();
});

test('ClosureHookJob accepts any callable', function () {
    $result = null;
    $callable = function (HookContext $ctx) use (&$result) {
        $result = $ctx->method.'_'.$ctx->phase;
    };

    $hook = new ClosureHookJob($callable);
    $hook->handle(makeClosureCtx('delete', 'before'));

    expect($result)->toBe('delete_before');
});
