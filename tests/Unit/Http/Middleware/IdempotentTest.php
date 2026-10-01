<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Techful\Idempotency\Http\Middleware\Idempotent;

beforeEach(function (): void {
    Cache::flush();
});

test('returns the page for a request method that is not checked', function (): void {
    $middleware = new Idempotent;

    $middleware->handle(
        Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a']),
        fn () => response('saved', 200),
    );

    $response = $middleware->handle(
        Request::create('/users/1', 'GET'),
        fn () => response('form', 200),
    );

    expect($response->getContent())->toBe('form');
});

test('uses the header when both the header and the input are present', function (): void {
    $middleware = new Idempotent;
    $hits = 0;
    $next = function () use (&$hits) {
        $hits++;

        return response('saved', 200);
    };

    $both = Request::create('/users/1', 'POST', ['idempotency_key' => 'from-input']);
    $both->headers->set('X-Idempotency-Key', 'from-header');

    $headerOnly = Request::create('/users/1', 'POST');
    $headerOnly->headers->set('X-Idempotency-Key', 'from-header');

    $middleware->handle($both, $next);
    $response = $middleware->handle($headerOnly, $next);

    expect($hits)->toBe(1)
        ->and($response->getContent())->toBe('saved');
});

test('accepts the idempotency key from the configured input name', function (): void {
    $middleware = new Idempotent;
    $hits = 0;
    $next = function () use (&$hits) {
        $hits++;

        return response('saved', 200);
    };

    $middleware->handle(Request::create('/users/1', 'POST', ['token' => 'key-a']), $next, 'token');
    $response = $middleware->handle(Request::create('/users/1', 'POST', ['token' => 'key-a']), $next, 'token');

    expect($hits)->toBe(1)
        ->and($response->getContent())->toBe('saved');
});

test('rejects a write that is missing an idempotency key', function (): void {
    $exception = null;

    try {
        (new Idempotent)->handle(
            Request::create('/users/1', 'POST'),
            fn () => response('no'),
        );
    } catch (ValidationException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(ValidationException::class)
        ->and($exception->errors())->toBe([
            'idempotency_key' => ['The Idempotency-Key is required'],
        ]);
});

test('returns 400 when the key is missing and validation is disabled', function (): void {
    $exception = null;

    try {
        (new Idempotent)->handle(
            Request::create('/users/1', 'POST'),
            fn () => response('no'),
            validate: false,
        );
    } catch (HttpException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(HttpException::class);

    if (! $exception instanceof HttpException) {
        return;
    }

    expect($exception->getStatusCode())->toBe(400)
        ->and($exception->getMessage())->toBe('Idempotency key is required');
});

test('runs the request again when the first response failed', function (): void {
    $middleware = new Idempotent;
    $hits = 0;
    $next = function () use (&$hits) {
        $hits++;

        return response('error', 500);
    };

    $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a']), $next);
    $response = $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a']), $next);

    expect($hits)->toBe(2)
        ->and($response->getStatusCode())->toBe(500);
});

test('runs the request again when the redirect carries validation errors', function (): void {
    $middleware = new Idempotent;
    $hits = 0;
    $next = function () use (&$hits) {
        $hits++;

        return idempotentRedirect('/users/1', withErrors: true);
    };

    $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a']), $next);
    $response = $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a']), $next);

    expect($hits)->toBe(2)
        ->and($response->isRedirect())->toBeTrue();
});

test('replays flashed session data with the cached response', function (): void {
    $middleware = new Idempotent;
    $hits = 0;
    $next = function (Request $request) use (&$hits) {
        $hits++;
        $request->session()->flash('status', 'Saved');
        $request->session()->flashInput(['name' => 'Ada']);

        return redirect('/users/1');
    };

    $middleware->handle(idempotentRequestWithSession(), $next);

    $replay = idempotentRequestWithSession();
    $response = $middleware->handle($replay, $next);

    expect($hits)->toBe(1)
        ->and($response->isRedirect())->toBeTrue()
        ->and($replay->session()->get('status'))->toBe('Saved')
        ->and($replay->session()->get('_old_input'))->toBe(['name' => 'Ada'])
        ->and($replay->session()->get('_flash.new'))->toContain('status', '_old_input');
});

test('replays a redirect that has no validation errors', function (): void {
    $middleware = new Idempotent;
    $hits = 0;
    $next = function () use (&$hits) {
        $hits++;

        return idempotentRedirect('/users/1', withErrors: false);
    };

    $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a']), $next);
    $response = $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a']), $next);

    expect($hits)->toBe(1)
        ->and($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe(url('/users/1'));
});

test('sends the idempotency key on the response and its replay', function (): void {
    $middleware = new Idempotent;
    $next = fn () => response('saved', 200);

    $first = $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a']), $next);
    $replay = $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a']), $next);

    expect($first->headers->get('X-Idempotency-Key'))->toBe('key-a')
        ->and($replay->headers->get('X-Idempotency-Key'))->toBe('key-a');
});

test('omits set-cookie from a replayed response', function (): void {
    $middleware = new Idempotent;
    $hits = 0;
    $next = function () use (&$hits) {
        $hits++;

        return response('saved', 200)->cookie('session', 'secret-token');
    };

    $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a']), $next);
    $response = $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a']), $next);

    expect($hits)->toBe(1)
        ->and($response->getContent())->toBe('saved')
        ->and($response->headers->has('set-cookie'))->toBeFalse();
});

test('runs the request again after the cached response expires', function (): void {
    $middleware = new Idempotent;
    $hits = 0;
    $next = function () use (&$hits) {
        $hits++;

        return response('saved-'.$hits, 200);
    };

    $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a']), $next, ttl: 10);

    $this->travel(11)->seconds();

    $response = $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a']), $next, ttl: 10);

    expect($hits)->toBe(2)
        ->and($response->getContent())->toBe('saved-2');
});

test('replays the cached response for the same user from a different ip', function (): void {
    $user = idempotentUser(1);
    $middleware = new Idempotent;
    $hits = 0;
    $next = function () use (&$hits) {
        $hits++;

        return response('saved', 200);
    };

    $middleware->handle(idempotentRequestFor($user, '10.0.0.1'), $next);
    $response = $middleware->handle(idempotentRequestFor($user, '10.0.0.2'), $next);

    expect($hits)->toBe(1)
        ->and($response->getContent())->toBe('saved');
});

test('runs the request again for a different user on the same ip', function (): void {
    $middleware = new Idempotent;
    $hits = 0;
    $next = function () use (&$hits) {
        $hits++;

        return response('saved-'.$hits, 200);
    };

    $middleware->handle(idempotentRequestFor(idempotentUser(1), '10.0.0.1'), $next);
    $response = $middleware->handle(idempotentRequestFor(idempotentUser(2), '10.0.0.1'), $next);

    expect($hits)->toBe(2)
        ->and($response->getContent())->toBe('saved-2');
});

test('runs the request again when a guest comes from a different ip', function (): void {
    $middleware = new Idempotent;
    $hits = 0;
    $next = function () use (&$hits) {
        $hits++;

        return response('saved-'.$hits, 200);
    };

    $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a'], [], [], ['REMOTE_ADDR' => '10.0.0.1']), $next);
    $response = $middleware->handle(Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a'], [], [], ['REMOTE_ADDR' => '10.0.0.2']), $next);

    expect($hits)->toBe(2)
        ->and($response->getContent())->toBe('saved-2');
});

function idempotentRequestWithSession(): Request
{
    $session = app(Store::class);
    $session->flush();
    $session->start();

    $request = Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a']);
    $request->setLaravelSession($session);

    return $request;
}

function idempotentRequestFor(User $user, string $ip): Request
{
    $request = Request::create('/users/1', 'POST', ['idempotency_key' => 'key-a'], [], [], [
        'REMOTE_ADDR' => $ip,
    ]);
    $request->setUserResolver(fn () => $user);

    return $request;
}

function idempotentRedirect(string $location, bool $withErrors): RedirectResponse
{
    $session = app(Store::class);
    $session->start();

    $redirect = redirect($location);
    $redirect->setSession($session);

    if ($withErrors) {
        $redirect->withErrors(['name' => 'The name is required.']);
    }

    return $redirect;
}

function idempotentUser(int $id): User
{
    return (new User)->forceFill(['id' => $id]);
}
