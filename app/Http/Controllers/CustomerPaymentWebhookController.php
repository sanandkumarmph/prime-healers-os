<?php

namespace App\Http\Controllers;

use App\Models\CustomerPaymentAccount;
use App\Models\CustomerPaymentLink;
use App\Services\CustomerPayments\CustomerPaymentLinks;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerPaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, CustomerPaymentLinks $service)
    {
        abort_if(strlen($request->getContent()) > 65536, 413);
        $account = CustomerPaymentAccount::where('webhook_token', $token)->firstOrFail();
        $signature = (string) $request->header('X-Razorpay-Signature');
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $signature)
            && hash_equals(hash_hmac('sha256', $request->getContent(), $account->webhook_secret), $signature), 401);
        $event = json_decode($request->getContent(), true, 32);
        abort_unless(is_array($event), 400);
        if (! in_array($event['event'] ?? '', ['payment_link.paid', 'payment_link.cancelled', 'payment_link.expired'], true)) {
            return response()->json(['received' => true]);
        }
        $entity = data_get($event, 'payload.payment_link.entity');
        abort_unless(is_array($entity) && is_string($entity['reference_id'] ?? null), 422);
        $link = CustomerPaymentLink::where('organization_id', $account->organization_id)->where('account_id', $account->id)->where('reference', $entity['reference_id'])->firstOrFail();
        try {
            $service->adopt($link, $entity);
            if ($event['event'] === 'payment_link.paid') {
                $payment = data_get($event, 'payload.payment.entity');
                abort_unless(is_array($payment), 422);
                $service->receive($link->fresh(), $entity, $payment, mb_substr((string) $request->header('X-Razorpay-Event-Id'), 0, 255) ?: null);
            }
        } catch (ValidationException $e) {
            return response()->json(['received' => false, 'message' => 'Payment verification failed.'], 422);
        }

        return response()->json(['received' => true]);
    }
}
