<?php

namespace App\Services;

use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use Illuminate\Support\Facades\Http;

class UrlTextExtractorService
{
    /**
     * Loads the page by URL and extracts the main text using the Readability algorithm.
     */
    public function extract(string $url): string
    {
        $html = $this->fetchPage($url);
        $text = $this->parseWithReadability($html, $url);

        if (empty(trim($text))) {
            throw new \RuntimeException(
                'Failed to extract text from the page. '.
                'The website might be using JavaScript to load content.'
            );
        }

        return $text;
    }

    /**
     * Blocks SSRF attacks by rejecting URLs that point to private or reserved
     * network ranges, loopback addresses, cloud metadata endpoints, and
     * non-HTTP(S) schemes before any network connection is made.
     */
    private function guardAgainstSsrf(string $url): void
    {
        $parsed = parse_url($url);

        if (! $parsed || empty($parsed['host'])) {
            throw new \RuntimeException('Invalid URL.');
        }

        // Allow only http and https
        $scheme = strtolower($parsed['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Only HTTP and HTTPS URLs are allowed.');
        }

        $host = strtolower($parsed['host']);

        // Strip IPv6 brackets: [::1] → ::1
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        // Block loopback hostnames directly
        $blockedHosts = ['localhost', 'localhost.localdomain', '127.0.0.1', '::1', '0.0.0.0'];
        if (in_array($host, $blockedHosts, true)) {
            throw new \RuntimeException('Access to this URL is not allowed.');
        }

        // If the host is already a raw IP, check it immediately
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host);

            return;
        }

        // Resolve all DNS records and check every IP.
        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip !== null) {
                $this->assertPublicIp($ip);
            }
        }
    }

    /**
     * Throws if the IP falls inside a private, loopback, link-local,
     * or otherwise reserved range (RFC 1918, 169.254.x.x, etc.).
     */
    private function assertPublicIp(string $ip): void
    {
        $isPublic = (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if (! $isPublic) {
            throw new \RuntimeException('Access to this URL is not allowed.');
        }
    }

    /**
     * Loads page HTML via HTTP request.
     */
    private function fetchPage(string $url): string
    {
        $this->guardAgainstSsrf($url);

        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
        ])
            ->timeout(15)
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "The website returned an error: {$response->status()}. Page is unavailable."
            );
        }

        return $response->body();
    }

    /**
     * Applies the Readability algorithm to the HTML and returns clean text.
     */
    private function parseWithReadability(string $html, string $url): string
    {
        $readability = new Readability(new Configuration([
            'originalURL' => $url,
            'fixRelativeURLs' => true,
        ]));

        try {
            $readability->parse($html);
        } catch (ParseException $e) {
            throw new \RuntimeException('Failed to parse page HTML: '.$e->getMessage());
        }

        $content = $readability->getContent();

        if (empty($content)) {
            return '';
        }

        return $this->htmlToPlainText($content);
    }

    /**
     * Converts HTML to clean text:
     * Preserves paragraph structure and removes all tags.
     */
    private function htmlToPlainText(string $html): string
    {
        // Adding line breaks after block elements before strip_tags
        $html = preg_replace('/<\/(p|div|h[1-6]|li|br|tr)>/i', "$0\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);
        $lines = array_values(array_filter($lines, fn ($line) => $line !== ''));

        $text = implode("\n", $lines);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
