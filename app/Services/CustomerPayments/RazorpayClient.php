<?php

namespace App\Services\CustomerPayments;

use App\Models\CustomerPaymentAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class RazorpayClient
{
    public function request(CustomerPaymentAccount $account, string $method, string $path, array $data = []): array
    {
        if ($account->mode !== 'test' || !str_starts_with($account->key_id, 'rzp_test_')
            || !preg_match('~^(payment_links(?:/plink_[A-Za-z0-9]+(?:/cancel)?)?|payments/pay_[A-Za-z0-9]+)$~D', $path)
            || !in_array($method, ['GET', 'POST'], true)) {
            throw GatewayFailure::withMessages(['payment_link' => 'Only Razorpay test payments are enabled in this integration.']);
        }
        // Fixed host, no redirects, no automatic POST retries, no credentials in exception text.
        try {
            $response = Http::withBasicAuth($account->key_id, $account->key_secret)->acceptJson()
                ->connectTimeout(4)->timeout(12)->withoutRedirecting()
                ->send($method, 'https://api.razorpay.com/v1/'.$path, [$method === 'GET' ? 'query' : 'json' => $data]);
        } catch (ConnectionException $e) {
            throw GatewayFailure::withMessages(['payment_link' => 'Razorpay did not respond. Use Refresh status before trying again; a link may already exist.']);
        }
        if (! $response->successful() || ! is_array($response->json())) {
            $failure = GatewayFailure::withMessages(['payment_link' => 'Razorpay could not complete the request. Check the gateway settings and refresh the link status.']);
            $failure->definitelyRejected = in_array($response->status(), [400, 401, 403, 422], true);
            throw $failure;
        }

        return $response->json();
    }
}
