<?php

namespace App\Http\Middleware;

use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\RentalController;
use App\Http\Controllers\SaleController;
use App\Services\Finance\OrganizationFinancialTransaction;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SerializeFinancialRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        // These controllers also reconcile legacy payments during GET requests.
        // Lock before controller reads, including invoice deletion and import sync.
        if (! $request->user() || ! in_array($request->route()?->getControllerClass(), [
            InvoiceController::class, PaymentController::class,
            RentalController::class, SaleController::class,
            \App\Http\Controllers\DeliveryController::class,
            \App\Http\Controllers\DeliveryCollectionController::class,
        ], true)) {
            return $next($request);
        }

        $session = $request->hasSession() ? $request->session()->all() : null;
        try {
            return app(OrganizationFinancialTransaction::class)->run(
                (int) $request->user()->organization_id,
                function () use ($request, $next) {
                    foreach ($request->route()->parameters() as $name => $model) {
                        if ($model instanceof \Illuminate\Database\Eloquent\Model && $model->getAttribute('organization_id') !== null) {
                            abort_unless((int) $model->organization_id === (int) $request->user()->organization_id,
                                $request->route()?->getControllerClass() === \App\Http\Controllers\DeliveryController::class ? 403 : 404);
                            $request->route()->setParameter($name, $model->fresh() ?? abort(404));
                        }
                    }
                    $response = $request->route()?->getControllerClass() === \App\Http\Controllers\DeliveryController::class
                        ? app(\App\Services\Deliveries\DeliverySubmissionService::class)->run($request, fn () => $next($request))
                        : $next($request);
                    if ($response->exception ?? null) {
                        throw $response->exception;
                    }
                    if ($response->getStatusCode() >= 400
                        || ($request->hasSession() && in_array('error', $request->session()->get('_flash.new', []), true))) {
                        throw new HttpResponseException($response);
                    }

                    return $response;
                }
            );
        } catch (HttpResponseException $exception) {
            app(\App\Services\Deliveries\DeliveryProofService::class)->cleanupFailedRequestFiles($request);
            return $exception->getResponse();
        } catch (\Throwable $exception) {
            app(\App\Services\Deliveries\DeliveryProofService::class)->cleanupFailedRequestFiles($request);
            if ($session !== null) {
                $request->session()->replace($session);
            }
            throw $exception;
        }
    }
}
