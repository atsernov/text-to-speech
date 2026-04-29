<?php

use Illuminate\Support\Facades\Http;

// Helper: wraps content in a Readability-friendly HTML page (same as UrlTextExtractorTest).
function articlePage(string $body, string $title = 'Test'): string
{
    return <<<HTML
    <!DOCTYPE html>
    <html>
    <head><title>{$title}</title></head>
    <body><article>{$body}</article></body>
    </html>
    HTML;
}

// POST /api/extract-url — successful extraction

test('extracts text from a url and returns json with text and characters', function () {
    Http::fake([
        'https://example.com' => Http::response(
            articlePage('<p>Tere tulemast Eestisse.</p>'),
            200,
        ),
    ]);

    $this->postJson('/api/extract-url', ['url' => 'https://example.com'])
        ->assertOk()
        ->assertJsonStructure(['text', 'characters'])
        ->assertJsonFragment(['characters' => mb_strlen('Tere tulemast Eestisse.')]);
});

test('response character count matches the length of the returned text', function () {
    Http::fake([
        'https://example.com' => Http::response(
            articlePage('<p>Mingi tekst siia.</p>'),
            200,
        ),
    ]);

    $response = $this->postJson('/api/extract-url', ['url' => 'https://example.com'])
        ->assertOk();

    expect($response->json('characters'))->toBe(mb_strlen($response->json('text')));
});

// POST /api/extract-url - validation errors

test('returns 422 validation error when url field is missing', function () {
    $this->postJson('/api/extract-url', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['url']);
});

test('returns 422 validation error when url field is not a valid url', function () {
    $this->postJson('/api/extract-url', ['url' => 'not-a-url'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['url']);
});

// POST /api/extract-url - HTTP errors from the remote server

test('returns 422 with error message when remote server responds with 404', function () {
    Http::fake([
        'https://example.com/missing' => Http::response('Not Found', 404),
    ]);

    $this->postJson('/api/extract-url', ['url' => 'https://example.com/missing'])
        ->assertStatus(422)
        ->assertJsonStructure(['error']);
});

test('returns 422 with error message when remote server responds with 500', function () {
    Http::fake([
        'https://example.com/broken' => Http::response('Server Error', 500),
    ]);

    $this->postJson('/api/extract-url', ['url' => 'https://example.com/broken'])
        ->assertStatus(422)
        ->assertJsonStructure(['error']);
});

// POST /api/extract-url - page with no extractable content

test('returns 422 when the page has no extractable text', function () {
    Http::fake([
        'https://example.com/spa' => Http::response(
            '<html><head></head><body><script>renderApp()</script></body></html>',
            200,
        ),
    ]);

    $this->postJson('/api/extract-url', ['url' => 'https://example.com/spa'])
        ->assertStatus(422)
        ->assertJsonStructure(['error']);
});
