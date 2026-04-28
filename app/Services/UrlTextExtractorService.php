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
     * Loads page HTML via HTTP request.
     */
    private function fetchPage(string $url): string
    {
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
