<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Fake purchase: adds credits without taking payment. */
class CreditController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'in:5,10,25']]);
        $nano = $data['amount'] * Money::NANO;

        DB::transaction(function () use ($request, $nano) {
            CreditTransaction::create(['user_id' => $request->user()->id, 'type' => 'purchase', 'amount' => $nano]);
            $request->user()->increment('balance', $nano);
        });

        return back();
    }
}
