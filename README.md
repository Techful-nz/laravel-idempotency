# Laravel Idempotency

Stop a double-clicked or resubmitted form from running twice. The `idempotent` middleware caches the response to a write and replays it when the same idempotency key is sent again to the same URL. The `<x-idempotency-key>` component gives each form a key made from a hash of its data.

## Installation

```bash
composer require techful-nz/laravel-idempotency
```

Publish the component's JavaScript to `public/vendor/idempotency`:

```bash
php artisan vendor:publish --tag=idempotency-assets
```

Optionally publish the config and the component's view:

```bash
php artisan vendor:publish --tag=idempotency-config
php artisan vendor:publish --tag=idempotency-views
```

## Usage

Apply the middleware to the routes that take writes:

```php
Route::middleware(['auth', 'idempotent'])->group(function () {
    // ...
});
```

Each parameter overrides its config value, in the order input name, TTL, validate and methods:

```php
Route::post('orders', StoreOrderController::class)->middleware('idempotent:token,10,false,POST|PUT');
```

Put the component inside each form that posts to those routes:

```blade
<form method="post" action="{{ route('orders.store') }}">
    @csrf
    <x-idempotency-key />
    ...
</form>
```

`<idempotency-key>` is a form-associated custom element. It hashes the form's fields, with uploaded files hashed by content, into its value, and recalculates on `input`. Use the `on` attribute to listen for a different event. Submitting the same data twice sends the same key, so the second submission gets the first response back. Changing the data changes the key.

Without JavaScript, the component renders a random key. A double submit still shares that key, but refreshing the page and submitting again does not.

API clients can send the key in the `X-Idempotency-Key` header instead.

## Behaviour

- Responses are cached per user, or per IP for guests, along with the method and URL. A response is replayed only when the key matches.
- Successful responses, and redirects without validation errors, are cached. A failed response or a redirect back with errors runs again on the next submit, so the user can correct the form.
- Session flash from that response, such as a status message or old input, is replayed with it.
- The response carries the idempotency key in the configured header, and that header is replayed with the rest.
- `Set-Cookie` headers are never replayed.
- A write without a key gets a validation error on the input name, or a 400 when `validate` is off.

Responses are stored in the default cache store, so use a store that every server shares.

## Testing

```bash
composer test
composer analyse
```

## License

MIT. See [LICENSE.md](LICENSE.md).
