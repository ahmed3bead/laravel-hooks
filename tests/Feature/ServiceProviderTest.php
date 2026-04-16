<?php

use Ahmed3bead\LaravelHooks\HookManager;
use Ahmed3bead\LaravelHooks\HookRegistry;

test('HookRegistry is bound as singleton', function () {
    $a = app(HookRegistry::class);
    $b = app(HookRegistry::class);
    expect($a)->toBe($b);
});

test('HookManager is bound as singleton', function () {
    $a = app(HookManager::class);
    $b = app(HookManager::class);
    expect($a)->toBe($b);
});

test('config file is merged', function () {
    expect(config('laravel-hooks.enabled'))->toBeTrue();
    expect(config('laravel-hooks.default_queue'))->toBe('default');
    expect(config('laravel-hooks.batch_queue'))->toBe('batch');
});

test('artisan command make:hook is registered', function () {
    $commands = array_keys(Artisan::all());
    expect($commands)->toContain('make:hook');
});

test('artisan command hooks:manage is registered', function () {
    $commands = array_keys(Artisan::all());
    expect($commands)->toContain('hooks:manage');
});
