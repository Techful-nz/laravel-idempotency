<?php

namespace Techful\Idempotency\Http\Middleware;

use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Idempotent
{
    /**
     * Replay the cached response when a write repeats the key of the last one to the same URL.
     *
     * Each parameter falls back to the `idempotency` config, so a route can override
     * any of them, e.g. `idempotent:token,10,false,POST|PUT`.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(
        Request $request,
        Closure $next,
        ?string $inputKey = null,
        ?int $ttl = null,
        bool|string|null $validate = null,
        ?string $methods = null,
    ): Response {
        $inputKey ??= Config::string('idempotency.input');
        $methods = $methods === null ? Config::array('idempotency.methods') : explode('|', $methods);

        if (!in_array($request->getMethod(), array_map(strtoupper(...), $methods), true)) {
            return $next($request);
        }

        $idempotencyKey = $request->header(Config::string('idempotency.header')) ?? $request
            ->string($inputKey)
            ->toString();

        if ($idempotencyKey !== '') {
            return $this->replayOrRun($request, $next, $idempotencyKey, $ttl ?? Config::integer('idempotency.ttl'));
        }

        if (filter_var($validate ?? Config::boolean('idempotency.validate'), FILTER_VALIDATE_BOOL)) {
            throw ValidationException::withMessages([$inputKey => 'The Idempotency-Key is required']);
        }

        throw new HttpException(400, 'Idempotency key is required');
    }

    /**
     * @param  Closure(Request): (Response)  $next
     */
    protected function replayOrRun(Request $request, Closure $next, string $idempotencyKey, int $ttl): Response
    {
        $cacheKey =
            'idempotent:'
            . hash('sha256', implode(':', [
                $request->user()?->getAuthIdentifier() ?? $request->ip(),
                $request->getMethod(),
                $request->getUri(),
            ]));

        /** @var array{idempotency_key: string, status: int, headers: array<string, array<int, string>>, content: string, flash?: array<string, mixed>}|null $cached */
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && $cached['idempotency_key'] === $idempotencyKey) {
            if ($request->isJson()) {
                throw new HttpException(409);
            }

            $this->replayFlash($request, $cached['flash'] ?? []);

            return response($cached['content'], $cached['status'], $cached['headers']);
        }

        $response = $next($request);

        if ($this->isReplayable($response)) {
            $response->headers->set(Config::string('idempotency.header'), $idempotencyKey);

            $headers = $response->headers->all();
            unset($headers['set-cookie']);

            Cache::put(
                $cacheKey,
                [
                    'idempotency_key' => $idempotencyKey,
                    'status' => $response->getStatusCode(),
                    'headers' => $headers,
                    'content' => $response->getContent() ?: '',
                    'flash' => $this->flashedSession($request, $response),
                ],
                $ttl,
            );
        }

        return $response;
    }

    /**
     * A failed response, or a redirect back with validation errors, is left
     * uncached so the user can correct the form and submit it again.
     */
    protected function isReplayable(Response $response): bool
    {
        if ($response->isSuccessful()) {
            return true;
        }

        return $response instanceof RedirectResponse && !$response->getSession()?->has('errors');
    }

    /**
     * Flash written during the original request, so a replay can show the same message.
     *
     * @return array<string, mixed>
     */
    protected function flashedSession(Request $request, Response $response): array
    {
        $flash = [];

        foreach ($this->sessions($request, $response) as $session) {
            foreach ($session->get('_flash.new', []) as $key) {
                if (is_string($key)) {
                    $flash[$key] = unserialize(serialize($session->get($key)));
                }
            }
        }

        return $flash;
    }

    /**
     * @param  array<string, mixed>  $flash
     */
    protected function replayFlash(Request $request, array $flash): void
    {
        if ($flash === [] || !$request->hasSession()) {
            return;
        }

        foreach ($flash as $key => $value) {
            $request->session()->flash($key, $value);
        }
    }

    /**
     * @return list<Session>
     */
    protected function sessions(Request $request, Response $response): array
    {
        $sessions = [];

        if ($request->hasSession()) {
            $sessions[spl_object_id($request->session())] = $request->session();
        }

        if ($response instanceof RedirectResponse && ($session = $response->getSession()) instanceof Session) {
            $sessions[spl_object_id($session)] = $session;
        }

        return array_values($sessions);
    }
}
