<?php

namespace App\Services;

class TextExtractorService
{
    /**
     * Reads a .txt file in 64KB chunks to avoid loading the entire file into memory at once.
     * Detects encoding from the first chunk and converts to UTF-8 if needed.
     */
    public function extractFromTxt(string $filePath): string
    {
        if (! file_exists($filePath)) {
            throw new \RuntimeException('Failed to open file.');
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open file.');
        }

        $content = '';
        $firstChunk = true;
        $encoding = null;

        while (! feof($handle)) {
            $chunk = fread($handle, 65536);
            if ($chunk === false) {
                break;
            }

            if ($firstChunk) {
                $chunk = $this->removeBom($chunk);
                $encoding = mb_detect_encoding(
                    $chunk,
                    ['UTF-8', 'Windows-1252', 'ISO-8859-15', 'ISO-8859-1'],
                    strict: true
                );
                $firstChunk = false;
            }

            $content .= $chunk;
        }

        fclose($handle);

        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);

        return trim($content);
    }

    /**
     * Reads a .docx file using streaming to avoid loading the entire XML into memory.
     *
     * Instead of loading the full XML string with getFromName(), we stream word/document.xml
     * from the ZIP to a temp file, then parse it with XMLReader (SAX-style, node by node).
     * This keeps memory usage low even for very large documents.
     */
    public function extractFromDocx(string $filePath): string
    {
        $zip = new \ZipArchive;
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Failed to open file.');
        }

        $stream = $zip->getStream('word/document.xml');
        if ($stream === false) {
            $zip->close();
            throw new \RuntimeException('Invalid .docx file: document.xml is missing.');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'docx_xml_');
        $dest = fopen($tmpFile, 'wb');
        stream_copy_to_stream($stream, $dest);
        fclose($stream);
        fclose($dest);
        $zip->close();

        try {
            return $this->parseDocumentXmlStreaming($tmpFile);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Returns true if the PDF needs OCR (scanned or garbled encoding).
     *
     * Samples only the first 3 pages with pdftotext to avoid processing the full file.
     * Treats the PDF as scanned if output is sparse (< 50 chars/page) or garbled
     * (low ratio of Unicode letters — indicates broken font encoding).
     */
    public function isPdfScanned(string $filePath): bool
    {
        $pageCount = $this->getPdfPageCount($filePath);
        $samplePages = min(3, $pageCount);

        $sampleText = $this->extractFromPdfRange($filePath, 1, $samplePages);
        $charsPerPage = mb_strlen($sampleText) / $samplePages;

        return $charsPerPage < 50 || $this->isGarbledText($sampleText);
    }

    /**
     * Extracts text from a regular (text-based) PDF using the pdftotext CLI tool.
     *
     * pdftotext (from poppler-utils) runs as a separate process and writes output
     * to a temp file, so PHP memory is barely affected regardless of PDF size.
     */
    public function extractFromPdf(string $filePath): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'pdf_txt_');

        try {
            $cmd = sprintf(
                'pdftotext -enc UTF-8 %s %s 2>&1',
                escapeshellarg($filePath),
                escapeshellarg($tmpFile)
            );
            $output = [];
            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                throw new \RuntimeException('pdftotext failed: '.implode("\n", $output));
            }

            return $this->extractFromTxt($tmpFile);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Extracts text from a page range of a PDF using pdftotext -f/-l flags.
     * Used for cheap sampling to detect PDF type without processing the full file.
     */
    private function extractFromPdfRange(string $filePath, int $firstPage, int $lastPage): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'pdf_txt_');

        try {
            $cmd = sprintf(
                'pdftotext -enc UTF-8 -f %d -l %d %s %s 2>&1',
                $firstPage,
                $lastPage,
                escapeshellarg($filePath),
                escapeshellarg($tmpFile)
            );
            $output = [];
            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                throw new \RuntimeException('pdftotext failed: '.implode("\n", $output));
            }

            return $this->extractFromTxt($tmpFile);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Streams OCR text from a scanned PDF page by page via a callback.
     *
     * Processes one page at a time — disk and memory usage stay constant.
     * Calls $onPage($page, $totalPages, $text) after each page is ready.
     *
     * Checks connection_aborted() before each page so the loop stops
     * immediately if the client disconnects or closes the browser.
     * ignore_user_abort(true) ensures the finally block always runs
     * and temp files are cleaned up even on disconnect.
     *
     * No page limit — the caller controls cancellation via disconnect.
     * Default language is Estonian ('est'). Multiple: 'est+eng'.
     */
    public function streamOcrPdf(string $filePath, callable $onPage, string $language = 'est'): void
    {
        ignore_user_abort(true);

        $pageCount = $this->getPdfPageCount($filePath);
        $tmpDir = sys_get_temp_dir().'/ocr_'.uniqid('', true);
        mkdir($tmpDir, 0700);

        try {
            for ($page = 1; $page <= $pageCount; $page++) {
                if (connection_aborted()) {
                    break;
                }

                $imageBase = $tmpDir.'/page';
                $cmd = sprintf(
                    'pdftoppm -r 200 -png -f %d -l %d %s %s 2>&1',
                    $page,
                    $page,
                    escapeshellarg($filePath),
                    escapeshellarg($imageBase)
                );
                $output = [];
                exec($cmd, $output, $exitCode);

                if ($exitCode !== 0) {
                    throw new \RuntimeException("pdftoppm failed on page {$page}: ".implode("\n", $output));
                }

                $images = glob($tmpDir.'/page-*.png');
                if (empty($images)) {
                    continue;
                }

                $imagePath = $images[0];
                $textBase = $tmpDir.'/text_'.$page;

                $cmd = sprintf(
                    'tesseract %s %s -l %s 2>&1',
                    escapeshellarg($imagePath),
                    escapeshellarg($textBase),
                    escapeshellarg($language)
                );
                $output = [];
                exec($cmd, $output, $exitCode);

                @unlink($imagePath);

                if ($exitCode !== 0) {
                    throw new \RuntimeException("tesseract failed on page {$page}: ".implode("\n", $output));
                }

                $textFile = $textBase.'.txt';
                $pageText = '';
                if (file_exists($textFile)) {
                    $pageText = trim(file_get_contents($textFile));
                    @unlink($textFile);
                }

                $onPage($page, $pageCount, $pageText);
            }
        } finally {
            foreach (glob($tmpDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmpDir);
        }
    }

    /**
     * Parses word/document.xml using XMLReader (streaming SAX-style parser).
     *
     * Reads one XML node at a time — never holds the full document in memory.
     * Collects text from <w:t> nodes grouped by <w:p> paragraphs.
     */
    private function parseDocumentXmlStreaming(string $xmlFilePath): string
    {
        $reader = new \XMLReader;
        if (! $reader->open($xmlFilePath)) {
            throw new \RuntimeException('Failed to open XML for parsing.');
        }

        $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $lines = [];
        $inParagraph = false;
        $currentLine = '';

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                if ($reader->namespaceURI === $wNs && $reader->localName === 'p') {
                    $inParagraph = true;
                    $currentLine = '';
                } elseif ($inParagraph && $reader->namespaceURI === $wNs && $reader->localName === 't') {
                    if (! $reader->isEmptyElement) {
                        $reader->read();
                        if ($reader->nodeType === \XMLReader::TEXT) {
                            $currentLine .= $reader->value;
                        }
                    }
                }
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT) {
                if ($reader->namespaceURI === $wNs && $reader->localName === 'p') {
                    $lines[] = $currentLine;
                    $inParagraph = false;
                    $currentLine = '';
                }
            }
        }

        $reader->close();

        $text = implode("\n", $lines);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * Returns the number of pages in a PDF file using pdfinfo (from poppler-utils).
     */
    private function getPdfPageCount(string $filePath): int
    {
        $cmd = 'pdfinfo '.escapeshellarg($filePath).' 2>&1';
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new \RuntimeException('pdfinfo failed: '.implode("\n", $output));
        }

        foreach ($output as $line) {
            if (preg_match('/^Pages:\s+(\d+)/i', $line, $matches)) {
                return (int) $matches[1];
            }
        }

        throw new \RuntimeException('Could not determine PDF page count.');
    }

    /**
     * Returns true if the text looks like garbled encoding output rather than readable content.
     *
     * Checks the ratio of Unicode letters to non-whitespace characters.
     * Normal text (any language) is mostly letters; encoding garbage is mostly
     * digits, control characters, and symbols — ratio drops well below 30%.
     */
    private function isGarbledText(string $text): bool
    {
        $nonWhitespace = preg_replace('/\s+/u', '', $text);
        $total = mb_strlen($nonWhitespace);

        if ($total === 0) {
            return false;
        }

        preg_match_all('/\p{L}/u', $nonWhitespace, $matches);
        $letterRatio = count($matches[0]) / $total;

        return $letterRatio < 0.3;
    }

    /**
     * Removes BOM from the start of the string.
     * UTF-8 BOM: EF BB BF
     * UTF-16 LE BOM: FF FE
     * UTF-16 BE BOM: FE FF
     */
    private function removeBom(string $content): string
    {
        $boms = [
            "\xEF\xBB\xBF",
            "\xFF\xFE",
            "\xFE\xFF",
        ];

        foreach ($boms as $bom) {
            if (str_starts_with($content, $bom)) {
                return substr($content, strlen($bom));
            }
        }

        return $content;
    }
}
