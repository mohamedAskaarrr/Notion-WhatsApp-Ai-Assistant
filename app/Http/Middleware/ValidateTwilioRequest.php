<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateTwilioRequest
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip validation if not in production or if validation is disabled
        if (config('app.env') === 'local' && !config('app.validate_twilio_requests', false)) {
            return $next($request);
        }

        // Get the signature from the request header
        $twilioSignature = $request->header('X-Twilio-Signature');

        if (!$twilioSignature) {
            \Log::warning('Twilio request missing signature header');
            return response()->json([
                'success' => false,
                'message' => 'Invalid request'
            ], 403);
        }

        // Get the auth token from config
        $authToken = config('services.twilio.auth_token');

        if (!$authToken) {
            \Log::error('Twilio auth token not configured');
            return response()->json([
                'success' => false,
                'message' => 'Server configuration error'
            ], 500);
        }

        // Validate the request signature
        if (!$this->validateRequest($request, $twilioSignature, $authToken)) {
            \Log::warning('Invalid Twilio signature', [
                'provided_signature' => $twilioSignature,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid request signature'
            ], 403);
        }

        return $next($request);
    }

    /**
     * Validate the Twilio request signature
     *
     * @param Request $request
     * @param string $twilioSignature
     * @param string $authToken
     * @return bool
     */
    private function validateRequest(Request $request, string $twilioSignature, string $authToken): bool
    {
        // Get the full URL (without query string parameters)
        $url = $request->url();

        // Build the data string from POST parameters
        $data = '';
        foreach ($request->all() as $key => $value) {
            $data .= $key . $value;
        }

        // Compute the signature
        $computedSignature = base64_encode(
            hash_hmac('sha1', $url . $data, $authToken, true)
        );

        // Compare with constant-time comparison to prevent timing attacks
        return hash_equals($computedSignature, $twilioSignature);
    }
}
