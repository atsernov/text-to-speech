<?php

use App\Services\TextExtractorService;

// Helper: builds a minimal valid .docx file (ZIP + word/document.xml).
function makeDocx(string $path, array $paragraphs): void
{
    $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<w:document xmlns:w="'.$wNs.'">';
    $xml .= '<w:body>';

    foreach ($paragraphs as $para) {
        $xml .= '<w:p><w:r><w:t>'.htmlspecialchars($para, ENT_XML1, 'UTF-8').'</w:t></w:r></w:p>';
    }

    $xml .= '</w:body></w:document>';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
}

// Returns a path in the system temp dir with a consistent prefix.
// All files matching the prefix are deleted in afterEach.
function tmpExt(string $name): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'exttest_'.$name;
}

// Check whether a CLI tool is available on the current system.
function cliAvailable(string $command): bool
{
    $cmd = PHP_OS_FAMILY === 'Windows' ? "where {$command}" : "which {$command}";
    exec($cmd, $out, $code);

    return $code === 0;
}

beforeEach(function () {
    $this->extractor = new TextExtractorService;
    $this->fixtures = __DIR__.'/../fixtures';
});

afterEach(function () {
    foreach (glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'exttest_*') as $file) {
        @unlink($file);
    }
});

// extractFromTxt - plain text files

test('extracts content from a UTF-8 text file', function () {
    $path = tmpExt('sample.txt');
    file_put_contents($path, "Esimene rida.\nTeine rida.");

    $text = $this->extractor->extractFromTxt($path);

    expect($text)->toBe("Esimene rida.\nTeine rida.");
});

test('removes UTF-8 BOM from the beginning of the file', function () {
    $path = tmpExt('bom.txt');
    file_put_contents($path, "\xEF\xBB\xBFTekst ilma BOM-ita.");

    $text = $this->extractor->extractFromTxt($path);

    expect($text)->toBe('Tekst ilma BOM-ita.');
    expect(str_starts_with($text, "\xEF\xBB\xBF"))->toBeFalse();
});

test('normalises Windows line endings to Unix line endings', function () {
    $path = tmpExt('crlf.txt');
    file_put_contents($path, "Rida üks.\r\nRida kaks.\r\nRida kolm.");

    $text = $this->extractor->extractFromTxt($path);

    expect($text)->toBe("Rida üks.\nRida kaks.\nRida kolm.");
    expect($text)->not->toContain("\r");
});

test('trims leading and trailing whitespace from text file', function () {
    $path = tmpExt('spaces.txt');
    file_put_contents($path, "   \n\nSisu algab siin.\n\n   ");

    $text = $this->extractor->extractFromTxt($path);

    expect($text)->toBe('Sisu algab siin.');
});

test('throws RuntimeException when txt file does not exist', function () {
    $path = tmpExt('does_not_exist.txt');

    expect(fn () => $this->extractor->extractFromTxt($path))
        ->toThrow(RuntimeException::class);
});

// extractFromDocx - Word documents

test('extracts text from a valid docx file', function () {
    $path = tmpExt('sample.docx');
    makeDocx($path, ['Esimene lõik.', 'Teine lõik.']);

    $text = $this->extractor->extractFromDocx($path);

    expect($text)->toContain('Esimene lõik.')
        ->and($text)->toContain('Teine lõik.');
});

test('each paragraph in docx appears on its own line', function () {
    $path = tmpExt('paras.docx');
    makeDocx($path, ['Lõik A', 'Lõik B', 'Lõik C']);

    $text = $this->extractor->extractFromDocx($path);
    $lines = array_values(array_filter(explode("\n", $text)));

    expect($lines[0])->toBe('Lõik A')
        ->and($lines[1])->toBe('Lõik B')
        ->and($lines[2])->toBe('Lõik C');
});

test('preserves Estonian special characters in docx', function () {
    $path = tmpExt('estonian.docx');
    makeDocx($path, ['Õpik: ä, ö, ü, õ — eesti keele tähed.']);

    $text = $this->extractor->extractFromDocx($path);

    expect($text)->toContain('ä, ö, ü, õ');
});

test('throws RuntimeException when docx file is not a valid zip', function () {
    $path = tmpExt('broken.docx');
    file_put_contents($path, 'this is not a zip file');

    expect(fn () => $this->extractor->extractFromDocx($path))
        ->toThrow(RuntimeException::class);
});

test('throws RuntimeException when docx zip has no word/document.xml', function () {
    $path = tmpExt('empty.docx');

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('README.txt', 'no document here');
    $zip->close();

    expect(fn () => $this->extractor->extractFromDocx($path))
        ->toThrow(RuntimeException::class, 'document.xml');
});

// extractFromPdf / isPdfScanned — pdftotext (poppler-utils)

test('extracted pdf text is not empty and contains expected word', function () {
    $text = $this->extractor->extractFromPdf($this->fixtures.'/sample.pdf');

    expect($text)->not->toBeEmpty()
        ->and($text)->toContain('Tere');
})->skip(fn () => ! file_exists(__DIR__.'/../fixtures/sample.pdf'), 'fixture sample.pdf not found')
    ->skip(fn () => ! cliAvailable('pdftotext'), 'pdftotext not available');

test('text-based pdf is not detected as scanned', function () {
    expect($this->extractor->isPdfScanned($this->fixtures.'/sample.pdf'))->toBeFalse();
})->skip(fn () => ! file_exists(__DIR__.'/../fixtures/sample.pdf'), 'fixture sample.pdf not found')
    ->skip(fn () => ! cliAvailable('pdftotext'), 'pdftotext not available')
    ->skip(fn () => ! cliAvailable('pdfinfo'), 'pdfinfo not available');

test('scanned pdf is detected as needing ocr', function () {
    expect($this->extractor->isPdfScanned($this->fixtures.'/sample-ocr.pdf'))->toBeTrue();
})->skip(fn () => ! file_exists(__DIR__.'/../fixtures/sample-ocr.pdf'), 'fixture sample-ocr.pdf not found')
    ->skip(fn () => ! cliAvailable('pdftotext'), 'pdftotext not available')
    ->skip(fn () => ! cliAvailable('pdfinfo'), 'pdfinfo not available');
