<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SynthesizeLongTextJob;
use App\Models\AudioFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AudioController extends Controller
{
    private const SESSION_COOKIE = 'tts_session_id';

    /**
     * Accepts text and a voice, dispatches a background synthesis job.
     * Immediately creates a DB record and returns the job_id.
     */
    public function synthesize(Request $request): JsonResponse
    {
        $request->validate([
            'text' => 'required|string|max:100000',
            'speaker' => 'required|string|max:100',
            'speed' => 'sometimes|numeric|min:0.5|max:2',
        ]);

        $sessionId = $this->getOrCreateSessionId($request);
        $jobId = Str::uuid()->toString();
        $textPreview = mb_substr(trim($request->input('text')), 0, 100);

        Cache::put("tts_job_{$jobId}", [
            'status' => 'pending',
            'progress' => 0,
            'total' => 0,
            'audio_url' => null,
        ], now()->addHours(2));

        AudioFile::create([
            'job_id' => $jobId,
            'status' => 'pending',
            'session_id' => $sessionId,
            'speaker' => $request->input('speaker'),
            'text_preview' => $textPreview,
        ]);

        SynthesizeLongTextJob::dispatch(
            $jobId,
            $request->input('text'),
            $request->input('speaker'),
            $sessionId,
            $textPreview,
            (float) $request->input('speed', 1.0),
        );

        return response()
            ->json(['job_id' => $jobId])
            // secure: false because the current deployment uses plain HTTP.
            ->withCookie(Cookie::make(self::SESSION_COOKIE, $sessionId, 60 * 24 * 365, httpOnly: true, secure: false, sameSite: 'lax'));
    }

    /**
     * Returns the current status of a job by job_id.
     * For pending jobs, also includes the position in the queue.
     */
    public function status(string $jobId): JsonResponse
    {
        $status = Cache::get("tts_job_{$jobId}");

        if (! $status) {
            return response()->json(['error' => 'Task not found'], 404);
        }

        // Calculate the queue position for pending jobs
        if ($status['status'] === 'pending') {
            $audioFile = AudioFile::where('job_id', $jobId)->first();
            if ($audioFile) {
                $position = AudioFile::where('status', 'pending')
                    ->where('id', '<', $audioFile->id)
                    ->count() + 1;
                $status['queue_position'] = $position;
            }
        }

        return response()->json($status);
    }

    /**
     * Returns the list of audio files for the current session (last 50).
     * For unfinished jobs, merges in the latest progress from cache.
     */
    public function myFiles(Request $request): JsonResponse
    {
        $sessionId = $request->cookie(self::SESSION_COOKIE);

        if (! $sessionId) {
            return response()->json([]);
        }

        $files = AudioFile::where('session_id', $sessionId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $allPendingIds = AudioFile::where('status', 'pending')
            ->orderBy('id')
            ->pluck('id')
            ->values();

        $pendingPositions = $allPendingIds->flip()->map(fn ($pos) => $pos + 1);

        $result = $files->map(function (AudioFile $file) use ($pendingPositions) {
            $data = [
                'id' => $file->id,
                'job_id' => $file->job_id,
                'status' => $file->status,
                'audio_url' => $file->audio_url,
                'speaker' => $file->speaker,
                'text_preview' => $file->text_preview,
                'created_at' => $file->created_at,
                'expires_in_days' => $file->expires_in_days,
                'progress' => 0,
                'total' => 0,
                'queue_position' => null,
                'error' => null,
            ];

            // For active jobs, pull fresh progress from cache
            if (in_array($file->status, ['pending', 'processing']) && $file->job_id) {
                $cache = Cache::get("tts_job_{$file->job_id}");
                if ($cache) {
                    $data['progress'] = $cache['progress'] ?? 0;
                    $data['total'] = $cache['total'] ?? 0;
                    $data['error'] = $cache['error'] ?? null;
                }
            }

            if ($file->status === 'pending') {
                $data['queue_position'] = $pendingPositions->get($file->id);
            }

            return $data;
        });

        return response()->json($result);
    }

    /**
     * Removes a record from the user's history (the file on disk is not deleted).
     * Validates session_id so a user cannot delete someone else's record.
     */
    public function deleteFile(Request $request, int $id): JsonResponse
    {
        $sessionId = $request->cookie(self::SESSION_COOKIE);

        if (! $sessionId) {
            return response()->json(['error' => 'No session'], 403);
        }

        $deleted = AudioFile::where('id', $id)
            ->where('session_id', $sessionId)
            ->delete();

        if (! $deleted) {
            return response()->json(['error' => 'Record not found'], 404);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Streams an audio file with Range request support (required for seeking).
     *
     * Access is restricted to the session that originally created the file.
     */
    public function stream(Request $request, string $filename): StreamedResponse
    {
        $filename = basename($filename);
        $path = storage_path('app/public/audio/'.$filename);

        if (! file_exists($path)) {
            abort(404);
        }

        // Verify that the requesting session owns this file.
        $sessionId = $request->cookie(self::SESSION_COOKIE);

        $ownsFile = AudioFile::where('filename', $filename)
            ->where('session_id', $sessionId)
            ->exists();

        if (! $ownsFile) {
            abort(403);
        }

        $size = filesize($path);
        $rangeHeader = request()->header('Range');

        if ($rangeHeader) {
            preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches);
            $start = (int) $matches[1];
            $end = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : $size - 1;
            $length = $end - $start + 1;

            return response()->stream(
                function () use ($path, $start, $length) {
                    $file = fopen($path, 'rb');
                    fseek($file, $start);
                    $remaining = $length;
                    while ($remaining > 0 && ! feof($file)) {
                        $chunk = fread($file, min(8192, $remaining));
                        echo $chunk;
                        $remaining -= strlen($chunk);
                        flush();
                    }
                    fclose($file);
                },
                206,
                [
                    'Content-Type' => 'audio/wav',
                    'Accept-Ranges' => 'bytes',
                    'Content-Length' => $length,
                    'Content-Range' => "bytes {$start}-{$end}/{$size}",
                ]
            );
        }

        return response()->stream(
            function () use ($path) {
                $file = fopen($path, 'rb');
                while (! feof($file)) {
                    echo fread($file, 8192);
                    flush();
                }
                fclose($file);
            },
            200,
            [
                'Content-Type' => 'audio/wav',
                'Accept-Ranges' => 'bytes',
                'Content-Length' => $size,
            ]
        );
    }

    private function getOrCreateSessionId(Request $request): string
    {
        $existing = $request->cookie(self::SESSION_COOKIE);

        if ($existing && preg_match('/^[a-f0-9\-]{36}$/', $existing)) {
            return $existing;
        }

        return Str::uuid()->toString();
    }
}
