<?php

namespace Ahmed3bead\LaravelHooks;

use Ahmed3bead\LaravelHooks\Contracts\WrappedResponseInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Hook Context
 *
 * Carries all information about the current hook execution — the method name,
 * phase, input data, result, the object the method was called on ($target),
 * and the authenticated user at the time of the call.
 */
class HookContext
{
    public function __construct(
        public string $method,
        public string $phase,
        public mixed $data,
        public array $parameters,
        public mixed $result,
        public object $target,
        public ?Model $model = null,
        public ?object $user = null,
        public array $metadata = []
    ) {}

    /**
     * Backward-compat: allow $context->service as an alias for $context->target.
     *
     * @deprecated Use $context->target instead.
     */
    public function __get(string $name): mixed
    {
        if ($name === 'service') {
            trigger_error('HookContext::$service is deprecated, use $target instead.', E_USER_DEPRECATED);

            return $this->target;
        }

        return null;
    }

    /**
     * Get the raw model data from the result, extracting from wrapped responses
     */
    public function getModelFromResult(): ?Model
    {
        if ($this->result === null) {
            return null;
        }

        if ($this->result instanceof Model) {
            return $this->result;
        }

        if ($this->result instanceof WrappedResponseInterface) {
            return $this->extractModelFromResponse($this->result);
        }

        if (is_iterable($this->result)) {
            foreach ($this->result as $item) {
                if ($item instanceof Model) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * Get the actual data from the result, unwrapping if necessary
     */
    public function getDataFromResult(): mixed
    {
        if ($this->result === null) {
            return null;
        }

        if ($this->result instanceof WrappedResponseInterface) {
            return $this->result->getData();
        }

        return $this->result;
    }

    /**
     * Get the resource data from the result
     */
    public function getResourceFromResult(): mixed
    {
        if ($this->result === null) {
            return null;
        }

        if ($this->result instanceof WrappedResponseInterface) {
            $data = $this->result->getData();

            if (is_object($data) && method_exists($data, 'resource')) {
                return $data;
            }

            return $data;
        }

        return $this->result;
    }

    /**
     * Get the wrapped response object
     */
    public function getWrappedResponse(): mixed
    {
        return $this->result;
    }

    /**
     * Check if the result is a wrapped response
     */
    public function hasWrappedResponse(): bool
    {
        return $this->result instanceof WrappedResponseInterface;
    }

    /**
     * Extract model from WrappedResponseInterface
     */
    private function extractModelFromResponse(WrappedResponseInterface $response): ?Model
    {
        $data = $response->getData();

        if (is_object($data) && property_exists($data, 'resource')) {
            $resource = $data->resource;
            if ($resource instanceof Model) {
                return $resource;
            }
        }

        if ($data instanceof Model) {
            return $data;
        }

        if (is_iterable($data)) {
            foreach ($data as $item) {
                if ($item instanceof Model) {
                    return $item;
                }
                if (is_object($item) && property_exists($item, 'resource') && $item->resource instanceof Model) {
                    return $item->resource;
                }
            }
        }

        return null;
    }

    /**
     * Get response status code if available
     */
    public function getStatusCode(): ?int
    {
        if ($this->result instanceof WrappedResponseInterface) {
            return $this->result->getStatusCode();
        }

        return null;
    }

    /**
     * Get response message if available
     */
    public function getMessage(): ?string
    {
        if ($this->result instanceof WrappedResponseInterface) {
            return $this->result->getMessage();
        }

        return null;
    }

    /**
     * Convert context to array for logging/debugging
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'phase' => $this->phase,
            'data' => $this->data,
            'request_data' => request()?->all() ?? [],
            'parameters' => $this->parameters,
            'result_type' => $this->result ? get_class($this->result) : null,
            'has_wrapped_response' => $this->hasWrappedResponse(),
            'status_code' => $this->getStatusCode(),
            'message' => $this->getMessage(),
            'target' => get_class($this->target),
            'model' => $this->model ? get_class($this->model) : null,
            'extracted_model' => $this->getModelFromResult() ? get_class($this->getModelFromResult()) : null,
            'user' => $this->user ? get_class($this->user) : null,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Get specific parameter by key
     */
    public function getParameter(string $key, mixed $default = null): mixed
    {
        return $this->parameters[$key] ?? $default;
    }

    /**
     * Check if parameter exists
     */
    public function hasParameter(string $key): bool
    {
        return array_key_exists($key, $this->parameters);
    }

    /**
     * Get metadata value
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Get request data
     */
    public function getRequestData(): array
    {
        return request()?->all() ?? [];
    }

    /**
     * Check if executing in 'before' phase
     */
    public function isBefore(): bool
    {
        return $this->phase === 'before';
    }

    /**
     * Check if executing in 'after' phase
     */
    public function isAfter(): bool
    {
        return $this->phase === 'after';
    }

    /**
     * Get the model primary key from extracted model
     */
    public function getModelId(): mixed
    {
        return $this->getModelFromResult()?->getKey();
    }

    /**
     * Get user ID if user exists
     */
    public function getUserId(): mixed
    {
        return $this->user?->id ?? $this->user?->getKey();
    }

    /**
     * Check if the operation was successful based on status code
     */
    public function isSuccessful(): bool
    {
        $statusCode = $this->getStatusCode();

        return $statusCode >= 200 && $statusCode < 300;
    }

    /**
     * Get model attributes as array
     */
    public function getModelAttributes(): array
    {
        $model = $this->getModelFromResult();

        return $model ? $model->toArray() : [];
    }

    /**
     * Get original model attributes (before changes)
     */
    public function getOriginalAttributes(): array
    {
        $model = $this->getModelFromResult();

        return $model ? $model->getOriginal() : [];
    }

    /**
     * Get model changes
     */
    public function getModelChanges(): array
    {
        $model = $this->getModelFromResult();

        return $model ? $model->getChanges() : [];
    }

    /**
     * Check if model was recently created
     */
    public function wasModelRecentlyCreated(): bool
    {
        $model = $this->getModelFromResult();

        return $model ? $model->wasRecentlyCreated : false;
    }
}
