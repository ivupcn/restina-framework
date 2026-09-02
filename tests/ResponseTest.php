<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Restina\Response;

class ResponseTest extends TestCase
{
    // ─── 构造 ────────────────────────────────────────────────

    public function testDefaultConstructor(): void
    {
        $response = new Response();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getReasonPhrase());
    }

    public function testConstructorWithCustomStatus(): void
    {
        $response = new Response(404);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', $response->getReasonPhrase());
    }

    public function testConstructorWithHeaders(): void
    {
        $response = new Response(200, ['X-Custom' => 'test']);
        $this->assertSame('test', $response->getHeaderLine('X-Custom'));
    }

    public function testConstructorWithStringBody(): void
    {
        $response = new Response(200, [], 'Hello');
        $response->getBody()->rewind();
        $this->assertSame('Hello', $response->getBody()->getContents());
    }

    // ─── json ────────────────────────────────────────────────

    public function testJsonSetsContentType(): void
    {
        $response = Response::json(['key' => 'value']);
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testJsonEncodesData(): void
    {
        $data = ['name' => 'Restina', 'version' => 1];
        $response = Response::json($data);
        $response->getBody()->rewind();
        $body = $response->getBody()->getContents();

        $decoded = json_decode($body, true);
        $this->assertSame('Restina', $decoded['name']);
        $this->assertSame(1, $decoded['version']);
    }

    public function testJsonWithCustomStatus(): void
    {
        $response = Response::json(['error' => 'not found'], 404);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testJsonWithExtraHeaders(): void
    {
        $response = Response::json([], 200, ['X-Request-Id' => 'abc']);
        $this->assertSame('abc', $response->getHeaderLine('X-Request-Id'));
    }

    public function testJsonWithUnicodeData(): void
    {
        $response = Response::json(['message' => '你好世界']);
        $response->getBody()->rewind();
        $body = $response->getBody()->getContents();

        // JSON_UNESCAPED_UNICODE 应保留中文字符
        $this->assertStringContainsString('你好世界', $body);
    }

    // ─── withJson ────────────────────────────────────────────

    public function testWithJsonProducesSameResult(): void
    {
        $response = new Response();
        $jsonResponse = $response->withJson(['data' => true]);

        $this->assertSame(200, $jsonResponse->getStatusCode());
        $this->assertSame('application/json', $jsonResponse->getHeaderLine('Content-Type'));
    }

    // ─── html ────────────────────────────────────────────────

    public function testHtmlSetsContentType(): void
    {
        $response = Response::html('<h1>Hello</h1>');
        $this->assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
    }

    public function testHtmlBody(): void
    {
        $response = Response::html('<p>Test</p>');
        $response->getBody()->rewind();
        $this->assertSame('<p>Test</p>', $response->getBody()->getContents());
    }

    // ─── text ────────────────────────────────────────────────

    public function testTextSetsContentType(): void
    {
        $response = Response::text('plain text');
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('Content-Type'));
    }

    // ─── redirect ────────────────────────────────────────────

    public function testRedirectSetsLocationHeader(): void
    {
        $response = Response::redirect('https://example.com');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://example.com', $response->getHeaderLine('Location'));
    }

    public function testRedirectWithCustomStatus(): void
    {
        $response = Response::redirect('https://example.com', 301);
        $this->assertSame(301, $response->getStatusCode());
    }

    // ─── api ─────────────────────────────────────────────────

    public function testApiReturnsRestfulStructure(): void
    {
        $response = Response::api(['id' => 1], 200, 'Created');
        $response->getBody()->rewind();
        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertSame(200, $body['code']);
        $this->assertSame('Created', $body['message']);
        $this->assertSame(['id' => 1], $body['data']);
        $this->assertArrayHasKey('timestamp', $body);
    }

    public function testApiDefaultMessage(): void
    {
        $response = Response::api(null);
        $response->getBody()->rewind();
        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertSame('Success', $body['message']);
    }

    // ─── withApi ─────────────────────────────────────────────

    public function testWithApiMatchesApiStructure(): void
    {
        $response = new Response();
        $apiResponse = $response->withApi(['user' => 'test'], 201, 'User Created');
        $apiResponse->getBody()->rewind();
        $body = json_decode($apiResponse->getBody()->getContents(), true);

        $this->assertSame(201, $body['code']);
        $this->assertSame('User Created', $body['message']);
        $this->assertSame(['user' => 'test'], $body['data']);
        $this->assertArrayHasKey('timestamp', $body);
    }

    // ─── error ───────────────────────────────────────────────

    public function testErrorReturnsErrorStructure(): void
    {
        $response = Response::error('Bad Request', 400, ['field' => 'name']);
        $response->getBody()->rewind();
        $body = json_decode($response->getBody()->getContents(), true);

        $this->assertSame(400, $body['code']);
        $this->assertSame('Bad Request', $body['message']);
        $this->assertSame(['field' => 'name'], $body['error']);
        $this->assertArrayHasKey('timestamp', $body);
    }

    public function testErrorWithDefaultStatus(): void
    {
        $response = Response::error('Something wrong');
        $this->assertSame(400, $response->getStatusCode());
    }

    // ─── withStatus ──────────────────────────────────────────

    public function testWithStatusReturnsNewInstance(): void
    {
        $original = new Response();
        $modified = $original->withStatus(404);

        $this->assertSame(200, $original->getStatusCode());
        $this->assertSame(404, $modified->getStatusCode());
    }

    public function testWithStatusSetsReasonPhrase(): void
    {
        $response = (new Response())->withStatus(201);
        $this->assertSame('Created', $response->getReasonPhrase());
    }

    public function testWithStatusCustomReasonPhrase(): void
    {
        $response = (new Response())->withStatus(200, 'All Good');
        $this->assertSame('All Good', $response->getReasonPhrase());
    }

    // ─── 状态码检查方法 ──────────────────────────────────────

    public function testIsSuccessful(): void
    {
        $this->assertTrue((new Response(200))->isSuccessful());
        $this->assertTrue((new Response(201))->isSuccessful());
        $this->assertTrue((new Response(299))->isSuccessful());
        $this->assertFalse((new Response(301))->isSuccessful());
        $this->assertFalse((new Response(400))->isSuccessful());
    }

    public function testIsRedirect(): void
    {
        $this->assertTrue((new Response(301))->isRedirect());
        $this->assertTrue((new Response(302))->isRedirect());
        $this->assertFalse((new Response(200))->isRedirect());
        $this->assertFalse((new Response(400))->isRedirect());
    }

    public function testIsClientError(): void
    {
        $this->assertTrue((new Response(400))->isClientError());
        $this->assertTrue((new Response(404))->isClientError());
        $this->assertFalse((new Response(200))->isClientError());
        $this->assertFalse((new Response(500))->isClientError());
    }

    public function testIsServerError(): void
    {
        $this->assertTrue((new Response(500))->isServerError());
        $this->assertTrue((new Response(503))->isServerError());
        $this->assertFalse((new Response(200))->isServerError());
        $this->assertFalse((new Response(404))->isServerError());
    }

    // ─── getReasonPhraseFor ──────────────────────────────────

    public function testGetReasonPhraseForKnownCode(): void
    {
        $this->assertSame('OK', Response::getReasonPhraseFor(200));
        $this->assertSame('Not Found', Response::getReasonPhraseFor(404));
        $this->assertSame('Internal Server Error', Response::getReasonPhraseFor(500));
    }

    public function testGetReasonPhraseForUnknownCode(): void
    {
        $this->assertSame('Unknown Status Code', Response::getReasonPhraseFor(999));
    }
}
