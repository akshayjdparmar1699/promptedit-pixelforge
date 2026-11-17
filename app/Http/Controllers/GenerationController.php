<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\Credits\InsufficientCreditsException;
use App\Services\Generations\GenerationService;
use App\Services\Pricing\PricingCatalog;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function __construct(
        private readonly GenerationService $generations,
        private readonly PricingCatalog $pricing,
    ) {}

    public function create(Request $request): Response
    {
        return Inertia::render('generations/Create', [
            'sizes' => $this->pricing->sizes(),
            'qualities' => $this->pricing->qualities(),
            'balanceNanos' => $request->user()->balance_nanos,
            'prefill' => [
                'prompt' => $request->string('prompt')->toString() ?: null,
                'size' => $request->string('size')->toString() ?: null,
                'quality' => $request->string('quality')->toString() ?: null,
                // Only a list of strings is accepted; anything else from the query string is dropped.
                'reference_images' => array_values(array_filter(
                    array_slice((array) $request->query('reference_images', []), 0, 4),
                    'is_string',
                )),
            ],
        ]);
    }

    public function estimate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prompt_characters' => ['required', 'integer', 'min:0', 'max:4000'],
            'size' => ['required', Rule::in($this->pricing->sizes())],
            'quality' => ['required', Rule::in($this->pricing->qualities())],
            'reference_images' => ['required', 'integer', 'min:0', 'max:4'],
        ]);

        // Only the length of the prompt matters for the estimate; avoid sending the whole prompt on every keystroke.
        $estimate = $this->generations->estimate(
            str_repeat('x', (int) $data['prompt_characters']),
            $data['size'],
            $data['quality'],
            (int) $data['reference_images'],
        );

        return response()->json($estimate);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in($this->pricing->sizes())],
            'quality' => ['required', Rule::in($this->pricing->qualities())],
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => ['required', 'url:http,https', 'max:2048'],
        ]);

        try {
            $this->generations->create(
                $request->user(),
                $data['prompt'],
                $data['size'],
                $data['quality'],
                array_values($data['reference_images'] ?? []),
            );
        } catch (InsufficientCreditsException $e) {
            return back()->withErrors([
                'credits' => 'You need $'.Money::nanosToDecimal($e->requiredNanos).' available to start this image, '
                    .'but your balance is $'.Money::nanosToDecimal($e->balanceNanos).'. Buy credits to continue.',
            ]);
        }

        return to_route('generations.index');
    }

    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()
            ->latest('id')
            ->paginate(12)
            ->through(fn (Generation $g) => [
                'id' => $g->id,
                'provider_id' => $g->provider_id,
                'prompt' => $g->prompt,
                'size' => $g->size,
                'quality' => $g->quality,
                'reference_images' => $g->reference_images,
                'status' => $g->status->value,
                'status_detail' => $g->status_detail,
                'estimated_charge_nanos' => $g->estimated_charge_nanos,
                'reserved_nanos' => $g->reserved_nanos,
                'charge_nanos' => $g->charge_nanos,
                'usage' => $g->usage,
                'image_url' => $g->image_url,
                'image_url_expires_at' => $g->image_url_expires_at?->toIso8601String(),
                'error_code' => $g->error_code,
                'error_message' => $g->error_message,
                'created_at' => $g->created_at->toIso8601String(),
                'completed_at' => $g->completed_at?->toIso8601String(),
            ]);

        return Inertia::render('generations/Index', [
            'generations' => $generations,
            'balanceNanos' => $request->user()->balance_nanos,
        ]);
    }

    /** Opens the form again with the same settings, so a failed image can be retried in one click. */
    public function retry(Request $request, Generation $generation): RedirectResponse
    {
        abort_unless($generation->user_id === $request->user()->id, 404);

        return to_route('generations.create', [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images,
        ]);
    }
}
