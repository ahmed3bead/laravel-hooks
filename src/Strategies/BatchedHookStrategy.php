<?php

namespace Ahmed3bead\LaravelHooks\Strategies;

use Ahmed3bead\LaravelHooks\Contracts\HookExecutionStrategy;
use Ahmed3bead\LaravelHooks\Contracts\HookJobInterface;
use Ahmed3bead\LaravelHooks\HookContext;
use Ahmed3bead\LaravelHooks\Jobs\BatchProcessorJob;
use Ahmed3bead\LaravelHooks\Jobs\BatchSchedulerJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * Batched Hook Execution Strategy
 *
 * Collects hooks and executes them in batches for better performance.
 * Uses Laravel Cache for storage so batches survive across processes
 * and queue workers.
 */
class BatchedHookStrategy implements HookExecutionStrategy
{
    private int $batchSize;

    private int $batchDelay;

    private string $batchKey;

    public function __construct(int $batchSize = 10, int $batchDelay = 60)
    {
        $this->batchSize = $batchSize;
        $this->batchDelay = $batchDelay;
        $this->batchKey = 'default';
    }

    public function getName(): string
    {
        return 'batch';
    }

    public function supportsRetry(): bool
    {
        return true;
    }

    public function execute(HookJobInterface $hook, HookContext $context): void
    {
        $batchKey = $this->determineBatchKey($hook, $context);
        $cacheKey = self::makeCacheKey($batchKey);

        $batch = Cache::get($cacheKey, []);

        $batch[] = [
            'hook_class' => get_class($hook),
            'context' => $context,
            'timestamp' => now(),
        ];

        // Store with a TTL to prevent stale batches from lingering forever
        $ttl = max($this->batchDelay * 3, 300);
        Cache::put($cacheKey, $batch, $ttl);

        if (count($batch) >= $this->batchSize) {
            $this->processBatch($batchKey);
        } else {
            $this->scheduleBatchProcessing($batchKey);
        }
    }

    public function setBatchSize(int $size): self
    {
        $this->batchSize = $size;

        return $this;
    }

    public function setBatchDelay(int $delay): self
    {
        $this->batchDelay = $delay;

        return $this;
    }

    public function setBatchKey(string $key): self
    {
        $this->batchKey = $key;

        return $this;
    }

    private function determineBatchKey(HookJobInterface $hook, HookContext $context): string
    {
        return $this->batchKey.'_'.get_class($hook).'_'.$context->method;
    }

    /**
     * Process a batch immediately by dispatching a BatchProcessorJob.
     */
    public static function processNow(string $batchKey): void
    {
        $cacheKey = self::makeCacheKey($batchKey);
        $batch = Cache::pull($cacheKey, []);

        if (empty($batch)) {
            return;
        }

        // Clear the scheduling flag so future items can schedule again
        Cache::forget(self::makeCacheKey($batchKey.':scheduled'));

        $job = new BatchProcessorJob($batch);

        Queue::connection(config('laravel-hooks.queue_connection', null))
            ->pushOn(config('laravel-hooks.batch_queue', 'batch'), $job);
    }

    private function processBatch(string $batchKey): void
    {
        static::processNow($batchKey);
    }

    private function scheduleBatchProcessing(string $batchKey): void
    {
        $schedulerCacheKey = self::makeCacheKey($batchKey.':scheduled');

        // Only schedule one job per batch key to avoid duplicate processing
        if (Cache::has($schedulerCacheKey)) {
            return;
        }

        Cache::put($schedulerCacheKey, true, $this->batchDelay + 30);

        $job = new BatchSchedulerJob($batchKey, $this->batchDelay);

        Queue::connection(config('laravel-hooks.queue_connection', null))
            ->laterOn(config('laravel-hooks.batch_queue', 'batch'), $this->batchDelay, $job);
    }

    /**
     * Force process all pending batches (useful for testing).
     *
     * Since batches are now cache-backed, this method requires explicit
     * batch keys to flush.
     */
    public static function flushBatches(array $batchKeys = []): void
    {
        foreach ($batchKeys as $batchKey) {
            static::processNow($batchKey);
        }
    }

    /**
     * Build the cache key for a given batch key.
     */
    public static function makeCacheKey(string $batchKey): string
    {
        return 'laravel-hooks:batch:'.$batchKey;
    }
}
