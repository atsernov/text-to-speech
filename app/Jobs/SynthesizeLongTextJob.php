<?php

namespace App\Jobs;

use App\Models\AudioFile;
use App\Services\TextSplitterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SynthesizeLongTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    private const CHUNK_MAX_ATTEMPTS = 3;
    private const CHUNK_RETRY_BASE_DELAY_MS = 1000;
    private const MP3_BITRATE = '64k';

    public function __construct(
        private string $jobId,
        private string $text,
        private string $speaker,
        private string $sessionId,
        private string $textPreview = '',
        private float $speed = 1.0,
    ) {}

    public function handle(TextSplitterService $splitter): void
    {
        $chunks = $splitter->split($this->text);
        $total = count($chunks);
        // Every created temp file — always cleaned up in finally
        $allTempFiles = [];
        // Successfully converted MP3 chunks — used for concatenation
        $doneMp3Files = [];
        $finalMp3Temp = null;

        try {
            $this->updateStatus('processing', 0, $total);

            foreach ($chunks as $index => $chunk) {
                $cancelledBy = Cache::get("tts_cancel_{$this->jobId}");
                if ($cancelledBy) {
                    $message = $cancelledBy === 'admin'
                        ? 'Tühistati administraatori poolt.'
                        : 'Peatatud kasutaja poolt.';
                    throw new \RuntimeException($message);
                }

                // Step 1: Fetch WAV from the TTS API
                $wavFile = tempnam(sys_get_temp_dir(), 'tts_wav_');
                $allTempFiles[] = $wavFile;

                $this->synthesizeChunk($chunk, $wavFile, $index + 1);

                // Step 2: Convert WAV → MP3 immediately and discard WAV
                $mp3File = tempnam(sys_get_temp_dir(), 'tts_mp3_');
                $allTempFiles[] = $mp3File;

                $this->convertToMp3($wavFile, $mp3File);

                // WAV is no longer needed — free disk space now
                if (file_exists($wavFile)) {
                    unlink($wavFile);
                }

                // Only added after successful conversion so a failed chunk is excluded
                $doneMp3Files[] = $mp3File;

                $this->updateStatus('processing', $index + 1, $total);
            }

            // All chunks succeeded — concatenate into the final MP3
            $finalMp3Temp = tempnam(sys_get_temp_dir(), 'tts_merged_');
            $this->concatMp3Files($doneMp3Files, $finalMp3Temp);

            $fileName = 'tts_'.Str::random(10).'.mp3';
            $stream = fopen($finalMp3Temp, 'rb');
            Storage::disk('public')->put('audio/'.$fileName, $stream);
            fclose($stream);

            $audioUrl = url('/api/audio/'.$fileName);

            AudioFile::where('job_id', $this->jobId)->update([
                'status' => 'done',
                'filename' => $fileName,
                'audio_url' => $audioUrl,
            ]);

            Cache::put("tts_job_{$this->jobId}", [
                'status' => 'done',
                'progress' => $total,
                'total' => $total,
                'audio_url' => $audioUrl,
                'is_partial' => false,
            ], now()->addHours(2));

        } catch (\Exception $e) {
            $partialAudioUrl = null;
            $partialFileName = null;

            // If at least one chunk was converted, save what we have as partial audio
            if (count($doneMp3Files) > 0) {
                try {
                    $partialMp3 = tempnam(sys_get_temp_dir(), 'tts_partial_');
                    $this->concatMp3Files($doneMp3Files, $partialMp3);

                    $partialFileName = 'tts_'.Str::random(10).'.mp3';
                    $stream = fopen($partialMp3, 'rb');
                    Storage::disk('public')->put('audio/'.$partialFileName, $stream);
                    fclose($stream);

                    if (file_exists($partialMp3)) {
                        unlink($partialMp3);
                    }

                    $partialAudioUrl = url('/api/audio/'.$partialFileName);
                } catch (\Exception) {
                    // Partial concat failed — proceed without audio
                }
            }

            AudioFile::where('job_id', $this->jobId)->update([
                'status' => 'failed',
                'filename' => $partialFileName,
                'audio_url' => $partialAudioUrl,
                'is_partial' => $partialAudioUrl !== null,
                'error_message' => $e->getMessage(),
            ]);

            // Use the cancellation message as-is; for all other errors use a generic user-facing message
            $knownMessages = ['Peatatud kasutaja poolt.', 'Tühistati administraatori poolt.'];
            $userFacingError = in_array($e->getMessage(), $knownMessages)
                ? $e->getMessage()
                : 'Süntees ebaõnnestus. Proovi hiljem uuesti.';

            Cache::put("tts_job_{$this->jobId}", [
                'status' => 'failed',
                'progress' => count($doneMp3Files),
                'total' => $total,
                'audio_url' => $partialAudioUrl,
                'is_partial' => $partialAudioUrl !== null,
                'error' => $userFacingError,
            ], now()->addHours(2));

        } finally {
            foreach ($allTempFiles as $tempFile) {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
            if ($finalMp3Temp && file_exists($finalMp3Temp)) {
                unlink($finalMp3Temp);
            }
        }
    }

    /**
     * Sends one text chunk to the TTS API and writes the WAV response into $tempFile.
     * Retries up to CHUNK_MAX_ATTEMPTS times with exponential back-off on failure.
     */
    private function synthesizeChunk(string $chunk, string $tempFile, int $partNumber): void
    {
        $lastStatus = 0;

        for ($attempt = 1; $attempt <= self::CHUNK_MAX_ATTEMPTS; $attempt++) {
            file_put_contents($tempFile, '');

            $response = Http::timeout(300)
                ->sink($tempFile)
                ->post('https://api.tartunlp.ai/text-to-speech/v2', [
                    'text' => $chunk,
                    'speaker' => $this->speaker,
                    'speed' => $this->speed,
                ]);

            if ($response->successful()) {
                return;
            }

            $lastStatus = $response->status();

            if ($attempt < self::CHUNK_MAX_ATTEMPTS) {
                $delayMs = self::CHUNK_RETRY_BASE_DELAY_MS * (2 ** ($attempt - 1));
                usleep($delayMs * 1000);
            }
        }

        throw new \RuntimeException(
            "Speech synthesis failed on part {$partNumber} after ".self::CHUNK_MAX_ATTEMPTS." attempts (HTTP {$lastStatus})."
        );
    }

    /**
     * Converts a WAV file to MP3 using ffmpeg.
     */
    private function convertToMp3(string $inputWav, string $outputMp3): void
    {
        $cmd = sprintf(
            'ffmpeg -i %s -b:a %s -f mp3 -y %s 2>&1',
            escapeshellarg($inputWav),
            escapeshellarg(self::MP3_BITRATE),
            escapeshellarg($outputMp3)
        );

        exec($cmd, $output, $code);

        if ($code !== 0) {
            Log::error('ffmpeg WAV→MP3 failed', ['output' => implode("\n", $output)]);
            throw new \RuntimeException('Audio encoding failed.');
        }
    }

    /**
     * Concatenates multiple MP3 files into a single output file using ffmpeg.
     * For a single input file, copies it directly (no re-encoding needed).
     */
    private function concatMp3Files(array $inputMp3s, string $outputMp3): void
    {
        if (count($inputMp3s) === 1) {
            copy($inputMp3s[0], $outputMp3);

            return;
        }

        $listFile = tempnam(sys_get_temp_dir(), 'tts_list_');
        $lines = array_map(fn ($f) => 'file '.escapeshellarg($f), $inputMp3s);
        file_put_contents($listFile, implode("\n", $lines));

        $cmd = sprintf(
            'ffmpeg -f concat -safe 0 -i %s -c copy -f mp3 -y %s 2>&1',
            escapeshellarg($listFile),
            escapeshellarg($outputMp3)
        );

        exec($cmd, $output, $code);

        if (file_exists($listFile)) {
            unlink($listFile);
        }

        if ($code !== 0) {
            Log::error('ffmpeg concat failed', ['output' => implode("\n", $output)]);
            throw new \RuntimeException('Audio concatenation failed.');
        }
    }

    private function updateStatus(string $status, int $progress, int $total): void
    {
        // Only the status is updated in the DB (progress lives in cache)
        AudioFile::where('job_id', $this->jobId)->update(['status' => $status]);

        Cache::put("tts_job_{$this->jobId}", [
            'status' => $status,
            'progress' => $progress,
            'total' => $total,
            'audio_url' => null,
        ], now()->addHours(2));
    }
}
