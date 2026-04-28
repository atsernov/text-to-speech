<?php

namespace App\Console\Commands;

use App\Models\VoiceSample;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class RefreshVoiceSamples extends Command
{
    protected $signature = 'voices:refresh';

    protected $description = 'Updates the TartNLP voice list and generates test audio samples';

    /**
     * Short phrase for testing a voice.
     */
    private const TEST_PHRASE = 'Tere! See on minu hääle näidis.';

    public function handle(): int
    {
        $this->info('Requesting the voice list from TartNLP API...');

        // Fetch the current list of voices
        try {
            $listResponse = Http::timeout(30)
                ->get('https://api.tartunlp.ai/text-to-speech/v2');
        } catch (\Exception $e) {
            $this->error('Failed to get the voice list: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $listResponse->successful()) {
            $this->error('API returned an error: '.$listResponse->status());

            return self::FAILURE;
        }

        $speakers = $listResponse->json('speakers') ?? [];

        if (empty($speakers)) {
            $this->error('The voice list is empty — the API structure might have changed.');

            return self::FAILURE;
        }

        $this->info('Voices found: '.count($speakers));

        // Deactivate voices that are no longer present in the API
        $apiNames = collect($speakers)->pluck('name');
        $deactivated = VoiceSample::whereNotIn('name', $apiNames)->update(['is_active' => false]);
        if ($deactivated > 0) {
            $this->warn("Voices deactivated (removed from API): {$deactivated}");
        }

        // Generate a sample track for each voice
        Storage::disk('public')->makeDirectory('voice-samples');

        $bar = $this->output->createProgressBar(count($speakers));
        $bar->start();

        $active = 0;
        $failed = 0;

        foreach ($speakers as $speaker) {
            $name = $speaker['name'];
            $languages = $speaker['languages'] ?? [];

            try {
                $audioResponse = Http::timeout(60)
                    ->post('https://api.tartunlp.ai/text-to-speech/v2', [
                        'text' => self::TEST_PHRASE,
                        'speaker' => $name,
                    ]);

                if ($audioResponse->successful() && strlen($audioResponse->body()) > 100) {
                    // Save the WAV file
                    $filename = 'sample_'.preg_replace('/[^a-z0-9_]/i', '_', $name).'.wav';
                    Storage::disk('public')->put('voice-samples/'.$filename, $audioResponse->body());

                    VoiceSample::updateOrCreate(
                        ['name' => $name],
                        [
                            'languages' => $languages,
                            'sample_filename' => $filename,
                            'is_active' => true,
                            'last_checked_at' => now(),
                        ]
                    );

                    $active++;
                } else {
                    // API returned an error — the voice is non-functional
                    VoiceSample::updateOrCreate(
                        ['name' => $name],
                        [
                            'languages' => $languages,
                            'sample_filename' => null,
                            'is_active' => false,
                            'last_checked_at' => now(),
                        ]
                    );

                    $failed++;
                }
            } catch (\Exception $e) {
                // Timeout or network error
                VoiceSample::updateOrCreate(
                    ['name' => $name],
                    [
                        'languages' => $languages,
                        'sample_filename' => null,
                        'is_active' => false,
                        'last_checked_at' => now(),
                    ]
                );

                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. Working voices: {$active}, inactive (removed from the list): {$failed}.");

        return self::SUCCESS;
    }
}
