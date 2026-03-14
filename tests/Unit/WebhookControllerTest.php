<?php

namespace Tests\Unit;

use App\Http\Controllers\WebhookController;
use App\Services\NotionService;
use App\Services\OpenAIService;
use App\Services\TwilioSignatureValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mockery;
use PHPUnit\Framework\TestCase;

class WebhookControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_handle_returns_403_when_signature_invalid(): void
    {
        $validator = Mockery::mock(TwilioSignatureValidator::class);
        $validator->shouldReceive('validate')->once()->andReturn(false);

        $openAI = Mockery::mock(OpenAIService::class);
        $notion = Mockery::mock(NotionService::class);

        $controller = new WebhookController($validator, $openAI, $notion);

        $request = Request::create('/api/webhook/whatsapp', 'POST', [
            'Body' => 'Hello',
            'From' => 'whatsapp:+1234567890',
        ]);

        $response = $controller->handle($request);

        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_handle_returns_twiml_on_valid_request(): void
    {
        $validator = Mockery::mock(TwilioSignatureValidator::class);
        $validator->shouldReceive('validate')->once()->andReturn(true);

        $openAI = Mockery::mock(OpenAIService::class);
        $openAI->shouldReceive('parseIntent')
            ->once()
            ->with('Create a note about meeting')
            ->andReturn([
                'action' => 'create_page',
                'title' => 'Meeting Note',
                'content' => 'Notes from meeting',
                'properties' => [],
            ]);

        $notion = Mockery::mock(NotionService::class);
        $notion->shouldReceive('createPage')
            ->once()
            ->andReturn(['object' => 'page', 'id' => 'page-123']);

        $controller = new WebhookController($validator, $openAI, $notion);

        $request = Request::create('/api/webhook/whatsapp', 'POST', [
            'Body' => 'Create a note about meeting',
            'From' => 'whatsapp:+1234567890',
        ]);

        $response = $controller->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<Response>', $response->getContent());
        $this->assertStringContainsString('<Message>', $response->getContent());
        $this->assertStringContainsString('Meeting Note', $response->getContent());
    }

    public function test_handle_returns_twiml_on_query_action(): void
    {
        $validator = Mockery::mock(TwilioSignatureValidator::class);
        $validator->shouldReceive('validate')->once()->andReturn(true);

        $openAI = Mockery::mock(OpenAIService::class);
        $openAI->shouldReceive('parseIntent')
            ->once()
            ->andReturn([
                'action' => 'query_database',
                'title' => '',
                'content' => '',
                'properties' => ['filter' => []],
            ]);

        $notion = Mockery::mock(NotionService::class);
        $notion->shouldReceive('queryDatabase')
            ->once()
            ->andReturn(['results' => [['id' => '1'], ['id' => '2']]]);

        $controller = new WebhookController($validator, $openAI, $notion);

        $request = Request::create('/api/webhook/whatsapp', 'POST', [
            'Body' => 'Find all tasks',
            'From' => 'whatsapp:+1234567890',
        ]);

        $response = $controller->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('2 results', $response->getContent());
    }

    public function test_handle_returns_error_message_on_exception(): void
    {
        $validator = Mockery::mock(TwilioSignatureValidator::class);
        $validator->shouldReceive('validate')->once()->andReturn(true);

        $openAI = Mockery::mock(OpenAIService::class);
        $openAI->shouldReceive('parseIntent')
            ->once()
            ->andThrow(new \RuntimeException('OpenAI API error'));

        $notion = Mockery::mock(NotionService::class);

        $controller = new WebhookController($validator, $openAI, $notion);

        $request = Request::create('/api/webhook/whatsapp', 'POST', [
            'Body' => 'Some message',
            'From' => 'whatsapp:+1234567890',
        ]);

        $response = $controller->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('error', $response->getContent());
    }

    public function test_handle_processes_update_page_action(): void
    {
        $validator = Mockery::mock(TwilioSignatureValidator::class);
        $validator->shouldReceive('validate')->once()->andReturn(true);

        $openAI = Mockery::mock(OpenAIService::class);
        $openAI->shouldReceive('parseIntent')
            ->once()
            ->andReturn([
                'action' => 'update_page',
                'title' => '',
                'content' => '',
                'properties' => ['page_id' => 'page-abc', 'status' => 'Done'],
            ]);

        $notion = Mockery::mock(NotionService::class);
        $notion->shouldReceive('updatePage')
            ->once()
            ->with('page-abc', ['page_id' => 'page-abc', 'status' => 'Done'])
            ->andReturn(['object' => 'page', 'id' => 'page-abc']);

        $controller = new WebhookController($validator, $openAI, $notion);

        $request = Request::create('/api/webhook/whatsapp', 'POST', [
            'Body' => 'Update page page-abc set status to Done',
            'From' => 'whatsapp:+1234567890',
        ]);

        $response = $controller->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('updated successfully', $response->getContent());
    }
}
