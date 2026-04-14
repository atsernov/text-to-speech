<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AudioFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class AdminController extends Controller
{
    // ── Dashboard ────────────────────────────────────────────────────────────

    public function dashboard()
    {
        $stats = [
            'total' => AudioFile::count(),
            'pending' => AudioFile::where('status', 'pending')->count(),
            'processing' => AudioFile::where('status', 'processing')->count(),
            'done' => AudioFile::where('status', 'done')->count(),
            'failed' => AudioFile::where('status', 'failed')->count(),
        ];

        return inertia('admin/Dashboard', ['stats' => $stats]);
    }

    // ── Jobs (queue) ─────────────────────────────────────────────────────────

    public function jobs()
    {
        $jobs = AudioFile::whereIn('status', ['pending', 'processing'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (AudioFile $f) => $this->enrichWithCache($f));

        return inertia('admin/Jobs', ['jobs' => $jobs]);
    }

    public function cancelJob(int $id): JsonResponse
    {
        $job = AudioFile::whereIn('status', ['pending', 'processing'])->findOrFail($id);

        // Mark as failed in cache so the polling loop stops
        if ($job->job_id) {
            Cache::put("tts_job_{$job->job_id}", [
                'status' => 'failed',
                'progress' => 0,
                'total' => 0,
                'error' => 'Cancelled by administrator.',
            ], now()->addHours(2));
        }

        $job->update(['status' => 'failed']);

        return response()->json(['ok' => true]);
    }

    // ── Files ─────────────────────────────────────────────────────────────────

    public function files(Request $request)
    {
        $query = AudioFile::orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('text_preview', 'like', '%'.$request->search.'%');
        }

        $files = $query->paginate(30)->withQueryString();

        return inertia('admin/Files', [
            'files' => $files,
            'filters' => $request->only('status', 'search'),
        ]);
    }

    public function deleteFile(int $id): JsonResponse
    {
        $file = AudioFile::findOrFail($id);

        // Remove the WAV file from disk if it exists
        if ($file->filename) {
            Storage::disk('public')->delete('audio/'.$file->filename);
        }

        // Mark cache as failed so any active polling stops
        if ($file->job_id) {
            Cache::put("tts_job_{$file->job_id}", [
                'status' => 'failed',
                'progress' => 0,
                'total' => 0,
                'error' => 'Deleted by administrator.',
            ], now()->addHours(2));
        }

        $file->delete();

        return response()->json(['ok' => true]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function enrichWithCache(AudioFile $file): array
    {
        $data = $file->toArray();

        if ($file->job_id) {
            $cache = Cache::get("tts_job_{$file->job_id}");
            $data['progress'] = $cache['progress'] ?? 0;
            $data['total'] = $cache['total'] ?? 0;
        }

        // Queue position among all pending jobs
        if ($file->status === 'pending') {
            $data['queue_position'] = AudioFile::where('status', 'pending')
                ->where('id', '<', $file->id)
                ->count() + 1;
        }

        return $data;
    }
}
