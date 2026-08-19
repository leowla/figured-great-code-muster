<?php

namespace App\Http\Controllers;

use App\Models\StockClass;
use App\Models\StockMovement;
use App\Models\StockRecord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class StockController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'classes' => StockClass::with('movements')->orderBy('id')->get(),
            'records' => StockRecord::orderBy('recorded_on')->orderBy('id')->get(),
        ]);
    }

    /**
     * Reads every raw paper-trail record and the movements already keyed in,
     * and asks Claude to propose the movements still missing - catching
     * duplicate dockets and corrections along the way. Returns suggestions
     * for the adviser to review and accept; nothing is saved here.
     */
    public function suggestMovements(): JsonResponse
    {
        // The full paper trail is a large prompt - Claude can take longer than
        // PHP's default 30s script limit to respond, well within our 120s HTTP timeout.
        set_time_limit(150);

        $apiKey = config('services.anthropic.key');
        if (! $apiKey || str_starts_with($apiKey, 'sk-ant-your-key')) {
            return response()->json([
                'error' => 'No Anthropic API key configured. Set ANTHROPIC_API_KEY in .env (ask a Figgie for the key).',
            ], 500);
        }

        $classes = StockClass::with('movements')->orderBy('id')->get();
        $records = StockRecord::orderBy('recorded_on')->orderBy('id')->get();

        $classLines = $classes->map(fn ($c) => sprintf(
            '- %s: opening %d, farmer-recorded closing %d. Already-keyed movements: %s',
            $c->name,
            $c->opening_count,
            $c->closing_count,
            $c->movements->isEmpty()
                ? 'none'
                : $c->movements->map(fn ($m) => "{$m->type} {$m->quantity} ({$m->note})")->implode('; '),
        ))->implode("\n");

        $recordLines = $records->map(fn ($r) => sprintf(
            '[%d] %s - %s: %s',
            $r->id,
            $r->recorded_on->format('Y-m-d'),
            $r->source,
            $r->body,
        ))->implode("\n");

        $system = <<<SYSTEM
            You are a rural accounting assistant reconciling livestock numbers for a sheep &
            beef farm. Stock year: 1 Jul 2025 to 30 Jun 2026. A stock movement is one of:
            birth, purchase, death, sale. You are given the current stock classes (opening
            count, the farmer's recorded closing count, and any movements already keyed in)
            and the raw paper trail: diary entries, sale dockets, and text messages.

            Your job: read every raw record and propose the stock movements that still need
            to be keyed in, so that opening + births + purchases - deaths - sales lands on
            the recorded closing count for each class.

            Rules:
            - Never repeat a movement that is already in the "Already-keyed movements" list.
            - Some records describe the same real-world event twice (e.g. a docket logged
              twice by mistake) - only produce one movement for it, and note the duplicate
              under "skipped".
            - Some records correct an earlier one (e.g. a follow-up text message correcting a
              headcount) - net them into a single movement using the corrected figure, and
              note under "skipped" which record(s) were superseded and why.
            - Match animals to the right stock class by what they are, not by wording alone -
              e.g. lambs/ewes go to the sheep classes, cows/steers/calves go to Cattle.
            - A kill for the freezer or home consumption is a death, not a sale.
            - Not every record implies a movement (general commentary with no headcount) -
              skip those too, with a reason.
            - Every movement must cite the raw record id(s) it came from.
            - NEVER invent or round up a quantity to make a class's tally hit the recorded
              closing count. Only key what a record actually states. If a record itself says
              the count is provisional or incomplete (e.g. "will know for sure at
              crutching"), key only the confirmed figure - do not guess the rest.
            - After using every record, a class may still not reconcile to its recorded
              closing count. That is a real gap in the paper trail, not a mistake for you to
              paper over - report it honestly in "unresolved" instead of forcing a number.

            Respond with ONLY a JSON object, no markdown fences, no prose outside the JSON:
            {
              "movements": [
                {"stock_class": "Lambs", "type": "sale", "quantity": 210, "note": "short note citing the source", "record_ids": [8], "reasoning": "one short sentence"}
              ],
              "skipped": [
                {"record_ids": [12], "reason": "one short sentence"}
              ],
              "unresolved": [
                {"stock_class": "Ewes", "reason": "one short sentence explaining the remaining gap and why the records don't resolve it"}
              ]
            }
            SYSTEM;

        $prompt = "Stock classes:\n{$classLines}\n\nRaw records:\n{$recordLines}";

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(120)->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-sonnet-4-6',
                'max_tokens' => 4096,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
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

        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)));
        $parsed = json_decode($text, true);

        if (! is_array($parsed) || ! isset($parsed['movements'])) {
            return response()->json(['error' => 'Could not parse a suggestion from the AI response.'], 502);
        }

        $classIds = $classes->pluck('id', 'name');
        $validTypes = ['birth', 'purchase', 'death', 'sale'];

        $suggestions = collect($parsed['movements'])
            ->filter(fn ($m) => is_array($m)
                && isset($m['stock_class'], $m['type'], $m['quantity'])
                && $classIds->has($m['stock_class'])
                && in_array($m['type'], $validTypes, true)
                && is_numeric($m['quantity'])
                && $m['quantity'] > 0)
            ->map(fn ($m) => [
                'stock_class_id' => $classIds[$m['stock_class']],
                'stock_class' => $m['stock_class'],
                'type' => $m['type'],
                'quantity' => (int) $m['quantity'],
                'note' => $m['note'] ?? null,
                'record_ids' => $m['record_ids'] ?? [],
                'reasoning' => $m['reasoning'] ?? null,
            ])
            ->values();

        return response()->json([
            'suggestions' => $suggestions,
            'skipped' => $parsed['skipped'] ?? [],
            'unresolved' => $parsed['unresolved'] ?? [],
        ]);
    }

    public function storeMovement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stock_class_id' => ['required', 'exists:stock_classes,id'],
            'type' => ['required', 'in:birth,purchase,death,sale'],
            'quantity' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string'],
        ]);

        return response()->json(StockMovement::create($validated), 201);
    }

    public function destroyMovement(StockMovement $stockMovement): JsonResponse
    {
        // Mis-keyed a movement? Delete it and key it again.
        $stockMovement->delete();

        return response()->json(['deleted' => true]);
    }
}
