<?php

namespace Tests\Unit;

use App\Services\NotionService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Mockery;
use PHPUnit\Framework\TestCase;

class NotionServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_create_page_sends_correct_request(): void
    {
        $notionResponse = [
            'object' => 'page',
            'id' => 'page-id-123',
            'properties' => [
                'title' => ['title' => [['text' => ['content' => 'Test Page']]]],
            ],
        ];

        $httpClient = Mockery::mock(ClientInterface::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->with('POST', 'https://api.notion.com/v1/pages', Mockery::on(function ($options) {
                return isset($options['json']['parent']['database_id'])
                    && isset($options['json']['properties']['title']);
            }))
            ->andReturn(new Response(200, [], json_encode($notionResponse)));

        $service = $this->createService($httpClient);
        $result = $service->createPage([
            'title' => 'Test Page',
            'content' => 'Test content',
            'properties' => [],
        ]);

        $this->assertEquals('page', $result['object']);
        $this->assertEquals('page-id-123', $result['id']);
    }

    public function test_create_page_includes_children_when_content_provided(): void
    {
        $httpClient = Mockery::mock(ClientInterface::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->with('POST', 'https://api.notion.com/v1/pages', Mockery::on(function ($options) {
                return isset($options['json']['children'])
                    && count($options['json']['children']) === 1;
            }))
            ->andReturn(new Response(200, [], json_encode(['object' => 'page', 'id' => 'page-123'])));

        $service = $this->createService($httpClient);
        $result = $service->createPage([
            'title' => 'Test',
            'content' => 'Some content here',
            'properties' => [],
        ]);

        $this->assertEquals('page-123', $result['id']);
    }

    public function test_update_page_sends_patch_request(): void
    {
        $httpClient = Mockery::mock(ClientInterface::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->with('PATCH', 'https://api.notion.com/v1/pages/page-123', Mockery::any())
            ->andReturn(new Response(200, [], json_encode(['object' => 'page', 'id' => 'page-123'])));

        $service = $this->createService($httpClient);
        $result = $service->updatePage('page-123', ['status' => 'Done']);

        $this->assertEquals('page-123', $result['id']);
    }

    public function test_update_page_throws_on_empty_page_id(): void
    {
        $httpClient = Mockery::mock(ClientInterface::class);

        $service = $this->createService($httpClient);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Page ID is required');

        $service->updatePage('', ['status' => 'Done']);
    }

    public function test_query_database_sends_post_request(): void
    {
        $queryResponse = [
            'object' => 'list',
            'results' => [
                ['object' => 'page', 'id' => 'page-1'],
                ['object' => 'page', 'id' => 'page-2'],
            ],
        ];

        $httpClient = Mockery::mock(ClientInterface::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->with('POST', 'https://api.notion.com/v1/databases/test-db-id/query', Mockery::any())
            ->andReturn(new Response(200, [], json_encode($queryResponse)));

        $service = $this->createService($httpClient);
        $result = $service->queryDatabase([]);

        $this->assertEquals('list', $result['object']);
        $this->assertCount(2, $result['results']);
    }

    public function test_query_database_passes_filter_when_provided(): void
    {
        $filter = ['property' => 'Status', 'select' => ['equals' => 'Done']];

        $httpClient = Mockery::mock(ClientInterface::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->with('POST', Mockery::any(), Mockery::on(function ($options) use ($filter) {
                return isset($options['json']['filter'])
                    && $options['json']['filter'] === $filter;
            }))
            ->andReturn(new Response(200, [], json_encode(['object' => 'list', 'results' => []])));

        $service = $this->createService($httpClient);
        $result = $service->queryDatabase($filter);

        $this->assertEquals('list', $result['object']);
    }

    public function test_add_to_database_delegates_to_create_page(): void
    {
        $notionResponse = ['object' => 'page', 'id' => 'page-123'];

        $httpClient = Mockery::mock(ClientInterface::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->with('POST', 'https://api.notion.com/v1/pages', Mockery::any())
            ->andReturn(new Response(200, [], json_encode($notionResponse)));

        $service = $this->createService($httpClient);
        $result = $service->addToDatabase([
            'title' => 'New Task',
            'content' => '',
            'properties' => ['priority' => 'high'],
        ]);

        $this->assertEquals('page-123', $result['id']);
    }

    public function test_create_page_throws_on_http_error(): void
    {
        $httpClient = Mockery::mock(ClientInterface::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->andThrow(new \GuzzleHttp\Exception\ConnectException(
                'Connection refused',
                new \GuzzleHttp\Psr7\Request('POST', 'https://api.notion.com/v1/pages')
            ));

        $service = $this->createService($httpClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to create Notion page/');

        $service->createPage(['title' => 'Test', 'content' => '', 'properties' => []]);
    }

    private function createService(ClientInterface $httpClient): NotionService
    {
        return new class($httpClient) extends NotionService {
            public function __construct(ClientInterface $httpClient)
            {
                // bypass parent constructor that calls config()
                $reflection = new \ReflectionClass(NotionService::class);

                $httpClientProp = $reflection->getProperty('httpClient');
                $httpClientProp->setAccessible(true);
                $httpClientProp->setValue($this, $httpClient);

                $prop = $reflection->getProperty('apiKey');
                $prop->setAccessible(true);
                $prop->setValue($this, 'test_notion_key');

                $prop = $reflection->getProperty('databaseId');
                $prop->setAccessible(true);
                $prop->setValue($this, 'test-db-id');

                $prop = $reflection->getProperty('notionVersion');
                $prop->setAccessible(true);
                $prop->setValue($this, '2022-06-28');
            }
        };
    }
}
