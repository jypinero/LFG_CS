<?php

namespace App\Services;

use GuzzleHttp\Client;

class PayMongoService
{
    protected Client $client;

    public function __construct()
    {
        $secret = config('paymongo.secret') ?: config('paymongo.secret_key') ?: config('services.paymongo.secret');
        if (! $secret) {
            // Don't throw exception in constructor - allow service to be instantiated
            // Error will be caught when trying to use the service
            $secret = '';
        }

        $base = rtrim(config('paymongo.base_url') ?: config('services.paymongo.base_url', 'https://api.paymongo.com/v1'), '/') . '/';

        $this->client = new Client([
            'base_uri' => $base,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($secret . ':'),
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Create a PayMongo payment intent.
     * $amount must be integer in centavos.
     * $description is optional.
     * $currency default 'PHP'.
     */
    public function createPaymentIntent(int $amount, ?string $description = null, string $currency = 'PHP'): array
    {
        $secret = config('paymongo.secret') ?: config('paymongo.secret_key') ?: config('services.paymongo.secret');
        if (! $secret) {
            throw new \RuntimeException('PayMongo secret key not configured. Please set PAYMONGO_SECRET_KEY in your .env file.');
        }

        $payload = [
            'data' => [
                'attributes' => [
                    'amount' => $amount,
                    'currency' => $currency,
                    'description' => $description ?? 'Subscription',
                    'payment_method_allowed' => ['card'], // adjust as needed
                    'capture_method' => 'automatic',
                ],
            ],
        ];

        $resp = $this->client->post('payment_intents', ['json' => $payload]);
        return json_decode($resp->getBody()->getContents(), true);
    }

    /**
     * Create a PayMongo Payment Link for checkout.
     * Payment Links provide a checkout_url that can be used to redirect users.
     * $amount must be integer in centavos.
     * $description is optional.
     * $currency default 'PHP'.
     */
    public function createPaymentLink(int $amount, ?string $description = null, string $currency = 'PHP', ?string $successUrl = null, ?string $failedUrl = null): array
    {
        $secret = config('paymongo.secret') ?: config('paymongo.secret_key') ?: config('services.paymongo.secret');
        if (! $secret) {
            throw new \RuntimeException('PayMongo secret key not configured. Please set PAYMONGO_SECRET_KEY in your .env file.');
        }

        $payload = [
            'data' => [
                'attributes' => [
                    'amount' => $amount,
                    'currency' => $currency,
                    'description' => $description ?? 'Subscription',
                    'payment_method_allowed' => ['card'],
                ],
            ],
        ];

        // Add redirect URLs if provided
        if ($successUrl || $failedUrl) {
            $payload['data']['attributes']['redirect'] = [];
            if ($successUrl) {
                $payload['data']['attributes']['redirect']['success'] = $successUrl;
            }
            if ($failedUrl) {
                $payload['data']['attributes']['redirect']['failed'] = $failedUrl;
            }
        }

        $resp = $this->client->post('links', ['json' => $payload]);
        return json_decode($resp->getBody()->getContents(), true);
    }
}
