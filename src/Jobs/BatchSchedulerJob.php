<?php

namespace Ahmed3bead\LaravelHooks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Ahmed3bead\LaravelHooks\Strategies\BatchedHookStrategy;
use Illuminate\Support\Facades\Log;

/**
 * Batch Scheduler Job
 *
 * Processes a pending batch after the configured delay has elapsed.
 * Retrieves the batch from cache and dispatches a BatchProcessorJob.
 */
class BatchSchedulerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        private string $batchKey,
        int $batchDelay
    ) {
        $this->delay = $batchDelay;
        $this->onQueue(config('laravel-hooks.batch_queue', 'batch'));
    }

    /**
     * Execute the scheduler — pull the batch from cache and process it.
     */
    public function handle(): void
    {
        Log::debug('Batch scheduler triggered', [
            'batch_key' => $this->batchKey,
            'delay' => $this->delay,
        ]);

        BatchedHookStrategy::processNow($this->batchKey);
    }

    /**
     * Get the tags for the job
     */
    public function tags(): array
    {
        return [
            'batch-scheduler',
            'key:'.$this->batchKey,
        ];
    }
}
