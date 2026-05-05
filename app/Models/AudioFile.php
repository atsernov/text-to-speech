<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudioFile extends Model
{
    /** How many days audio files are kept before automatic deletion. */
    public const RETENTION_DAYS = 7;

    protected $fillable = [
        'job_id',
        'status',
        'session_id',
        'filename',
        'audio_url',
        'error_message',
        'is_partial',
        'speaker',
        'text_preview',
    ];

    protected $appends = ['expires_in_days'];

    /**
     * Returns the number of days remaining before this file is auto-deleted.
     * Calculated for 'done' files and 'failed' files that have partial audio.
     */
    public function getExpiresInDaysAttribute(): ?int
    {
        $hasAudio = $this->status === 'done'
            || ($this->status === 'failed' && $this->is_partial && $this->audio_url);

        if (! $hasAudio || ! $this->created_at) {
            return null;
        }

        $expiresAt = $this->created_at->copy()->addDays(self::RETENTION_DAYS);
        $secondsLeft = $expiresAt->timestamp - now()->timestamp;

        return max(0, (int) ceil($secondsLeft / 86400));
    }
}
