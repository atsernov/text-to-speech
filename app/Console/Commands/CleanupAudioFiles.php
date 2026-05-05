<?php

namespace App\Console\Commands;

use App\Models\AudioFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupAudioFiles extends Command
{
    protected $signature = 'audio:cleanup';

    protected $description = 'Deletes audio files and DB records older than 30 days';

    public function handle(): int
    {
        $cutoff = now()->subDays(AudioFile::RETENTION_DAYS);

        // Delete files from disk and their DB records
        $doneFiles = AudioFile::where('status', 'done')
            ->where('created_at', '<', $cutoff)
            ->get();

        $deletedFiles = 0;
        foreach ($doneFiles as $file) {
            if ($file->filename) {
                Storage::disk('public')->delete('audio/'.$file->filename);
            }
            $file->delete();
            $deletedFiles++;
        }

        // Remove failed/pending records older than the retention period (no files on disk)
        $deletedRecords = AudioFile::whereIn('status', ['failed', 'pending'])
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Cleanup complete: {$deletedFiles} audio files deleted, {$deletedRecords} stale records removed.");

        return self::SUCCESS;
    }
}
