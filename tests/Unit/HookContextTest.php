<?php

use Ahmed3bead\LaravelHooks\Contracts\WrappedResponseInterface;
use Ahmed3bead\LaravelHooks\HookContext;
use Illuminate\Database\Eloquent\Model;

// Helper: minimal service stub
function makeService(): object
{
    return new class
    {
        public string $name = 'TestService';
    };
}

// Helper: minimal model stub
function makeModel(array $attrs = []): Model
{
    $model = new class extends Model
    {
        protected $guarded = [];
    };
    foreach ($attrs as $key => $value) {
        $model->$key = $value;
    }

    return $model;
}

// Helper: wrapped response stub
function makeWrappedResponse(mixed $data, int $status = 200, string $message = 'OK'): WrappedResponseInterface
{
    return new class($data, $status, $message) implements WrappedResponseInterface
    {
        public function __construct(
            private mixed $data,
            private int $status,
            private string $message
        ) {}

        public function getData(): mixed
        {
            return $this->data;
        }

        public function getStatusCode(): int
        {
            return $this->status;
        }

        public function getMessage(): string
        {
            return $this->message;
        }
    };
}

test('constructor sets all properties', function () {
    $service = makeService();
    $ctx = new HookContext(
        method: 'create',
        phase: 'before',
        data: ['foo' => 'bar'],
        parameters: ['id' => 1],
        result: null,
        target: $service,
    );

    expect($ctx->method)->toBe('create')
        ->and($ctx->phase)->toBe('before')
        ->and($ctx->data)->toBe(['foo' => 'bar'])
        ->and($ctx->parameters)->toBe(['id' => 1])
        ->and($ctx->result)->toBeNull()
        ->and($ctx->target)->toBe($service);
});

test('isBefore returns true when phase is before', function () {
    $ctx = new HookContext('create', 'before', null, [], null, makeService());
    expect($ctx->isBefore())->toBeTrue();
    expect($ctx->isAfter())->toBeFalse();
});

test('isAfter returns true when phase is after', function () {
    $ctx = new HookContext('create', 'after', null, [], null, makeService());
    expect($ctx->isAfter())->toBeTrue();
    expect($ctx->isBefore())->toBeFalse();
});

test('getParameter returns value or default', function () {
    $ctx = new HookContext('create', 'before', null, ['key' => 'value'], null, makeService());
    expect($ctx->getParameter('key'))->toBe('value');
    expect($ctx->getParameter('missing', 'default'))->toBe('default');
});

test('hasParameter checks existence', function () {
    $ctx = new HookContext('create', 'before', null, ['key' => 'value'], null, makeService());
    expect($ctx->hasParameter('key'))->toBeTrue();
    expect($ctx->hasParameter('missing'))->toBeFalse();
});

test('getMetadata returns value or default', function () {
    $ctx = new HookContext('create', 'before', null, [], null, makeService(), null, null, ['foo' => 'bar']);
    expect($ctx->getMetadata('foo'))->toBe('bar');
    expect($ctx->getMetadata('missing', 'default'))->toBe('default');
});

test('getModelFromResult returns null when result is null', function () {
    $ctx = new HookContext('create', 'after', null, [], null, makeService());
    expect($ctx->getModelFromResult())->toBeNull();
});

test('getModelFromResult returns model when result is a model', function () {
    $model = makeModel();
    $ctx = new HookContext('create', 'after', null, [], $model, makeService());
    expect($ctx->getModelFromResult())->toBe($model);
});

test('getModelFromResult extracts model from wrapped response', function () {
    $model = makeModel();
    $wrapped = makeWrappedResponse($model);
    $ctx = new HookContext('create', 'after', null, [], $wrapped, makeService());
    expect($ctx->getModelFromResult())->toBe($model);
});

test('getDataFromResult unwraps wrapped response', function () {
    $data = ['id' => 42];
    $wrapped = makeWrappedResponse($data);
    $ctx = new HookContext('create', 'after', null, [], $wrapped, makeService());
    expect($ctx->getDataFromResult())->toBe($data);
});

test('getDataFromResult returns raw result when not wrapped', function () {
    $ctx = new HookContext('create', 'after', null, [], 'raw', makeService());
    expect($ctx->getDataFromResult())->toBe('raw');
});

test('hasWrappedResponse detects wrapped response', function () {
    $wrapped = makeWrappedResponse(null);
    $ctx = new HookContext('create', 'after', null, [], $wrapped, makeService());
    expect($ctx->hasWrappedResponse())->toBeTrue();
});

test('hasWrappedResponse returns false for plain result', function () {
    $ctx = new HookContext('create', 'after', null, [], 'plain', makeService());
    expect($ctx->hasWrappedResponse())->toBeFalse();
});

test('getStatusCode returns code from wrapped response', function () {
    $wrapped = makeWrappedResponse(null, 201, 'Created');
    $ctx = new HookContext('create', 'after', null, [], $wrapped, makeService());
    expect($ctx->getStatusCode())->toBe(201);
});

test('getStatusCode returns null for non-wrapped result', function () {
    $ctx = new HookContext('create', 'after', null, [], 'plain', makeService());
    expect($ctx->getStatusCode())->toBeNull();
});

test('getMessage returns message from wrapped response', function () {
    $wrapped = makeWrappedResponse(null, 200, 'Success message');
    $ctx = new HookContext('create', 'after', null, [], $wrapped, makeService());
    expect($ctx->getMessage())->toBe('Success message');
});

test('isSuccessful returns true for 2xx status codes', function () {
    $wrapped200 = makeWrappedResponse(null, 200);
    $wrapped201 = makeWrappedResponse(null, 201);
    $wrapped400 = makeWrappedResponse(null, 400);

    $ctx200 = new HookContext('create', 'after', null, [], $wrapped200, makeService());
    $ctx201 = new HookContext('create', 'after', null, [], $wrapped201, makeService());
    $ctx400 = new HookContext('create', 'after', null, [], $wrapped400, makeService());

    expect($ctx200->isSuccessful())->toBeTrue()
        ->and($ctx201->isSuccessful())->toBeTrue()
        ->and($ctx400->isSuccessful())->toBeFalse();
});

test('toArray returns array with expected keys', function () {
    $ctx = new HookContext('create', 'before', null, [], null, makeService());
    $array = $ctx->toArray();

    expect($array)->toHaveKeys([
        'method', 'phase', 'data', 'parameters',
        'result_type', 'has_wrapped_response', 'status_code', 'message',
        'target', 'model', 'extracted_model', 'user', 'metadata',
    ]);
});

test('getUserId returns null when no user set', function () {
    $ctx = new HookContext('create', 'before', null, [], null, makeService());
    expect($ctx->getUserId())->toBeNull();
});

test('getUserId returns user id when user has id property', function () {
    $user = new stdClass;
    $user->id = 99;
    $ctx = new HookContext('create', 'before', null, [], null, makeService(), null, $user);
    expect($ctx->getUserId())->toBe(99);
});

// --- toLogArray ---

test('toLogArray excludes sensitive data and parameters', function () {
    $ctx = new HookContext(
        'create', 'after', ['password' => 'secret123'], ['token' => 'abc'], 'result', makeService()
    );
    $log = $ctx->toLogArray();

    expect($log)->toHaveKeys(['method', 'phase', 'result_type', 'has_wrapped_response', 'status_code', 'target', 'model', 'user_id'])
        ->and($log)->not->toHaveKey('data')
        ->and($log)->not->toHaveKey('parameters')
        ->and($log)->not->toHaveKey('metadata');
});

test('toLogArray returns correct values', function () {
    $user = new stdClass;
    $user->id = 42;
    $ctx = new HookContext('update', 'before', null, [], null, makeService(), null, $user);
    $log = $ctx->toLogArray();

    expect($log['method'])->toBe('update')
        ->and($log['phase'])->toBe('before')
        ->and($log['result_type'])->toBeNull()
        ->and($log['user_id'])->toBe(42);
});

// --- getResourceFromResult ---

test('getResourceFromResult returns null when result is null', function () {
    $ctx = new HookContext('create', 'after', null, [], null, makeService());
    expect($ctx->getResourceFromResult())->toBeNull();
});

test('getResourceFromResult returns raw result when not wrapped', function () {
    $ctx = new HookContext('create', 'after', null, [], 'raw_value', makeService());
    expect($ctx->getResourceFromResult())->toBe('raw_value');
});

test('getResourceFromResult extracts resource property from wrapped response data', function () {
    $model = makeModel(['id' => 1]);
    $resourceObj = new stdClass;
    $resourceObj->resource = $model;
    $wrapped = makeWrappedResponse($resourceObj);
    $ctx = new HookContext('create', 'after', null, [], $wrapped, makeService());
    expect($ctx->getResourceFromResult())->toBe($model);
});

test('getResourceFromResult returns data when no resource property', function () {
    $data = ['id' => 42];
    $wrapped = makeWrappedResponse($data);
    $ctx = new HookContext('create', 'after', null, [], $wrapped, makeService());
    expect($ctx->getResourceFromResult())->toBe($data);
});

// --- getModelFromResult with iterable ---

test('getModelFromResult extracts model from iterable result', function () {
    $model = makeModel(['id' => 5]);
    $ctx = new HookContext('create', 'after', null, [], [$model], makeService());
    expect($ctx->getModelFromResult())->toBe($model);
});

test('getModelFromResult returns null for iterable without models', function () {
    $ctx = new HookContext('create', 'after', null, [], ['not a model'], makeService());
    expect($ctx->getModelFromResult())->toBeNull();
});

// --- extractModelFromResponse edge cases ---

test('getModelFromResult extracts model from wrapped response resource property', function () {
    $model = makeModel(['id' => 10]);
    $resourceObj = new stdClass;
    $resourceObj->resource = $model;
    $wrapped = makeWrappedResponse($resourceObj);
    $ctx = new HookContext('create', 'after', null, [], $wrapped, makeService());
    expect($ctx->getModelFromResult())->toBe($model);
});

test('getModelFromResult extracts model from iterable inside wrapped response', function () {
    $model = makeModel(['id' => 20]);
    $wrapped = makeWrappedResponse([$model]);
    $ctx = new HookContext('create', 'after', null, [], $wrapped, makeService());
    expect($ctx->getModelFromResult())->toBe($model);
});

// --- Model helper methods ---

test('getModelId returns model key from result', function () {
    $model = makeModel();
    // Simulate a model with a key
    $model->id = 123;
    $ctx = new HookContext('create', 'after', null, [], $model, makeService());
    expect($ctx->getModelId())->toBe(123);
});

test('getModelId returns null when no model in result', function () {
    $ctx = new HookContext('create', 'after', null, [], 'not a model', makeService());
    expect($ctx->getModelId())->toBeNull();
});

test('getModelAttributes returns model array', function () {
    $model = makeModel(['name' => 'test', 'email' => 'test@example.com']);
    $ctx = new HookContext('create', 'after', null, [], $model, makeService());
    $attrs = $ctx->getModelAttributes();
    expect($attrs)->toHaveKey('name')
        ->and($attrs['name'])->toBe('test');
});

test('getModelAttributes returns empty array when no model', function () {
    $ctx = new HookContext('create', 'after', null, [], 'string result', makeService());
    expect($ctx->getModelAttributes())->toBe([]);
});

test('getModelChanges returns empty array when no model', function () {
    $ctx = new HookContext('create', 'after', null, [], null, makeService());
    expect($ctx->getModelChanges())->toBe([]);
});

test('getOriginalAttributes returns empty array when no model', function () {
    $ctx = new HookContext('create', 'after', null, [], null, makeService());
    expect($ctx->getOriginalAttributes())->toBe([]);
});

test('wasModelRecentlyCreated returns false when no model', function () {
    $ctx = new HookContext('create', 'after', null, [], null, makeService());
    expect($ctx->wasModelRecentlyCreated())->toBeFalse();
});

// --- Deprecated $service property ---

test('accessing $service property triggers deprecation and returns target', function () {
    $service = makeService();
    $ctx = new HookContext('create', 'before', null, [], null, $service);
    $result = @$ctx->service;
    expect($result)->toBe($service);
});

test('accessing unknown property returns null', function () {
    $ctx = new HookContext('create', 'before', null, [], null, makeService());
    expect($ctx->nonexistent)->toBeNull();
});

// --- getWrappedResponse ---

test('getWrappedResponse returns the raw result', function () {
    $wrapped = makeWrappedResponse('data', 200);
    $ctx = new HookContext('create', 'after', null, [], $wrapped, makeService());
    expect($ctx->getWrappedResponse())->toBe($wrapped);
});

// --- getMessage for non-wrapped ---

test('getMessage returns null for non-wrapped result', function () {
    $ctx = new HookContext('create', 'after', null, [], 'plain', makeService());
    expect($ctx->getMessage())->toBeNull();
});

// --- getRequestData ---

test('getRequestData returns empty array when no request', function () {
    $ctx = new HookContext('create', 'before', null, [], null, makeService());
    expect($ctx->getRequestData())->toBe([]);
});
