<?php

use Ahmed3bead\LaravelHooks\HookContext;
use Ahmed3bead\LaravelHooks\Contracts\WrappedResponseInterface;
use Illuminate\Database\Eloquent\Model;

// Helper: minimal service stub
function makeService(): object
{
    return new class {
        public string $name = 'TestService';
    };
}

// Helper: minimal model stub
function makeModel(array $attrs = []): Model
{
    $model = new class extends Model {
        protected $guarded = [];
        public bool $wasRecentlyCreated = false;
    };
    foreach ($attrs as $key => $value) {
        $model->$key = $value;
    }
    return $model;
}

// Helper: wrapped response stub
function makeWrappedResponse(mixed $data, int $status = 200, string $message = 'OK'): WrappedResponseInterface
{
    return new class($data, $status, $message) implements WrappedResponseInterface {
        public function __construct(
            private mixed $data,
            private int $status,
            private string $message
        ) {}
        public function getData(): mixed { return $this->data; }
        public function getStatusCode(): int { return $this->status; }
        public function getMessage(): string { return $this->message; }
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
        service: $service,
    );

    expect($ctx->method)->toBe('create')
        ->and($ctx->phase)->toBe('before')
        ->and($ctx->data)->toBe(['foo' => 'bar'])
        ->and($ctx->parameters)->toBe(['id' => 1])
        ->and($ctx->result)->toBeNull()
        ->and($ctx->service)->toBe($service);
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
        'method', 'phase', 'data', 'request_data', 'parameters',
        'result_type', 'has_wrapped_response', 'status_code', 'message',
        'service', 'model', 'extracted_model', 'user', 'metadata'
    ]);
});

test('getUserId returns null when no user set', function () {
    $ctx = new HookContext('create', 'before', null, [], null, makeService());
    expect($ctx->getUserId())->toBeNull();
});

test('getUserId returns user id when user has id property', function () {
    $user = new stdClass();
    $user->id = 99;
    $ctx = new HookContext('create', 'before', null, [], null, makeService(), null, $user);
    expect($ctx->getUserId())->toBe(99);
});
