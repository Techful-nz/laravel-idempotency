<?php

namespace Techful\Idempotency\View\Components;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\View\Component;

class IdempotencyKey extends Component
{
    /**
     * The random value is the fallback when JavaScript doesn't run: a double submit
     * still shares a key, though a refresh and resubmit does not.
     */
    public function __construct(
        public ?string $name = null,
        public ?string $value = null,
        public bool $showErrors = true,
    ) {
        $this->name ??= Config::string('idempotency.input');
        $this->value ??= hash('sha256', Str::random(32));
    }

    public function render(): string
    {
        return 'idempotency::components.idempotency-key';
    }
}
