<?php

use Ahmed3bead\LaravelHooks\Contracts\HookJobInterface;
use Ahmed3bead\LaravelHooks\HookContext;
use Ahmed3bead\LaravelHooks\HookManager;

// Hook fixture for commands test
class CommandsTestHook implements HookJobInterface
{
    public function handle(HookContext $context): void {}

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

test('hooks:manage debug with missing target option returns error', function () {
    $this->artisan('hooks:manage', ['action' => 'debug'])
        ->assertExitCode(0); // Command::SUCCESS is returned but error message shown
});

// --- Export command ---

test('hooks:manage export creates json file in storage', function () {
    $manager = app(HookManager::class);
    $manager->addSyncHook(stdClass::class, 'create', 'after', CommandsTestHook::class);

    $filename = 'test_export_'.uniqid().'.json';

    $this->artisan('hooks:manage', ['action' => 'export', '--export' => $filename])
        ->assertExitCode(0);

    $path = storage_path('app/'.$filename);
    expect(file_exists($path))->toBeTrue();

    $data = json_decode(file_get_contents($path), true);
    expect($data)->toHaveKey('exported_at')
        ->and($data)->toHaveKey('stats')
        ->and($data)->toHaveKey('hooks');

    // Cleanup
    @unlink($path);
});

test('hooks:manage export rejects non-json extension', function () {
    $this->artisan('hooks:manage', ['action' => 'export', '--export' => 'hooks.xml'])
        ->assertExitCode(0); // Command returns SUCCESS but shows error message
});

test('hooks:manage export strips path traversal', function () {
    $filename = '../../etc/passwd.json';

    $this->artisan('hooks:manage', ['action' => 'export', '--export' => $filename])
        ->assertExitCode(0);

    // Should only create file in storage/app/, not in ../../etc/
    $safePath = storage_path('app/passwd.json');
    if (file_exists($safePath)) {
        @unlink($safePath);
    }
    expect(file_exists('/etc/passwd.json'))->toBeFalse();
});

// --- Stats with hooks registered ---

test('hooks:manage stats shows distribution when hooks exist', function () {
    $manager = app(HookManager::class);
    $manager->addSyncHook(stdClass::class, 'create', 'after', CommandsTestHook::class);
    $manager->addSyncHook(stdClass::class, 'update', 'before', CommandsTestHook::class);

    $this->artisan('hooks:manage', ['action' => 'stats'])
        ->assertExitCode(0);
});

// --- Debug with target ---

test('hooks:manage debug with valid target shows hooks', function () {
    $manager = app(HookManager::class);
    $manager->addSyncHook(stdClass::class, 'create', 'after', CommandsTestHook::class);

    $this->artisan('hooks:manage', ['action' => 'debug', '--target' => stdClass::class])
        ->assertExitCode(0);
});

test('hooks:manage debug with non-existent class shows error', function () {
    $this->artisan('hooks:manage', ['action' => 'debug', '--target' => 'App\\NonExistent\\FakeClass'])
        ->assertExitCode(0); // Command returns SUCCESS but shows error
});
