<?php

namespace App\Services;

class WavMergerService
{
    /**
     * Merges multiple WAV files into a single output file.
     * Uses streaming reads — does not load entire files into memory.
     *
     * @param  string[]  $inputPaths  Paths to the WAV files to merge
     * @param  string  $outputPath  Path to the resulting output file
     */
    public function merge(array $inputPaths, string $outputPath): void
    {
        if (count($inputPaths) === 1) {
            copy($inputPaths[0], $outputPath);

            return;
        }

        $out = fopen($outputPath, 'wb');

        // Read the header from the first file and write it to the output
        $header = $this->readWavHeader($inputPaths[0]);
        fwrite($out, $header);

        $totalDataSize = 0;

        // Stream audio data from each file
        foreach ($inputPaths as $inputPath) {
            $dataOffset = $this->findDataOffset($inputPath);
            $in = fopen($inputPath, 'rb');
            fseek($in, $dataOffset);

            // Read and write in 8KB chunks — avoids loading the whole file into memory
            while (! feof($in)) {
                $chunk = fread($in, 8192);
                if ($chunk !== false && $chunk !== '') {
                    fwrite($out, $chunk);
                    $totalDataSize += strlen($chunk);
                }
            }

            fclose($in);
        }

        // Seek back to the beginning and fix the sizes in the header
        $riffSize = strlen($header) - 8 + $totalDataSize;

        // Update RIFF chunk size (bytes 4–7)
        fseek($out, 4);
        fwrite($out, pack('V', $riffSize));

        // Find the 'data' position in the header and update the data chunk size
        $dataChunkPos = strpos($header, 'data');
        if ($dataChunkPos !== false) {
            fseek($out, $dataChunkPos + 4);
            fwrite($out, pack('V', $totalDataSize));
        }

        fclose($out);
    }

    /**
     * Reads the entire WAV header up to the start of the audio data.
     */
    private function readWavHeader(string $filePath): string
    {
        $dataOffset = $this->findDataOffset($filePath);
        $in = fopen($filePath, 'rb');
        $header = fread($in, $dataOffset);
        fclose($in);

        return $header;
    }

    /**
     * Finds the byte offset where audio data begins (after the 'data' chunk ID and its size field).
     * Traverses WAV chunks properly instead of assuming a fixed offset of 44.
     */
    private function findDataOffset(string $filePath): int
    {
        $in = fopen($filePath, 'rb');

        // Skip RIFF + size + WAVE (12 bytes)
        fseek($in, 12);

        while (! feof($in)) {
            $chunkId = fread($in, 4);
            if (strlen($chunkId) < 4) {
                break;
            }

            $sizeRaw = fread($in, 4);
            if (strlen($sizeRaw) < 4) {
                break;
            }

            $chunkSize = unpack('V', $sizeRaw)[1];

            if ($chunkId === 'data') {
                $offset = ftell($in); // position immediately after 'data' + 4-byte size field
                fclose($in);

                return $offset;
            }

            // Advance to the next chunk (WAV pads chunks to 2-byte boundaries)
            fseek($in, $chunkSize, SEEK_CUR);
            if ($chunkSize % 2 !== 0) {
                fseek($in, 1, SEEK_CUR);
            }
        }

        fclose($in);

        throw new \RuntimeException('Invalid WAV file: data chunk not found in '.$filePath);
    }
}
