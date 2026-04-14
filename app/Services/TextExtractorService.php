<?php

namespace App\Services;

class TextExtractorService
{
    /**
     * Reads a .txt file, detects the encoding, and returns a UTF-8 string.
     */
    public function extractFromTxt(string $filePath): string
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new \RuntimeException('Failed to read file.');
        }

        // Removes BOM (Byte Order Mark) if present
        $content = $this->removeBom($content);

        // Detecting encoding. Order matters — try UTF-8 first
        $encoding = mb_detect_encoding(
            $content,
            ['UTF-8', 'Windows-1252', 'ISO-8859-15', 'ISO-8859-1'],
            strict: true
        );

        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);

        return trim($content);
    }

    /**
     * Reads a .docx file and returns text in UTF-8.
     *
     * .docx is a ZIP archive. All text is stored in word/document.xml.
     * Reading XML directly — it's more reliable than PHPWord as it doesn't try to
     * process images and other media within the document.
     */
    public function extractFromDocx(string $filePath): string
    {
        $zip = new \ZipArchive;

        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Failed to open file.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new \RuntimeException('Invalid .docx file: document.xml is missing.');
        }

        return $this->parseDocumentXml($xml);
    }

    /**
     * Parses XML from word/document.xml and extracts clean text.
     */
    private function parseDocumentXml(string $xml): string
    {
        $dom = new \DOMDocument;
        $dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $lines = [];

        // Iterating through each paragraph (<w:p>) — these are the document lines.
        $paragraphs = $xpath->query('//w:p');
        foreach ($paragraphs as $paragraph) {
            $line = '';

            // Collecting all text nodes (<w:t>) within a paragraph
            $textNodes = $xpath->query('.//w:t', $paragraph);
            foreach ($textNodes as $textNode) {
                $line .= $textNode->nodeValue;
            }

            $lines[] = $line;
        }

        // Joining paragraphs and removing consecutive empty lines
        $text = implode("\n", $lines);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
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
            "\xEF\xBB\xBF", // UTF-8
            "\xFF\xFE",      // UTF-16 LE
            "\xFE\xFF",      // UTF-16 BE
        ];

        foreach ($boms as $bom) {
            if (str_starts_with($content, $bom)) {
                return substr($content, strlen($bom));
            }
        }

        return $content;
    }
}
