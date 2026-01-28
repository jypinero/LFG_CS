<?php

namespace App\Services;

use GuzzleHttp\Client;

class PayMongoService
{
    protected Client $client;

    public function __construct()
    {
        $secret = config('paymongo.secret_key') ?: config('services.paymongo.secret');
        if (! $secret) {
            throw new \RuntimeException('PayMongo secret key not configured.');
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
}
