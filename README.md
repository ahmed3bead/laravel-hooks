<p align="center">
  <img src="https://github.com/ahmed3bead/laravel-hooks/blob/main/image.png?raw=true" width="100%" alt="Lara-CRUD Banner">
</p>

# Laravel Hooks

Add **before**, **after**, and **error** lifecycle hooks to any method in any class — controllers, services, jobs, models, or plain PHP objects.

No event system boilerplate. No observers. Just attach a hook to a method and it runs automatically.

---

## Requirements

- PHP 8.2+
- Laravel 10, 11, 12, or 13

---

## Installation

```bash
composer require ahmedebead/laravel-hooks
```

Laravel auto-discovers the package. To publish the config:

```bash
php artisan vendor:publish --tag=laravel-hooks-config
```

---

## Quick Start

### 1. Add the trait to any class

```php
use Ahmed3bead\LaravelHooks\HookableTrait;

class OrderService
{
    use HookableTrait;

    public function create(array $data): Order
    {
        return $this->executeWithHooks('create', function () use ($data) {
            return Order::create($data);
        });
    }
}
```

### 2. Create a hook

```bash
php artisan make:hook OrderCreated --type=audit --phase=after --sync
```

Or manually:

```php
use Ahmed3bead\LaravelHooks\BaseHookJob;
use Ahmed3bead\LaravelHooks\HookContext;

class OrderCreatedHook extends BaseHookJob
{
    public function handle(HookContext $context): void
    {
        $order = $context->getModelFromResult();

        AuditLog::create([
            'action'   => 'order.created',
            'order_id' => $order?->id,
            'user_id'  => $context->getUserId(),
        ]);
    }
}
```

### 3. Register the hook

**From inside the class** — `registerHooks()` is called automatically when the class first uses hooks:

```php
class OrderService
{
    use HookableTrait;

    protected function registerHooks(): void
    {
        $this->afterHook('create', OrderCreatedHook::class);
    }
}
```

**From a service provider** — register hooks for any class from anywhere:

```php
use Ahmed3bead\LaravelHooks\HookManager;

class AppServiceProvider extends ServiceProvider
{
    public function boot(HookManager $hooks): void
    {
        $hooks->addSyncHook(OrderService::class, 'create', 'after', OrderCreatedHook::class);
    }
}
```

**Inline, anywhere** — fluent API on any instance:

```php
$orderService->afterHook('create', OrderCreatedHook::class);
```

---

## Works With Any Class

`HookableTrait` is not limited to services:

```php
class UserController  { use HookableTrait; }
class ProcessOrderJob { use HookableTrait; }
class DataImporter    { use HookableTrait; }
class User extends Model { use HookableTrait; }
```

---

## Execution Phases

| Phase   | When it fires |
|---------|---------------|
| `before` | Before the method body runs |
| `after`  | After the method returns |
| `error`  | When the method throws — hook fires, exception re-thrown |

---

## Registering Hooks

### From inside the class (public API)

```php
// Phase shortcuts — always synchronous
$this->beforeHook('create', MyHook::class);
$this->afterHook('create', MyHook::class);
$this->errorHook('create', MyHook::class);

// Explicit sync — choose any phase
$this->syncHook('before', 'create', MyHook::class);
$this->syncHook('after',  'create', MyHook::class);
$this->syncHook('error',  'create', MyHook::class);

// Inline closure — no separate class needed
$this->syncHookWithLogic('after', 'create', function (HookContext $ctx) {
    Log::info('Created', ['user' => $ctx->getUserId()]);
});

// Any strategy
$this->hook('after', 'create', MyHook::class, strategy: 'queue');
$this->hook('after', 'create', MyHook::class, strategy: 'delay', options: ['delay' => 300]);

// Inline closure with any strategy
$this->hookWithLogic('after', 'create', function (HookContext $ctx) {
    // runs in the background
}, strategy: 'queue');
```

### From outside the class (HookManager)

```php
$hooks = app(HookManager::class);

$hooks->addSyncHook(OrderService::class, 'create', 'after', AuditHook::class);
$hooks->addQueuedHook(OrderService::class, 'create', 'after', EmailHook::class);
$hooks->addDelayedHook(OrderService::class, 'create', 'after', FollowUpHook::class, delay: 3600);
$hooks->addBatchedHook(OrderService::class, 'index', 'after', AnalyticsHook::class);

// Register multiple at once
$hooks->addHooks([
    ['target' => OrderService::class, 'method' => 'create', 'phase' => 'after', 'hook' => AuditHook::class],
    ['target' => OrderService::class, 'method' => 'update', 'phase' => 'after', 'hook' => AuditHook::class],
]);

// Global hook — fires for every class using HookableTrait
$hooks->addGlobalHook('create', 'after', GlobalAuditHook::class);
```

---

## Execution Strategies

### Sync (default)

Runs immediately in the same request.

```php
$this->afterHook('create', AuditHook::class);
// or
$hooks->addSyncHook(OrderService::class, 'create', 'after', AuditHook::class);
```

### Queued

Pushed to a Laravel queue and processed in the background.

```php
$this->hook('after', 'create', EmailHook::class, strategy: 'queue');
// or
$hooks->addQueuedHook(OrderService::class, 'create', 'after', EmailHook::class);
```

### Delayed

Queued with a delay (seconds).

```php
$this->hook('after', 'create', FollowUpHook::class, strategy: 'delay', options: ['delay' => 3600]);
// or
$hooks->addDelayedHook(OrderService::class, 'create', 'after', FollowUpHook::class, delay: 3600);
```

### Batched

Collects executions and processes them together when the batch is full.

```php
$hooks->addBatchedHook(OrderService::class, 'index', 'after', AnalyticsHook::class, options: [
    'batch_size' => 50,
    'batch_delay' => 60, // seconds
]);
```

### Conditional

Wraps any strategy with runtime conditions.

```php
use Ahmed3bead\LaravelHooks\Strategies\ConditionalHookStrategy;
use Ahmed3bead\LaravelHooks\Strategies\SyncHookStrategy;

$strategy = (new ConditionalHookStrategy(new SyncHookStrategy()))
    ->onlyInEnvironment('production')
    ->onlyWhenConfigEnabled('features.audit');

$hooks->registerStrategy('prod_audit', $strategy);
$hooks->addHook(OrderService::class, 'create', 'after', AuditHook::class, 'prod_audit');
```

---

## Wrapping a Method

Use `executeWithHooks()` inside any method to trigger the full before/after/error cycle automatically:

```php
public function process(array $data): mixed
{
    return $this->executeWithHooks('process', function () use ($data) {
        return $this->doWork($data);
    }, $data); // optional: pass $data into HookContext::$data
}
```

---

## Writing Hook Classes

### Extend BaseHookJob

```php
use Ahmed3bead\LaravelHooks\BaseHookJob;
use Ahmed3bead\LaravelHooks\HookContext;

class MyHook extends BaseHookJob
{
    protected int $priority = 10;           // lower = runs first (default: 100)
    protected bool $async = false;          // true = queued
    protected string $queueName = 'hooks';  // queue name when async

    public function handle(HookContext $context): void
    {
        // your logic
    }
}
```

### HookContext — what's available

```php
public function handle(HookContext $context): void
{
    // Core
    $context->method;                  // 'create', 'update', ...
    $context->phase;                   // 'before', 'after', 'error'
    $context->data;                    // value passed as $data to executeWithHooks()
    $context->result;                  // raw return value (after/error phase)
    $context->target;                  // the object the method was called on
    $context->user;                    // Auth::user() at the time of the call

    // Helpers
    $context->isBefore();              // true when phase === 'before'
    $context->isAfter();               // true when phase === 'after'
    $context->getParameter('key');     // named parameter by key
    $context->getMetadata('key');      // metadata value by key

    // Model extraction (works with plain models and wrapped responses)
    $context->getModelFromResult();    // first Eloquent model found in result
    $context->getDataFromResult();     // unwrapped data from result
    $context->getModelAttributes();    // model->toArray()
    $context->getModelChanges();       // model->getChanges()
    $context->getOriginalAttributes(); // model->getOriginal()
    $context->wasModelRecentlyCreated(); // model->wasRecentlyCreated

    // Response helpers (when result implements WrappedResponseInterface)
    $context->getStatusCode();         // HTTP status code
    $context->getMessage();            // response message
    $context->isSuccessful();          // true for 2xx status codes
    $context->hasWrappedResponse();    // true if result is a WrappedResponseInterface

    // User
    $context->getUserId();             // $user->id ?? $user->getKey()
}
```

### Conditional execution

```php
class MyHook extends BaseHookJob
{
    public function shouldExecute(HookContext $context): bool
    {
        return $context->user !== null; // only run for authenticated users
    }
}
```

---

## Inline Closures (No Class Required)

For quick, one-off hooks you don't need a full class:

```php
// Synchronous inline hook
$this->syncHookWithLogic('after', 'create', function (HookContext $ctx) {
    Log::info('Created', [
        'method'  => $ctx->method,
        'user_id' => $ctx->getUserId(),
    ]);
});

// Inline hook with any strategy
$this->hookWithLogic('after', 'create', function (HookContext $ctx) {
    dispatch(new SendWelcomeEmail($ctx->getUserId()));
}, strategy: 'queue');
```

---

## Priority

Lower numbers run first. Default priority is `100`.

```php
// Runs first
$hooks->addHook(OrderService::class, 'create', 'after', ValidationHook::class, 'sync', ['priority' => 10]);

// Runs last
$hooks->addHook(OrderService::class, 'create', 'after', NotificationHook::class, 'sync', ['priority' => 200]);
```

---

## Custom Response Objects

If your methods return a custom response wrapper, implement `WrappedResponseInterface` so hooks can extract the model and status automatically:

```php
use Ahmed3bead\LaravelHooks\Contracts\WrappedResponseInterface;

class ApiResponse implements WrappedResponseInterface
{
    public function getData(): mixed      { return $this->data; }
    public function getStatusCode(): int  { return $this->statusCode; }
    public function getMessage(): string  { return $this->message; }
}
```

---

## Artisan Commands

### Generate a hook class

```bash
php artisan make:hook OrderCreated
php artisan make:hook OrderCreated --type=audit --phase=after --queue
php artisan make:hook OrderCreated --type=notification --sync
```

Available `--type` values: `audit`, `notification`, `cache`, `logging`, `validation`, `security`, `analytics`, `general`

### Manage hooks at runtime

```bash
php artisan hooks:manage list                                        # all registered hooks
php artisan hooks:manage stats                                       # counts, strategies, debug mode
php artisan hooks:manage debug --target="App\Services\OrderService" # hooks for one class
php artisan hooks:manage test                                        # verify the system loads
php artisan hooks:manage enable
php artisan hooks:manage disable --force
php artisan hooks:manage clear  --force
php artisan hooks:manage flush                                       # flush pending batched hooks
php artisan hooks:manage export --export=hooks.json
```

---

## Configuration

`config/laravel-hooks.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `true` | Enable/disable all hooks globally |
| `debug` | `false` | Log every hook registration and execution |
| `queue_connection` | `null` | Queue connection for async hooks (`null` = default) |
| `default_queue` | `'default'` | Queue name for queued/delayed hooks |
| `batch_queue` | `'batch'` | Queue name for batched hooks |
| `generation_directory` | `'App\\Hooks'` | Namespace for generated hook classes |

Environment variables:

```env
LARAVEL_HOOKS_ENABLED=true
LARAVEL_HOOKS_DEBUG=false
LARAVEL_HOOKS_QUEUE_CONNECTION=redis
LARAVEL_HOOKS_DEFAULT_QUEUE=default
LARAVEL_HOOKS_BATCH_QUEUE=batch
```

---

## Testing

```bash
vendor/bin/pest
vendor/bin/pest --coverage
```

Use `Queue::fake()` to assert queued hooks were dispatched without processing them:

```php
use Illuminate\Support\Facades\Queue;
use Ahmed3bead\LaravelHooks\Jobs\QueuedHookJob;

Queue::fake();

$service->create(['name' => 'Test']);

Queue::assertPushed(QueuedHookJob::class);
```

---

## Upgrading from an Earlier Version

### Renamed identifiers

| Old | New |
|-----|-----|
| `HookContext::$service` | `HookContext::$target` |
| `addServiceSyncHook()` | `addSyncHookRegistration()` |
| `addServiceQueuedHook()` | `addQueuedHookRegistration()` |
| `addServiceDelayedHook()` | `addDelayedHookRegistration()` |
| `addServiceBatchedHook()` | `addBatchedHookRegistration()` |
| `addServiceHook()` | `addHookRegistration()` |
| `removeServiceHooks()` | `removeHooks()` |
| `removeServiceHook()` | `removeHook()` |
| `enableServiceHooks()` | `enableHooks()` |
| `debugService()` | `debugTarget()` |
| `--service` CLI flag | `--target` CLI flag |
| `total_service_hooks` stat key | `total_target_hooks` |
| `service_hooks` array key | `target_hooks` |

All old names still work but emit `E_USER_DEPRECATED`. Update at your own pace.

---

## License

MIT
