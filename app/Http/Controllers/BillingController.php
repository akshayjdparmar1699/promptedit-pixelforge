<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Services\Credits\CreditLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('billing/Index', [
            'balanceNanos' => $request->user()->balance_nanos,
            'packs' => config('pixelforge.credit_packs'),
            'transactions' => CreditTransaction::where('user_id', $request->user()->id)
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (CreditTransaction $t) => [
                    'id' => $t->id,
                    'type' => $t->type,
                    'description' => $t->description,
                    'amount_nanos' => $t->amount_nanos,
                    'balance_after_nanos' => $t->balance_after_nanos,
                    'created_at' => $t->created_at->toIso8601String(),
                ]),
        ]);
    }

    public function purchase(Request $request, CreditLedger $ledger): RedirectResponse
    {
        $data = $request->validate([
            'dollars' => ['required', 'integer', Rule::in(config('pixelforge.credit_packs'))],
        ]);

        $ledger->purchase($request->user(), (int) $data['dollars']);

        return back();
    }
}
