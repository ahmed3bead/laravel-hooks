<?php

namespace Ahmed3bead\LaravelHooks;

use Ahmed3bead\LaravelHooks\Contracts\HookExecutionStrategy;
use Illuminate\Support\Facades\Log;

/**
 * Hook Manager
 *
 * Main facade for hook management operations. Provides a clean interface
 * for registering and executing hooks on any class — controllers, services,
 * jobs, models, or plain PHP objects.
 */
class HookManager
{
    private HookRegistry $registry;

    private array $middleware = [];

    private bool $debugMode = false;

    public function __construct(?HookRegistry $registry = null)
    {
        $this->registry = $registry ?? new HookRegistry;
        $this->debugMode = config('laravel-hooks.debug', false);
    }

    /**
     * Register a synchronous hook
     */
    public function addSyncHook(
        string $targetClass,
        string $method,
        string $phase,
        string $hookClass,
        array $options = []
    ): self {
        return $this->addHook($targetClass, $method, $phase, $hookClass, 'sync', $options);
    }

    /**
     * Register a queued hook
     */
    public function addQueuedHook(
        string $targetClass,
        string $method,
        string $phase,
        string $hookClass,
        array $options = []
    ): self {
        return $this->addHook($targetClass, $method, $phase, $hookClass, 'queue', $options);
    }

    /**
     * Register a delayed hook
     */
    public function addDelayedHook(
        string $targetClass,
        string $method,
        string $phase,
        string $hookClass,
        int $delay = 30,
        array $options = []
    ): self {
        $options['delay'] = $delay;

        return $this->addHook($targetClass, $method, $phase, $hookClass, 'delay', $options);
    }

    /**
     * Register a batched hook
     */
    public function addBatchedHook(
        string $targetClass,
        string $method,
        string $phase,
        string $hookClass,
        array $options = []
    ): self {
        return $this->addHook($targetClass, $method, $phase, $hookClass, 'batch', $options);
    }

    /**
     * Register a hook with custom strategy
     */
    public function addHook(
        string $targetClass,
        string $method,
        string $phase,
        string $hookClass,
        string $strategy = 'sync',
        array $options = []
    ): self {
        $this->validatePhase($phase);
        $this->validateStrategy($strategy);

        $this->registry->registerHook(
            $targetClass,
            $method,
            $phase,
            $hookClass,
            $strategy,
            $options
        );

        if ($this->debugMode) {
            Log::debug('Hook registered via manager', [
                'hook' => $hookClass,
                'phase' => $phase,
                'strategy' => $strategy,
                'target' => $targetClass,
                'method' => $method,
                'options' => $options,
            ]);
        }

        return $this;
    }

    /**
     * Register multiple hooks at once.
     *
     * Each definition must have a 'target' key (class name).
     * The legacy 'service' key is still accepted for backward compatibility.
     */
    public function addHooks(array $hookDefinitions): self
    {
        foreach ($hookDefinitions as $definition) {
            $this->addHook(
                $definition['target'] ?? $definition['service'],
                $definition['method'],
                $definition['phase'],
                $definition['hook'],
                $definition['strategy'] ?? 'sync',
                $definition['options'] ?? []
            );
        }

        return $this;
    }

    /**
     * Register a global hook that runs for every class using HookableTrait
     */
    public function addGlobalHook(
        string $method,
        string $phase,
        string $hookClass,
        string $strategy = 'sync',
        array $options = []
    ): self {
        $this->validatePhase($phase);
        $this->validateStrategy($strategy);

        $this->registry->registerGlobalHook(
            $method,
            $phase,
            $hookClass,
            $strategy,
            $options
        );

        return $this;
    }

    /**
     * Execute hooks for a given context
     */
    public function executeHooks(HookContext $context): void
    {
        if (! $this->registry->isEnabled()) {
            return;
        }

        $context = $this->applyMiddleware($context);

        $targetClass = get_class($context->target);

        if ($this->debugMode) {
            Log::debug('Executing hooks via manager', [
                'target' => $targetClass,
                'method' => $context->method,
                'phase' => $context->phase,
            ]);
        }

        try {
            $this->registry->executeHooks(
                $targetClass,
                $context->method,
                $context->phase,
                $context
            );
        } catch (\Exception $e) {
            Log::error('Hook execution failed in manager', [
                'target' => $targetClass,
                'method' => $context->method,
                'phase' => $context->phase,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Create hook context for before phase
     */
    public function createBeforeContext(
        string $method,
        mixed $data,
        array $parameters,
        object $target,
        ?object $user = null,
        array $metadata = []
    ): HookContext {
        return new HookContext(
            method: $method,
            phase: 'before',
            data: $data,
            parameters: $parameters,
            result: null,
            target: $target,
            user: $user,
            metadata: $metadata
        );
    }

    /**
     * Create hook context for after phase
     */
    public function createAfterContext(
        string $method,
        mixed $data,
        array $parameters,
        mixed $result,
        object $target,
        ?object $user = null,
        array $metadata = []
    ): HookContext {
        return new HookContext(
            method: $method,
            phase: 'after',
            data: $data,
            parameters: $parameters,
            result: $result,
            target: $target,
            user: $user,
            metadata: $metadata
        );
    }

    /**
     * Add middleware for hook execution
     */
    public function addMiddleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    /**
     * Remove all middleware
     */
    public function clearMiddleware(): self
    {
        $this->middleware = [];

        return $this;
    }

    /**
     * Enable/disable debug mode
     */
    public function setDebugMode(bool $debug): self
    {
        $this->debugMode = $debug;

        return $this;
    }

    /**
     * Register a custom execution strategy
     */
    public function registerStrategy(string $name, HookExecutionStrategy $strategy): self
    {
        $this->registry->registerStrategy($name, $strategy);

        return $this;
    }

    /**
     * Remove hooks for a target class method
     */
    public function removeHooks(string $targetClass, string $method, string $phase): self
    {
        $this->registry->removeHooks($targetClass, $method, $phase);

        return $this;
    }

    /**
     * Remove a specific hook
     */
    public function removeHook(
        string $targetClass,
        string $method,
        string $phase,
        string $hookClass
    ): self {
        $this->registry->removeHook($targetClass, $method, $phase, $hookClass);

        return $this;
    }

    /**
     * Clear all hooks
     */
    public function clearAll(): self
    {
        $this->registry->clearAll();

        return $this;
    }

    /**
     * Enable/disable hook execution
     */
    public function enable(bool $enabled = true): self
    {
        $this->registry->setEnabled($enabled);

        return $this;
    }

    /**
     * Disable hook execution
     */
    public function disable(): self
    {
        return $this->enable(false);
    }

    /**
     * Check if hooks are enabled
     */
    public function isEnabled(): bool
    {
        return $this->registry->isEnabled();
    }

    /**
     * Get hook statistics
     */
    public function getStats(): array
    {
        return array_merge($this->registry->getStats(), [
            'middleware_count' => count($this->middleware),
            'debug_mode' => $this->debugMode,
        ]);
    }

    /**
     * Get the underlying registry
     */
    public function getRegistry(): HookRegistry
    {
        return $this->registry;
    }

    /**
     * Debug hooks for a specific target class
     */
    public function debugTarget(string $targetClass): array
    {
        return $this->registry->debugTarget($targetClass);
    }

    /**
     * @deprecated Use debugTarget() instead.
     */
    public function debugService(string $targetClass): array
    {
        trigger_error('debugService() is deprecated, use debugTarget() instead.', E_USER_DEPRECATED);

        return $this->debugTarget($targetClass);
    }

    /**
     * Validate hook phase
     */
    private function validatePhase(string $phase): void
    {
        if (! in_array($phase, ['before', 'after', 'error'])) {
            throw new \InvalidArgumentException("Invalid hook phase: {$phase}. Must be 'before', 'after', or 'error'.");
        }
    }

    /**
     * Validate execution strategy
     */
    private function validateStrategy(string $strategy): void
    {
        $validStrategies = ['sync', 'queue', 'delay', 'batch'];

        if (! in_array($strategy, $validStrategies)) {
            throw new \InvalidArgumentException(
                "Invalid hook strategy: {$strategy}. Must be one of: ".implode(', ', $validStrategies)
            );
        }
    }

    /**
     * Apply middleware to context
     */
    private function applyMiddleware(HookContext $context): HookContext
    {
        $modifiedContext = $context;

        foreach ($this->middleware as $middleware) {
            $modifiedContext = $middleware($modifiedContext);

            if (! $modifiedContext instanceof HookContext) {
                throw new \RuntimeException('Middleware must return a HookContext instance');
            }
        }

        return $modifiedContext;
    }

    /**
     * Bulk register hooks from configuration array
     */
    public function loadFromConfig(array $config): self
    {
        foreach ($config as $targetClass => $targetDef) {
            if (isset($targetDef['hooks'])) {
                foreach ($targetDef['hooks'] as $hookDef) {
                    $this->addHook(
                        $targetClass,
                        $hookDef['method'],
                        $hookDef['phase'],
                        $hookDef['hook'],
                        $hookDef['strategy'] ?? 'sync',
                        $hookDef['options'] ?? []
                    );
                }
            }

            if (isset($targetDef['global_hooks'])) {
                foreach ($targetDef['global_hooks'] as $hookDef) {
                    $this->addGlobalHook(
                        $hookDef['method'],
                        $hookDef['phase'],
                        $hookDef['hook'],
                        $hookDef['strategy'] ?? 'sync',
                        $hookDef['options'] ?? []
                    );
                }
            }
        }

        return $this;
    }
}
