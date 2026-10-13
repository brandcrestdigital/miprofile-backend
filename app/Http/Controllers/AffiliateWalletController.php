<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\WalletTransaction;

class AffiliateWalletController extends Controller
{
    public function index(Request $request)
    {
        $affiliate = auth('affiliate')->user();

        if (!$affiliate) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.'
            ], 401);
        }

        $perPage = min(
            (int) $request->get('per_page', 10),
            100
        );

        $type = $request->get('type', 'all');

        $query = WalletTransaction::where(
            'affiliate_id',
            $affiliate->id
        );

        /*
        |--------------------------------------------------------------------------
        | Type Filter
        |--------------------------------------------------------------------------
        */

        if (in_array($type, ['credit', 'debit'])) {
            $query->where('type', $type);
        }

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if ($request->filled('search')) {

            $search = $request->search;

            $query->where(function ($q) use ($search) {

                $q->where(
                    'description',
                    'like',
                    "%{$search}%"
                )

                    ->orWhere(
                        'transaction_no',
                        'like',
                        "%{$search}%"
                    );
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Status Filter
        |--------------------------------------------------------------------------
        */

        if ($request->filled('status')) {

            $query->where(
                'status',
                $request->status
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Transactions
        |--------------------------------------------------------------------------
        */

        $transactions = $query
            ->latest('created_at')
            ->paginate($perPage);

        /*
        |--------------------------------------------------------------------------
        | Format Transactions
        |--------------------------------------------------------------------------
        */

        $transactions->getCollection()->transform(
            function ($transaction) {

                $amount = (float) $transaction->amount;

                return [
                    'id' => $transaction->id,

                    'date' => $transaction->created_at
                        ? $transaction->created_at->format('d M Y')
                        : null,

                    'description' => $transaction->description,

                    'transaction_no' =>
                    $transaction->transaction_no,

                    'type' => $transaction->type,

                    'amount' => round($amount, 2),

                    'formatted_amount' => ($transaction->type === 'credit' ? '+' : '-')
                        . '₹'
                        . number_format($amount, 2),

                    'status' => $transaction->status,
                ];
            }
        );

        return response()->json([
            'success' => true,

            'data' => [

                /*
                |--------------------------------------------------------------------------
                | Wallet Balance
                |--------------------------------------------------------------------------
                */

                'balance' => round(
                    (float) $affiliate->wallet_balance,
                    2
                ),

                'formatted_balance' =>
                '₹' . number_format(
                    (float) $affiliate->wallet_balance,
                    2
                ),

                /*
                |--------------------------------------------------------------------------
                | Transactions
                |--------------------------------------------------------------------------
                */

                'transactions' => $transactions,
            ]
        ]);
    }
}
