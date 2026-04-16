# Laravel Hooks

Add **before**, **after**, and **error** lifecycle callbacks to any method in any class — services, controllers, jobs, models, or plain PHP objects.

No event system boilerplate. No observers. Just attach a hook class to a method and it runs automatically.

---

## Requirements

- PHP 8.2+
- Laravel 10, 11, 12, or 13

## Installation

```bash
composer require ahmedebead/laravel-hooks
```

That's it. Laravel auto-discovers the package.

To publish the config file:

```bash
php artisan vendor:publish --tag=laravel-hooks-config
```

---

## Quick Start

### Step 1 — Add the trait to any class

```php
use Ahmed3bead\LaravelHooks\HookableTrait;

class OrderService
{
    use HookableTrait;

    public function create(array $data): Order
    {
        // Wrap the real work with executeWithHooks()
        return $this->executeWithHooks('create', function () use ($data) {
            return Order::create($data);
        });
    }
}
```

### Step 2 — Create a hook

```bash
php artisan make:hook OrderCreated --type=audit --phase=after --sync
```

Or create it manually:

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

### Step 3 — Register the hook

**Option A — from inside the class** (in a `registerHooks()` method that the trait calls automatically):

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

**Option B — from a service provider** (register hooks for any class, anywhere):

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

**Option C — inline, anywhere in your code**:

```php
$orderService = new OrderService();
$orderService->afterHook('create', OrderCreatedHook::class);
```

---

## The Trait — Full API

Add `HookableTrait` to any class (not just services):

```php
use Ahmed3bead\LaravelHooks\HookableTrait;

class MyController  { use HookableTrait; }
class MyJob         { use HookableTrait; }
class MyProcessor   { use HookableTrait; }
```

### Register hooks (public methods)

```php
// Phase shortcuts (always sync)
$this->beforeHook('methodName', MyHook::class);
$this->afterHook('methodName', MyHook::class);
$this->errorHook('methodName', MyHook::class);

// Explicit sync (choose the phase)
$this->syncHook('before', 'methodName', MyHook::class);
$this->syncHook('after',  'methodName', MyHook::class);
$this->syncHook('error',  'methodName', MyHook::class);

// With strategy and options
$this->hook('after', 'methodName', MyHook::class, strategy: 'queue');
$this->hook('after', 'methodName', MyHook::class, strategy: 'delay', options: ['delay' => 300]);
```

### Wrap a method call

```php
public function process(array $data): mixed
{
    return $this->executeWithHooks('process', function () use ($data) {
        // your real logic here
        return $this->doWork($data);
    }, $data); // optional: pass data to the context
}
```

---

## Execution Phases

| Phase   | When it fires                                         |
|---------|-------------------------------------------------------|
| `before` | Before the method body runs                          |
| `after`  | After the method returns a result                    |
| `error`  | When the method throws — hook runs, exception re-thrown |

---

## Execution Strategies

### Sync (default) — runs immediately, same request

```php
$hooks->addSyncHook(OrderService::class, 'create', 'after', AuditHook::class);
// or
$this->afterHook('create', AuditHook::class);
```

### Queued — runs in the background via Laravel queues

```php
$hooks->addQueuedHook(OrderService::class, 'create', 'after', SendEmailHook::class);
// or
$this->hook('after', 'create', SendEmailHook::class, strategy: 'queue');
```

### Delayed — queued with a delay (seconds)

```php
$hooks->addDelayedHook(OrderService::class, 'create', 'after', FollowUpHook::class, delay: 3600);
// or
$this->hook('after', 'create', FollowUpHook::class, strategy: 'delay', options: ['delay' => 3600]);
```

### Batched — collected and processed together

```php
$hooks->addBatchedHook(OrderService::class, 'index', 'after', AnalyticsHook::class, options: ['batch_size' => 50]);
```

### Conditional — wraps any strategy with runtime conditions

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

## Hook Classes

### Using the base class

```php
use Ahmed3bead\LaravelHooks\BaseHookJob;
use Ahmed3bead\LaravelHooks\HookContext;

class MyHook extends BaseHookJob
{
    protected int $priority = 10;          // lower = runs first (default: 100)
    protected bool $async = false;         // true = queued
    protected string $queueName = 'hooks'; // queue to use when async

    public function handle(HookContext $context): void
    {
        // your logic
    }
}
```

### Available context helpers

```php
public function handle(HookContext $context): void
{
    $context->method;                  // 'create', 'update', ...
    $context->phase;                   // 'before', 'after', 'error'
    $context->data;                    // input data
    $context->result;                  // raw return value (after phase)
    $context->service;                 // the object the method was called on
    $context->user;                    // Auth::user() at time of call

    $context->getModelFromResult();    // extracts Eloquent model from result
    $context->getDataFromResult();     // unwraps custom response objects
    $context->getStatusCode();         // HTTP status if result is WrappedResponse
    $context->getMessage();            // message if result is WrappedResponse
    $context->isSuccessful();          // true for 2xx status codes
    $context->getUserId();             // auth user's ID
    $context->getModelAttributes();    // model->toArray()
    $context->getModelChanges();       // model->getChanges()
    $context->isBefore();
    $context->isAfter();
    $context->getParameter('key');
    $context->getMetadata('key');
}
```

### Adding conditions to a hook

```php
class MyHook extends BaseHookJob
{
    public function shouldExecute(HookContext $context): bool
    {
        // Only run when there is an authenticated user
        return $context->user !== null;
    }
}
```

---

## Priority

Lower numbers run first. Default is 100.

```php
$hooks->addHook(OrderService::class, 'create', 'after', EarlyHook::class, 'sync', ['priority' => 10]);
$hooks->addHook(OrderService::class, 'create', 'after', LateHook::class,  'sync', ['priority' => 200]);
```

---

## Global Hooks

Run for every class that uses `HookableTrait`, for a given method name:

```php
$hooks->addGlobalHook('create', 'after', GlobalAuditHook::class);
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

### Manage the hook system

```bash
php artisan hooks:manage list                        # show all registered hooks
php artisan hooks:manage stats                       # counts, strategies, debug mode
php artisan hooks:manage test                        # sanity-check that everything loads
php artisan hooks:manage enable
php artisan hooks:manage disable --force
php artisan hooks:manage clear --force
php artisan hooks:manage flush                       # flush pending batched hooks
php artisan hooks:manage export --export=hooks.json
php artisan hooks:manage debug --service="App\Services\OrderService"
```

---

## Custom Response Objects

If your methods return a custom response wrapper (not a plain model), implement `WrappedResponseInterface` so hooks can extract the model and status automatically:

```php
use Ahmed3bead\LaravelHooks\Contracts\WrappedResponseInterface;

class ApiResponse implements WrappedResponseInterface
{
    public function getData(): mixed   { return $this->data; }
    public function getStatusCode(): int { return $this->statusCode; }
    public function getMessage(): string { return $this->message; }
}
```

---

## Configuration

`config/laravel-hooks.php`:

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `true` | Enable/disable all hooks globally |
| `debug` | `false` | Log every hook registration and execution |
| `queue_connection` | `null` | Queue connection for async hooks (`null` = default) |
| `default_queue` | `'default'` | Queue name for queued hooks |
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

## Testing Your Hooks

```bash
vendor/bin/pest
vendor/bin/pest --coverage
```

In your own tests, use `Queue::fake()` to assert hooks were dispatched:

```php
use Illuminate\Support\Facades\Queue;
use Ahmed3bead\LaravelHooks\Jobs\QueuedHookJob;

Queue::fake();

$service->create(['name' => 'Test']);

Queue::assertPushed(QueuedHookJob::class);
```

---

## License

MIT
