# Laravel Hooks

A standalone hook system for Laravel applications. Attach lifecycle callbacks (`before` / `after` / `error`) to any service method with five execution strategies: **sync**, **queued**, **delayed**, **batched**, and **conditional**.

## Requirements

- PHP 8.2+
- Laravel 10, 11, 12, or 13

## Installation

```bash
composer require ahmedebead/laravel-hooks
```

Laravel auto-discovers the service provider. To publish the config file:

```bash
php artisan vendor:publish --tag=laravel-hooks-config
```

## Configuration

`config/laravel-hooks.php`:

```php
return [
    'enabled'          => env('LARAVEL_HOOKS_ENABLED', true),
    'debug'            => env('LARAVEL_HOOKS_DEBUG', false),
    'queue_connection' => env('LARAVEL_HOOKS_QUEUE_CONNECTION', null),
    'default_queue'    => env('LARAVEL_HOOKS_DEFAULT_QUEUE', 'default'),
    'batch_queue'      => env('LARAVEL_HOOKS_BATCH_QUEUE', 'batch'),
    'generation_directory' => 'App\\Hooks',
];
```

## Basic Usage

### 1. Add `ServiceHookTrait` to a service

```php
use Ahmed3bead\LaravelHooks\ServiceHookTrait;

class UserService
{
    use ServiceHookTrait;

    public function create(array $data): User
    {
        return $this->executeWithHooks('create', function () use ($data) {
            return User::create($data);
        }, $data);
    }
}
```

### 2. Create a hook class

```php
use Ahmed3bead\LaravelHooks\BaseHookJob;
use Ahmed3bead\LaravelHooks\HookContext;

class UserCreatedAuditHook extends BaseHookJob
{
    public function handle(HookContext $context): void
    {
        $model = $context->getModelFromResult();

        AuditLog::create([
            'action'   => $context->method,
            'user_id'  => $context->getUserId(),
            'model_id' => $model?->getKey(),
        ]);
    }
}
```

### 3. Register the hook in a service provider

```php
use Ahmed3bead\LaravelHooks\HookManager;

class AppServiceProvider extends ServiceProvider
{
    public function boot(HookManager $hooks): void
    {
        $hooks->addSyncHook(
            serviceClass: UserService::class,
            method:       'create',
            phase:        'after',
            hookClass:    UserCreatedAuditHook::class,
        );
    }
}
```

## Execution Phases

| Phase   | When it fires                        |
|---------|--------------------------------------|
| `before` | Before the method body runs         |
| `after`  | After the method returns a result   |
| `error`  | When the method throws an exception |

## Execution Strategies

### Sync (default)

Executes immediately in the same request cycle.

```php
$hooks->addSyncHook(UserService::class, 'create', 'after', UserCreatedAuditHook::class);
```

### Queued

Dispatches the hook as a Laravel queue job.

```php
$hooks->addQueuedHook(UserService::class, 'create', 'after', SendWelcomeEmailHook::class);
```

### Delayed

Queues the hook with a delay (seconds).

```php
$hooks->addDelayedHook(
    serviceClass: UserService::class,
    method:       'create',
    phase:        'after',
    hookClass:    ScheduleOnboardingHook::class,
    delay:        300, // 5 minutes
);
```

### Batched

Collects hooks and processes them together once the batch size is reached.

```php
$hooks->addBatchedHook(
    serviceClass: UserService::class,
    method:       'create',
    phase:        'after',
    hookClass:    AnalyticsHook::class,
    options:      ['batch_size' => 50, 'batch_delay' => 60],
);
```

### Conditional

Wraps any other strategy with additional runtime conditions.

```php
use Ahmed3bead\LaravelHooks\Strategies\ConditionalHookStrategy;
use Ahmed3bead\LaravelHooks\Strategies\SyncHookStrategy;

$strategy = (new ConditionalHookStrategy(new SyncHookStrategy()))
    ->onlyInEnvironment('production')
    ->onlyWhenConfigEnabled('features.audit');

$hooks->registerStrategy('prod_audit', $strategy);
$hooks->addHook(UserService::class, 'create', 'after', AuditHook::class, 'prod_audit');
```

## Hook Context

Every hook receives a `HookContext` instance:

```php
public function handle(HookContext $context): void
{
    $context->method;              // method name, e.g. 'create'
    $context->phase;               // 'before', 'after', or 'error'
    $context->data;                // input data passed to the method
    $context->parameters;          // named parameters
    $context->result;              // raw result (after phase only)
    $context->service;             // the service instance
    $context->user;                // current authenticated user (or null)
    $context->metadata;            // array of context metadata

    // Helper methods
    $context->getModelFromResult();     // extracts Eloquent model from result
    $context->getDataFromResult();      // unwraps WrappedResponseInterface if needed
    $context->getStatusCode();          // HTTP status code if result is WrappedResponse
    $context->getMessage();             // message if result is WrappedResponse
    $context->isSuccessful();           // true for 2xx status codes
    $context->getUserId();              // auth user ID
    $context->getModelAttributes();     // model->toArray()
    $context->getModelChanges();        // model->getChanges()
    $context->isBefore();
    $context->isAfter();
    $context->getParameter('key');
    $context->getMetadata('key');
}
```

## Custom Hook Class

```php
use Ahmed3bead\LaravelHooks\BaseHookJob;
use Ahmed3bead\LaravelHooks\HookContext;

class PriorityAuditHook extends BaseHookJob
{
    protected int $priority = 10;      // lower = runs first
    protected bool $async = true;
    protected string $queueName = 'hooks';
    protected int $retryAttempts = 3;

    public function handle(HookContext $context): void
    {
        // your logic
    }
}
```

## Registering Hooks via `ServiceHookTrait`

From inside the service using `registerHooks()` (called automatically on first use):

```php
class OrderService
{
    use ServiceHookTrait;

    protected function registerHooks(): void
    {
        $this->addServiceSyncHook('after', 'create', OrderAuditHook::class);
        $this->addServiceQueuedHook('after', 'create', SendOrderEmailHook::class);
        $this->addServiceDelayedHook('after', 'create', FollowUpHook::class, delay: 86400);
        $this->addServiceBatchedHook('after', 'index', AnalyticsHook::class);
    }
}
```

## Artisan Commands

### Generate a new hook class

```bash
php artisan make:hook UserCreated
php artisan make:hook UserCreated --type=audit --phase=after --queue
php artisan make:hook UserCreated --type=notification --sync
```

Available `--type` values: `audit`, `notification`, `cache`, `logging`, `validation`, `security`, `analytics`, `general`

### Manage hooks at runtime

```bash
php artisan hooks:manage list               # list all registered hooks
php artisan hooks:manage stats              # show statistics
php artisan hooks:manage test               # sanity-check the hook system
php artisan hooks:manage enable             # enable hook execution
php artisan hooks:manage disable --force    # disable hook execution
php artisan hooks:manage clear --force      # remove all registered hooks
php artisan hooks:manage flush              # flush pending batched hooks
php artisan hooks:manage export --export=hooks.json
php artisan hooks:manage debug --service="App\Services\UserService"
```

## Global Hooks

Run for every service that uses `ServiceHookTrait`:

```php
$hooks->addGlobalHook('create', 'after', GlobalAuditHook::class);
```

## Priority

Lower numbers run first (default is 100):

```php
$hooks->addHook(UserService::class, 'create', 'after', EarlyHook::class, 'sync', ['priority' => 10]);
$hooks->addHook(UserService::class, 'create', 'after', LateHook::class,  'sync', ['priority' => 200]);
```

## Implementing `WrappedResponseInterface`

If your service returns a custom response object, implement this interface so hooks can extract model/status data:

```php
use Ahmed3bead\LaravelHooks\Contracts\WrappedResponseInterface;

class ApiResponse implements WrappedResponseInterface
{
    public function getData(): mixed { return $this->data; }
    public function getStatusCode(): int { return $this->statusCode; }
    public function getMessage(): string { return $this->message; }
}
```

## Testing

```bash
composer install
vendor/bin/pest
vendor/bin/pest --coverage
```

## License

MIT
