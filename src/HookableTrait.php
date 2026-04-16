<?php

namespace Ahmed3bead\LaravelHooks;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Hook Trait
 *
 * Adds before/after/error lifecycle hooks to any class — controllers,
 * services, jobs, models, or plain PHP objects.
 *
 * Quick-start from inside the class:
 *   $this->afterHook('create', MyHook::class);
 *
 * Quick-start from outside (service provider, test, anywhere):
 *   app(HookManager::class)->addSyncHook(MyClass::class, 'create', 'after', MyHook::class);
 */
trait HookableTrait
{
    private ?HookManager $hookManager = null;

    private bool $hooksInitialized = false;

    /**
     * List of methods that support hooks. Empty means all methods are hookable.
     */
    protected array $hookableMethods = [];

    // -------------------------------------------------------------------------
    // Public API — works in any class (controllers, services, jobs, models, etc.)
    // -------------------------------------------------------------------------

    /**
     * Register a hook.
     *
     * @param  string  $phase  'before', 'after', or 'error'
     * @param  string  $method  The method name to attach the hook to
     * @param  string  $hookClass  Hook class implementing HookJobInterface
     * @param  string  $strategy  'sync' (default), 'queue', 'delay', or 'batch'
     * @param  array  $options  Optional: priority, delay, batch_size, enabled, ...
     */
    public function hook(
        string $phase,
        string $method,
        string $hookClass,
        string $strategy = 'sync',
        array $options = []
    ): static {
        return $this->addHookRegistration($phase, $method, $hookClass, $strategy, $options);
    }

    /**
     * Register a synchronous before-hook.
     */
    public function beforeHook(string $method, string $hookClass, array $options = []): static
    {
        return $this->addHookRegistration('before', $method, $hookClass, 'sync', $options);
    }

    /**
     * Register a synchronous after-hook.
     */
    public function afterHook(string $method, string $hookClass, array $options = []): static
    {
        return $this->addHookRegistration('after', $method, $hookClass, 'sync', $options);
    }

    /**
     * Register a synchronous error-hook (fires when the method throws).
     */
    public function errorHook(string $method, string $hookClass, array $options = []): static
    {
        return $this->addHookRegistration('error', $method, $hookClass, 'sync', $options);
    }

    /**
     * Register a synchronous hook — explicit sync alias.
     *
     * @param  string  $phase  'before', 'after', or 'error'
     */
    public function syncHook(string $phase, string $method, string $hookClass, array $options = []): static
    {
        return $this->addHookRegistration($phase, $method, $hookClass, 'sync', $options);
    }

    /**
     * Register an inline callable as a synchronous hook — no separate class needed.
     *
     * Example:
     *   $this->syncHookWithLogic('after', 'create', function (HookContext $ctx) {
     *       Log::info('Created', ['method' => $ctx->method]);
     *   });
     *
     * @param  string  $phase  'before', 'after', or 'error'
     * @param  string  $method  The method to attach the hook to
     * @param  callable  $callback  Callable that receives a HookContext argument
     * @param  array  $options  Optional: priority, enabled, ...
     */
    public function syncHookWithLogic(
        string $phase,
        string $method,
        callable $callback,
        array $options = []
    ): static {
        return $this->hookWithLogic($phase, $method, $callback, 'sync', $options);
    }

    /**
     * Register an inline callable as a hook with any strategy.
     *
     * @param  string  $phase  'before', 'after', or 'error'
     * @param  string  $method  The method to attach the hook to
     * @param  callable  $callback  Callable that receives a HookContext argument
     * @param  string  $strategy  'sync' (default), 'queue', 'delay', or 'batch'
     * @param  array  $options  Optional: priority, enabled, ...
     */
    public function hookWithLogic(
        string $phase,
        string $method,
        callable $callback,
        string $strategy = 'sync',
        array $options = []
    ): static {
        // Generate a unique class alias for this closure and bind it in the container.
        $uniqueId = 'closure_hook_'.spl_object_id((object) []).'_'.uniqid();
        app()->bind($uniqueId, fn () => new ClosureHookJob($callback));

        return $this->addHookRegistration($phase, $method, $uniqueId, $strategy, $options);
    }

    // -------------------------------------------------------------------------
    // Internal / advanced helpers (protected — override in subclasses as needed)
    // -------------------------------------------------------------------------

    /**
     * Add a synchronous hook registration.
     */
    protected function addSyncHookRegistration(
        string $phase,
        string $method,
        string $hookClass,
        array $options = []
    ): self {
        $this->getHookManager()->addSyncHook(
            static::class,
            $method,
            $phase,
            $hookClass,
            $options
        );

        return $this;
    }

    /**
     * Get or create the HookManager instance.
     */
    protected function getHookManager(): HookManager
    {
        if ($this->hookManager === null) {
            $this->hookManager = app(HookManager::class);
            $this->initializeHooks();
        }

        return $this->hookManager;
    }

    /**
     * Initialize hooks for this class (calls registerHooks() if defined).
     */
    private function initializeHooks(): void
    {
        if ($this->hooksInitialized) {
            return;
        }

        if (method_exists($this, 'registerHooks')) {
            $this->registerHooks();
        }

        $this->hooksInitialized = true;
    }

    /**
     * Override this method in your class to register hooks automatically on instantiation.
     *
     * Example:
     *   protected function registerHooks(): void
     *   {
     *       $this->afterHook('create', MyHook::class);
     *   }
     */
    protected function registerHooks(): void
    {
        // Default empty implementation.
    }

    /**
     * Add a queued hook registration.
     */
    protected function addQueuedHookRegistration(
        string $phase,
        string $method,
        string $hookClass,
        array $options = []
    ): self {
        $this->getHookManager()->addQueuedHook(
            static::class,
            $method,
            $phase,
            $hookClass,
            $options
        );

        return $this;
    }

    /**
     * Add a delayed hook registration.
     */
    protected function addDelayedHookRegistration(
        string $phase,
        string $method,
        string $hookClass,
        int $delay = 30,
        array $options = []
    ): self {
        $this->getHookManager()->addDelayedHook(
            static::class,
            $method,
            $phase,
            $hookClass,
            $delay,
            $options
        );

        return $this;
    }

    /**
     * Add a batched hook registration.
     */
    protected function addBatchedHookRegistration(
        string $phase,
        string $method,
        string $hookClass,
        array $options = []
    ): self {
        $this->getHookManager()->addBatchedHook(
            static::class,
            $method,
            $phase,
            $hookClass,
            $options
        );

        return $this;
    }

    /**
     * Add multiple hook registrations at once.
     */
    protected function addHookRegistrations(array $hookDefinitions): self
    {
        foreach ($hookDefinitions as $definition) {
            $this->addHookRegistration(
                $definition['phase'],
                $definition['method'],
                $definition['hook'],
                $definition['strategy'] ?? 'sync',
                $definition['options'] ?? []
            );
        }

        return $this;
    }

    /**
     * Add a hook registration with a custom strategy.
     */
    protected function addHookRegistration(
        string $phase,
        string $method,
        string $hookClass,
        string $strategy = 'sync',
        array $options = []
    ): self {
        $this->getHookManager()->addHook(
            static::class,
            $method,
            $phase,
            $hookClass,
            $strategy,
            $options
        );

        return $this;
    }

    /**
     * Remove all hooks for a method and phase.
     */
    protected function removeHooks(string $method, string $phase): self
    {
        $this->getHookManager()->removeHooks(static::class, $method, $phase);

        return $this;
    }

    /**
     * Remove a specific hook class from a method and phase.
     */
    protected function removeHook(
        string $method,
        string $phase,
        string $hookClass
    ): self {
        $this->getHookManager()->removeHook(static::class, $method, $phase, $hookClass);

        return $this;
    }

    /**
     * Enable or disable hook execution for this class.
     */
    protected function enableHooks(bool $enabled = true): self
    {
        $this->getHookManager()->enable($enabled);

        return $this;
    }

    /**
     * Debug: return all registered hooks for this class.
     */
    protected function debugHooks(): array
    {
        return $this->getHookManager()->debugTarget(static::class);
    }

    /**
     * Get hook execution statistics.
     */
    protected function getHookStats(): array
    {
        return $this->getHookManager()->getStats();
    }

    /**
     * Execute a method with automatic before/after/error hook execution.
     *
     * Usage:
     *   return $this->executeWithHooks('create', function () use ($data) {
     *       return MyModel::create($data);
     *   }, $data);
     */
    protected function executeWithHooks(
        string $method,
        callable $callback,
        mixed $data = null,
        array $parameters = []
    ): mixed {
        $this->executeBeforeHooks($method, $data, $parameters);

        try {
            $result = $callback();
            $this->executeAfterHooks($method, $data, $parameters, $result);

            return $result;
        } catch (\Exception $e) {
            $this->executeErrorHooks($method, $data, $parameters, $e);
            throw $e;
        }
    }

    /**
     * Execute before-hooks for a method.
     */
    protected function executeBeforeHooks(
        string $method,
        mixed $data = null,
        array $parameters = []
    ): void {
        if (! $this->shouldExecuteHooks()) {
            return;
        }

        $context = $this->getHookManager()->createBeforeContext(
            method: $method,
            data: $data,
            parameters: $parameters,
            target: $this,
            user: $this->getCurrentUser(),
            metadata: $this->getHookMetadata($method, 'before')
        );

        $this->getHookManager()->executeHooks($context);
    }

    /**
     * Check if hooks should be executed.
     */
    protected function shouldExecuteHooks(): bool
    {
        return $this->getHookManager()->isEnabled() &&
            config('laravel-hooks.enabled', true);
    }

    /**
     * Get the current authenticated user for the hook context.
     */
    protected function getCurrentUser(): ?object
    {
        return Auth::user();
    }

    /**
     * Get metadata to attach to the hook context.
     */
    protected function getHookMetadata(string $method, string $phase): array
    {
        return [
            'target_class' => static::class,
            'timestamp' => now(),
            'request_id' => request()?->id ?? null,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ];
    }

    /**
     * Execute after-hooks for a method.
     */
    protected function executeAfterHooks(
        string $method,
        mixed $data = null,
        array $parameters = [],
        mixed $result = null
    ): void {
        if (! $this->shouldExecuteHooks()) {
            return;
        }

        $context = $this->getHookManager()->createAfterContext(
            method: $method,
            data: $data,
            parameters: $parameters,
            result: $result,
            target: $this,
            user: $this->getCurrentUser(),
            metadata: $this->getHookMetadata($method, 'after')
        );

        $this->getHookManager()->executeHooks($context);
    }

    /**
     * Execute error-hooks (fires when the method throws; exception is re-thrown after).
     */
    protected function executeErrorHooks(
        string $method,
        mixed $data = null,
        array $parameters = [],
        ?\Exception $error = null
    ): void {
        if (! $this->shouldExecuteHooks()) {
            return;
        }

        $context = new HookContext(
            method: $method,
            phase: 'error',
            data: $data,
            parameters: $parameters,
            result: $error,
            target: $this,
            user: $this->getCurrentUser(),
            metadata: array_merge(
                $this->getHookMetadata($method, 'error'),
                ['error_message' => $error?->getMessage()]
            )
        );

        try {
            $this->getHookManager()->executeHooks($context);
        } catch (\Exception $hookError) {
            Log::error('Error hook execution failed', [
                'target' => static::class,
                'method' => $method,
                'original_error' => $error?->getMessage(),
                'hook_error' => $hookError->getMessage(),
            ]);
        }
    }

    /**
     * Bulk register hooks from an array configuration.
     */
    protected function registerHooksFromConfig(array $config): self
    {
        foreach ($config as $hookDef) {
            $this->addHookRegistration(
                $hookDef['phase'],
                $hookDef['method'],
                $hookDef['hook'],
                $hookDef['strategy'] ?? 'sync',
                $hookDef['options'] ?? []
            );
        }

        return $this;
    }

    /**
     * Register a hook that only runs when a runtime condition passes.
     */
    protected function addConditionalHook(
        string $phase,
        string $method,
        string $hookClass,
        callable $condition,
        string $strategy = 'sync',
        array $options = []
    ): self {
        $options['conditions'] = $options['conditions'] ?? [];
        $options['conditions'][] = $condition;

        return $this->addHookRegistration($phase, $method, $hookClass, $strategy, $options);
    }

    /**
     * Register the same hook on multiple methods at once.
     */
    protected function addHookForMethods(
        string $phase,
        array $methods,
        string $hookClass,
        string $strategy = 'sync',
        array $options = []
    ): self {
        foreach ($methods as $method) {
            $this->addHookRegistration($phase, $method, $hookClass, $strategy, $options);
        }

        return $this;
    }

    /**
     * Register a hook with an explicit priority.
     */
    protected function addPriorityHook(
        string $phase,
        string $method,
        string $hookClass,
        int $priority,
        string $strategy = 'sync',
        array $options = []
    ): self {
        $options['priority'] = $priority;

        return $this->addHookRegistration($phase, $method, $hookClass, $strategy, $options);
    }

    /**
     * Execute hooks safely — logs failures but does not throw.
     */
    protected function safeExecuteHooks(
        string $method,
        string $phase,
        mixed $data = null,
        array $parameters = [],
        mixed $result = null
    ): void {
        try {
            $this->executeHooksIfSupported($method, $phase, $data, $parameters, $result);
        } catch (\Exception $e) {
            Log::warning('Hook execution failed but continuing', [
                'target' => static::class,
                'method' => $method,
                'phase' => $phase,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Execute hooks only if the method is in the hookableMethods allow-list.
     */
    protected function executeHooksIfSupported(
        string $method,
        string $phase,
        mixed $data = null,
        array $parameters = [],
        mixed $result = null
    ): void {
        if (! $this->methodSupportsHooks($method)) {
            return;
        }

        if ($phase === 'before') {
            $this->executeBeforeHooks($method, $data, $parameters);
        } else {
            $this->executeAfterHooks($method, $data, $parameters, $result);
        }
    }

    /**
     * Check if a method supports hooks.
     * If $hookableMethods is empty, all methods are hookable by default.
     */
    protected function methodSupportsHooks(string $method): bool
    {
        if (empty($this->hookableMethods)) {
            return true;
        }

        return in_array($method, $this->hookableMethods);
    }

    /**
     * Get the class identifier used for hook registration.
     */
    protected function getTargetIdentifier(): string
    {
        return static::class;
    }

    // -------------------------------------------------------------------------
    // Deprecated aliases — kept for backward compatibility
    // -------------------------------------------------------------------------

    /** @deprecated Use addSyncHookRegistration() instead. */
    protected function addServiceSyncHook(string $phase, string $method, string $hookClass, array $options = []): self
    {
        trigger_error('addServiceSyncHook() is deprecated, use addSyncHookRegistration() instead.', E_USER_DEPRECATED);

        return $this->addSyncHookRegistration($phase, $method, $hookClass, $options);
    }

    /** @deprecated Use addQueuedHookRegistration() instead. */
    protected function addServiceQueuedHook(string $phase, string $method, string $hookClass, array $options = []): self
    {
        trigger_error('addServiceQueuedHook() is deprecated, use addQueuedHookRegistration() instead.', E_USER_DEPRECATED);

        return $this->addQueuedHookRegistration($phase, $method, $hookClass, $options);
    }

    /** @deprecated Use addDelayedHookRegistration() instead. */
    protected function addServiceDelayedHook(string $phase, string $method, string $hookClass, int $delay = 30, array $options = []): self
    {
        trigger_error('addServiceDelayedHook() is deprecated, use addDelayedHookRegistration() instead.', E_USER_DEPRECATED);

        return $this->addDelayedHookRegistration($phase, $method, $hookClass, $delay, $options);
    }

    /** @deprecated Use addBatchedHookRegistration() instead. */
    protected function addServiceBatchedHook(string $phase, string $method, string $hookClass, array $options = []): self
    {
        trigger_error('addServiceBatchedHook() is deprecated, use addBatchedHookRegistration() instead.', E_USER_DEPRECATED);

        return $this->addBatchedHookRegistration($phase, $method, $hookClass, $options);
    }

    /** @deprecated Use addHookRegistration() instead. */
    protected function addServiceHook(string $phase, string $method, string $hookClass, string $strategy = 'sync', array $options = []): self
    {
        trigger_error('addServiceHook() is deprecated, use addHookRegistration() instead.', E_USER_DEPRECATED);

        return $this->addHookRegistration($phase, $method, $hookClass, $strategy, $options);
    }

    /** @deprecated Use addHookRegistrations() instead. */
    protected function addServiceHooks(array $hookDefinitions): self
    {
        trigger_error('addServiceHooks() is deprecated, use addHookRegistrations() instead.', E_USER_DEPRECATED);

        return $this->addHookRegistrations($hookDefinitions);
    }

    /** @deprecated Use removeHooks() instead. */
    protected function removeServiceHooks(string $method, string $phase): self
    {
        trigger_error('removeServiceHooks() is deprecated, use removeHooks() instead.', E_USER_DEPRECATED);

        return $this->removeHooks($method, $phase);
    }

    /** @deprecated Use removeHook() instead. */
    protected function removeServiceHook(string $method, string $phase, string $hookClass): self
    {
        trigger_error('removeServiceHook() is deprecated, use removeHook() instead.', E_USER_DEPRECATED);

        return $this->removeHook($method, $phase, $hookClass);
    }

    /** @deprecated Use enableHooks() instead. */
    protected function enableServiceHooks(bool $enabled = true): self
    {
        trigger_error('enableServiceHooks() is deprecated, use enableHooks() instead.', E_USER_DEPRECATED);

        return $this->enableHooks($enabled);
    }

    /** @deprecated Use debugHooks() instead. */
    protected function debugServiceHooks(): array
    {
        trigger_error('debugServiceHooks() is deprecated, use debugHooks() instead.', E_USER_DEPRECATED);

        return $this->debugHooks();
    }

    /** @deprecated Use getTargetIdentifier() instead. */
    protected function getServiceIdentifier(): string
    {
        trigger_error('getServiceIdentifier() is deprecated, use getTargetIdentifier() instead.', E_USER_DEPRECATED);

        return $this->getTargetIdentifier();
    }
}
