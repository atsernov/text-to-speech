<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudioFile extends Model
{
    /** How many days audio files are kept before automatic deletion. */
    public const RETENTION_DAYS = 30;

    protected $fillable = [
        'job_id',
        'status',
        'session_id',
        'filename',
        'audio_url',
        'speaker',
        'text_preview',
    ];

    protected $appends = ['expires_in_days'];

    /**
     * Returns the number of days remaining before this file is auto-deleted.
     * Only meaningful for 'done' files. Returns null for all other statuses.
     */
    public function getExpiresInDaysAttribute(): ?int
    {
        if ($this->status !== 'done' || ! $this->created_at) {
            return null;
        }

        $expiresAt = $this->created_at->copy()->addDays(self::RETENTION_DAYS);
        $secondsLeft = $expiresAt->timestamp - now()->timestamp;

        return max(0, (int) ceil($secondsLeft / 86400));
    }
}
