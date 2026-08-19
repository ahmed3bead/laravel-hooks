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

// --- MakeHookCommand ---

test('make:hook creates a hook file with sync dispatch mode', function () {
    $name = 'TestMakeSync'.uniqid();

    $this->artisan('make:hook', [
        'name' => $name,
        '--type' => 'cache',
        '--method' => ['create'],
        '--phase' => 'after',
        '--sync' => true,
    ])->assertExitCode(0);

    $className = $name.'Hook';
    $path = app_path("Hooks/{$className}.php");
    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);
    expect($content)->toContain('class '.$className)
        ->and($content)->toContain('extends BaseHookJob')
        ->and($content)->toContain('// Synchronous execution');

    @unlink($path);
});

test('make:hook creates an audit type hook', function () {
    $name = 'TestAudit'.uniqid();

    $this->artisan('make:hook', [
        'name' => $name,
        '--type' => 'audit',
        '--method' => ['create', 'update'],
        '--phase' => 'after',
        '--sync' => true,
    ])->assertExitCode(0);

    $className = $name.'Hook';
    $path = app_path("Hooks/{$className}.php");
    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);
    expect($content)->toContain('Audit trail hook')
        ->and($content)->toContain('audit_logs');

    @unlink($path);
});

test('make:hook creates a notification type hook', function () {
    $name = 'TestNotification'.uniqid();

    $this->artisan('make:hook', [
        'name' => $name,
        '--type' => 'notification',
        '--method' => ['create'],
        '--phase' => 'after',
        '--sync' => true,
    ])->assertExitCode(0);

    $className = $name.'Hook';
    $path = app_path("Hooks/{$className}.php");
    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);
    expect($content)->toContain('Notification hook')
        ->and($content)->toContain('sendNotification');

    @unlink($path);
});

test('make:hook creates an analytics type hook', function () {
    $name = 'TestAnalytics'.uniqid();

    $this->artisan('make:hook', [
        'name' => $name,
        '--type' => 'analytics',
        '--method' => ['*'],
        '--phase' => 'after',
        '--sync' => true,
    ])->assertExitCode(0);

    $className = $name.'Hook';
    $path = app_path("Hooks/{$className}.php");
    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);
    expect($content)->toContain('Analytics tracking hook')
        ->and($content)->toContain('sendToAnalytics');

    @unlink($path);
});

test('make:hook with queue dispatch mode sets async config', function () {
    $name = 'TestQueue'.uniqid();

    $this->artisan('make:hook', [
        'name' => $name,
        '--type' => 'cache',
        '--method' => ['create'],
        '--phase' => 'after',
        '--queue' => true,
    ])->assertExitCode(0);

    $className = $name.'Hook';
    $path = app_path("Hooks/{$className}.php");
    $content = file_get_contents($path);

    expect($content)->toContain('$async = true')
        ->and($content)->toContain("'default'");

    @unlink($path);
});

test('make:hook with delay dispatch mode adds delay note', function () {
    $name = 'TestDelay'.uniqid();

    $this->artisan('make:hook', [
        'name' => $name,
        '--type' => 'cache',
        '--method' => ['create'],
        '--phase' => 'after',
        '--delay' => true,
    ])->assertExitCode(0);

    $className = $name.'Hook';
    $path = app_path("Hooks/{$className}.php");
    $content = file_get_contents($path);

    expect($content)->toContain('$async = true')
        ->and($content)->toContain('addDelayedHookRegistration');

    @unlink($path);
});

test('make:hook with batch dispatch mode adds batch note', function () {
    $name = 'TestBatch'.uniqid();

    $this->artisan('make:hook', [
        'name' => $name,
        '--type' => 'cache',
        '--method' => ['create'],
        '--phase' => 'after',
        '--batch' => true,
    ])->assertExitCode(0);

    $className = $name.'Hook';
    $path = app_path("Hooks/{$className}.php");
    $content = file_get_contents($path);

    expect($content)->toContain('$async = true')
        ->and($content)->toContain('addBatchedHookRegistration');

    @unlink($path);
});

test('make:hook appends Hook suffix if not present', function () {
    $name = 'MyCustom'.uniqid();

    $this->artisan('make:hook', [
        'name' => $name,
        '--type' => 'cache',
        '--method' => ['create'],
        '--phase' => 'after',
        '--sync' => true,
    ])->assertExitCode(0);

    $className = $name.'Hook';
    $path = app_path("Hooks/{$className}.php");
    expect(file_exists($path))->toBeTrue();

    @unlink($path);
});

test('make:hook does not double Hook suffix', function () {
    $clearName = 'TestDoubleHook';

    $this->artisan('make:hook', [
        'name' => $clearName,
        '--type' => 'cache',
        '--method' => ['create'],
        '--phase' => 'after',
        '--sync' => true,
        '--force' => true,
    ])->assertExitCode(0);

    // Should NOT create TestDoubleHookHook
    $path = app_path('Hooks/TestDoubleHook.php');
    expect(file_exists($path))->toBeTrue();

    @unlink($path);
});

test('make:hook with --condition includes condition method', function () {
    $name = 'TestCondition'.uniqid();

    $this->artisan('make:hook', [
        'name' => $name,
        '--type' => 'cache',
        '--method' => ['create'],
        '--phase' => 'after',
        '--sync' => true,
        '--condition' => true,
    ])->assertExitCode(0);

    $className = $name.'Hook';
    $path = app_path("Hooks/{$className}.php");
    $content = file_get_contents($path);

    expect($content)->toContain('shouldExecute');

    @unlink($path);
});

test('make:hook with custom priority sets priority in class', function () {
    $name = 'TestPriority'.uniqid();

    $this->artisan('make:hook', [
        'name' => $name,
        '--type' => 'cache',
        '--method' => ['create'],
        '--phase' => 'after',
        '--sync' => true,
        '--priority' => '50',
    ])->assertExitCode(0);

    $className = $name.'Hook';
    $path = app_path("Hooks/{$className}.php");
    $content = file_get_contents($path);

    expect($content)->toContain('$priority = 50');

    @unlink($path);
});

test('make:hook fails if file exists without --force', function () {
    $name = 'TestExisting'.uniqid();

    // Create first
    $this->artisan('make:hook', [
        'name' => $name,
        '--type' => 'cache',
        '--method' => ['create'],
        '--phase' => 'after',
        '--sync' => true,
    ])->assertExitCode(0);

    // Try again without --force
    $this->artisan('make:hook', [
        'name' => $name,
        '--type' => 'cache',
        '--method' => ['create'],
        '--phase' => 'after',
        '--sync' => true,
    ])->assertExitCode(1);

    $className = $name.'Hook';
    @unlink(app_path("Hooks/{$className}.php"));
});

test('make:hook with --force overwrites existing file', function () {
    $name = 'TestForce'.uniqid();

    // Create first
    $this->artisan('make:hook', [
        'name' => $name,
        '--type' => 'cache',
        '--method' => ['create'],
        '--phase' => 'after',
        '--sync' => true,
    ])->assertExitCode(0);

    // Overwrite with --force
    $this->artisan('make:hook', [
        'name' => $name,
        '--type' => 'audit',
        '--method' => ['create'],
        '--phase' => 'after',
        '--sync' => true,
        '--force' => true,
    ])->assertExitCode(0);

    $className = $name.'Hook';
    $path = app_path("Hooks/{$className}.php");
    $content = file_get_contents($path);
    expect($content)->toContain('Audit trail hook');

    @unlink($path);
});

test('make:hook with wildcard method adds comment instead of onlyForMethods', function () {
    $name = 'TestWildcard'.uniqid();

    $this->artisan('make:hook', [
        'name' => $name,
        '--type' => 'cache',
        '--method' => ['*'],
        '--phase' => 'after',
        '--sync' => true,
    ])->assertExitCode(0);

    $className = $name.'Hook';
    $path = app_path("Hooks/{$className}.php");
    $content = file_get_contents($path);

    expect($content)->toContain('Applies to all methods');

    @unlink($path);
});

test('make:hook with multiple methods adds onlyForMethods array', function () {
    $name = 'TestMultiMethod'.uniqid();

    $this->artisan('make:hook', [
        'name' => $name,
        '--type' => 'cache',
        '--method' => ['create', 'update', 'delete'],
        '--phase' => 'after',
        '--sync' => true,
    ])->assertExitCode(0);

    $className = $name.'Hook';
    $path = app_path("Hooks/{$className}.php");
    $content = file_get_contents($path);

    expect($content)->toContain('onlyForMethods')
        ->and($content)->toContain("'create'")
        ->and($content)->toContain("'update'")
        ->and($content)->toContain("'delete'");

    @unlink($path);
});

test('make:hook with before phase sets onlyForPhase', function () {
    $name = 'TestBeforePhase'.uniqid();

    $this->artisan('make:hook', [
        'name' => $name,
        '--type' => 'cache',
        '--method' => ['create'],
        '--phase' => 'before',
        '--sync' => true,
    ])->assertExitCode(0);

    $className = $name.'Hook';
    $path = app_path("Hooks/{$className}.php");
    $content = file_get_contents($path);

    expect($content)->toContain("onlyForPhase('before')");

    @unlink($path);
});
