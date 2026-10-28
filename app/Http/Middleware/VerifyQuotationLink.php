<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyQuotationLink
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->hasValidSignature()) {
            return response()->view('sales-workspace.quote-unavailable', [], 403)
                ->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);
        }
        return $next($request);
    }
}
