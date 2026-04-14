<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UrlTextExtractorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UrlExtractorController extends Controller
{
    /**
     * Принимает URL, извлекает текст со страницы и возвращает его.
     */
    public function extract(Request $request, UrlTextExtractorService $extractor): JsonResponse
    {
        $request->validate([
            'url' => 'required|url|max:2048',
        ]);

        try {
            $text = $extractor->extract($request->input('url'));

            return response()->json([
                'text' => $text,
                'characters' => mb_strlen($text),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
