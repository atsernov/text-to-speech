<?php

namespace App\Services;

class TextSplitterService
{
    private int $maxChunkSize;

    public function __construct(int $maxChunkSize = 4000)
    {
        $this->maxChunkSize = $maxChunkSize;
    }

    /**
     * Splits text into chunks no larger than maxChunkSize characters.
     * Tries to split at sentence boundaries.
     *
     * @return string[]
     */
    public function split(string $text): array
    {
        $text = trim($text);

        if (mb_strlen($text) <= $this->maxChunkSize) {
            return [$text];
        }

        $chunks = [];
        $sentences = $this->splitIntoSentences($text);
        $currentChunk = '';

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            if ($sentence === '') {
                continue;
            }

            // If a single sentence exceeds the limit, it is split by words.
            if (mb_strlen($sentence) > $this->maxChunkSize) {
                if ($currentChunk !== '') {
                    $chunks[] = trim($currentChunk);
                    $currentChunk = '';
                }
                foreach ($this->splitByWords($sentence) as $wordChunk) {
                    $chunks[] = $wordChunk;
                }

                continue;
            }

            $separator = $currentChunk !== '' ? ' ' : '';
            $candidate = $currentChunk.$separator.$sentence;

            if (mb_strlen($candidate) > $this->maxChunkSize) {
                $chunks[] = trim($currentChunk);
                $currentChunk = $sentence;
            } else {
                $currentChunk = $candidate;
            }
        }

        if (trim($currentChunk) !== '') {
            $chunks[] = trim($currentChunk);
        }

        return array_values(array_filter($chunks));
    }

    /**
     * Splits text into sentences based on punctuation.
     *
     * @return string[]
     */
    private function splitIntoSentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', $text);

        return $parts ?: [$text];
    }

    /**
     * Splits a long string into parts by spaces (by words).
     *
     * @return string[]
     */
    private function splitByWords(string $text): array
    {
        $words = explode(' ', $text);
        $chunks = [];
        $currentChunk = '';

        foreach ($words as $word) {
            $separator = $currentChunk !== '' ? ' ' : '';
            $candidate = $currentChunk.$separator.$word;

            if (mb_strlen($candidate) > $this->maxChunkSize) {
                if ($currentChunk !== '') {
                    $chunks[] = trim($currentChunk);
                }
                $currentChunk = $word;
            } else {
                $currentChunk = $candidate;
            }
        }

        if (trim($currentChunk) !== '') {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }
}
