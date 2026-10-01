<?php

return [

    /*
     * The request header checked for an idempotency key before the input.
     */
    'header' => 'X-Idempotency-Key',

    /*
     * The input field holding the key. The <x-idempotency-key> component
     * submits under this name, and validation errors are reported against it.
     */
    'input' => 'idempotency_key',

    /*
     * How long, in seconds, a response is replayed for a repeated key.
     */
    'ttl' => 60,

    /*
     * Reject a write without a key with a validation error. When false,
     * it is rejected with a 400 instead.
     */
    'validate' => true,

    /*
     * The request methods the middleware applies to.
     */
    'methods' => ['POST', 'PUT', 'PATCH', 'DELETE'],

];
