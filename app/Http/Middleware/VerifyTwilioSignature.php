<?php

namespace App\Http\Middleware;

use App\Services\TwilioSignatureValidator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTwilioSignature
{
    public function __construct(private readonly TwilioSignatureValidator $validator) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->validator->validate($request)) {
            return response('Forbidden: Invalid Twilio signature.', 403);
        }

        return $next($request);
    }
}
