<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The AI proxy. The frontend posts { system?, prompt } here; we call the
 * Anthropic Messages API with the key from .env and return { text }.
 *
 * The key stays on the server. Never call the Anthropic API from browser
 * code, and never put the key anywhere in the frontend.
 */
class AiController extends Controller
{
    /**
     * Original generic AI endpoint (used by AI Example tab)
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'system' => ['nullable', 'string'],
            'prompt' => ['required', 'string'],
        ]);

        $apiKey = config('services.anthropic.key');
        if (! $apiKey || str_starts_with($apiKey, 'sk-ant-your-key')) {
            return response()->json([
                'error' => 'No Anthropic API key configured. Set ANTHROPIC_API_KEY in .env.',
            ], 500);
        }

        $body = [
            'model' => 'claude-sonnet-4-6',
            'max_tokens' => 4096,
            'messages' => [
                ['role' => 'user', 'content' => $validated['prompt']],
            ],
        ];
        if (! empty($validated['system'])) {
            $body['system'] = $validated['system'];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(120)->post('https://api.anthropic.com/v1/messages', $body);
        } catch (ConnectionException $e) {
            return response()->json(['error' => 'Could not reach the Anthropic API: '.$e->getMessage()], 502);
        }

        if ($response->failed()) {
            return response()->json([
                'error' => $response->json('error.message') ?? 'Anthropic API request failed.',
            ], $response->status());
        }

        $text = collect($response->json('content'))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        return response()->json(['text' => $text]);
    }

    /**
     * Stock Reconciliation Paper Trail Parser
     */
    public function parsePaperTrail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'records' => ['required', 'array'],
            'records.*.id' => ['required'],
            'records.*.recorded_on' => ['required', 'string'],
            'records.*.source' => ['required', 'string'],
            'records.*.body' => ['required', 'string'],
        ]);

        // Get actual stock classes from DB to ensure valid IDs
        $stockClasses = \App\Models\StockClass::all()->map(function ($class) {
            return ['id' => $class->id, 'name' => $class->name];
        })->toArray();

        $systemPrompt = <<<PROMPT
You are a livestock reconciliation assistant for NZ farms.
Extract UNRECORDED stock movements from paper trail records.

VALID STOCK CLASSES (use exact ID):
{$this->formatStockClasses($stockClasses)}

VALID MOVEMENT TYPES: birth, purchase, death, sale

CRITICAL RULES:
1. IGNORE all "Sale docket" and "Purchase docket" entries - these are already recorded
2. ONLY extract movements from Diary, Text message, Email sources
3. Map keywords: "sold/sell/cull"->sale, "bought/purchase"->purchase, 
   "born/calved/lambing/dropped"->birth, "lost/died/dead/perished/killed"->death
4. If quantity is ambiguous ("about", "approx", "probably"), set confidence="low"
5. For corrections (e.g., "actually 38 not 40"), use the CORRECTED value
6. Output ONLY valid JSON array. If no unrecorded movements are found, return an empty array []. No markdown, no explanations, no preamble.

JSON FORMAT per item:
[
  {
    "id": <original record id>,
    "stock_class_id": <integer ID from valid classes above>,
    "type": "birth|purchase|death|sale",
    "quantity": <integer>,
    "confidence": "high|low",
    "source_note": "<short citation like 'Text from Kate (30 May)'>",
    "reasoning": "<brief explanation of mapping>"
  }
]
PROMPT;

        $userPrompt = "Paper trail records to parse:\n\n";
        foreach ($validated['records'] as $record) {
            $userPrompt .= "[{$record['recorded_on']}] {$record['source']}: {$record['body']}\n";
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => config('services.anthropic.key'),
                'anthropic-version' => '2023-06-01',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-sonnet-4-6',
                'max_tokens' => 4096,
                'system' => $systemPrompt,
                'messages' => [['role' => 'user', 'content' => $userPrompt]],
            ]);

            if ($response->failed()) {
                throw new \Exception($response->json('error.message') ?? 'API request failed');
            }

            $text = collect($response->json('content'))
                ->where('type', 'text')
                ->pluck('text')
                ->implode('');

            // DEFENSIVE CLEANUP: Strip markdown fences and conversational text
            $cleanedText = trim($text);
            $cleanedText = preg_replace('/^```(?:json)?\s*/mi', '', $cleanedText);
            $cleanedText = preg_replace('/```\s*$/mi', '', $cleanedText);
            $cleanedText = trim($cleanedText);

            // Find the first [ and last ] to isolate the JSON array
            $startPos = strpos($cleanedText, '[');
            $endPos = strrpos($cleanedText, ']');

            if ($startPos !== false && $endPos !== false && $endPos > $startPos) {
                $cleanedText = substr($cleanedText, $startPos, $endPos - $startPos + 1);
            }

            $suggestions = json_decode($cleanedText, true);

            // Capture the JSON error state IMMEDIATELY after decode.
            // Anything that runs after this (including Log::warning(), which
            // internally calls json_encode() via Monolog) will reset PHP's
            // internal json_last_error() flag back to JSON_ERROR_NONE, so we
            // must grab it now or we'll always see "No error" later.
            $jsonError = json_last_error();
            $jsonErrorMsg = json_last_error_msg();

            // If json_decode returned null, check if the raw text was empty or non-JSON
            if ($suggestions === null && $jsonError !== JSON_ERROR_NONE) {
                Log::warning('AI returned malformed JSON', [
                    'raw_text' => $text,
                    'cleaned' => $cleanedText,
                    'json_error' => $jsonErrorMsg,
                ]);
                throw new \Exception('Invalid JSON syntax: '.$jsonErrorMsg);
            }

            // If it decoded to something other than an array (e.g. empty/null text response), fallback safely to an empty array
            if (! is_array($suggestions)) {
                Log::info('AI response did not contain a JSON array, defaulting to empty suggestions', ['raw_text' => $text]);
                $suggestions = [];
            }

            return response()->json(['suggestions' => $suggestions]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'AI parsing failed: '.$e->getMessage(),
                'suggestions' => [],
            ], 500);
        }
    }

    /**
     * Helper to format stock classes for the system prompt
     */
    private function formatStockClasses(array $classes): string
    {
        return collect($classes)
            ->map(fn ($c) => "- ID {$c['id']}: {$c['name']}")
            ->implode("\n");
    }
}