<?php

use Ahmed3bead\LaravelHooks\HookManager;
use Ahmed3bead\LaravelHooks\HookRegistry;
use Ahmed3bead\LaravelHooks\Contracts\HookJobInterface;
use Ahmed3bead\LaravelHooks\HookContext;

// Hook fixture for commands test
class CommandsTestHook implements HookJobInterface
{
    public function handle(HookContext $context): void {}
    public function shouldExecute(HookContext $context): bool { return true; }
    public function getPriority(): int { return 100; }
    public function getRetryAttempts(): int { return 1; }
    public function getRetryDelay(): int { return 0; }
    public function getTimeout(): int { return 30; }
    public function isAsync(): bool { return false; }
    public function getQueueName(): string { return 'default'; }
    public function getMetadata(): array { return []; }
    public function execute(HookContext $context): void {}
}

test('hooks:manage stats runs successfully', function () {
    $this->artisan('hooks:manage', ['action' => 'stats'])
        ->assertExitCode(0);
});

test('hooks:manage list runs successfully with no hooks', function () {
    $this->artisan('hooks:manage', ['action' => 'list'])
        ->assertExitCode(0);
});

test('hooks:manage test runs successfully', function () {
    $this->artisan('hooks:manage', ['action' => 'test'])
        ->assertExitCode(0);
});

test('hooks:manage enable enables hook system', function () {
    $manager = app(HookManager::class);
    $manager->disable();

    $this->artisan('hooks:manage', ['action' => 'enable'])
        ->assertExitCode(0);

    expect($manager->isEnabled())->toBeTrue();
});

test('hooks:manage disable disables hook system with force', function () {
    $manager = app(HookManager::class);

    $this->artisan('hooks:manage', ['action' => 'disable', '--force' => true])
        ->assertExitCode(0);

    expect($manager->isEnabled())->toBeFalse();
});

test('hooks:manage clear clears hooks with force flag', function () {
    $manager = app(HookManager::class);
    $manager->addSyncHook(stdClass::class, 'create', 'after', CommandsTestHook::class);

    $this->artisan('hooks:manage', ['action' => 'clear', '--force' => true])
        ->assertExitCode(0);

    expect($manager->getStats()['total_hooks'])->toBe(0);
});

test('hooks:manage flush runs successfully', function () {
    $this->artisan('hooks:manage', ['action' => 'flush'])
        ->assertExitCode(0);
});

test('hooks:manage with unknown action returns failure', function () {
    $this->artisan('hooks:manage', ['action' => 'unknown'])
        ->assertExitCode(1);
});

test('hooks:manage list shows registered hooks', function () {
    $manager = app(HookManager::class);
    $manager->addSyncHook(stdClass::class, 'create', 'after', CommandsTestHook::class);

    $this->artisan('hooks:manage', ['action' => 'list'])
        ->assertExitCode(0);
});

test('hooks:manage debug with missing service option returns error', function () {
    $this->artisan('hooks:manage', ['action' => 'debug'])
        ->assertExitCode(0); // Command::SUCCESS is returned but error message shown
});
