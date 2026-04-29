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
        $tempFiles = [];
        $mergedTempFile = null;

        try {
            $this->updateStatus('processing', 0, $total);

            foreach ($chunks as $index => $chunk) {
                $response = Http::timeout(300)
                    ->post('https://api.tartunlp.ai/text-to-speech/v2', [
                        'text' => $chunk,
                        'speaker' => $this->speaker,
                        'speed' => $this->speed,
                    ]);

                if (! $response->successful()) {
                    throw new \RuntimeException(
                        'Speech synthesis failed on part '.($index + 1).' (HTTP '.$response->status().').'
                    );
                }

                $tempFile = tempnam(sys_get_temp_dir(), 'tts_chunk_');
                file_put_contents($tempFile, $response->body());
                $tempFiles[] = $tempFile;

                $this->updateStatus('processing', $index + 1, $total);
            }

            $mergedTempFile = tempnam(sys_get_temp_dir(), 'tts_merged_');
            $merger->merge($tempFiles, $mergedTempFile);

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
            ], now()->addHours(2));

        } catch (\Exception $e) {
            AudioFile::where('job_id', $this->jobId)->update(['status' => 'failed']);

            Cache::put("tts_job_{$this->jobId}", [
                'status' => 'failed',
                'progress' => 0,
                'total' => 0,
                'error' => $e->getMessage(),
            ], now()->addHours(2));

        } finally {
            foreach ($tempFiles as $tempFile) {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
            if ($mergedTempFile && file_exists($mergedTempFile)) {
                unlink($mergedTempFile);
            }
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
