<?php

namespace App\Http\Controllers;

use App\Mail\CardActivatedMail;
use App\Models\Brand;
use App\Models\CardPricingSetting;
use App\Models\DigitalCard;
use App\Models\Organization;
use App\Models\OrganizationCardPurchase;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Validator;
use App\Models\NfcCardRequest;

class OrganizationCardPurchaseController extends Controller
{
    public function index($organization_id)
    {
        return response()->json([
            'success' => true,
            'data'    => OrganizationCardPurchase::where('organization_id', $organization_id)
                ->latest()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'organization_id' => 'required|exists:organizations,id',
            'total_cards'     => 'required|integer|min:1',
            'price_per_card'  => 'required|numeric|min:1',
            'validity_days'   => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->first()], 422);
        }

        $start  = Carbon::now();
        $expiry = Carbon::now()->addDays($request->validity_days);

        $purchase = OrganizationCardPurchase::create([
            'organization_id' => $request->organization_id,
            'total_cards'     => $request->total_cards,
            'used_cards'      => 0,
            'price_per_card'  => $request->price_per_card,
            'total_amount'    => $request->total_cards * $request->price_per_card,
            'validity_days'   => $request->validity_days,
            'start_date'      => $start,
            'expiry_date'     => $expiry,
            'payment_status'  => 'paid',
        ]);

        return response()->json([
            'success' => true,
            'data'    => $purchase,
        ], 201);
    }

    public function show1($id)
    {
        return response()->json([
            'success' => true,
            'data'    => OrganizationCardPurchase::findOrFail($id),
        ]);
    }

    public function destroy($id)
    {
        OrganizationCardPurchase::findOrFail($id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Purchase deleted (soft)',
        ]);
    }

    ///// ssssss


    // public function nfcRequests(Request $request)
    // {
    //     $organization = auth()->user()->organization_id ;

    //     // dd($organization);

    //     $requests = NfcCardRequest::with('purchase')
    //         ->where('organization_id', $organization)
    //         ->latest()
    //         ->paginate(10);

    //     return response()->json([
    //         'success' => true,
    //         'data' => $requests,
    //     ]);
    // }


    public function nfcRequests(Request $request)
    {
        $organizationId = auth()->user()->organization_id;

        $requests = NfcCardRequest::with('purchase')
            ->where('organization_id', $organizationId)
            ->whereHas('purchase', function ($query) {
                $query->where('is_nfc_card', 1);
            })
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }


    public function nfcAdminRequests(Request $request)
    {
        $search = $request->search;

        $requests = NfcCardRequest::with([
            'organization',
            'purchase'
        ])
            ->whereHas('purchase', function ($query) {
                $query->where('is_nfc_card', 1);
            })
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('card_link', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('state', 'like', "%{$search}%")
                        ->orWhereHas('organization', function ($org) use ($search) {
                            $org->where('company_name', 'like', "%{$search}%")
                                ->orWhere('name', 'like', "%{$search}%");
                        });
                });
            })
            ->latest()
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }



    public function updateAdminNfcRequest(Request $request, $id)
    {
        // $request->validate([
        //     'status' => 'required|in:pending,approved,rejected,completed',
        //     'rejected_reason' => 'nullable|string',
        // ]);


        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,approved,rejected,completed',
            'rejected_reason' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->first()], 422);
        }

        $nfc = NfcCardRequest::findOrFail($id);

        $nfc->update([
            'status' => $request->status,
            'rejected_reason' => $request->status === 'rejected'
                ? $request->rejected_reason
                : null,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Status updated successfully.',
        ]);
    }







    public function reapplyNfcRequest(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'card_link'      => 'required|url|max:255',

            'address_line1'  => 'required|string|max:255',
            'address_line2'  => 'nullable|string|max:255',
            'landmark'       => 'nullable|string|max:255',

            'pincode'        => 'required|digits:6',

            'city'           => 'required|string|max:100',
            'district'       => 'nullable|string|max:100',
            'state'          => 'required|string|max:100',
            'country'        => 'required|string|max:100',

            'remarks'        => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $organizationId = auth()->user()->organization_id;

        $requestData = NfcCardRequest::where('id', $id)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$requestData) {
            return response()->json([
                'success' => false,
                'message' => 'NFC request not found.',
            ], 404);
        }

        // Prevent editing after approval/completion
        // if (in_array($requestData->status, ['approved', 'completed'])) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'This NFC request cannot be modified.',
        //     ], 422);
        // }

        $requestData->update([
            'card_link'      => $request->card_link,
            'address_line1'  => $request->address_line1,
            'address_line2'  => $request->address_line2,
            'landmark'       => $request->landmark,
            'pincode'        => $request->pincode,
            'city'           => $request->city,
            'district'       => $request->district,
            'state'          => $request->state,
            'country'        => $request->country,

            'status' => 'pending',          // back to pending
            'rejected_reason' => null,      // clear old rejection
            'remarks'        => $request->remarks,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'NFC request updated successfully.',
            'data'    => $requestData->fresh(),
        ]);
    }




    public function nfcAddDetails(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'card_link'      => 'required|url|max:255',

            'address_line1'  => 'required|string|max:255',
            'address_line2'  => 'nullable|string|max:255',
            'landmark'       => 'nullable|string|max:255',

            'pincode'        => 'required|digits:6',

            'city'           => 'required|string|max:100',
            'district'       => 'nullable|string|max:100',
            'state'          => 'required|string|max:100',
            'country'        => 'required|string|max:100',

            'remarks'        => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $organizationId = auth()->user()->organization_id;

        $requestData = NfcCardRequest::where('id', $id)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$requestData) {
            return response()->json([
                'success' => false,
                'message' => 'NFC request not found.',
            ], 404);
        }

        // Prevent editing after approval/completion
        if (in_array($requestData->status, ['approved', 'completed'])) {
            return response()->json([
                'success' => false,
                'message' => 'This NFC request cannot be modified.',
            ], 422);
        }

        $requestData->update([
            'card_link'      => $request->card_link,
            'address_line1'  => $request->address_line1,
            'address_line2'  => $request->address_line2,
            'landmark'       => $request->landmark,
            'pincode'        => $request->pincode,
            'city'           => $request->city,
            'district'       => $request->district,
            'state'          => $request->state,
            'country'        => $request->country,
            'remarks'        => $request->remarks,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'NFC request updated successfully.',
            'data'    => $requestData->fresh(),
        ]);
    }


    public function purchase(Request $request, Organization $organization)
    {
        $validator = Validator::make($request->all(), [
            'quantity'        => 'required|integer|min:1',


            'payment_type'    => 'required_if:is_free_card,0|in:online,cash',
            'payment_mode'    => 'required_if:is_free_card,0|string|max:50',
            'payment_proof'   => 'required_if:is_free_card,0|image|max:2048',

            'validity_days'   => 'required|integer',

            'is_free_card'    => 'required|boolean',
            'is_nfc_card'    => 'required|boolean',

            'price_per_card'  => 'required|numeric',
            'subtotal'        => 'required|numeric',
            'discount_amount' => 'required|numeric',
            'taxable_amount'  => 'required|numeric',
            'gst_percentage'  => 'required|numeric',
            'gst_amount'      => 'required|numeric',
            'total_amount'    => 'required|numeric',

            'coupon_code'     => 'nullable|string',
            'coupon_type'     => 'nullable|in:percentage,flat',
            'coupon_value'    => 'nullable|numeric',
            'nfc_price_per_card'    => 'nullable|numeric',
            'base_card_price' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        DB::beginTransaction();

        try {

            $pricePerCard   = $request->base_card_price;
            $subtotal       = $request->subtotal;
            $discountAmount = $request->discount_amount;
            $taxableAmount  = $request->taxable_amount;
            $gstPercentage  = $request->gst_percentage;
            $gstAmount      = $request->gst_amount;
            $grandTotal     = $request->total_amount;

            $validityDays = (int) $request->validity_days;

            $expiryDate = now()->addDays($validityDays);

            // Upload payment proof
            $paymentProofPath = null;

            if ($request->hasFile('payment_proof')) {
                $paymentProofPath = $request->file('payment_proof')
                    ->store('payment_proofs', 'public');
            }

            // Save purchase
            $purchase = OrganizationCardPurchase::create([
                'organization_id' => $organization->id,

                'total_cards'     => $request->quantity,
                'used_cards'      => 0,

                'price_per_card'  => $pricePerCard,

                'subtotal'        => $subtotal,
                'discount_amount' => $discountAmount,
                'taxable_amount'  => $taxableAmount,
                'gst_percentage'  => $gstPercentage,
                'gst_amount'      => $gstAmount,
                'total_amount'    => $grandTotal,

                // Coupon
                'coupon_code'     => $request->coupon_code,
                'coupon_type'     => $request->coupon_type,
                'coupon_value'    => $request->coupon_value,

                'is_free_card'    => $request->is_free_card,

                'is_nfc_card'    => $request->is_nfc_card,
                'nfc_price_per_card'    => $request->nfc_price_per_card,

                'validity_days'   => $validityDays,
                'start_date'      => now(),
                'expiry_date'     => $expiryDate,

                'payment_status'  => $request->is_free_card ? 'free' : 'paid',

                'payment_type'    => $request->payment_type,
                'payment_method'  => $request->payment_mode,
                'payment_proof'   => $paymentProofPath,
            ]);

            if ($request->boolean('is_nfc_card')) {

                NfcCardRequest::create([
                    'organization_id' => $organization->id,
                    'purchase_id'     => $purchase->id,

                    // Initially empty because user fills them later
                    'card_link'       => null,
                    'address_line1'   => '',
                    'address_line2'   => null,
                    'landmark'        => null,

                    'pincode'         => '',
                    'city'            => '',
                    'district'        => null,
                    'state'           => '',
                    'country'         => 'India',

                    'remarks'         => null,

                    'status'          => 'pending',
                ]);
            }

            // Generate digital cards
            for ($i = 0; $i < $request->quantity; $i++) {

                DigitalCard::create([
                    'organization_id' => $organization->id,
                    'purchase_id'     => $purchase->id,
                    'card_number'     => strtoupper(Str::random(10)),
                    'status'          => 'active',
                    'issued_at'       => now(),
                    'expires_at'      => $expiryDate,
                ]);
            }

            DB::commit();

            try {

                $owner = $organization->users()
                    ->where('role', 'employeer')
                    ->first();

                \Log::info('Organization Users', [
                    'users' => $owner->toArray(),
                ]);

                if ($owner && $owner->email) {

                    Mail::to($owner->email)->send(
                        new CardActivatedMail([
                            'customer_name'      => $owner->name,
                            'order_id'           => 'MP-' . str_pad($purchase->id, 6, '0', STR_PAD_LEFT),
                            'card_type'          => $request->is_free_card ? 'Free Card' : 'Premium Card',
                            'payment_status'     => ucfirst($purchase->payment_status),
                            'purchase_date'      => now()->format('d M Y'),
                            'validity_period'    => $validityDays . ' Days',
                            'activation_status'  => 'Activated',
                            // 'profile_setup_link' => config('app.frontend_url'),
                            // 'login_url'          => config('app.frontend_url') . '/login',
                            'profile_setup_link' => env('REACT_APP_URL'),
                            'login_url'          => rtrim(env('REACT_APP_URL'), '/') . '/login',
                            'username'           => $owner->email,
                        ])
                    );
                }
            } catch (\Exception $mailException) {

                \Log::error('Card activation email failed', [
                    'error'       => $mailException->getMessage(),
                    'purchase_id' => $purchase->id,
                ]);
            }

            try {

                $invoiceNumber = 'MP-' . str_pad(
                    $purchase->id,
                    6,
                    '0',
                    STR_PAD_LEFT
                );

                $invoiceLink = url(
                    '/api/organization/download-invoice/' . $purchase->id
                );

                $message = "Hi {$owner->name} ✨

Your Mi Profile invoice is generated.

Invoice No: {$invoiceNumber}

Quantity: {$purchase->total_cards}

Total: ₹{$purchase->total_amount}

Status: " . ucfirst($purchase->payment_status) . "

Download invoice:
{$invoiceLink}

Team Mi Profile";

                app(\App\Services\Messenger360Service::class)
                    ->send($owner->phone, $message);
            } catch (\Exception $whatsappException) {

                \Log::error('Invoice WhatsApp Failed', [
                    'error'       => $whatsappException->getMessage(),
                    'purchase_id' => $purchase->id,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Cards purchased successfully',
                'data'    => $purchase,
            ]);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function show_w($id)
    {

        $organization = Organization::with([
            'cardPurchases' => function ($q) {
                $q->latest();
            },
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $organization,
        ]);
    }

    public function show(Request $request, $id)
    {


        $search  = $request->get('search');
        $perPage = $request->get('per_page', 5);

        $organization = Organization::findOrFail($id);

        // 🔎 Search inside card purchases
        $cardPurchasesQuery = $organization->cardPurchases()->latest();

        if ($search) {
            $cardPurchasesQuery->where(function ($q) use ($search) {
                $q->where('total_cards', 'like', "%{$search}%")
                    ->orWhere('payment_status', 'like', "%{$search}%")
                    ->orWhere('expiry_date', 'like', "%{$search}%");
            });
        }

        $cardPurchases = $cardPurchasesQuery->paginate($perPage);

        // Replace relation manually (without breaking response)
        $organization->setRelation('cardPurchases', $cardPurchases);

        // Counts from digital_cards table (UNCHANGED)
        $totalCards = DigitalCard::where('organization_id', $id)->count();

        $activeCards = DigitalCard::where('organization_id', $id)
            ->where('status', 'active')
            ->count();

        $inactiveCards = DigitalCard::where('organization_id', $id)
            ->where('status', 'inactive')
            ->count();

        $brands = Brand::where('organization_id', $id)
            ->select('logo', 'cover_page')
            ->get()
            ->map(function ($brand) {
                return [
                    'logo'       => $brand->logo
                        ? asset('storage/' . $brand->logo)
                        : null,
                    'cover_page' => $brand->cover_page
                        ? asset('storage/' . $brand->cover_page)
                        : null,
                ];
            });


        $nfcCardCount = OrganizationCardPurchase::where('organization_id', $id)
            ->where('is_nfc_card', true)
            ->sum('total_cards');

        return response()->json([
            'success'      => true,
            'data'         => $organization,
            'brand'        => $brands,
            'card_summary' => [
                'total_cards'    => $totalCards,
                'active_cards'   => $activeCards,
                'inactive_cards' => $inactiveCards,
                'nfc_cards' => $nfcCardCount,
            ],
        ]);
    }



    public function show_only(Request $request, $id = null)
    {
        $search  = $request->get('search');
        $perPage = $request->get('per_page', 5);

        /*
    |--------------------------------------------------------------------------
    | ORGANIZATION ID
    |--------------------------------------------------------------------------
    |
    | If ID is provided:
    |   /organization/68
    |
    | If ID is not provided:
    |   Get organization_id from logged-in user
    |
    */

        if (!$id) {

            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized.',
                ], 401);
            }

            $id = $user->organization_id;
        }

        /*
    |--------------------------------------------------------------------------
    | ORGANIZATION
    |--------------------------------------------------------------------------
    */

        $organization = Organization::findOrFail($id);


        /*
    |--------------------------------------------------------------------------
    | CARD PURCHASES
    |--------------------------------------------------------------------------
    */

        $cardPurchasesQuery = $organization
            ->cardPurchases()
            ->latest();

        if ($search) {

            $cardPurchasesQuery->where(function ($q) use ($search) {

                $q->where(
                    'total_cards',
                    'like',
                    "%{$search}%"
                )
                    ->orWhere(
                        'payment_status',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'expiry_date',
                        'like',
                        "%{$search}%"
                    );
            });
        }

        $cardPurchases = $cardPurchasesQuery
            ->paginate($perPage);


        /*
    |--------------------------------------------------------------------------
    | SET PAGINATED RELATION
    |--------------------------------------------------------------------------
    */

        $organization->setRelation(
            'cardPurchases',
            $cardPurchases
        );


        /*
    |--------------------------------------------------------------------------
    | DIGITAL CARD COUNTS
    |--------------------------------------------------------------------------
    */

        $totalCards = DigitalCard::where(
            'organization_id',
            $id
        )->count();


        $activeCards = DigitalCard::where(
            'organization_id',
            $id
        )
            ->where('status', 'active')
            ->count();


        $inactiveCards = DigitalCard::where(
            'organization_id',
            $id
        )
            ->where('status', 'inactive')
            ->count();


        /*
    |--------------------------------------------------------------------------
    | BRAND
    |--------------------------------------------------------------------------
    */

        $brands = Brand::where(
            'organization_id',
            $id
        )
            ->select(
                'logo',
                'cover_page'
            )
            ->get()
            ->map(function ($brand) {

                return [
                    'logo' => $brand->logo
                        ? asset('storage/' . $brand->logo)
                        : null,

                    'cover_page' => $brand->cover_page
                        ? asset('storage/' . $brand->cover_page)
                        : null,
                ];
            });


        /*
    |--------------------------------------------------------------------------
    | NFC CARD COUNT
    |--------------------------------------------------------------------------
    */

        $nfcCardCount = OrganizationCardPurchase::where(
            'organization_id',
            $id
        )
            ->where('is_nfc_card', true)
            ->sum('total_cards');


        /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

        return response()->json([

            'success' => true,

            'data' => $organization,

            'brand' => $brands,

            'card_summary' => [

                'total_cards' => $totalCards,

                'active_cards' => $activeCards,

                'inactive_cards' => $inactiveCards,

                'nfc_cards' => $nfcCardCount,

            ],

        ]);
    }

    public function stats($id)
    {
        $organization = Organization::with('cardPurchases')
            ->findOrFail($id);

        // 🔥 Card Stats
        $totalCards = DigitalCard::where('organization_id', $id)->count();

        $activeCards = DigitalCard::where('organization_id', $id)
            ->where('status', 'active')
            ->count();

        $inactiveCards = DigitalCard::where('organization_id', $id)
            ->where('status', 'inactive')
            ->count();

        return response()->json([
            'success' => true,
            'data'    => $organization,

            'stats'   => [
                'total_cards'    => $totalCards,
                'active_cards'   => $activeCards,
                'inactive_cards' => $inactiveCards,
            ],
        ]);
    }
}
