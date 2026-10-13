<?php

namespace App\Http\Controllers;

use App\Models\CashfreeOrder;
use App\Models\OrganizationCardPurchase;
use App\Services\CashfreeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\DigitalCard;
use Illuminate\Support\Str;

class CashfreeController extends Controller
{
    /**
     * Create Cashfree Order
     *
     * This method:
     * 1. Creates the Cashfree order
     * 2. Saves temporary order information
     * 3. DOES NOT create OrganizationCardPurchase yet
     */
    public function createOrder(
        Request $request,
        CashfreeService $cashfree,
        $organizationId
    ) {
        /*
        |--------------------------------------------------------------------------
        | Validate Request
        |--------------------------------------------------------------------------
        */

        $request->validate([
            'quantity'           => 'required|integer|min:1',
            'price_per_card'     => 'required|numeric|min:0',

            'subtotal'           => 'required|numeric|min:0',
            'discount_amount'    => 'nullable|numeric|min:0',
            'taxable_amount'     => 'required|numeric|min:0',

            'gst_percentage'     => 'required|numeric|min:0',
            'gst_amount'         => 'required|numeric|min:0',

            'total_amount'       => 'required|numeric|min:1',

            'validity_days'      => 'required|integer|min:1',

            'payment_method'     => 'nullable|string',

            'coupon_code'        => 'nullable|string',
            'coupon_type'        => 'nullable|string',
            'coupon_value'      => 'nullable|numeric',

            'nfc_price_per_card' => 'nullable|numeric|min:0',
            'is_nfc_card'        => 'nullable|boolean',

            'card_type'          => 'nullable|string',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Authenticated User
        |--------------------------------------------------------------------------
        */

        $user = auth('api')->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        if ($user->role === 'admin') {
            $source = 'admin';
        } elseif ($user->role === 'employeer') {
            $source = 'organization';
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Invalid user role.',
            ], 403);
        }



        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized.',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | Generate Internal Cashfree Order ID
        |--------------------------------------------------------------------------
        */

        $orderId = 'ORDER_' . strtoupper(uniqid());

        /*
        |--------------------------------------------------------------------------
        | Create Cashfree Order
        |--------------------------------------------------------------------------
        */

        $amount = round((float) $request->total_amount, 2);
        $response = $cashfree->createOrder(
            orderId: $orderId,
            amount: $amount,
            customerId: 'USER_' . auth('api')->id(),
            customerName: $user->name,
            customerEmail: $user->email,
            customerPhone: $user->phone,
        );

        /*
        |--------------------------------------------------------------------------
        | Cashfree Order Creation Failed
        |--------------------------------------------------------------------------
        */

        if ($response->failed()) {
            return response()->json([
                'status' => false,
                'message' => 'Unable to create Cashfree order.',
                'error' => $response->json(),
            ], $response->status());
        }

        /*
        |--------------------------------------------------------------------------
        | Cashfree Response
        |--------------------------------------------------------------------------
        */

        $data = $response->json();

        /*
        |--------------------------------------------------------------------------
        | Validate Cashfree Response
        |--------------------------------------------------------------------------
        */

        if (
            empty($data['order_id']) ||
            empty($data['payment_session_id'])
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid response received from Cashfree.',
                'cashfree_response' => $data,
            ], 500);
        }

        /*
        |--------------------------------------------------------------------------
        | Save Temporary Cashfree Order
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | OrganizationCardPurchase is NOT created here.
        |
        | We only save:
        | - Cashfree order ID
        | - Cashfree CF order ID
        | - Purchase information as JSON
        |
        */

        $cashfreeOrder = CashfreeOrder::create([
            'organization_id' => $organizationId,

            'cashfree_order_id' => $data['order_id'],

            'cashfree_cf_order_id' => $data['cf_order_id'] ?? null,

            'status' => 'pending',

            // IMPORTANT
            'source' => $source,

            'order_data' => [
                'quantity' => (int) $request->quantity,

                'validity_days' => (int) $request->validity_days,

                'price_per_card' => (float) $request->price_per_card,

                'subtotal' => (float) $request->subtotal,

                'discount_amount' =>
                (float) ($request->discount_amount ?? 0),

                'taxable_amount' =>
                (float) $request->taxable_amount,

                'gst_percentage' =>
                (float) $request->gst_percentage,

                'gst_amount' =>
                (float) $request->gst_amount,

                'total_amount' =>
                (float) $request->total_amount,

                'is_nfc_card' =>
                (int) ($request->is_nfc_card ?? 0),

                'nfc_price_per_card' =>
                (float) ($request->nfc_price_per_card ?? 0),

                'card_type' =>
                $request->card_type,

                'coupon_code' =>
                $request->coupon_code,

                'coupon_type' =>
                $request->coupon_type,

                'coupon_value' =>
                $request->coupon_value !== null
                    ? (float) $request->coupon_value
                    : null,

                'payment_method' =>
                $request->payment_method,
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Return Payment Session
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'status' => true,

            'message' => 'Cashfree order created successfully.',

            'order_id' => $data['order_id'],

            'cf_order_id' =>
            $data['cf_order_id'] ?? null,

            'payment_session_id' =>
            $data['payment_session_id'],

            'cashfree_order_id' =>
            $cashfreeOrder->id,
        ]);
    }


    /**
     * Cashfree Payment Return
     *
     * This method:
     * 1. Gets Cashfree order
     * 2. Verifies payment
     * 3. Checks SUCCESS
     * 4. Creates OrganizationCardPurchase
     * 5. Marks Cashfree order as paid
     */
    public function paymentReturn(
        Request $request,
        CashfreeService $cashfree
    ) {
        /*
        |--------------------------------------------------------------------------
        | Get Cashfree Order ID
        |--------------------------------------------------------------------------
        */

        $orderId = $request->query('order_id');

        if (!$orderId) {
            return response()->json([
                'status' => false,
                'message' => 'Cashfree order ID missing.',
            ], 400);
        }

        /*
        |--------------------------------------------------------------------------
        | Find Temporary Cashfree Order
        |--------------------------------------------------------------------------
        */

        $cashfreeOrder = CashfreeOrder::where(
            'cashfree_order_id',
            $orderId
        )->first();

        if (!$cashfreeOrder) {
            return response()->json([
                'status' => false,
                'message' => 'Cashfree order not found.',
                'order_id' => $orderId,
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Already Processed
        |--------------------------------------------------------------------------
        */

        if ($cashfreeOrder->status === 'paid') {
            return response()->json([
                'status' => true,
                'message' => 'Payment already processed.',
                'order_id' => $orderId,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Get Payment Details From Cashfree
        |--------------------------------------------------------------------------
        */

        $response = $cashfree->getPayments($orderId);

        if ($response->failed()) {
            return response()->json([
                'status' => false,
                'message' =>
                'Unable to verify Cashfree payment.',

                'cashfree_response' =>
                $response->json(),

            ], $response->status());
        }

        /*
        |--------------------------------------------------------------------------
        | Payment Response
        |--------------------------------------------------------------------------
        */

        $payments = $response->json();

        /*
        |--------------------------------------------------------------------------
        | Find Successful Payment
        |--------------------------------------------------------------------------
        */

        $successfulPayment = collect($payments)->first(
            function ($payment) {

                return (
                    ($payment['payment_status'] ?? null)
                    === 'SUCCESS'
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Payment Not Successful
        |--------------------------------------------------------------------------
        */

        if (!$successfulPayment) {

            $cashfreeOrder->update([
                'status' => 'failed',
            ]);

            return response()->json([
                'status' => false,

                'message' =>
                'Payment is not successful.',

                'order_id' =>
                $orderId,

                'payment_status' =>
                'failed',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Payment ID
        |--------------------------------------------------------------------------
        */

        $paymentId =
            $successfulPayment['cf_payment_id']
            ?? null;

        if (!$paymentId) {
            return response()->json([
                'status' => false,
                'message' =>
                'Cashfree payment ID not found.',
                'order_id' => $orderId,
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Get Original Purchase Data
        |--------------------------------------------------------------------------
        */

        $orderData = $cashfreeOrder->order_data;

        if (!$orderData) {
            return response()->json([
                'status' => false,

                'message' =>
                'Purchase order data not found.',

                'order_id' =>
                $orderId,
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Create Start / Expiry Date
        |--------------------------------------------------------------------------
        */

        $start = Carbon::now();

        $expiry = $start->copy()->addDays(
            (int) $orderData['validity_days']
        );

        /*
        |--------------------------------------------------------------------------
        | Create Actual Organization Card Purchase
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | This happens ONLY after successful payment.
        |
        */

        $purchase = OrganizationCardPurchase::create([

            'organization_id' =>
            $cashfreeOrder->organization_id,

            'total_cards' =>
            $orderData['quantity'],

            'used_cards' =>
            0,

            'price_per_card' =>
            $orderData['price_per_card'],

            'subtotal' =>
            $orderData['subtotal'],

            'discount_amount' =>
            $orderData['discount_amount'] ?? 0,

            'taxable_amount' =>
            $orderData['taxable_amount'],

            'gst_percentage' =>
            $orderData['gst_percentage'],

            'gst_amount' =>
            $orderData['gst_amount'],

            'total_amount' =>
            $orderData['total_amount'],

            'validity_days' =>
            $orderData['validity_days'],

            'start_date' =>
            $start,

            'expiry_date' =>
            $expiry,

            'payment_status' =>
            'paid',

            'payment_method' =>
            $orderData['payment_method'] ?? null,

            'payment_type' =>
            'online',

            'payment_proof' =>
            null,

            'coupon_code' =>
            $orderData['coupon_code'] ?? null,

            'coupon_type' =>
            $orderData['coupon_type'] ?? null,

            'coupon_value' =>
            $orderData['coupon_value'] ?? null,

            'nfc_price_per_card' =>
            $orderData['nfc_price_per_card'] ?? 0,

            'is_nfc' =>
            $orderData['is_nfc_card'] ?? 0,

            'cashfree_order_id' =>
            $cashfreeOrder->cashfree_order_id,

            'cashfree_cf_order_id' =>
            $cashfreeOrder->cashfree_cf_order_id,

            'cashfree_payment_id' =>
            $paymentId,
        ]);


        /*
|--------------------------------------------------------------------------
| Create Digital Cards
|--------------------------------------------------------------------------
*/

        $organization = \App\Models\Organization::find(
            $cashfreeOrder->organization_id
        );

        if (!$organization) {
            return response()->json([
                'status' => false,
                'message' => 'Organization not found.',
                'organization_id' => $cashfreeOrder->organization_id,
            ], 404);
        }

        for ($i = 0; $i < (int) $orderData['quantity']; $i++) {

            DigitalCard::create([
                'organization_id' => $organization->id,
                'purchase_id'     => $purchase->id,
                'card_number'     => strtoupper(Str::random(10)),
                'status'          => 'active',
                'issued_at'       => now(),
                'expires_at'      => $expiry,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Mark Cashfree Order as Paid
        |--------------------------------------------------------------------------
        */

        $cashfreeOrder->update([
            'status' => 'paid',

            'cashfree_payment_id' =>
            $paymentId,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Return Success
        |--------------------------------------------------------------------------
        */



        // return redirect()->away(
        //     config('app.frontend_url')
        //         . '/organizations/' . $cashfreeOrder->organization_id
        //         . '?payment=success'
        //         . '&purchase_id=' . $purchase->id
        //         . '&order_id=' . $orderId
        // );


        if ($cashfreeOrder->source === 'organization') {

            $frontendUrl = rtrim(config('app.frontend_url_organization'), '/');

            return redirect()->away(
                $frontendUrl
                    . '/organizations/cards'
                    . '?payment=success'
                    . '&purchase_id=' . $purchase->id
                    . '&order_id=' . $orderId
            );
        }

        if ($cashfreeOrder->source === 'admin') {

            $frontendUrl = rtrim(config('app.frontend_url_admin'), '/');

            return redirect()->away(
                $frontendUrl
                    . '/admin/organizations/'
                    . $cashfreeOrder->organization_id
                    . '?payment=success'
                    . '&purchase_id=' . $purchase->id
                    . '&order_id=' . $orderId
            );
        }
    }
}
