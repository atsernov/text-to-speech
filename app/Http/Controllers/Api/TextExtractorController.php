<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TextExtractorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TextExtractorController extends Controller
{
    /**
     * Accepts a .txt or .docx file, extracts the text, and returns it.
     * Maximum file size: 10MB.
     */
    public function extract(Request $request, TextExtractorService $extractor): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:txt,docx',
                'max:10240',
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
}
