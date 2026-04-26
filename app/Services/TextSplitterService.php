<?php

namespace App\Services;

class TextSplitterService
{
    private int $maxChunkSize;
    private string $locale;

    public function __construct(int $maxChunkSize = 2000, string $locale = 'et_EE')
    {
        $this->maxChunkSize = $maxChunkSize;
        $this->locale = $locale;
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
     * Splits text into sentences using IntlBreakIterator (Unicode UAX #29 rules).
     * Correctly handles abbreviations (Dr., lk., jne.), decimals, and initials.
     * Falls back to punctuation-based regex if the intl extension is unavailable.
     *
     * @return string[]
     */
    private function splitIntoSentences(string $text): array
    {
        if (class_exists(\IntlBreakIterator::class)) {
            $iterator = \IntlBreakIterator::createSentenceInstance($this->locale);
            $iterator->setText($text);

            $sentences = [];
            foreach ($iterator->getPartsIterator() as $part) {
                $sentences[] = $part;
            }

            return $sentences ?: [$text];
        }

        // Fallback: split after sentence-ending punctuation followed by whitespace.
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
