<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

beforeEach(function (): void {
    view()->share('errors', new ViewErrorBag);
});

test('renders the key under the configured input name', function (): void {
    config()->set('idempotency.input', 'token');

    $html = Blade::render('<x-idempotency-key />');

    expect($html)->toContain('<idempotency-key name="token"');
});

test('renders a random fallback value', function (): void {
    $first = Blade::render('<x-idempotency-key />');
    $second = Blade::render('<x-idempotency-key />');

    preg_match('/value="([a-f0-9]{64})"/', $first, $firstValue);
    preg_match('/value="([a-f0-9]{64})"/', $second, $secondValue);

    expect($firstValue[1] ?? null)->not->toBeNull()
        ->and($firstValue[1])->not->toBe($secondValue[1] ?? null);
});

test('keeps an explicit value', function (): void {
    $html = Blade::render('<x-idempotency-key value="given-key" />');

    expect($html)->toContain('value="given-key"');
});

test('loads the published script', function (): void {
    $html = Blade::render('<x-idempotency-key />');

    expect($html)->toContain(asset('vendor/idempotency/idempotency-key.js'));
});

test('shows the missing key error', function (): void {
    view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag([
        'idempotency_key' => 'The Idempotency-Key is required',
    ])));

    $html = Blade::render('<x-idempotency-key />');

    expect($html)->toContain('The Idempotency-Key is required');
});
