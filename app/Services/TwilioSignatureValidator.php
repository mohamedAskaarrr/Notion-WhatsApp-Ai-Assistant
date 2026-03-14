<?php

namespace App\Services;

use Illuminate\Http\Request;

class TwilioSignatureValidator
{
    private string $authToken;

    public function __construct()
    {
        $this->authToken = config('services.twilio.auth_token', '');
    }

    public function validate(Request $request): bool
    {
        $signature = $request->header('X-Twilio-Signature', '');

        if (empty($signature)) {
            return false;
        }

        if (empty($this->authToken)) {
            return false;
        }

        $url = $request->fullUrl();
        $postParams = $request->post();

        $expectedSignature = $this->computeSignature($url, $postParams);

        return hash_equals($expectedSignature, $signature);
    }

    public function computeSignature(string $url, array $postParams = []): string
    {
        ksort($postParams);
        $data = $url;
        foreach ($postParams as $key => $value) {
            $data .= $key . $value;
        }

        return base64_encode(hash_hmac('sha1', $data, $this->authToken, true));
    }
}
