<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Techful\Idempotency\Http\Middleware\Idempotent;

test('replays the cached response when the same idempotency key is sent again', function (): void {
    Cache::flush();

    $middleware = new Idempotent;
    $hits = 0;
    $next = function () use (&$hits) {
        $hits++;

        return response('saved', 200);
    };

    $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a']), $next);
    $response = $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a']), $next);

    expect($hits)->toBe(1)
        ->and($response->getContent())->toBe('saved');
});

test('runs the request again when the idempotency key changes', function (): void {
    Cache::flush();

    $middleware = new Idempotent;
    $hits = 0;
    $next = function () use (&$hits) {
        $hits++;

        return response($hits === 1 ? 'first' : 'second', 200);
    };

    $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a']), $next);
    $response = $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-b']), $next);

    expect($hits)->toBe(2)
        ->and($response->getContent())->toBe('second');
});

test('does not replay a response cached for a different uri', function (): void {
    Cache::flush();

    $middleware = new Idempotent;

    $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a']), fn () => response('user-one', 200));
    $response = $middleware->handle(Request::create('/users/2', 'POST', ['idempotency_key' => 'key-a']), fn () => response('user-two', 200));

    expect($response->getContent())->toBe('user-two');
});

test('the idempotent alias replays a repeated write on a route', function (): void {
    $hits = 0;
    Route::post('/orders', function () use (&$hits) {
        $hits++;

        return 'saved';
    })->middleware('idempotent');

    $this->post('/orders', ['idempotency_key' => 'key-a'])->assertOk();
    $this->post('/orders', ['idempotency_key' => 'key-a'])->assertOk()->assertContent('saved');

    expect($hits)->toBe(1);
});

test('a route parameter of false turns validation off and returns 400', function (): void {
    Route::post('/orders', fn () => 'saved')->middleware('idempotent:idempotency_key,60,false');

    $this->post('/orders')->assertStatus(400);
});

test('reads the key from the configured header', function (): void {
    config()->set('idempotency.header', 'Idempotency-Key');
    $hits = 0;
    Route::post('/orders', function () use (&$hits) {
        $hits++;

        return 'saved';
    })->middleware('idempotent');

    $this->post('/orders', [], ['Idempotency-Key' => 'key-a'])->assertOk();
    $this->post('/orders', [], ['Idempotency-Key' => 'key-a'])->assertOk();

    expect($hits)->toBe(1);
});
