<?php

use App\Services\WavMergerService;

// Helper: generates a minimal valid WAV file with synthetic audio data.
function makeWav(string $path, int $sampleCount = 100): void
{
    $audioData = str_repeat("\x00\x00", $sampleCount); // silence: 16-bit zeros
    $dataSize = strlen($audioData);

    $bytes = 'RIFF';
    $bytes .= pack('V', 36 + $dataSize); // RIFF chunk size = header(36) + data
    $bytes .= 'WAVE';
    $bytes .= 'fmt ';
    $bytes .= pack('V', 16);             // fmt chunk size (always 16 for PCM)
    $bytes .= pack('v', 1);              // audio format: 1 = PCM
    $bytes .= pack('v', 1);              // channels: mono
    $bytes .= pack('V', 22050);          // sample rate
    $bytes .= pack('V', 44100);          // byte rate
    $bytes .= pack('v', 2);              // block align
    $bytes .= pack('v', 16);             // bits per sample
    $bytes .= 'data';
    $bytes .= pack('V', $dataSize);      // data chunk size
    $bytes .= $audioData;

    file_put_contents($path, $bytes);
}

// Returns a path inside the system temp dir with a consistent prefix.
// All files with this prefix are deleted in afterEach.
function tmpWav(string $name): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'wavtest_'.$name;
}

// Read the data chunk size stored in a WAV file header.
function readWavDataSize(string $path): int
{
    $fp = fopen($path, 'rb');
    fseek($fp, 40);
    $raw = fread($fp, 4);
    fclose($fp);

    return unpack('V', $raw)[1];
}

// Read the RIFF chunk size stored in the file header.
function readRiffSize(string $path): int
{
    $fp = fopen($path, 'rb');
    fseek($fp, 4);
    $raw = fread($fp, 4);
    fclose($fp);

    return unpack('V', $raw)[1];
}

beforeEach(function () {
    $this->merger = new WavMergerService;
});

afterEach(function () {
    // Clean up all temp files created by tmpWav().
    foreach (glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'wavtest_*') as $file) {
        @unlink($file);
    }
});

// Single-file shortcut

test('single input file is copied directly to the output', function () {
    $input = tmpWav('input.wav');
    $output = tmpWav('output.wav');

    makeWav($input, 200);
    $this->merger->merge([$input], $output);

    expect(file_exists($output))->toBeTrue()
        ->and(file_get_contents($output))->toBe(file_get_contents($input));
});

// Merging two files

test('merged file exists and is not empty', function () {
    $a = tmpWav('a.wav');
    $b = tmpWav('b.wav');
    $output = tmpWav('merged.wav');

    makeWav($a, 100);
    makeWav($b, 100);
    $this->merger->merge([$a, $b], $output);

    expect(file_exists($output))->toBeTrue()
        ->and(filesize($output))->toBeGreaterThan(0);
});

test('merged file starts with a valid RIFF/WAVE header', function () {
    $a = tmpWav('a.wav');
    $b = tmpWav('b.wav');
    $output = tmpWav('merged.wav');

    makeWav($a, 100);
    makeWav($b, 100);
    $this->merger->merge([$a, $b], $output);

    $bytes = file_get_contents($output);
    expect(substr($bytes, 0, 4))->toBe('RIFF')
        ->and(substr($bytes, 8, 4))->toBe('WAVE');
});

test('data chunk size equals the sum of both input data sizes', function () {
    $a = tmpWav('a.wav');
    $b = tmpWav('b.wav');
    $output = tmpWav('merged.wav');

    makeWav($a, 100);
    makeWav($b, 150);

    $this->merger->merge([$a, $b], $output);

    $expected = readWavDataSize($a) + readWavDataSize($b);
    expect(readWavDataSize($output))->toBe($expected);
});

test('RIFF size field in merged file is consistent with actual file size', function () {
    $a = tmpWav('a.wav');
    $b = tmpWav('b.wav');
    $output = tmpWav('merged.wav');

    makeWav($a, 100);
    makeWav($b, 100);
    $this->merger->merge([$a, $b], $output);

    $expected = filesize($output) - 8;
    expect(readRiffSize($output))->toBe($expected);
});

test('audio data from both files is present in the merged output', function () {
    $a = tmpWav('a.wav');
    $b = tmpWav('b.wav');
    $output = tmpWav('merged.wav');

    // Distinct non-zero byte patterns so we can verify each file's data separately.
    $audioA = str_repeat("\x11\x11", 50);
    $audioB = str_repeat("\x22\x22", 50);

    $header = 'RIFF'.pack('V', 36 + 100).'WAVE';
    $header .= 'fmt '.pack('V', 16).pack('v', 1).pack('v', 1);
    $header .= pack('V', 22050).pack('V', 44100).pack('v', 2).pack('v', 16);
    $header .= 'data'.pack('V', 100);

    file_put_contents($a, $header.$audioA);
    file_put_contents($b, $header.$audioB);

    $this->merger->merge([$a, $b], $output);

    $outputBytes = file_get_contents($output);
    expect(str_contains($outputBytes, $audioA))->toBeTrue()
        ->and(str_contains($outputBytes, $audioB))->toBeTrue();
});

// Merging more than two files

test('merging three files produces correct total data size', function () {
    $a = tmpWav('a.wav');
    $b = tmpWav('b.wav');
    $c = tmpWav('c.wav');
    $output = tmpWav('merged.wav');

    makeWav($a, 100);
    makeWav($b, 200);
    makeWav($c, 150);
    $this->merger->merge([$a, $b, $c], $output);

    $expected = readWavDataSize($a) + readWavDataSize($b) + readWavDataSize($c);
    expect(readWavDataSize($output))->toBe($expected);
});

// Error handling

test('throws RuntimeException when input files are not valid WAV', function () {
    $bad1 = tmpWav('bad1.wav');
    $bad2 = tmpWav('bad2.wav');
    $output = tmpWav('output.wav');

    // Two invalid files: the single-file shortcut is skipped, so findDataOffset
    // is called and throws because there is no 'data' chunk.
    file_put_contents($bad1, 'this is not a wav file');
    file_put_contents($bad2, 'this is not a wav file');

    expect(fn () => $this->merger->merge([$bad1, $bad2], $output))
        ->toThrow(RuntimeException::class);
});
