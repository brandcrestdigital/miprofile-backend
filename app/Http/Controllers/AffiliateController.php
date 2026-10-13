<?php

namespace App\Http\Controllers;



use App\Models\Affiliate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Str;
use App\Mail\AffiliateRegistrationMail;
use App\Models\AffiliateBankAccount;
use App\Models\AffiliateWithdrawal;
use App\Models\Organization;
use App\Models\OrganizationCardPurchase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;

class AffiliateController extends Controller
{

    /**
     * Summary of register
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'  => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:affiliates,email',
            'phone' => 'required|digits:10|unique:affiliates,phone',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $useRandomPassword = env('AFFILIATE_RANDOM_PASSWORD', false);

        $password = $useRandomPassword
            ? Str::random(10)
            : '123456';

        $affiliate = Affiliate::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'phone'    => $request->phone,
            'password' => Hash::make($password),
            'status'   => 'pending',
        ]);

        Mail::to($affiliate->email)
            ->send(new AffiliateRegistrationMail($affiliate));


        return response()->json([
            'status'  => true,
            'message' => 'Registration successful. Your account is waiting for admin approval.',
            'data'    => [
                'id'     => $affiliate->id,
                'name'   => $affiliate->name,
                'email'  => $affiliate->email,
                'phone'  => $affiliate->phone,
                'status' => $affiliate->status,
            ],
        ], 201);
    }


    /**
     * Login Affitite
     */

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $affiliate = Affiliate::where('email', $request->email)->first();

        if (!$affiliate) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid email or password.',
            ], 401);
        }

        /*
    |--------------------------------------------------------------------------
    | Check Password
    |--------------------------------------------------------------------------
    */

        if (!Hash::check($request->password, $affiliate->password)) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid email or password.',
            ], 401);
        }

        /*
    |--------------------------------------------------------------------------
    | Check Approval Status
    |--------------------------------------------------------------------------
    */

        if ($affiliate->status === 'pending') {
            return response()->json([
                'status'  => false,
                'message' => 'Your account is still under review.',
            ], 403);
        }

        if ($affiliate->status === 'rejected') {
            return response()->json([
                'status' => false,
                'message' => 'Your affiliate application has been rejected.',
                'rejection_remarks' => $affiliate->rejection_remarks,
            ], 403);
        }

        if ($affiliate->status !== 'approved') {
            return response()->json([
                'status'  => false,
                'message' => 'Your account is not approved.',
            ], 403);
        }

        /*
    |--------------------------------------------------------------------------
    | Check Active Status
    |--------------------------------------------------------------------------
    */

        if (!$affiliate->is_active) {
            return response()->json([
                'status'  => false,
                'message' => 'Your affiliate account is inactive.',
            ], 403);
        }

        /*
    |--------------------------------------------------------------------------
    | Generate JWT Token
    |--------------------------------------------------------------------------
    */

        // $token = auth('affiliate')->login($affiliate);
        // $token = JWTAuth::fromUser($affiliate);
        $token = auth('affiliate')->login($affiliate);

        return response()->json([
            'status'  => true,
            'message' => 'Login successful.',
            'token'   => $token,
            'data'    => [
                'id'         => $affiliate->id,
                'name'       => $affiliate->name,
                'email'      => $affiliate->email,
                'phone'      => $affiliate->phone,
                'status'     => $affiliate->status,
                'commission' => $affiliate->commission,
                'type'       => $affiliate->type,
                'is_active'  => $affiliate->is_active,
            ],
        ], 200);
    }


    /**
     * Dashboard
     */

    public function dashboard_w(Request $request)
    {


        $affiliate = auth('affiliate')->user();

        if (!$affiliate) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized.',
            ], 401);
        }


        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        $validator = Validator::make($request->all(), [
            'timezone' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }


        /*
        |--------------------------------------------------------------------------
        | Date Ranges
        |--------------------------------------------------------------------------
        */

        $todayStart = now()->startOfDay();
        $todayEnd   = now()->endOfDay();

        $weekStart = now()->startOfWeek();
        $weekEnd   = now()->endOfWeek();

        $monthStart = now()->startOfMonth();
        $monthEnd   = now()->endOfMonth();


        /*
        |--------------------------------------------------------------------------
        | Organization Registrations - Today
        |--------------------------------------------------------------------------
        */

        $registrationsToday = Organization::where(
            'affiliate_id',
            $affiliate->id
        )
            ->whereBetween('created_at', [
                $todayStart,
                $todayEnd
            ])
            ->count();


        /*
        |--------------------------------------------------------------------------
        | Organization Registrations - This Week
        |--------------------------------------------------------------------------
        */

        $registrationsWeek = Organization::where(
            'affiliate_id',
            $affiliate->id
        )
            ->whereBetween('created_at', [
                $weekStart,
                $weekEnd
            ])
            ->count();


        /*
        |--------------------------------------------------------------------------
        | Organization Registrations - This Month
        |--------------------------------------------------------------------------
        */

        $registrationsMonth = Organization::where(
            'affiliate_id',
            $affiliate->id
        )
            ->whereBetween('created_at', [
                $monthStart,
                $monthEnd
            ])
            ->count();


        /*
        |--------------------------------------------------------------------------
        | Purchases - Today
        |--------------------------------------------------------------------------
        */

        $purchasesToday = OrganizationCardPurchase::whereHas(
            'organization',
            function ($query) use ($affiliate) {

                $query->where(
                    'affiliate_id',
                    $affiliate->id
                );
            }
        )
            ->whereBetween('created_at', [
                $todayStart,
                $todayEnd
            ])
            ->count();


        /*
        |--------------------------------------------------------------------------
        | Purchases - This Week
        |--------------------------------------------------------------------------
        */

        $purchasesWeek = OrganizationCardPurchase::whereHas(
            'organization',
            function ($query) use ($affiliate) {

                $query->where(
                    'affiliate_id',
                    $affiliate->id
                );
            }
        )
            ->whereBetween('created_at', [
                $weekStart,
                $weekEnd
            ])
            ->count();


        /*
        |--------------------------------------------------------------------------
        | Purchases - This Month
        |--------------------------------------------------------------------------
        */

        $purchasesMonth = OrganizationCardPurchase::whereHas(
            'organization',
            function ($query) use ($affiliate) {

                $query->where(
                    'affiliate_id',
                    $affiliate->id
                );
            }
        )
            ->whereBetween('created_at', [
                $monthStart,
                $monthEnd
            ])
            ->count();


        /*
        |--------------------------------------------------------------------------
        | Affiliate Commission Percentage
        |--------------------------------------------------------------------------
        */

        $commissionPercentage = (float) (
            $affiliate->commission ?? 0
        );


        /*
        |--------------------------------------------------------------------------
        | Wallet Balance
        |--------------------------------------------------------------------------
        |
        | There is NO wallet_balance column in affiliates table.
        |
        | Therefore:
        |
        | Wallet Balance =
        | Paid Purchase Amount × Affiliate Commission %
        |
        */

        $paidPurchases = OrganizationCardPurchase::whereHas(
            'organization',
            function ($query) use ($affiliate) {

                $query->where(
                    'affiliate_id',
                    $affiliate->id
                );
            }
        )
            ->where('payment_status', 'paid')
            ->get();


        $walletBalance = $paidPurchases->sum(
            function ($purchase) use ($commissionPercentage) {

                /*
                |--------------------------------------------------------------------------
                | Purchase Amount
                |--------------------------------------------------------------------------
                */

                $totalAmount = (float) (
                    $purchase->grand_total
                    ?? $purchase->total_amount
                    ?? 0
                );


                /*
                |--------------------------------------------------------------------------
                | Commission
                |--------------------------------------------------------------------------
                */

                return (
                    $totalAmount *
                    $commissionPercentage
                ) / 100;
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Referral Link
        |--------------------------------------------------------------------------
        */

        $referralLink = 'https://org.miprofile.in/register?'
            . 'ref=' . rawurlencode(
                $affiliate->affiliate_code
            )
            . '&affiliate=' . rawurlencode(
                $affiliate->name
            );


        /*
        |--------------------------------------------------------------------------
        | Dashboard Response
        |--------------------------------------------------------------------------
        */

        return response()->json([

            'status' => true,

            'message' => 'Affiliate dashboard fetched successfully.',

            'data' => [

                /*
                |--------------------------------------------------------------------------
                | Wallet
                |--------------------------------------------------------------------------
                */

                'wallet_balance' => round(
                    $walletBalance,
                    2
                ),

                'commission_percentage' => $commissionPercentage,


                /*
                |--------------------------------------------------------------------------
                | Referral
                |--------------------------------------------------------------------------
                */

                'referral_link' => $referralLink,


                /*
                |--------------------------------------------------------------------------
                | Registrations
                |--------------------------------------------------------------------------
                */

                'registrations' => [

                    'today' => $registrationsToday,

                    'this_week' => $registrationsWeek,

                    'this_month' => $registrationsMonth,
                ],


                /*
                |--------------------------------------------------------------------------
                | Purchases
                |--------------------------------------------------------------------------
                */

                'purchases' => [

                    'today' => $purchasesToday,

                    'this_week' => $purchasesWeek,

                    'this_month' => $purchasesMonth,
                ],
            ],
        ]);
    }


    public function dashboard(Request $request)
    {
        $affiliate = auth('affiliate')->user();

        if (!$affiliate) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

        $validator = Validator::make($request->all(), [
            'timezone' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        /*
    |--------------------------------------------------------------------------
    | Date Ranges
    |--------------------------------------------------------------------------
    */

        $todayStart = now()->startOfDay();
        $todayEnd   = now()->endOfDay();

        $weekStart = now()->startOfWeek();
        $weekEnd   = now()->endOfWeek();

        $monthStart = now()->startOfMonth();
        $monthEnd   = now()->endOfMonth();

        /*
    |--------------------------------------------------------------------------
    | Organization Registrations - Today
    |--------------------------------------------------------------------------
    */

        $registrationsToday = Organization::where(
            'affiliate_id',
            $affiliate->id
        )
            ->whereBetween('created_at', [
                $todayStart,
                $todayEnd
            ])
            ->count();

        /*
    |--------------------------------------------------------------------------
    | Organization Registrations - This Week
    |--------------------------------------------------------------------------
    */

        $registrationsWeek = Organization::where(
            'affiliate_id',
            $affiliate->id
        )
            ->whereBetween('created_at', [
                $weekStart,
                $weekEnd
            ])
            ->count();

        /*
    |--------------------------------------------------------------------------
    | Organization Registrations - This Month
    |--------------------------------------------------------------------------
    */

        $registrationsMonth = Organization::where(
            'affiliate_id',
            $affiliate->id
        )
            ->whereBetween('created_at', [
                $monthStart,
                $monthEnd
            ])
            ->count();

        /*
    |--------------------------------------------------------------------------
    | Purchases - Today
    |--------------------------------------------------------------------------
    */

        $purchasesToday = OrganizationCardPurchase::whereHas(
            'organization',
            function ($query) use ($affiliate) {
                $query->where(
                    'affiliate_id',
                    $affiliate->id
                );
            }
        )
            ->whereBetween('created_at', [
                $todayStart,
                $todayEnd
            ])
            ->count();

        /*
    |--------------------------------------------------------------------------
    | Purchases - This Week
    |--------------------------------------------------------------------------
    */

        $purchasesWeek = OrganizationCardPurchase::whereHas(
            'organization',
            function ($query) use ($affiliate) {
                $query->where(
                    'affiliate_id',
                    $affiliate->id
                );
            }
        )
            ->whereBetween('created_at', [
                $weekStart,
                $weekEnd
            ])
            ->count();

        /*
    |--------------------------------------------------------------------------
    | Purchases - This Month
    |--------------------------------------------------------------------------
    */

        $purchasesMonth = OrganizationCardPurchase::whereHas(
            'organization',
            function ($query) use ($affiliate) {
                $query->where(
                    'affiliate_id',
                    $affiliate->id
                );
            }
        )
            ->whereBetween('created_at', [
                $monthStart,
                $monthEnd
            ])
            ->count();

        /*
    |--------------------------------------------------------------------------
    | Affiliate Commission Percentage
    |--------------------------------------------------------------------------
    */

        $commissionPercentage = (float) (
            $affiliate->commission ?? 0
        );

        /*
    |--------------------------------------------------------------------------
    | TOTAL COMMISSION
    |--------------------------------------------------------------------------
    |
    | Affiliate:
    | Organization
    |     ↓
    | OrganizationCardPurchase
    |
    */

        $paidPurchases = OrganizationCardPurchase::whereHas(
            'organization',
            function ($query) use ($affiliate) {
                $query->where(
                    'affiliate_id',
                    $affiliate->id
                );
            }
        )
            ->where('payment_status', 'paid')
            ->get();

        $totalCommission = $paidPurchases->sum(
            function ($purchase) use ($commissionPercentage) {

                $totalAmount = (float) (
                    $purchase->grand_total
                    ?? $purchase->total_amount
                    ?? 0
                );

                return (
                    $totalAmount *
                    $commissionPercentage
                ) / 100;
            }
        );

        $totalCommission = round(
            $totalCommission,
            2
        );

        /*
    |--------------------------------------------------------------------------
    | WITHDRAWALS
    |--------------------------------------------------------------------------
    |
    | pending   = money reserved
    | approved  = already paid
    | completed = already paid
    | rejected  = money returned to available balance
    |
    */

        $totalWithdrawals = AffiliateWithdrawal::where(
            'affiliate_id',
            $affiliate->id
        )
            ->whereIn('status', [
                'pending',
                'approved',
                'completed',
            ])
            ->sum('amount');

        $totalWithdrawals = round(
            (float) $totalWithdrawals,
            2
        );

        /*
    |--------------------------------------------------------------------------
    | AVAILABLE BALANCE
    |--------------------------------------------------------------------------
    */

        $availableBalance = max(
            0,
            round(
                $totalCommission - $totalWithdrawals,
                2
            )
        );

        /*
    |--------------------------------------------------------------------------
    | Referral Link
    |--------------------------------------------------------------------------
    */

        $referralLink = 'https://org.miprofile.in/register?'
            . 'ref=' . rawurlencode(
                $affiliate->affiliate_code
            )
            . '&affiliate=' . rawurlencode(
                $affiliate->name
            );

        /*
    |--------------------------------------------------------------------------
    | Dashboard Response
    |--------------------------------------------------------------------------
    */

        return response()->json([

            'status' => true,

            'message' =>
            'Affiliate dashboard fetched successfully.',

            'data' => [

                /*
            |--------------------------------------------------------------------------
            | Wallet
            |--------------------------------------------------------------------------
            */

                'wallet_balance' => $availableBalance,

                'formatted_wallet_balance' =>
                '₹' . number_format(
                    $availableBalance,
                    2
                ),

                /*
            |--------------------------------------------------------------------------
            | Commission
            |--------------------------------------------------------------------------
            */

                'total_commission' =>
                $totalCommission,

                'commission_percentage' =>
                $commissionPercentage,

                /*
            |--------------------------------------------------------------------------
            | Withdrawals
            |--------------------------------------------------------------------------
            */

                'total_withdrawals' =>
                $totalWithdrawals,

                'available_balance' =>
                $availableBalance,

                'formatted_available_balance' =>
                '₹' . number_format(
                    $availableBalance,
                    2
                ),

                /*
            |--------------------------------------------------------------------------
            | Referral
            |--------------------------------------------------------------------------
            */

                'referral_link' =>
                $referralLink,

                /*
            |--------------------------------------------------------------------------
            | Registrations
            |--------------------------------------------------------------------------
            */

                'registrations' => [

                    'today' =>
                    $registrationsToday,

                    'this_week' =>
                    $registrationsWeek,

                    'this_month' =>
                    $registrationsMonth,
                ],

                /*
            |--------------------------------------------------------------------------
            | Purchases
            |--------------------------------------------------------------------------
            */

                'purchases' => [

                    'today' =>
                    $purchasesToday,

                    'this_week' =>
                    $purchasesWeek,

                    'this_month' =>
                    $purchasesMonth,
                ],
            ],
        ]);
    }

    /**
     * List of Orginazation
     */





    public function organizations(Request $request)
    {
        $affiliate = auth('affiliate')->user();

        if (!$affiliate) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        // Pagination
        $perPage = $request->get('per_page', 10);

        $organizations = Organization::where(
            'affiliate_id',
            $affiliate->id
        )
            ->withCount([
                'cardPurchases as total_cards' => function ($query) {
                    $query->select(
                        \DB::raw('COALESCE(SUM(total_cards), 0)')
                    );
                }
            ])
            ->paginate($perPage);

        /*
    |--------------------------------------------------------------------------
    | Map Organization Data
    |--------------------------------------------------------------------------
    */

        $data = $organizations->getCollection()->map(
            function ($organization) use ($affiliate) {

                $purchases = OrganizationCardPurchase::where(
                    'organization_id',
                    $organization->id
                )
                    ->where('payment_status', 'paid')
                    ->get();

                /*
            |--------------------------------------------------------------------------
            | Total Cards
            |--------------------------------------------------------------------------
            */

                $totalCards = $purchases->sum('total_cards');

                /*
            |--------------------------------------------------------------------------
            | Total Purchase Amount
            |--------------------------------------------------------------------------
            */

                $totalAmount = $purchases->sum(
                    function ($purchase) {

                        return (float) (
                            $purchase->grand_total
                            ?? $purchase->total_amount
                            ?? 0
                        );
                    }
                );

                /*
            |--------------------------------------------------------------------------
            | Commission
            |--------------------------------------------------------------------------
            */

                $commissionPercentage =
                    (float) $affiliate->commission;

                $totalEarned =
                    ($totalAmount * $commissionPercentage) / 100;

                return [
                    'id'                    => $organization->id,
                    'name'                  => $organization->name,
                    'email'                 => $organization->email,
                    'phone'                 => $organization->phone,
                    'commission_percentage' => $commissionPercentage,
                    'total_cards'           => $totalCards,
                    'total_earned'          => round(
                        $totalEarned,
                        2
                    ),
                ];
            }
        )->values();

        /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

        return response()->json([
            'status'  => true,
            'message' => 'Affiliate organizations fetched successfully.',

            'data' => $data,

            'meta' => [
                'current_page' => $organizations->currentPage(),
                'per_page'     => $organizations->perPage(),
                'total'        => $organizations->total(),
                'last_page'    => $organizations->lastPage(),
                'from'         => $organizations->firstItem(),
                'to'           => $organizations->lastItem(),
            ],
        ]);
    }


    /**
     * waller
     */

    public function wallet(Request $request)
    {
        /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

        $affiliate = auth('affiliate')->user();

        if (!$affiliate) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized.',
            ], 401);
        }


        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

        $validator = Validator::make($request->all(), [
            'type' => 'nullable|in:all,credit,debit',
            'search' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }


        /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

        $type = strtolower($request->get('type', 'all'));

        $search = trim(
            $request->get('search', '')
        );

        $perPage = (int) $request->get(
            'per_page',
            10
        );


        /*
    |--------------------------------------------------------------------------
    | Commission Percentage
    |--------------------------------------------------------------------------
    */

        $commissionPercentage = (float) (
            $affiliate->commission ?? 0
        );


        /*
    |--------------------------------------------------------------------------
    | Transactions Collection
    |--------------------------------------------------------------------------
    */

        $transactions = collect();


        /*
    |--------------------------------------------------------------------------
    | 1. Organization Registration Commission
    |--------------------------------------------------------------------------
    |
    | Every organization registered through this affiliate
    | becomes a credit transaction.
    |
    */

        $organizations = Organization::where(
            'affiliate_id',
            $affiliate->id
        )
            ->orderBy('created_at', 'desc')
            ->get();


        foreach ($organizations as $organization) {

            /*
        |--------------------------------------------------------------------------
        | Registration Commission
        |--------------------------------------------------------------------------
        |
        | Change this calculation if your registration commission
        | uses a different amount/rate.
        |
        */

            $registrationCommission = 0;

            /*
         * If you have a fixed registration commission,
         * replace the above with that amount.
         *
         * Example:
         *
         * $registrationCommission = 500;
         */


            if ($registrationCommission > 0) {

                $transactions->push([
                    'id' => 'registration_' . $organization->id,

                    'date' => $organization->created_at
                        ? $organization->created_at->format('Y-m-d H:i:s')
                        : null,

                    'description' =>
                    'Commission from Organization Registration',

                    'transaction_no' =>
                    'Registration #' . $organization->id,

                    'type' => 'credit',

                    'amount' => round(
                        $registrationCommission,
                        2
                    ),

                    'status' => 'completed',

                    'reference_type' =>
                    'organization',

                    'reference_id' =>
                    $organization->id,
                ]);
            }
        }


        /*
    |--------------------------------------------------------------------------
    | 2. Purchase Commission
    |--------------------------------------------------------------------------
    */

        $paidPurchases = OrganizationCardPurchase::whereHas(
            'organization',
            function ($query) use ($affiliate) {

                $query->where(
                    'affiliate_id',
                    $affiliate->id
                );
            }
        )
            ->where(
                'payment_status',
                'paid'
            )
            ->orderBy(
                'created_at',
                'desc'
            )
            ->get();


        foreach ($paidPurchases as $purchase) {

            /*
        |--------------------------------------------------------------------------
        | Purchase Amount
        |--------------------------------------------------------------------------
        */

            $totalAmount = (float) (
                $purchase->grand_total
                ?? $purchase->total_amount
                ?? 0
            );


            /*
        |--------------------------------------------------------------------------
        | Commission
        |--------------------------------------------------------------------------
        */

            $commission = (
                $totalAmount *
                $commissionPercentage
            ) / 100;


            $commission = round(
                $commission,
                2
            );


            if ($commission <= 0) {
                continue;
            }


            $transactions->push([
                'id' => 'purchase_' . $purchase->id,

                'date' => $purchase->created_at
                    ? $purchase->created_at->format(
                        'Y-m-d H:i:s'
                    )
                    : null,

                'description' =>
                'Commission from Purchase',

                'transaction_no' =>
                'Purchase #' . $purchase->id,

                'type' => 'credit',

                'amount' => $commission,

                'status' => 'completed',

                'reference_type' =>
                'purchase',

                'reference_id' =>
                $purchase->id,
            ]);
        }


        /*
    |--------------------------------------------------------------------------
    | 3. Wallet Withdrawals
    |--------------------------------------------------------------------------
    |
    | This part depends on your withdrawal table.
    |
    | Example assumes:
    |
    | wallet_withdrawals
    | affiliate_id
    | amount
    | status
    | created_at
    |
    |--------------------------------------------------------------------------
    */

        /*
    |--------------------------------------------------------------------------
    | REMOVE THIS BLOCK IF YOU DON'T HAVE withdrawal table yet
    |--------------------------------------------------------------------------
    */

        if (class_exists(\App\Models\WalletWithdrawal::class)) {

            $withdrawals = \App\Models\WalletWithdrawal::where(
                'affiliate_id',
                $affiliate->id
            )
                ->where(
                    'status',
                    'completed'
                )
                ->orderBy(
                    'created_at',
                    'desc'
                )
                ->get();


            foreach ($withdrawals as $withdrawal) {

                $amount = round(
                    (float) $withdrawal->amount,
                    2
                );


                if ($amount <= 0) {
                    continue;
                }


                $transactions->push([
                    'id' =>
                    'withdrawal_' . $withdrawal->id,

                    'date' =>
                    $withdrawal->created_at
                        ? $withdrawal->created_at->format(
                            'Y-m-d H:i:s'
                        )
                        : null,

                    'description' =>
                    'Wallet Withdrawal',

                    'transaction_no' =>
                    'Withdrawal #' . $withdrawal->id,

                    'type' => 'debit',

                    'amount' => $amount,

                    'status' =>
                    strtolower(
                        $withdrawal->status
                    ),

                    'reference_type' =>
                    'withdrawal',

                    'reference_id' =>
                    $withdrawal->id,
                ]);
            }
        }


        /*
    |--------------------------------------------------------------------------
    | Sort Transactions
    |--------------------------------------------------------------------------
    */

        $transactions = $transactions
            ->sortByDesc('date')
            ->values();


        /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

        if ($search !== '') {

            $searchLower = strtolower(
                $search
            );

            $transactions = $transactions
                ->filter(function ($transaction) use (
                    $searchLower
                ) {

                    return str_contains(
                        strtolower(
                            $transaction['description']
                        ),
                        $searchLower
                    )
                        ||
                        str_contains(
                            strtolower(
                                $transaction['transaction_no']
                            ),
                            $searchLower
                        );
                })
                ->values();
        }


        /*
    |--------------------------------------------------------------------------
    | Type Filter
    |--------------------------------------------------------------------------
    */

        if (
            in_array(
                $type,
                ['credit', 'debit']
            )
        ) {

            $transactions = $transactions
                ->where(
                    'type',
                    $type
                )
                ->values();
        }


        /*
    |--------------------------------------------------------------------------
    | Wallet Balance
    |--------------------------------------------------------------------------
    |
    | Credit - Debit
    |
    */

        $totalCredits = $transactions
            ->where('type', 'credit')
            ->sum('amount');

        $totalDebits = $transactions
            ->where('type', 'debit')
            ->sum('amount');


        $walletBalance = round(
            $totalCredits - $totalDebits,
            2
        );


        /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

        $total = $transactions->count();

        $currentPage = (int) $request->get(
            'page',
            1
        );

        $lastPage = max(
            1,
            (int) ceil(
                $total / $perPage
            )
        );

        $currentPage = min(
            $currentPage,
            $lastPage
        );


        $paginatedTransactions = $transactions
            ->slice(
                ($currentPage - 1) * $perPage,
                $perPage
            )
            ->values();


        /*
    |--------------------------------------------------------------------------
    | Format Amount
    |--------------------------------------------------------------------------
    */

        $paginatedTransactions =
            $paginatedTransactions->map(
                function ($transaction) {

                    $amount = number_format(
                        (float) $transaction['amount'],
                        2,
                        '.',
                        ''
                    );

                    $transaction['formatted_amount'] =
                        $transaction['type'] === 'credit'
                        ? '+₹' . $amount
                        : '-₹' . $amount;

                    $transaction['display_date'] =
                        $transaction['date']
                        ? \Carbon\Carbon::parse(
                            $transaction['date']
                        )->format('d M Y')
                        : null;

                    return $transaction;
                }
            )
            ->values();


        /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

        return response()->json([

            'status' => true,

            'message' =>
            'Affiliate wallet fetched successfully.',

            'data' => [

                /*
            |--------------------------------------------------------------------------
            | Wallet
            |--------------------------------------------------------------------------
            */

                'wallet_balance' =>
                $walletBalance,

                'formatted_wallet_balance' =>
                '₹' . number_format(
                    $walletBalance,
                    2
                ),

                'commission_percentage' =>
                $commissionPercentage,

                /*
            |--------------------------------------------------------------------------
            | Summary
            |--------------------------------------------------------------------------
            */

                'summary' => [

                    'total_credits' =>
                    round(
                        $totalCredits,
                        2
                    ),

                    'total_debits' =>
                    round(
                        $totalDebits,
                        2
                    ),

                    'transaction_count' =>
                    $total,
                ],

                /*
            |--------------------------------------------------------------------------
            | Transactions
            |--------------------------------------------------------------------------
            */

                'transactions' => [

                    'current_page' =>
                    $currentPage,

                    'per_page' =>
                    $perPage,

                    'total' =>
                    $total,

                    'last_page' =>
                    $lastPage,

                    'data' =>
                    $paginatedTransactions,
                ],
            ],
        ]);
    }




    public function transactionsssss(Request $request)
    {
        /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

        $affiliate = auth('affiliate')->user();

        if (!$affiliate) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized.',
            ], 401);
        }


        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

        $validator = Validator::make($request->all(), [
            'type' => 'nullable|in:income,withdrawals',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }


        /*
    |--------------------------------------------------------------------------
    | Commission Percentage
    |--------------------------------------------------------------------------
    */

        $commissionPercentage = (float) (
            $affiliate->commission ?? 0
        );


        /*
    |--------------------------------------------------------------------------
    | Income
    |--------------------------------------------------------------------------
    */

        $purchases = OrganizationCardPurchase::with([
            'organization'
        ])
            ->whereHas(
                'organization',
                function ($query) use ($affiliate) {

                    $query->where(
                        'affiliate_id',
                        $affiliate->id
                    );
                }
            )
            ->where(
                'payment_status',
                'paid'
            )
            ->latest('created_at')
            ->get();


        /*
    |--------------------------------------------------------------------------
    | Convert Purchases To Income Transactions
    |--------------------------------------------------------------------------
    */

        $incomeTransactions = $purchases->map(
            function ($purchase) use (
                $commissionPercentage
            ) {

                /*
            |--------------------------------------------------------------------------
            | Purchase Amount
            |--------------------------------------------------------------------------
            */

                $purchaseAmount = (float) (
                    $purchase->grand_total
                    ?? $purchase->total_amount
                    ?? 0
                );


                /*
            |--------------------------------------------------------------------------
            | Commission
            |--------------------------------------------------------------------------
            */

                $commission = (
                    $purchaseAmount *
                    $commissionPercentage
                ) / 100;


                $commission = round(
                    $commission,
                    2
                );


                return [

                    'id' => $purchase->id,

                    'date' => $purchase->created_at
                        ? $purchase->created_at->format(
                            'Y-m-d'
                        )
                        : null,

                    'display_date' => $purchase->created_at
                        ? $purchase->created_at->format(
                            'd/m/Y'
                        )
                        : null,

                    'organization' =>
                    $purchase->organization
                        ? $purchase->organization->name
                        : null,

                    'cards' => (int) (
                        $purchase->total_cards ?? 0
                    ),

                    'purchase_amount' => round(
                        $purchaseAmount,
                        2
                    ),

                    'formatted_purchase_amount' =>
                    '₹' . number_format(
                        $purchaseAmount,
                        2
                    ),

                    'commission' => $commission,

                    'formatted_commission' =>
                    '+ ₹' . number_format(
                        $commission,
                        2
                    ),

                    'status' => 'credited',

                    'payment_status' =>
                    $purchase->payment_status,

                    'reference_id' =>
                    $purchase->id,
                ];
            }
        )
            ->values();


        /*
    |--------------------------------------------------------------------------
    | Calculate Available Balance
    |--------------------------------------------------------------------------
    */

        $totalIncome = round(
            $incomeTransactions->sum(
                'commission'
            ),
            2
        );


        /*
    |--------------------------------------------------------------------------
    | Withdrawal Amount
    |--------------------------------------------------------------------------
    |
    | Add your withdrawal model/table here when available.
    |
    */

        $totalWithdrawals = 0;


        /*
    |--------------------------------------------------------------------------
    | Available Balance
    |--------------------------------------------------------------------------
    */

        $availableBalance = round(
            $totalIncome - $totalWithdrawals,
            2
        );


        /*
    |--------------------------------------------------------------------------
    | Type
    |--------------------------------------------------------------------------
    */

        $type = $request->get(
            'type',
            'income'
        );


        /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

        $perPage = (int) $request->get(
            'per_page',
            10
        );

        $page = (int) $request->get(
            'page',
            1
        );


        /*
    |--------------------------------------------------------------------------
    | Income Response
    |--------------------------------------------------------------------------
    */

        if ($type === 'income') {

            $total = $incomeTransactions->count();

            $lastPage = max(
                1,
                (int) ceil(
                    $total / $perPage
                )
            );

            $page = min(
                $page,
                $lastPage
            );

            $data = $incomeTransactions
                ->slice(
                    ($page - 1) * $perPage,
                    $perPage
                )
                ->values();


            return response()->json([

                'status' => true,

                'message' =>
                'Affiliate transactions fetched successfully.',

                'data' => [

                    /*
                |--------------------------------------------------------------------------
                | Balance
                |--------------------------------------------------------------------------
                */

                    'available_balance' =>
                    $availableBalance,

                    'formatted_available_balance' =>
                    '₹' . number_format(
                        $availableBalance,
                        2
                    ),

                    'minimum_withdrawal' => 1000,

                    'commission_percentage' =>
                    $commissionPercentage,

                    /*
                |--------------------------------------------------------------------------
                | Income
                |--------------------------------------------------------------------------
                */

                    'income' => [

                        'current_page' => $page,

                        'per_page' => $perPage,

                        'total' => $total,

                        'last_page' => $lastPage,

                        'data' => $data,
                    ],
                ],
            ]);
        }


        /*
    |--------------------------------------------------------------------------
    | Withdrawals
    |--------------------------------------------------------------------------
    */

        return response()->json([

            'status' => true,

            'message' =>
            'Withdrawal requests fetched successfully.',

            'data' => [

                'available_balance' =>
                $availableBalance,

                'formatted_available_balance' =>
                '₹' . number_format(
                    $availableBalance,
                    2
                ),

                'minimum_withdrawal' => 1000,

                'withdrawals' => [
                    'current_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                    'last_page' => 1,
                    'data' => [],
                ],
            ],
        ]);
    }

    public function transactions(Request $request)
    {
        /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

        $affiliate = auth('affiliate')->user();

        if (!$affiliate) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized.',
            ], 401);
        }


        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

        $validator = Validator::make($request->all(), [
            'type' => 'nullable|in:income,withdrawals,all',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }


        /*
    |--------------------------------------------------------------------------
    | Commission Percentage
    |--------------------------------------------------------------------------
    */

        $commissionPercentage = (float) (
            $affiliate->commission ?? 0
        );


        /*
    |--------------------------------------------------------------------------
    | Get Paid Purchases
    |--------------------------------------------------------------------------
    */

        $purchases = OrganizationCardPurchase::with([
            'organization'
        ])
            ->whereHas(
                'organization',
                function ($query) use ($affiliate) {

                    $query->where(
                        'affiliate_id',
                        $affiliate->id
                    );
                }
            )
            ->where(
                'payment_status',
                'paid'
            )
            ->latest('created_at')
            ->get();


        /*
    |--------------------------------------------------------------------------
    | Commission Transactions
    |--------------------------------------------------------------------------
    */

        $incomeTransactions = $purchases
            ->map(function ($purchase) use ($commissionPercentage) {

                $purchaseAmount = (float) (
                    $purchase->grand_total
                    ?? $purchase->total_amount
                    ?? 0
                );


                $commission = (
                    $purchaseAmount *
                    $commissionPercentage
                ) / 100;

                $commission = round(
                    $commission,
                    2
                );


                return [

                    'id' => 'income_' . $purchase->id,

                    'date' => $purchase->created_at
                        ? $purchase->created_at->format('Y-m-d')
                        : null,

                    'display_date' => $purchase->created_at
                        ? $purchase->created_at->format('d/m/Y')
                        : null,

                    'type' => 'income',

                    'transaction_type' => 'commission',

                    'organization' =>
                    $purchase->organization
                        ? $purchase->organization->name
                        : null,

                    'cards' => (int) (
                        $purchase->total_cards ?? 0
                    ),

                    'purchase_amount' => round(
                        $purchaseAmount,
                        2
                    ),

                    'formatted_purchase_amount' =>
                    '₹' . number_format(
                        $purchaseAmount,
                        2
                    ),

                    'amount' => $commission,

                    'formatted_amount' =>
                    '+ ₹' . number_format(
                        $commission,
                        2
                    ),

                    'commission' => $commission,

                    'formatted_commission' =>
                    '+ ₹' . number_format(
                        $commission,
                        2
                    ),

                    'status' => 'credited',

                    'payment_status' =>
                    $purchase->payment_status,

                    'processed_date' => null,

                    'remark' => null,

                    'reference_id' =>
                    $purchase->id,
                ];
            })
            ->values();


        /*
    |--------------------------------------------------------------------------
    | Get Withdrawal Records
    |--------------------------------------------------------------------------
    */

        $withdrawals = AffiliateWithdrawal::where(
            'affiliate_id',
            $affiliate->id
        )
            ->latest('created_at')
            ->get();


        /*
    |--------------------------------------------------------------------------
    | Withdrawal Transactions
    |--------------------------------------------------------------------------
    */

        $withdrawalTransactions = $withdrawals
            ->map(function ($withdrawal) {

                return [

                    'id' =>
                    'withdrawal_' . $withdrawal->id,

                    'date' => $withdrawal->created_at
                        ? $withdrawal->created_at->format('Y-m-d')
                        : null,

                    'display_date' => $withdrawal->created_at
                        ? $withdrawal->created_at->format('d/m/Y')
                        : null,

                    'type' => 'withdrawal',

                    'transaction_type' => 'withdrawal',

                    'organization' => null,

                    'cards' => null,

                    'purchase_amount' => null,

                    'formatted_purchase_amount' => null,

                    'amount' => (float) $withdrawal->amount,

                    'formatted_amount' =>
                    '- ₹' . number_format(
                        (float) $withdrawal->amount,
                        2
                    ),

                    'commission' => null,

                    'formatted_commission' => null,

                    'status' =>
                    $withdrawal->status,

                    'payment_status' => null,

                    'processed_date' =>
                    $withdrawal->processed_at
                        ? $withdrawal->processed_at->format('d/m/Y')
                        : null,

                    'remark' =>
                    $withdrawal->remark,

                    'reference_id' =>
                    $withdrawal->id,
                ];
            })
            ->values();


        /*
    |--------------------------------------------------------------------------
    | Total Income
    |--------------------------------------------------------------------------
    */

        $totalIncome = round(
            $incomeTransactions->sum('commission'),
            2
        );


        /*
    |--------------------------------------------------------------------------
    | Total Withdrawals
    |--------------------------------------------------------------------------
    |
    | Pending / Approved / Completed amounts are reserved.
    |
    | Rejected amounts are NOT deducted.
    |
    |--------------------------------------------------------------------------
    */

        $totalWithdrawals = AffiliateWithdrawal::where(
            'affiliate_id',
            $affiliate->id
        )
            ->whereIn('status', [
                'pending',
                'approved',
                'completed',
            ])
            ->sum('amount');


        $totalWithdrawals = round(
            (float) $totalWithdrawals,
            2
        );


        /*
    |--------------------------------------------------------------------------
    | Available Balance
    |--------------------------------------------------------------------------
    */

        $availableBalance = round(
            $totalIncome - $totalWithdrawals,
            2
        );


        if ($availableBalance < 0) {
            $availableBalance = 0;
        }


        /*
    |--------------------------------------------------------------------------
    | Combine Income + Withdrawal Transactions
    |--------------------------------------------------------------------------
    */

        $allTransactions = $incomeTransactions
            ->concat($withdrawalTransactions)
            ->sortByDesc(function ($transaction) {

                return $transaction['date'] ?? '';
            })
            ->values();


        /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

        $perPage = (int) $request->get(
            'per_page',
            10
        );

        $page = (int) $request->get(
            'page',
            1
        );


        $totalTransactions = $allTransactions->count();

        $lastPage = max(
            1,
            (int) ceil(
                $totalTransactions / $perPage
            )
        );


        $page = min(
            $page,
            $lastPage
        );


        $transactionData = $allTransactions
            ->slice(
                ($page - 1) * $perPage,
                $perPage
            )
            ->values();


        /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

        return response()->json([

            'status' => true,

            'message' =>
            'Affiliate transactions fetched successfully.',

            'data' => [

                /*
            |--------------------------------------------------------------------------
            | Balance
            |--------------------------------------------------------------------------
            */

                'available_balance' =>
                $availableBalance,

                'formatted_available_balance' =>
                '₹' . number_format(
                    $availableBalance,
                    2
                ),

                'minimum_withdrawal' => 5,

                'commission_percentage' =>
                $commissionPercentage,


                /*
            |--------------------------------------------------------------------------
            | Combined Transactions
            |--------------------------------------------------------------------------
            */

                'transactions' => [

                    'current_page' =>
                    $page,

                    'per_page' =>
                    $perPage,

                    'total' =>
                    $totalTransactions,

                    'last_page' =>
                    $lastPage,

                    'data' =>
                    $transactionData,
                ],


                /*
            |--------------------------------------------------------------------------
            | Optional Separate Lists
            |--------------------------------------------------------------------------
            |
            | Keep these if your existing frontend still uses them.
            |
            |--------------------------------------------------------------------------
            */

                'income' => [

                    'current_page' => 1,

                    'per_page' => $incomeTransactions->count(),

                    'total' => $incomeTransactions->count(),

                    'last_page' => 1,

                    'data' => $incomeTransactions,
                ],

                'withdrawals' => [

                    'current_page' => 1,

                    'per_page' => $withdrawalTransactions->count(),

                    'total' => $withdrawalTransactions->count(),

                    'last_page' => 1,

                    'data' => $withdrawalTransactions,
                ],
            ],
        ]);
    }


    public function withdrawRequest(Request $request)
    {
        $affiliate = auth('affiliate')->user();

        if (!$affiliate) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // ------------------------------------------------------------
        // VALIDATION
        // ------------------------------------------------------------

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:5',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $amount = (float) $request->amount;

        // ------------------------------------------------------------
        // COMMISSION PERCENTAGE
        // ------------------------------------------------------------

        $commissionPercentage = (float) (
            $affiliate->commission ?? 0
        );

        // ------------------------------------------------------------
        // GET PAID PURCHASES
        // ------------------------------------------------------------

        $purchases = OrganizationCardPurchase::whereHas(
            'organization',
            function ($query) use ($affiliate) {
                $query->where(
                    'affiliate_id',
                    $affiliate->id
                );
            }
        )
            ->where('payment_status', 'paid')
            ->get();

        // ------------------------------------------------------------
        // CALCULATE TOTAL INCOME
        // ------------------------------------------------------------

        $totalIncome = 0;

        foreach ($purchases as $purchase) {

            $purchaseAmount = (float) (
                $purchase->grand_total
                ?? $purchase->total_amount
                ?? 0
            );

            $commission = (
                $purchaseAmount *
                $commissionPercentage
            ) / 100;

            $totalIncome += round($commission, 2);
        }

        $totalIncome = round($totalIncome, 2);

        // ------------------------------------------------------------
        // GET TOTAL WITHDRAWALS
        // ------------------------------------------------------------

        $totalWithdrawals = AffiliateWithdrawal::where(
            'affiliate_id',
            $affiliate->id
        )
            ->whereIn('status', [
                'pending',
                'approved',
                'completed',
            ])
            ->sum('amount');

        $totalWithdrawals = round(
            (float) $totalWithdrawals,
            2
        );

        // ------------------------------------------------------------
        // AVAILABLE BALANCE
        // ------------------------------------------------------------

        $walletBalance = round(
            $totalIncome - $totalWithdrawals,
            2
        );

        // ------------------------------------------------------------
        // CHECK BALANCE
        // ------------------------------------------------------------

        if ($amount > $walletBalance) {
            return response()->json([
                'status' => false,
                'message' => 'Insufficient available balance.',
                'data' => [
                    'requested_amount' => $amount,
                    'available_balance' => $walletBalance,
                ],
            ], 422);
        }

        // ------------------------------------------------------------
        // CREATE WITHDRAWAL
        // ------------------------------------------------------------

        DB::beginTransaction();

        try {

            $withdrawal = AffiliateWithdrawal::create([
                'affiliate_id' => $affiliate->id,
                'amount'       => $amount,
                'status'       => 'pending',
            ]);

            DB::commit();

            // New available balance after this request
            $remainingBalance = round(
                $walletBalance - $amount,
                2
            );

            return response()->json([
                'status' => true,
                'message' => 'Withdrawal request submitted successfully.',
                'data' => [
                    'id' => $withdrawal->id,
                    'amount' => $amount,
                    'status' => $withdrawal->status,
                    'remaining_balance' => $remainingBalance,
                ],
            ], 201);
        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to submit withdrawal request.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    public function updateProfile(Request $request)
    {
        $affiliate = auth('affiliate')->user();

        if (!$affiliate) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }


        /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

        $validator = Validator::make($request->all(), [

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'currentPassword' => [
                'nullable',
                'string',
            ],

            'newPassword' => [
                'nullable',
                'string',
                'min:6',
            ],

            'confirmPassword' => [
                'nullable',
                'same:newPassword',
            ],

        ]);


        if ($validator->fails()) {

            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }


        /*
    |--------------------------------------------------------------------------
    | Update Name
    |--------------------------------------------------------------------------
    */

        $affiliate->name = trim(
            $request->name
        );


        /*
    |--------------------------------------------------------------------------
    | Password Change
    |--------------------------------------------------------------------------
    */

        if ($request->filled('newPassword')) {

            /*
        |--------------------------------------------------------------------------
        | Current Password Required
        |--------------------------------------------------------------------------
        */

            if (!$request->filled('currentPassword')) {

                return response()->json([
                    'status' => false,
                    'message' =>
                    'Current password is required.',
                ], 422);
            }


            /*
        |--------------------------------------------------------------------------
        | Verify Current Password
        |--------------------------------------------------------------------------
        */

            if (!Hash::check(
                $request->currentPassword,
                $affiliate->password
            )) {

                return response()->json([
                    'status' => false,
                    'message' =>
                    'Current password is incorrect.',
                ], 422);
            }


            /*
        |--------------------------------------------------------------------------
        | Confirm Password
        |--------------------------------------------------------------------------
        */

            if (
                $request->newPassword !==
                $request->confirmPassword
            ) {

                return response()->json([
                    'status' => false,
                    'message' =>
                    'New password and confirm password do not match.',
                ], 422);
            }


            /*
        |--------------------------------------------------------------------------
        | Save New Password
        |--------------------------------------------------------------------------
        */

            $affiliate->password = Hash::make(
                $request->newPassword
            );
        }


        /*
    |--------------------------------------------------------------------------
    | IMPORTANT
    |--------------------------------------------------------------------------
    |
    | Email and phone are intentionally NOT updated.
    |
    |--------------------------------------------------------------------------
    */


        $affiliate->save();


        /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

        return response()->json([

            'status' => true,

            'message' =>
            'Profile updated successfully.',

            'data' => [

                'id' =>
                $affiliate->id,

                'name' =>
                $affiliate->name,

                'email' =>
                $affiliate->email,

                'phone' =>
                $affiliate->phone,
            ],

        ]);
    }



    /**
     * Adding Bank Details for Affiliate
     */


    public function submitBankDetails(Request $request)
    {
        $affiliate = auth('affiliate')->user();

        if (!$affiliate) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'account_holder_name' => 'required|string|max:255',
            'account_number'      => 'required|string|max:50',
            'ifsc_code'           => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'
            ],
            'bank_name'           => 'required|string|max:255',
            'branch_name'         => 'nullable|string|max:255',
            'upi_id'              => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        /*
    |--------------------------------------------------------------------------
    | Check existing bank account
    |--------------------------------------------------------------------------
    */

        $bankAccount = AffiliateBankAccount::where(
            'affiliate_id',
            $affiliate->id
        )->first();

        /*
    |--------------------------------------------------------------------------
    | Don't allow editing while pending
    |--------------------------------------------------------------------------
    */

        if (
            $bankAccount &&
            $bankAccount->status === 'pending'
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Your bank details are already submitted and waiting for approval.',
                'data' => $bankAccount,
            ], 422);
        }

        /*
    |--------------------------------------------------------------------------
    | Save / Resubmit
    |--------------------------------------------------------------------------
    */

        if (!$bankAccount) {

            $bankAccount = new AffiliateBankAccount();

            $bankAccount->affiliate_id = $affiliate->id;
        }

        $bankAccount->account_holder_name =
            trim($request->account_holder_name);

        $bankAccount->account_number =
            trim($request->account_number);

        $bankAccount->ifsc_code =
            strtoupper(trim($request->ifsc_code));

        $bankAccount->bank_name =
            trim($request->bank_name);

        $bankAccount->branch_name =
            $request->branch_name
            ? trim($request->branch_name)
            : null;

        $bankAccount->upi_id =
            $request->upi_id
            ? trim($request->upi_id)
            : null;

        /*
    |--------------------------------------------------------------------------
    | New submission
    |--------------------------------------------------------------------------
    */

        $bankAccount->status = 'pending';
        $bankAccount->admin_remark = null;
        $bankAccount->submitted_at = now();
        $bankAccount->approved_at = null;

        $bankAccount->save();

        return response()->json([
            'status' => true,
            'message' => 'Bank details submitted successfully for approval.',
            'data' => [
                'id' => $bankAccount->id,
                'account_holder_name' =>
                $bankAccount->account_holder_name,
                'account_number' =>
                $bankAccount->account_number,
                'ifsc_code' =>
                $bankAccount->ifsc_code,
                'bank_name' =>
                $bankAccount->bank_name,
                'branch_name' =>
                $bankAccount->branch_name,
                'upi_id' =>
                $bankAccount->upi_id,
                'status' =>
                $bankAccount->status,
                'admin_remark' =>
                $bankAccount->admin_remark,
                'submitted_at' =>
                $bankAccount->submitted_at,
            ],
        ], 201);
    }


    /**
     * Get Bank Details for Affiliate
     */

    public function getBankDetails()
    {
        $affiliate = auth('affiliate')->user();

        if (!$affiliate) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $bankAccount = AffiliateBankAccount::where(
            'affiliate_id',
            $affiliate->id
        )->first();

        return response()->json([
            'status' => true,
            'data' => $bankAccount,
        ]);
    }
}
