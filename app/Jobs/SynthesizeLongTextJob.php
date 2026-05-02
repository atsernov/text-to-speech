<?php

namespace App\Jobs;

use App\Models\AudioFile;
use App\Services\TextSplitterService;
use App\Services\WavMergerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SynthesizeLongTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    private const CHUNK_MAX_ATTEMPTS = 3;
    private const CHUNK_RETRY_BASE_DELAY_MS = 1000;

    public function __construct(
        private string $jobId,
        private string $text,
        private string $speaker,
        private string $sessionId,
        private string $textPreview = '',
        private float $speed = 1.0,
    ) {}

    public function handle(TextSplitterService $splitter, WavMergerService $merger): void
    {
        $chunks = $splitter->split($this->text);
        $total = count($chunks);
        // Every created temp file — always cleaned up in finally
        $allTempFiles = [];
        // Only chunks that finished successfully — used for merging
        $doneTempFiles = [];
        $mergedTempFile = null;

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

                $tempFile = tempnam(sys_get_temp_dir(), 'tts_chunk_');
                $allTempFiles[] = $tempFile;

                $this->synthesizeChunk($chunk, $tempFile, $index + 1);

                // Added only after successful synthesis so the failed chunk is excluded
                $doneTempFiles[] = $tempFile;

                $this->updateStatus('processing', $index + 1, $total);
            }

            $mergedTempFile = tempnam(sys_get_temp_dir(), 'tts_merged_');
            $merger->merge($doneTempFiles, $mergedTempFile);

            $fileName = 'tts_'.Str::random(10).'.wav';
            $stream = fopen($mergedTempFile, 'rb');
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

            // If at least one chunk was synthesized, save what we have as partial audio
            if (count($doneTempFiles) > 0) {
                try {
                    $partialMerged = tempnam(sys_get_temp_dir(), 'tts_partial_');
                    $merger->merge($doneTempFiles, $partialMerged);

                    $partialFileName = 'tts_'.Str::random(10).'.wav';
                    $stream = fopen($partialMerged, 'rb');
                    Storage::disk('public')->put('audio/'.$partialFileName, $stream);
                    fclose($stream);

                    if (file_exists($partialMerged)) {
                        unlink($partialMerged);
                    }

                    $partialAudioUrl = url('/api/audio/'.$partialFileName);
                } catch (\Exception) {
                    // Partial merge failed — proceed without audio
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
                'progress' => count($doneTempFiles),
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
            if ($mergedTempFile && file_exists($mergedTempFile)) {
                unlink($mergedTempFile);
            }
        }
    }

    /**
     * Sends one text chunk to the TTS API and writes the audio response into $tempFile.
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
