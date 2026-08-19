<?php

namespace Ahmed3bead\LaravelHooks;

use Ahmed3bead\LaravelHooks\Contracts\HookExecutionStrategy;
use Ahmed3bead\LaravelHooks\Contracts\HookJobInterface;
use Ahmed3bead\LaravelHooks\Strategies\BatchedHookStrategy;
use Ahmed3bead\LaravelHooks\Strategies\DelayedHookStrategy;
use Ahmed3bead\LaravelHooks\Strategies\QueuedHookStrategy;
use Ahmed3bead\LaravelHooks\Strategies\SyncHookStrategy;
use Illuminate\Support\Facades\Log;

/**
 * Hook Registry
 *
 * Central registry for managing hook collections and execution strategies.
 * Implements the Registry pattern for centralized hook management.
 */
class HookRegistry
{
    private array $hooks = [];

    private array $strategies = [];

    private array $globalHooks = [];

    private bool $enabled = true;

    public function __construct()
    {
        $this->initializeStrategies();
    }

    /**
     * Initialize default execution strategies
     */
    private function initializeStrategies(): void
    {
        $this->strategies = [
            'sync' => new SyncHookStrategy,
            'queue' => new QueuedHookStrategy,
            'delay' => new DelayedHookStrategy,
            'batch' => new BatchedHookStrategy,
        ];
    }

    /**
     * Register a hook for a specific target class method and phase
     */
    public function registerHook(
        string $targetClass,
        string $method,
        string $phase,
        string $hookClass,
        string $strategy = 'sync',
        array $options = []
    ): self {
        $key = $this->makeKey($targetClass, $method, $phase);

        if (! isset($this->hooks[$key])) {
            $this->hooks[$key] = [];
        }

        $hookConfig = [
            'class' => $hookClass,
            'strategy' => $strategy,
            'options' => $options,
            'priority' => $options['priority'] ?? 100,
            'enabled' => $options['enabled'] ?? true,
        ];

        $this->hooks[$key][] = $hookConfig;

        Log::debug('Hook registered', [
            'target' => $targetClass,
            'method' => $method,
            'phase' => $phase,
            'hook' => $hookClass,
            'strategy' => $strategy,
        ]);

        return $this;
    }

    /**
     * Register a global hook that runs for all services
     */
    public function registerGlobalHook(
        string $method,
        string $phase,
        string $hookClass,
        string $strategy = 'sync',
        array $options = []
    ): self {
        $key = $this->makeKey('*', $method, $phase);

        if (! isset($this->globalHooks[$key])) {
            $this->globalHooks[$key] = [];
        }

        $hookConfig = [
            'class' => $hookClass,
            'strategy' => $strategy,
            'options' => $options,
            'priority' => $options['priority'] ?? 100,
            'enabled' => $options['enabled'] ?? true,
        ];

        $this->globalHooks[$key][] = $hookConfig;

        return $this;
    }

    /**
     * Get hooks for a specific target class method and phase
     */
    public function getHooks(string $targetClass, string $method, string $phase): array
    {
        if (! $this->enabled) {
            return [];
        }

        $specificKey = $this->makeKey($targetClass, $method, $phase);
        $globalKey = $this->makeKey('*', $method, $phase);
        $allMethodsKey = $this->makeKey($targetClass, '*', $phase);
        $allGlobalKey = $this->makeKey('*', '*', $phase);

        // Tag each hook with a specificity weight so that, at equal priority,
        // target-specific hooks run before wildcard/global hooks.
        $tagged = [];
        foreach ([
            [$this->hooks[$specificKey] ?? [], 0],
            [$this->hooks[$allMethodsKey] ?? [], 1],
            [$this->globalHooks[$globalKey] ?? [], 2],
            [$this->globalHooks[$allGlobalKey] ?? [], 3],
        ] as [$group, $specificity]) {
            foreach ($group as $hook) {
                $hook['_specificity'] = $specificity;
                $tagged[] = $hook;
            }
        }

        // Filter enabled hooks and sort by priority, then specificity
        $enabledHooks = array_filter($tagged, fn ($hook) => $hook['enabled']);

        usort($enabledHooks, function ($a, $b) {
            return $a['priority'] <=> $b['priority']
                ?: $a['_specificity'] <=> $b['_specificity'];
        });

        return $enabledHooks;
    }

    /**
     * Execute hooks for a specific context
     */
    public function executeHooks(string $targetClass, string $method, string $phase, HookContext $context): void
    {
        $hooks = $this->getHooks($targetClass, $method, $phase);

        if (empty($hooks)) {
            return;
        }

        Log::debug('Executing hooks', [
            'target' => $targetClass,
            'method' => $method,
            'phase' => $phase,
            'hook_count' => count($hooks),
        ]);

        foreach ($hooks as $hookConfig) {
            try {
                $this->executeHook($hookConfig, $context);
            } catch (\Exception $e) {
                Log::error('Hook execution error', [
                    'hook' => $hookConfig['class'],
                    'strategy' => $hookConfig['strategy'],
                    'error' => $e->getMessage(),
                    'context' => $context->toLogArray(),
                ]);

                // Continue with other hooks unless configured to stop
                if ($hookConfig['options']['stop_on_failure'] ?? false) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Execute a single hook with its strategy
     */
    private function executeHook(array $hookConfig, HookContext $context): void
    {
        $hookClass = $hookConfig['class'];
        $strategyName = $hookConfig['strategy'];
        $options = $hookConfig['options'];

        // Create hook instance
        $hook = $this->createHookInstance($hookClass, $options);

        // Get execution strategy
        $strategy = $this->getStrategy($strategyName, $options);

        // Execute hook with strategy
        $strategy->execute($hook, $context);
    }

    /**
     * Create hook instance with dependency injection
     */
    private function createHookInstance(string $hookClass, array $options): HookJobInterface
    {
        // Validate class name format to prevent injection
        if (! preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $hookClass)) {
            throw new \InvalidArgumentException('Hook class name contains invalid characters.');
        }

        // For real classes (not IoC aliases), verify interface BEFORE resolution
        // to prevent executing arbitrary constructors.
        if (class_exists($hookClass)) {
            if (! is_a($hookClass, HookJobInterface::class, true)) {
                throw new \InvalidArgumentException("Hook class {$hookClass} must implement HookJobInterface");
            }

            return app($hookClass);
        }

        // IoC alias (e.g. closure hooks) — must be bound in the container
        if (! app()->bound($hookClass)) {
            throw new \InvalidArgumentException("Hook class {$hookClass} does not exist");
        }

        $hook = app($hookClass);

        if (! $hook instanceof HookJobInterface) {
            throw new \InvalidArgumentException("Hook class {$hookClass} must implement HookJobInterface");
        }

        return $hook;
    }

    /**
     * Get execution strategy instance
     *
     * Clones mutable strategies before configuring to prevent
     * cross-request state bleed from shared singleton instances.
     */
    private function getStrategy(string $strategyName, array $options): HookExecutionStrategy
    {
        if (! isset($this->strategies[$strategyName])) {
            throw new \InvalidArgumentException("Unknown hook strategy: {$strategyName}");
        }

        $strategy = $this->strategies[$strategyName];

        // Configure strategy with options — clone first to avoid mutating the shared instance
        if ($strategy instanceof DelayedHookStrategy && isset($options['delay'])) {
            $this->validatePositiveInt($options['delay'], 'delay');
            $strategy = clone $strategy;
            $strategy->setDelay($options['delay']);
        }

        if ($strategy instanceof BatchedHookStrategy) {
            if (isset($options['batch_size'])) {
                $this->validatePositiveInt($options['batch_size'], 'batch_size');
            }
            if (isset($options['batch_delay'])) {
                $this->validatePositiveInt($options['batch_delay'], 'batch_delay');
            }

            $needsClone = isset($options['batch_size']) || isset($options['batch_delay']) || isset($options['batch_key']);
            if ($needsClone) {
                $strategy = clone $strategy;
                if (isset($options['batch_size'])) {
                    $strategy->setBatchSize($options['batch_size']);
                }
                if (isset($options['batch_delay'])) {
                    $strategy->setBatchDelay($options['batch_delay']);
                }
                if (isset($options['batch_key'])) {
                    $strategy->setBatchKey($options['batch_key']);
                }
            }
        }

        return $strategy;
    }

    /**
     * Register custom execution strategy
     */
    public function registerStrategy(string $name, HookExecutionStrategy $strategy): self
    {
        $this->strategies[$name] = $strategy;

        return $this;
    }

    /**
     * Remove hooks for a specific target class method and phase
     */
    public function removeHooks(string $targetClass, string $method, string $phase): self
    {
        $key = $this->makeKey($targetClass, $method, $phase);
        unset($this->hooks[$key]);

        return $this;
    }

    /**
     * Remove a specific hook
     */
    public function removeHook(string $targetClass, string $method, string $phase, string $hookClass): self
    {
        $key = $this->makeKey($targetClass, $method, $phase);

        if (isset($this->hooks[$key])) {
            $this->hooks[$key] = array_filter(
                $this->hooks[$key],
                fn ($hook) => $hook['class'] !== $hookClass
            );
        }

        return $this;
    }

    /**
     * Clear all hooks
     */
    public function clearAll(): self
    {
        $this->hooks = [];
        $this->globalHooks = [];

        return $this;
    }

    /**
     * Enable/disable hook execution
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    /**
     * Check if hooks are enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get all registered hooks
     */
    public function getAllHooks(): array
    {
        return [
            'target_hooks' => $this->hooks,
            'global_hooks' => $this->globalHooks,
        ];
    }

    /**
     * Get hook statistics
     */
    public function getStats(): array
    {
        $totalTargetHooks = array_sum(array_map('count', $this->hooks));
        $totalGlobalHooks = array_sum(array_map('count', $this->globalHooks));

        return [
            'enabled' => $this->enabled,
            'total_target_hooks' => $totalTargetHooks,
            'total_global_hooks' => $totalGlobalHooks,
            'total_hooks' => $totalTargetHooks + $totalGlobalHooks,
            'registered_strategies' => array_keys($this->strategies),
            'target_hook_keys' => array_keys($this->hooks),
            'global_hook_keys' => array_keys($this->globalHooks),
        ];
    }

    /**
     * Validate that a value is a positive integer.
     */
    private function validatePositiveInt(mixed $value, string $name): void
    {
        if (! is_int($value) || $value < 1) {
            throw new \InvalidArgumentException(
                "Option '{$name}' must be a positive integer, got: ".var_export($value, true)
            );
        }
    }

    /**
     * Create a unique key for hook storage
     */
    private function makeKey(string $targetClass, string $method, string $phase): string
    {
        return "{$targetClass}::{$method}::{$phase}";
    }

    /**
     * Debug method to list all hooks for a target class
     */
    public function debugTarget(string $targetClass): array
    {
        $debug = [];

        foreach ($this->hooks as $key => $hooks) {
            if (str_starts_with($key, $targetClass)) {
                $debug[$key] = $hooks;
            }
        }

        foreach ($this->globalHooks as $key => $hooks) {
            $debug["global::{$key}"] = $hooks;
        }

        return $debug;
    }

    /**
     * @deprecated Use debugTarget() instead.
     */
    public function debugService(string $targetClass): array
    {
        trigger_error('debugService() is deprecated, use debugTarget() instead.', E_USER_DEPRECATED);

        return $this->debugTarget($targetClass);
    }
}
