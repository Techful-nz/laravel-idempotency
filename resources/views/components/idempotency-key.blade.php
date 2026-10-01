@once
    <script type="module" src="{{ asset('vendor/idempotency/idempotency-key.js') }}"></script>
@endonce

<idempotency-key name="{{ $name }}" value="{{ $value }}" {{ $attributes }}></idempotency-key>

@if ($showErrors)
    @error($name)
        <p class="idempotency-key-error" role="alert">{{ $message }}</p>
    @enderror
@endif
