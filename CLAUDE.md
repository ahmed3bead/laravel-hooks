# CLAUDE.md — ahmedebead/laravel-hooks

This file is for AI assistants working in this repository.

## Package Identity

- **Package**: `ahmedebead/laravel-hooks`
- **Namespace**: `Ahmed3bead\LaravelHooks`
- **Type**: Standalone Laravel package (zero dependencies on `lara-crud`)
- **Purpose**: Lifecycle hook system for Laravel service classes

## Directory Structure

```
laravel-hooks/
├── config/
│   └── laravel-hooks.php          # Published config
├── src/
│   ├── Contracts/
│   │   ├── HookExecutionStrategy.php   # Strategy interface
│   │   ├── HookJobInterface.php        # Hook class interface
│   │   └── WrappedResponseInterface.php # For unwrapping custom responses
│   ├── Console/
│   │   ├── MakeHookCommand.php         # php artisan make:hook
│   │   └── HooksManagementCommand.php  # php artisan hooks:manage
│   ├── Jobs/
│   │   ├── QueuedHookJob.php
│   │   ├── HookChainJob.php
│   │   ├── BatchProcessorJob.php
│   │   └── BatchSchedulerJob.php
│   ├── Strategies/
│   │   ├── SyncHookStrategy.php
│   │   ├── QueuedHookStrategy.php
│   │   ├── DelayedHookStrategy.php
│   │   ├── BatchedHookStrategy.php
│   │   └── ConditionalHookStrategy.php
│   ├── BaseHookJob.php             # Abstract base; implements Template Method
│   ├── HookContext.php             # Value object passed to every hook
│   ├── HookManager.php             # Public facade; use this to register/execute hooks
│   ├── HookRegistry.php            # Internal hook storage + strategy dispatch
│   ├── HookableTrait.php        # Adds hook support to any service class
│   └── LaravelHooksServiceProvider.php
├── tests/
│   ├── Pest.php
│   ├── TestCase.php                # Extends Orchestra Testbench
│   ├── Unit/
│   │   ├── HookContextTest.php
│   │   ├── HookRegistryTest.php
│   │   ├── HookManagerTest.php
│   │   ├── BaseHookJobTest.php
│   │   ├── StrategiesTest.php
│   │   └── HookableTraitTest.php
│   └── Feature/
│       ├── ServiceProviderTest.php
│       └── ArtisanCommandsTest.php
├── composer.json
├── phpunit.xml
└── README.md
```

## Common Commands

```bash
# Install dependencies
composer install

# Run tests
vendor/bin/pest
vendor/bin/pest --coverage
vendor/bin/pest tests/Unit
vendor/bin/pest tests/Feature

# Static analysis
vendor/bin/phpstan analyse src --level=8

# Code formatting
vendor/bin/pint

# Validate composer.json
composer validate
```

## Key Design Decisions

### HookContext is a value object
All hook phases receive a `HookContext`. It has null-safe `request()` calls throughout to support CLI and queue contexts.

### BaseHookJob.conditions structure
`$this->conditions` stores `['callable' => fn, 'description' => '...']` arrays. `shouldExecute()` calls `$condition['callable']($context)` — NOT `$condition($context)` directly.

### Strategy registration
`HookRegistry` initializes `sync`, `queue`, `delay`, `batch` strategies. `ConditionalHookStrategy` is a wrapper, not a first-class registered name — it must be registered manually with a custom name.

### BatchedHookStrategy uses static state
`self::$batches` is a static array, meaning batches only survive within a single PHP process. `BatchSchedulerJob::handle()` is a placeholder — actual distributed batching would require a cache/Redis backing store.

### WrappedResponseInterface
`HookContext::getModelFromResult()`, `getDataFromResult()`, `getStatusCode()`, `getMessage()` all detect `WrappedResponseInterface`. If the consuming app's service returns a custom response object, it should implement this interface.

## Laravel Version Compatibility

| Laravel | PHP   | Orchestra Testbench |
|---------|-------|---------------------|
| 10.x    | 8.2+  | ^8.0                |
| 11.x    | 8.3+  | ^9.0                |
| 12.x    | 8.3+  | ^10.0               |
| 13.x    | 8.3+  | ^11.0               |

## Known Limitations / TODOs

- `BatchSchedulerJob::handle()` is a stub — distributed batch processing requires Redis/cache backing
- No Facade alias registered (users call `app(HookManager::class)` or inject it)
- No event dispatching around hook execution (could be added as a feature)

## Adding a New Strategy

1. Create `src/Strategies/MyStrategy.php` implementing `HookExecutionStrategy`
2. Register it in `HookRegistry::initializeStrategies()` OR let users register via `$hookManager->registerStrategy('name', $strategy)`
3. Add `'name'` to the `$validStrategies` array in `HookManager::validateStrategy()`
4. Write tests in `tests/Unit/StrategiesTest.php`
