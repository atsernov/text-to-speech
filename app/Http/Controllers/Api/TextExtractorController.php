<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TextExtractorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TextExtractorController extends Controller
{
    /**
     * Accepts a .txt or .docx file, extracts the text, and returns it.
     * File size is limited at the server level (nginx + php.ini), not here.
     */
    public function extract(Request $request, TextExtractorService $extractor): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:txt,docx',
                'max:512000', // 500MB — matches nginx/php.ini limits
            ],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        try {
            $text = match ($extension) {
                'txt' => $extractor->extractFromTxt($file->getRealPath()),
                'docx' => $extractor->extractFromDocx($file->getRealPath()),
                default => throw new \RuntimeException('Unsupported file format.'),
            };

            return response()->json([
                'text' => $text,
                'characters' => mb_strlen($text),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to read file: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Accepts a PDF file and extracts text from it.
     *
     * Text-based PDFs are extracted instantly and returned as JSON.
     * Scanned PDFs (or PDFs with broken font encoding) use OCR and return
     * a Server-Sent Events stream — one event per page — so the client
     * can display text progressively without hitting a gateway timeout.
     *
     * SSE event formats:
     *   data: {"type":"page",  "page":1, "total":120, "text":"..."}
     *   data: {"type":"done",  "total":120}
     *   data: {"type":"error", "message":"..."}
     *
     * Processing stops automatically if the client disconnects.
     */
    public function extractPdf(Request $request, TextExtractorService $extractor): JsonResponse|StreamedResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:pdf',
                'max:512000', // 500MB — matches nginx/php.ini limits
            ],
        ]);

        $filePath = $request->file('file')->getRealPath();

        try {
            $isScanned = $extractor->isPdfScanned($filePath);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to read file: '.$e->getMessage(),
            ], 422);
        }

        if (! $isScanned) {
            try {
                $text = $extractor->extractFromPdf($filePath);

                return response()->json([
                    'text' => $text,
                    'characters' => mb_strlen($text),
                ]);
            } catch (\Exception $e) {
                return response()->json([
                    'error' => 'Failed to read file: '.$e->getMessage(),
                ], 422);
            }
        }

        return response()->stream(function () use ($filePath, $extractor) {
            try {
                $extractor->streamOcrPdf(
                    $filePath,
                    function (int $page, int $total, string $text) {
                        echo 'data: '.json_encode([
                            'type' => 'page',
                            'page' => $page,
                            'total' => $total,
                            'text' => $text,
                        ])."\n\n";
                        $this->flushOutput();
                    }
                );

                echo 'data: '.json_encode(['type' => 'done'])."\n\n";
                $this->flushOutput();

            } catch (\Exception $e) {
                echo 'data: '.json_encode([
                    'type' => 'error',
                    'message' => $e->getMessage(),
                ])."\n\n";
                $this->flushOutput();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no', // Disable nginx buffering — critical for SSE
        ]);
    }

    /**
     * Flushes output to the client. ob_flush() is only called when an output
     * buffer is actually active — calling it without a buffer throws a warning
     * and interrupts the SSE stream.
     */
    private function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
