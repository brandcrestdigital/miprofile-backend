<?php

namespace App\Http\Controllers;



use App\Models\Affiliate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Mail\AffiliateApprovedMail;
use App\Mail\AffiliateRejectedMail;
use App\Mail\EmployerCredentialMail;
use App\Models\AffiliateBankAccount;
use App\Models\AffiliateWithdrawal;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\OrganizationCardPurchase;
use App\Models\User;
use App\Services\Messenger360Service;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class AdminAffiliateController extends Controller
{
    /**
     * List Affiliates
     */
    public function index(Request $request)
    {
        $query = Affiliate::query();

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if ($request->filled('search')) {

            $search = $request->search;

            $query->where(function ($q) use ($search) {

                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Status Filter
        |--------------------------------------------------------------------------
        */

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $perPage = $request->get('per_page', 10);

        $affiliates = $query
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'status'  => true,
            'message' => 'Affiliates fetched successfully.',
            'data'    => $affiliates->items(),
            'pagination' => [
                'current_page' => $affiliates->currentPage(),
                'last_page'    => $affiliates->lastPage(),
                'per_page'     => $affiliates->perPage(),
                'total'        => $affiliates->total(),
            ],
        ]);
    }


    /**
     * Show Affiliate
     */
    public function show($id)
    {
        $affiliate = Affiliate::find($id);

        if (!$affiliate) {
            return response()->json([
                'status'  => false,
                'message' => 'Affiliate not found.',
            ], 404);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Affiliate details fetched successfully.',
            'data'    => $affiliate,
        ]);
    }


    /**
     * Approve Affiliate
     */
    public function approve(Request $request, $id)
    {

        // dd($request->all());
        $validator = Validator::make($request->all(), [
            'commission' => 'required|numeric|min:0|max:100',
            'type'       => 'required|in:lifetime,onetime',
        ]);


        // dd($request->all());

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $affiliate = Affiliate::find($id);

        if (!$affiliate) {
            return response()->json([
                'status'  => false,
                'message' => 'Affiliate not found.',
            ], 404);
        }

        if ($affiliate->status === 'approved') {
            return response()->json([
                'status'  => false,
                'message' => 'Affiliate is already approved.',
            ], 400);
        }

        /*
    |--------------------------------------------------------------------------
    | Generate Password
    |--------------------------------------------------------------------------
    */

        $useRandomPassword = env('AFFILIATE_RANDOM_PASSWORD', false);

        $password = $useRandomPassword
            ? Str::random(10)
            : '123456';

        /*
    |--------------------------------------------------------------------------
    | Approve Affiliate
    |--------------------------------------------------------------------------
    */

        $affiliateCode = 'AFF' . str_pad(
            $affiliate->id,
            3,
            '0',
            STR_PAD_LEFT
        );

        $affiliate->update([
            'status'            => 'approved',
            'affiliate_code'     => $affiliateCode,
            'commission'        => $request->commission,
            'type'              => $request->type,
            'approved_at'       => now(),
            'approved_by'       => auth()->id(),
            'rejection_remarks' => null,
            'password'          => Hash::make($password),
            'is_active'         => true,
        ]);

        $referralUrl = 'https://org.miprofile.in/register?' . http_build_query([
            'ref'       => $affiliate->affiliate_code,
            // 'affiliate' => $affiliate->name,
        ]);

        /*
    |--------------------------------------------------------------------------
    | Send Approval Email
    |--------------------------------------------------------------------------
    */

        // if(env('APP_ENV') === 'production') {
        //     Mail::to($affiliate->email)->send(
        //         new AffiliateApprovedMail(
        //             $affiliate,
        //             $password,
        //             $referralUrl
        //         )
        //     );
        // }

        Mail::to($affiliate->email)->send(
            new AffiliateApprovedMail(
                $affiliate,
                $password,
                $referralUrl
            )
        );

        return response()->json([
            'status'  => true,
            'message' => 'Affiliate approved successfully.',
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
     * Reject Affiliate
     */
    public function reject(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'rejection_remarks' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $affiliate = Affiliate::find($id);

        if (!$affiliate) {
            return response()->json([
                'status'  => false,
                'message' => 'Affiliate not found.',
            ], 404);
        }

        $affiliate->update([
            'status'            => 'rejected',
            'rejection_remarks' => $request->rejection_remarks,
            'approved_at'       => null,
            'approved_by'       => null,
        ]);

        Mail::to($affiliate->email)->send(
            new AffiliateRejectedMail(
                $affiliate,
                $request->rejection_remarks
            )
        );

        return response()->json([
            'status'  => true,
            'message' => 'Affiliate rejected successfully.',
            'data'    => [
                'id'                => $affiliate->id,
                'name'              => $affiliate->name,
                'status'            => $affiliate->status,
                'rejection_remarks' => $affiliate->rejection_remarks,
            ],
        ]);
    }


    /**
     * Update Commission / Type
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'commission' => 'required|numeric|min:0|max:100',
            'type'       => 'required|in:onetime,lifetime',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $affiliate = Affiliate::find($id);

        if (!$affiliate) {
            return response()->json([
                'status'  => false,
                'message' => 'Affiliate not found.',
            ], 404);
        }

        $affiliate->update([
            'commission' => $request->commission,
            'type'       => $request->type,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Affiliate updated successfully.',
            'data'    => $affiliate,
        ]);
    }


    /**
     * Activate / Deactivate Affiliate
     */
    public function toggleStatus($id)
    {
        $affiliate = Affiliate::find($id);

        if (!$affiliate) {
            return response()->json([
                'status'  => false,
                'message' => 'Affiliate not found.',
            ], 404);
        }

        $affiliate->is_active = !$affiliate->is_active;

        $affiliate->save();

        return response()->json([
            'status'  => true,
            'message' => $affiliate->is_active
                ? 'Affiliate activated successfully.'
                : 'Affiliate deactivated successfully.',
            'data' => [
                'id'        => $affiliate->id,
                'is_active' => $affiliate->is_active,
            ],
        ]);
    }


    /**
     * View Rejection Remark
     */
    public function remark($id)
    {
        $affiliate = Affiliate::find($id);

        if (!$affiliate) {
            return response()->json([
                'status'  => false,
                'message' => 'Affiliate not found.',
            ], 404);
        }

        return response()->json([
            'status'  => true,
            'message' => 'Rejection remark fetched successfully.',
            'data'    => [
                'id'                => $affiliate->id,
                'status'            => $affiliate->status,
                'rejection_remarks' => $affiliate->rejection_remarks,
            ],
        ]);
    }


    /**
     * Add Orginazation
     */

    public function store(Request $request, Messenger360Service $whatsapp)
    {
        $validator = Validator::make($request->all(), [
            'organization_name' => 'required|string|max:255',
            'email'             => 'required|email',
            'phone'             => 'required|string|max:15',
            'ref'               => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error'   => $validator->errors(),
            ], 422);
        }

        // Check duplicate email
        if (User::where('email', $request->email)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Email already registered',
            ], 422);
        }

        // Find affiliate from referral code
        $affiliate = null;

        if ($request->filled('ref')) {

            $affiliate = Affiliate::where('affiliate_code', $request->ref)
                ->where('status', 'approved')
                ->where('is_active', true)
                ->first();

            if (!$affiliate) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or inactive affiliate referral code.',
                ], 422);
            }
        }

        DB::beginTransaction();

        try {

            /*
        |--------------------------------------------------------------------------
        | Create Organization
        |--------------------------------------------------------------------------
        */

            $organization = Organization::create([
                'name'         => $request->organization_name,
                'email'        => $request->email,
                'phone'        => $request->phone,
                'affiliate_id' => $affiliate?->id,
            ]);


            /*
        |--------------------------------------------------------------------------
        | Generate Password
        |--------------------------------------------------------------------------
        */

            $password = Str::password(10, true, true, true);


            /*
        |--------------------------------------------------------------------------
        | Create Organization Admin
        |--------------------------------------------------------------------------
        */

            $admin = User::create([
                'name'            => $request->organization_name,
                'email'           => $request->email,
                'phone'           => $request->phone,
                'password'        => Hash::make($password),
                'role'            => 'employeer',
                'organization_id' => $organization->id,
            ]);


            /*
        |--------------------------------------------------------------------------
        | Create Brand
        |--------------------------------------------------------------------------
        */

            Brand::create([
                'organization_id'       => $organization->id,
                'brand_name'            => $request->organization_name,
                'template_change'       => false,
                'cover_change'          => false,
                'custom_community_logo' => false,
            ]);


            /*
        |--------------------------------------------------------------------------
        | Send Email
        |--------------------------------------------------------------------------
        */

            Mail::mailer('smtp')
                ->to($admin->email)
                ->send(
                    new EmployerCredentialMail(
                        $admin,
                        $password
                    )
                );


            /*
        |--------------------------------------------------------------------------
        | WhatsApp
        |--------------------------------------------------------------------------
        */

            $setupLink = env('REACT_APP_URL') . '/login';

            $message = "Hi {$admin->name} ✨\n\n"
                . "You're in!\n\n"
                . "🔗 {$setupLink}\n\n"
                . "Team Mi Profile";

            $whatsapp->send(
                $admin->phone,
                $message
            );


            DB::commit();

            return response()->json([
                'success'      => true,
                'message'      => 'Organization registered successfully',
                'organization' => [
                    'id'   => $organization->id,
                    'name' => $organization->name,
                    'email' => $organization->email,
                    'phone' => $organization->phone,
                    'affiliate_id' => $organization->affiliate_id,
                ],
            ], 201);
        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * admin side transaction history for affiliate
     */


    public function transactions($id)
    {
        $affiliate = Affiliate::find($id);

        if (!$affiliate) {
            return response()->json([
                'status' => false,
                'message' => 'Affiliate not found.',
            ], 404);
        }

        $commissionPercentage = (float) ($affiliate->commission ?? 0);

        /*
    |--------------------------------------------------------------------------
    | Affiliate Organization Purchases
    |--------------------------------------------------------------------------
    | Affiliate -> Organization -> OrganizationCardPurchase
    */
        $purchases = OrganizationCardPurchase::whereHas(
            'organization',
            function ($query) use ($affiliate) {
                $query->where('affiliate_id', $affiliate->id);
            }
        )
            ->where('payment_status', 'paid')
            ->latest()
            ->get();

        /*
    |--------------------------------------------------------------------------
    | Commission Transactions
    |--------------------------------------------------------------------------
    */
        $incomeTransactions = $purchases->map(
            function ($purchase) use ($commissionPercentage) {

                $purchaseAmount = (float) (
                    $purchase->grand_total ?? 0
                );

                $commission = round(
                    ($purchaseAmount * $commissionPercentage) / 100,
                    2
                );

                return [
                    'id' => $purchase->id,

                    'type' => 'income',

                    'transaction_type' => 'commission',

                    'description' =>
                    'Commission from organization card purchase',

                    'purchase_amount' => $purchaseAmount,

                    'commission_percentage' =>
                    $commissionPercentage,

                    'amount' => $commission,

                    'status' => 'credited',

                    'date' => optional(
                        $purchase->created_at
                    )->format('d M Y, h:i A'),
                ];
            }
        );

        /*
    |--------------------------------------------------------------------------
    | Withdrawal Transactions
    |--------------------------------------------------------------------------
    */
        $withdrawals = AffiliateWithdrawal::where(
            'affiliate_id',
            $affiliate->id
        )
            ->latest()
            ->get();

        $withdrawalTransactions = $withdrawals->map(
            function ($withdrawal) {

                return [
                    'id' => $withdrawal->id,

                    'type' => 'withdrawal',

                    'transaction_type' => 'withdrawal',

                    'description' => 'Withdrawal request',

                    'amount' => (float) $withdrawal->amount,

                    'status' => $withdrawal->status,

                    'remark' => $withdrawal->remark,

                    'date' => optional(
                        $withdrawal->created_at
                    )->format('d M Y, h:i A'),
                ];
            }
        );

        /*
    |--------------------------------------------------------------------------
    | Total Income
    |--------------------------------------------------------------------------
    */
        $totalIncome = round(
            $incomeTransactions->sum('amount'),
            2
        );

        /*
    |--------------------------------------------------------------------------
    | Reserved / Used Withdrawal Amount
    |--------------------------------------------------------------------------
    */
        $totalWithdrawals = round(
            $withdrawals
                ->whereIn('status', [
                    'pending',
                    'approved',
                    'completed',
                ])
                ->sum('amount'),
            2
        );

        /*
    |--------------------------------------------------------------------------
    | Available Balance
    |--------------------------------------------------------------------------
    */
        $availableBalance = max(
            0,
            round(
                $totalIncome - $totalWithdrawals,
                2
            )
        );

        /*
    |--------------------------------------------------------------------------
    | Merge Transactions
    |--------------------------------------------------------------------------
    */
        $transactions = $incomeTransactions
            ->concat($withdrawalTransactions)
            ->sortByDesc(function ($transaction) {
                return $transaction['date'];
            })
            ->values();

        /*
    |--------------------------------------------------------------------------
    | Bank Account
    |--------------------------------------------------------------------------
    */
        $bankAccount = AffiliateBankAccount::where(
            'affiliate_id',
            $affiliate->id
        )->first();

        /*
    |--------------------------------------------------------------------------
    | Latest Withdrawal
    |--------------------------------------------------------------------------
    */
        $latestWithdrawal = $withdrawals->first();

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
            | Affiliate
            |--------------------------------------------------------------------------
            */
                'affiliate' => [
                    'id' => $affiliate->id,

                    'name' => $affiliate->name,

                    'email' => $affiliate->email,

                    'phone' => $affiliate->phone,
                ],

                /*
            |--------------------------------------------------------------------------
            | Latest Withdrawal
            |--------------------------------------------------------------------------
            */
                'withdrawal' => $latestWithdrawal
                    ? [
                        'id' => $latestWithdrawal->id,
                        'amount' => (float) $latestWithdrawal->amount,
                        'status' => $latestWithdrawal->status,
                        'remark' => $latestWithdrawal->remark,

                        'requested_at' => optional(
                            $latestWithdrawal->created_at
                        )->format('d M Y, h:i A'),

                        'payment_screenshot' =>
                        $latestWithdrawal->payment_screenshot
                            ? asset(
                                'storage/' .
                                    $latestWithdrawal->payment_screenshot
                            )
                            : null,

                        'transaction_reference' =>
                        $latestWithdrawal->transaction_reference,

                        'processed_at' => optional(
                            $latestWithdrawal->processed_at
                        )->format('d M Y, h:i A'),
                    ]
                    : null,

                /*
|--------------------------------------------------------------------------
| All Withdrawal Requests
|--------------------------------------------------------------------------
*/
                'withdrawals' => $withdrawals->map(
                    function ($withdrawal) {

                        return [
                            'id' => $withdrawal->id,

                            'amount' =>
                            (float) $withdrawal->amount,

                            'status' =>
                            $withdrawal->status,

                            'remark' =>
                            $withdrawal->remark,

                            'requested_at' => optional(
                                $withdrawal->created_at
                            )->format('d M Y, h:i A'),

                            'payment_screenshot' =>
                            $withdrawal->payment_screenshot
                                ? asset(
                                    'storage/' .
                                        $withdrawal->payment_screenshot
                                )
                                : null,

                            'transaction_reference' =>
                            $withdrawal->transaction_reference,

                            'processed_at' => optional(
                                $withdrawal->processed_at
                            )->format('d M Y, h:i A'),
                        ];
                    }
                )->values(),

                /*
            |--------------------------------------------------------------------------
            | Bank Account
            |--------------------------------------------------------------------------
            */
                'bank_account' => $bankAccount
                    ? [
                        'id' => $bankAccount->id,

                        'accountHolderName' =>
                        $bankAccount->account_holder_name,

                        'accountNumber' =>
                        $bankAccount->account_number,

                        'ifscCode' =>
                        $bankAccount->ifsc_code,

                        'bankName' =>
                        $bankAccount->bank_name,

                        'branchName' =>
                        $bankAccount->branch_name,

                        'upiId' =>
                        $bankAccount->upi_id,

                        'status' =>
                        $bankAccount->status,
                    ]
                    : null,

                /*
            |--------------------------------------------------------------------------
            | Summary
            |--------------------------------------------------------------------------
            */
                'total_income' =>
                $totalIncome,

                'total_withdrawals' =>
                $totalWithdrawals,

                'available_balance' =>
                $availableBalance,

                'commission_percentage' =>
                $commissionPercentage,

                /*
            |--------------------------------------------------------------------------
            | Transactions
            |--------------------------------------------------------------------------
            */
                'transactions' =>
                $transactions,
            ],
        ]);
    }


    /**
     * Approve Withdrawal
     */


    public function approveWithdrawal(
        Request $request,
        $withdrawal
    ) {
        $validator = Validator::make($request->all(), [
            'payment_screenshot' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',

            'transaction_reference' =>
            'required|string|max:255',

            'remark' =>
            'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $withdrawal = AffiliateWithdrawal::find($withdrawal);

        if (!$withdrawal) {
            return response()->json([
                'status' => false,
                'message' => 'Withdrawal not found.',
            ], 404);
        }

        if ($withdrawal->status !== 'pending') {
            return response()->json([
                'status' => false,
                'message' =>
                'Only pending withdrawals can be approved.',
            ], 422);
        }

        DB::beginTransaction();

        try {

            /*
        |--------------------------------------------------------------------------
        | Upload payment screenshot
        |--------------------------------------------------------------------------
        */

            $screenshotPath = $request
                ->file('payment_screenshot')
                ->store(
                    'affiliate-withdrawals/payment-proofs',
                    'public'
                );

            /*
        |--------------------------------------------------------------------------
        | Update withdrawal
        |--------------------------------------------------------------------------
        */

            $withdrawal->payment_screenshot = $screenshotPath;

            $withdrawal->transaction_reference =
                $request->transaction_reference;

            $withdrawal->remark =
                $request->remark;

            $withdrawal->status = 'approved';

            $withdrawal->processed_at = now();

            $withdrawal->save();

            DB::commit();

            return response()->json([
                'status' => true,

                'message' =>
                'Withdrawal approved and payment proof saved.',

                'data' => [
                    'id' => $withdrawal->id,

                    'amount' =>
                    (float) $withdrawal->amount,

                    'status' =>
                    $withdrawal->status,

                    'transaction_reference' =>
                    $withdrawal->transaction_reference,

                    'payment_screenshot' =>
                    asset(
                        'storage/' .
                            $withdrawal->payment_screenshot
                    ),

                    'remark' =>
                    $withdrawal->remark,

                    'processed_at' =>
                    optional(
                        $withdrawal->processed_at
                    )->format('d M Y, h:i A'),
                ],
            ]);
        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' =>
                'Failed to approve withdrawal.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
