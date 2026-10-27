<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ProtectPaymentSubmission
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('invoices.bulk.action') && $request->input('bulk_action') !== 'mark_paid') {
            return $next($request);
        }

        $validated = $request->validate([
            'payment_submission_token' => 'required|uuid',
        ], [
            'payment_submission_token.required' => 'Please reload the payment form and try again.',
            'payment_submission_token.uuid' => 'Please reload the payment form and try again.',
        ]);
        $organizationId = (int) $request->user()->organization_id;
        $session = $request->hasSession() ? $request->session()->all() : null;

        try {
            return DB::transaction(function () use ($request, $next, $validated, $organizationId) {
                // PHOS does not yet have Rentnexis's financial lock context.
                // Serialize interactive payment submissions before controllers read balances.
                Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();

                foreach (['rental', 'sale', 'invoice'] as $parameter) {
                    $model = $request->route($parameter);
                    if ($model instanceof \Illuminate\Database\Eloquent\Model) {
                        abort_unless((int) $model->organization_id === $organizationId, 404);
                        $request->route()->setParameter($parameter, $model->fresh() ?? abort(404));
                    }
                }

                try {
                    DB::table('payment_submissions')->insert([
                        'organization_id' => $organizationId,
                        'token' => strtolower($validated['payment_submission_token']),
                        'created_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException $exception) {
                    throw ValidationException::withMessages([
                        'payment_submission_token' => 'This payment form has already been submitted. Reload the page before recording another payment.',
                    ]);
                }

                $response = $next($request);
                // Laravel can render an exception within the routing pipeline.
                // Propagate it so both the receipt and token claim roll back.
                if ($response->exception ?? null) {
                    throw $response->exception;
                }
                if ($response->getStatusCode() >= 400) {
                    throw ValidationException::withMessages(['amount' => 'Payment could not be recorded. Please try again.']);
                }
                if ($request->hasSession() && in_array('error', $request->session()->get('_flash.new', []), true)) {
                    throw ValidationException::withMessages(['amount' => (string) $request->session()->get('error')]);
                }

                return $response;
            });
        } catch (\Throwable $exception) {
            // Session flashes are not rolled back with the database.
            if ($session !== null) {
                $request->session()->replace($session);
            }
            throw $exception;
        }
    }
}
