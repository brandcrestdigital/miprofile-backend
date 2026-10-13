<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CashfreeService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('services.cashfree.environment') === 'production'
            ? 'https://api.cashfree.com/pg'
            : 'https://sandbox.cashfree.com/pg';
    }


    protected function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'x-api-version' => config(
                'services.cashfree.api_version',
                '2023-08-01'
            ),
            'x-client-id' => config('services.cashfree.client_id'),
            'x-client-secret' => config('services.cashfree.client_secret'),
        ];
    }

    public function createOrder(
        string $orderId,
        float $amount,
        string $customerId,
        string $customerName,
        string $customerEmail,
        string $customerPhone
    ) {
        return Http::withHeaders([
            'Content-Type'      => 'application/json',
            'x-api-version'     => config('services.cashfree.api_version'),
            'x-client-id'       => config('services.cashfree.client_id'),
            'x-client-secret'  => config('services.cashfree.client_secret'),
            'x-idempotency-key' => (string) Str::uuid(),
        ])->post($this->baseUrl . '/orders', [
            'order_id'       => $orderId,
            'order_amount'   => $amount,
            'order_currency' => 'INR',

            'customer_details' => [
                'customer_id'    => $customerId,
                'customer_name'  => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
            ],

            'order_meta' => [
                'return_url' => url('/api/payment/cashfree/return')
                    . '?order_id=' . $orderId,
            ],
        ]);
    }

    public function getPayments(string $orderId)
    {
        return Http::withHeaders($this->headers())
            ->get(
                $this->baseUrl . '/orders/' . $orderId . '/payments'
            );
    }

    public function getOrder(string $orderId)
    {
        return Http::withHeaders($this->headers())
            ->get(
                $this->baseUrl . '/orders/' . $orderId
            );
    }
}
