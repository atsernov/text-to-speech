<?php

use App\Services\TextSplitterService;

// Each test gets a fresh instance with the default 2000-character limit.
beforeEach(function () {
    $this->splitter = new TextSplitterService;
});

test('short text is returned as a single chunk', function () {
    $text = 'Tere, maailm!';

    $chunks = $this->splitter->split($text);

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0])->toBe($text);
});

test('empty string returns a single empty chunk', function () {
    $chunks = $this->splitter->split('');

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0])->toBe('');
});

test('text exactly at the limit is returned as a single chunk', function () {
    $text = str_repeat('a', 2000);

    $chunks = $this->splitter->split($text);

    expect($chunks)->toHaveCount(1);
});

// Chunk size guarantee

test('no chunk exceeds the maximum size', function () {
    // Build text with many short sentences so the splitter has natural split points.
    $sentence = 'See on üks lause. ';
    $text = str_repeat($sentence, 200);

    $chunks = $this->splitter->split($text);

    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(2000);
    }
});

test('long text is split into multiple chunks', function () {
    $sentence = 'See on üks lause. ';
    $text = str_repeat($sentence, 200);

    $chunks = $this->splitter->split($text);

    expect(count($chunks))->toBeGreaterThan(1);
});

test('reassembling chunks reproduces the original text', function () {
    $sentence = 'Järgmine lause on siin. ';
    $text = trim(str_repeat($sentence, 200));

    $chunks = $this->splitter->split($text);

    expect(implode(' ', $chunks))->toBe($text);
});

// Sentence boundary splitting

test('splits at sentence boundaries, not mid-sentence', function () {
    // Two clearly separate sentences that together exceed 2000 chars.
    $sentence1 = str_repeat('A', 1100).'.';
    $sentence2 = str_repeat('B', 1100).'.';
    $text = $sentence1.' '.$sentence2;

    $chunks = $this->splitter->split($text);

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0])->toBe($sentence1)
        ->and($chunks[1])->toBe($sentence2);
});

// Fallback: word splitting for sentences longer than the limit

test('a single sentence longer than the limit is split by words', function () {
    // One long "sentence" with no punctuation — only spaces between words.
    $words = array_fill(0, 500, 'sõna');
    $text = implode(' ', $words);

    $chunks = $this->splitter->split($text);

    expect(count($chunks))->toBeGreaterThan(1);
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(2000);
    }
});

test('word splitting does not cut words in half', function () {
    $words = array_fill(0, 400, 'pikksõna');
    $text = implode(' ', $words);

    $chunks = $this->splitter->split($text);

    $allWords = explode(' ', implode(' ', $chunks));
    foreach ($allWords as $word) {
        expect($word)->toBe('pikksõna');
    }
});

// Custom limit via constructor

test('respects a custom chunk size passed to the constructor', function () {
    $splitter = new TextSplitterService(maxChunkSize: 100);
    $text = str_repeat('Lühike lause. ', 20);

    $chunks = $splitter->split($text);

    expect(count($chunks))->toBeGreaterThan(1);
    foreach ($chunks as $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(100);
    }
});

// Edge cases

test('leading and trailing whitespace is trimmed', function () {
    $chunks = $this->splitter->split('   Tere!   ');

    expect($chunks[0])->toBe('Tere!');
});

test('text with only whitespace returns a single empty chunk', function () {
    $chunks = $this->splitter->split('     ');

    expect($chunks)->toHaveCount(1);
    expect($chunks[0])->toBe('');
});
