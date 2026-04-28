<?php

namespace App\Http\Controllers;

use App\Models\VoiceSample;
use Illuminate\Support\Facades\Http;

class Index extends Controller
{
    public function index()
    {
        $speakers = $this->getSpeakers();

        return inertia('Index', [
            'speakers' => $speakers,
        ]);
    }

    private function getSpeakers(): ?array
    {
        $fromDb = VoiceSample::where('is_active', true)
            ->orderBy('name')
            ->get();

        if ($fromDb->isNotEmpty()) {
            return $fromDb->map(fn (VoiceSample $v) => [
                'name' => $v->name,
                'languages' => $v->languages ?? [],
                'sample_url' => $v->sample_filename
                    ? asset('storage/voice-samples/'.$v->sample_filename)
                    : null,
            ])->values()->all();
        }

        // Fall back to a direct API request, without sample tracks
        try {
            $response = Http::timeout(10)
                ->get('https://api.tartunlp.ai/text-to-speech/v2');

            if (! $response->successful()) {
                return null;
            }

            return collect($response->json('speakers') ?? [])
                ->map(fn (array $s) => [
                    'name' => $s['name'],
                    'languages' => $s['languages'] ?? [],
                    'sample_url' => null,
                ])
                ->values()
                ->all();
        } catch (\Exception) {
            return null;
        }
    }
}
