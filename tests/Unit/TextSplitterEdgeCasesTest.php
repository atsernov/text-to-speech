<?php

use App\Services\TextSplitterService;

beforeEach(function () {
    $this->splitter = new TextSplitterService;
});

// Punctuation-only and symbol-heavy input

test('text consisting only of punctuation is returned as a single chunk', function () {
    $text = '... --- !!! ??? ...';

    $chunks = $this->splitter->split($text);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0])->toBe($text);
});

test('text with numbers and symbols is not corrupted during splitting', function () {
    $text = str_repeat('Hind on 3.14 € (käibemaksuga 20%). ', 100);

    $chunks = $this->splitter->split($text);

    $reassembled = implode(' ', $chunks);
    // Every chunk must be within the limit
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(2000);
    }
    // No characters are lost
    expect(mb_strlen($reassembled))->toBe(mb_strlen(trim($text)));
});

// Word longer than the chunk limit - no spaces to split on

test('a single word longer than the limit is returned as its own chunk', function () {
    $longWord = str_repeat('a', 2500);

    $chunks = $this->splitter->split($longWord);

    // The word must appear intact in at least one chunk
    $found = false;
    foreach ($chunks as $chunk) {
        if (str_contains($chunk, $longWord)) {
            $found = true;
            break;
        }
    }
    expect($found)->toBeTrue();
});

test('text where one word exceeds limit does not cause an infinite loop', function () {
    $longWord = str_repeat('b', 2500);
    $text = 'Normaalne algus. '.$longWord.' Normaalne lõpp.';

    // Must complete without hanging and return at least one chunk
    $chunks = $this->splitter->split($text);

    expect(count($chunks))->toBeGreaterThanOrEqual(1);
});

// Multilingual text (Estonian + English)

test('mixed estonian and english text is split correctly', function () {
    $estonian = str_repeat('Tere, see on eestikeelne lause. ', 50);
    $english = str_repeat('Hello, this is an English sentence. ', 50);
    $text = trim($estonian.$english);

    $chunks = $this->splitter->split($text);

    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(2000);
    }

    // Both languages must survive reassembly
    $reassembled = implode(' ', $chunks);
    expect($reassembled)->toContain('eestikeelne')
        ->and($reassembled)->toContain('English');
});

// Newlines and mixed whitespace

test('text with newlines between sentences is split within the limit', function () {
    // Sentences separated by newlines instead of spaces
    $text = implode("\n", array_fill(0, 200, 'See lause lõpeb punktiga.'));

    $chunks = $this->splitter->split($text);

    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(2000);
    }
});

test('multiple consecutive spaces between words do not cause empty chunks', function () {
    $text = 'Sõna   teine    kolmas.';

    $chunks = $this->splitter->split($text);

    expect($chunks)->not->toContain('');
});
