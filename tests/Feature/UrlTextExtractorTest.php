<?php

use App\Services\UrlTextExtractorService;
use Illuminate\Support\Facades\Http;

// Helper: wraps plain text in a minimal but Readability-friendly HTML page.
function makePage(string $bodyContent, string $title = 'Test page'): string
{
    return <<<HTML
    <!DOCTYPE html>
    <html>
    <head><title>{$title}</title></head>
    <body>
        <article>
            {$bodyContent}
        </article>
    </body>
    </html>
    HTML;
}

beforeEach(function () {
    $this->extractor = new UrlTextExtractorService;
});

// Successful extraction

test('extracts plain text from a simple HTML page', function () {
    Http::fake([
        'https://example.com' => Http::response(
            makePage('<p>Tere tulemast Eestisse.</p>'),
            200,
        ),
    ]);

    $text = $this->extractor->extract('https://example.com');

    expect($text)->toContain('Tere tulemast Eestisse.');
});

test('strips HTML tags from extracted content', function () {
    Http::fake([
        'https://example.com' => Http::response(
            makePage('<p>Puhas <strong>tekst</strong> ilma <em>siltideta</em>.</p>'),
            200,
        ),
    ]);

    $text = $this->extractor->extract('https://example.com');

    expect($text)->not->toContain('<strong>')
        ->and($text)->not->toContain('<em>')
        ->and($text)->toContain('Puhas tekst ilma siltideta.');
});

test('decodes HTML entities in extracted text', function () {
    Http::fake([
        'https://example.com' => Http::response(
            makePage('<p>Hind on 5 &euro; &amp; maksud on 20&#37;.</p>'),
            200,
        ),
    ]);

    $text = $this->extractor->extract('https://example.com');

    expect($text)->toContain('€')
        ->and($text)->toContain('&')
        ->and($text)->toContain('%')
        ->and($text)->not->toContain('&euro;')
        ->and($text)->not->toContain('&amp;');
});

test('extracts text from a multi-paragraph page', function () {
    Http::fake([
        'https://example.com' => Http::response(
            makePage('
                <p>Esimene lõik räägib ühest asjast.</p>
                <p>Teine lõik räägib teisest asjast.</p>
                <p>Kolmas lõik võtab kõik kokku.</p>
            '),
            200,
        ),
    ]);

    $text = $this->extractor->extract('https://example.com');

    expect($text)->toContain('Esimene lõik')
        ->and($text)->toContain('Teine lõik')
        ->and($text)->toContain('Kolmas lõik');
});

// HTTP errors

test('throws RuntimeException when server returns 404', function () {
    Http::fake([
        'https://example.com/missing' => Http::response('Not Found', 404),
    ]);

    expect(fn () => $this->extractor->extract('https://example.com/missing'))
        ->toThrow(RuntimeException::class, '404');
});

test('throws RuntimeException when server returns 500', function () {
    Http::fake([
        'https://example.com/broken' => Http::response('Server Error', 500),
    ]);

    expect(fn () => $this->extractor->extract('https://example.com/broken'))
        ->toThrow(RuntimeException::class, '500');
});

// Navigation is ignored, main article content is extracted

test('extracts article content and ignores navigation and footer', function () {
    Http::fake([
        'https://example.com/article' => Http::response(
            <<<HTML
            <!DOCTYPE html>
            <html>
            <head><title>Artikkel</title></head>
            <body>
                <header>
                    <nav>Avaleht | Meist | Kontakt | Logi sisse</nav>
                </header>
                <main>
                    <article>
                        <h1>Eesti keele ajalugu</h1>
                        <p>Eesti keel kuulub soome-ugri keelkonda ja on lähisugulane soome keelega.</p>
                        <p>Kirjakeel kujunes välja 19. sajandil tänu rahvuslikule ärkamisajale.</p>
                    </article>
                </main>
                <footer>© 2026 Kõlaro. Kõik õigused kaitstud.</footer>
            </body>
            </html>
            HTML,
            200,
        ),
    ]);

    $text = $this->extractor->extract('https://example.com/article');

    // Main article content must be present
    expect($text)->toContain('Eesti keele ajalugu')
        ->and($text)->toContain('soome-ugri keelkonda')
        ->and($text)->toContain('19. sajandil')
        ->and($text)->not->toContain('Logi sisse')
        ->and($text)->not->toContain('Kõik õigused kaitstud');

    // Navigation and footer should not appear in the extracted text
});

// Empty / unextractable content

test('throws RuntimeException when page has no extractable text', function () {
    // A page with only a script tag.
    Http::fake([
        'https://example.com/spa' => Http::response(
            '<html><head></head><body><script>renderApp()</script></body></html>',
            200,
        ),
    ]);

    expect(fn () => $this->extractor->extract('https://example.com/spa'))
        ->toThrow(RuntimeException::class);
});

// SSRF protection

test('throws RuntimeException for a localhost url', function () {
    expect(fn () => $this->extractor->extract('http://localhost/admin'))
        ->toThrow(RuntimeException::class);
});

test('throws RuntimeException for a loopback ip url', function () {
    expect(fn () => $this->extractor->extract('http://127.0.0.1/secret'))
        ->toThrow(RuntimeException::class);
});

test('throws RuntimeException for a private network ip', function () {
    expect(fn () => $this->extractor->extract('http://192.168.1.1'))
        ->toThrow(RuntimeException::class);
});

test('throws RuntimeException for the aws metadata endpoint', function () {
    expect(fn () => $this->extractor->extract('http://169.254.169.254/latest/meta-data/'))
        ->toThrow(RuntimeException::class);
});

test('throws RuntimeException for a non-http scheme', function () {
    expect(fn () => $this->extractor->extract('file:///etc/passwd'))
        ->toThrow(RuntimeException::class);
});

test('throws RuntimeException for an ipv6 loopback url', function () {
    expect(fn () => $this->extractor->extract('http://[::1]/admin'))
        ->toThrow(RuntimeException::class);
});
