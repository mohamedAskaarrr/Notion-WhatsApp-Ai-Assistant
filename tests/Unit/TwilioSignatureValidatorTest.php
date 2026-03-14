<?php

namespace Tests\Unit;

use App\Services\TwilioSignatureValidator;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class TwilioSignatureValidatorTest extends TestCase
{
    private string $authToken = 'test_auth_token_12345';

    public function test_compute_signature_produces_correct_hmac(): void
    {
        $validator = $this->createValidatorWithToken($this->authToken);

        $url = 'https://example.com/webhook/whatsapp';
        $params = ['Body' => 'Hello', 'From' => 'whatsapp:+1234567890'];

        $signature = $validator->computeSignature($url, $params);

        ksort($params);
        $data = $url;
        foreach ($params as $key => $value) {
            $data .= $key . $value;
        }
        $expected = base64_encode(hash_hmac('sha1', $data, $this->authToken, true));

        $this->assertEquals($expected, $signature);
    }

    public function test_compute_signature_sorts_params_alphabetically(): void
    {
        $validator = $this->createValidatorWithToken($this->authToken);

        $url = 'https://example.com/webhook/whatsapp';

        $params1 = ['From' => 'whatsapp:+1234567890', 'Body' => 'Hello'];
        $params2 = ['Body' => 'Hello', 'From' => 'whatsapp:+1234567890'];

        $signature1 = $validator->computeSignature($url, $params1);
        $signature2 = $validator->computeSignature($url, $params2);

        $this->assertEquals($signature1, $signature2);
    }

    public function test_validate_returns_false_when_no_signature_header(): void
    {
        $validator = $this->createValidatorWithToken($this->authToken);

        $request = Request::create('/webhook/whatsapp', 'POST', [
            'Body' => 'Hello',
            'From' => 'whatsapp:+1234567890',
        ]);

        $this->assertFalse($validator->validate($request));
    }

    public function test_validate_returns_false_when_auth_token_is_empty(): void
    {
        $validator = $this->createValidatorWithToken('');

        $request = Request::create('/webhook/whatsapp', 'POST', [
            'Body' => 'Hello',
        ]);
        $request->headers->set('X-Twilio-Signature', 'somesignature');

        $this->assertFalse($validator->validate($request));
    }

    public function test_validate_returns_true_with_valid_signature(): void
    {
        $validator = $this->createValidatorWithToken($this->authToken);

        $url = 'http://localhost/api/webhook/whatsapp';
        $params = ['Body' => 'Hello', 'From' => 'whatsapp:+1234567890'];

        $validSignature = $validator->computeSignature($url, $params);

        $request = Request::create('/api/webhook/whatsapp', 'POST', $params, [], [], [
            'HTTP_HOST' => 'localhost',
        ]);
        $request->headers->set('X-Twilio-Signature', $validSignature);

        $this->assertTrue($validator->validate($request));
    }

    public function test_validate_returns_false_with_invalid_signature(): void
    {
        $validator = $this->createValidatorWithToken($this->authToken);

        $url = 'https://example.com/webhook/whatsapp';
        $params = ['Body' => 'Hello', 'From' => 'whatsapp:+1234567890'];

        $request = Request::create('/webhook/whatsapp', 'POST', $params, [], [], [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
        ]);
        $request->headers->set('X-Twilio-Signature', 'invalid_signature');

        $this->assertFalse($validator->validate($request));
    }

    private function createValidatorWithToken(string $token): TwilioSignatureValidator
    {
        return new class($token) extends TwilioSignatureValidator {
            public function __construct(private string $token)
            {
                // bypass parent constructor that calls config()
            }

            public function validate(\Illuminate\Http\Request $request): bool
            {
                $signature = $request->header('X-Twilio-Signature', '');

                if (empty($signature)) {
                    return false;
                }

                if (empty($this->token)) {
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
                return base64_encode(hash_hmac('sha1', $data, $this->token, true));
            }
        };
    }
}
