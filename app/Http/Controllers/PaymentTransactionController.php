<?php
namespace App\Http\Controllers;

use App\Models\OrganizationCardPurchase;
use Illuminate\Http\Request;

class PaymentTransactionController extends Controller
{
    public function index(Request $request)
    {
        $search  = $request->get('search');
        $perPage = $request->get('per_page', 5);

        $query = OrganizationCardPurchase::with('organization')
            ->latest();

        // 🔍 SEARCH
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                    ->orWhere('total_amount', 'like', "%{$search}%")
                    ->orWhere('payment_method', 'like', "%{$search}%")
                    ->orWhere('payment_type', 'like', "%{$search}%")
                    ->orWhereHas('organization', function ($org) use ($search) {
                        $org->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $transactions = $query->paginate($perPage);

        $transactions->getCollection()->transform(function ($tx) {

            return [
                'id'                 => $tx->id,
                'order_id'           => 'ORD-' . $tx->id,
                'invoice_no'         => $this->generateInvoiceNo($tx->id),
                'organization_id'    => $tx->organization?->id,
                'organization'       => $tx->organization?->name,
                'gst'                => $tx->organization?->GSTNumber,

                'organization_email' => $tx->organization?->email,
                'organization_phone' => $tx->organization?->phone,
                'cards'              => $tx->total_cards,
                'amount'             => $tx->total_amount,
                'payment_method'     => $tx->payment_method ?? 'N/A',
                'payment_type'       => $tx->payment_type ?? 'N/A',
                'payment_status'     => $tx->payment_status ?? 'N/A',

                // Active / Expired based on expiry_date
                'card_status'        => now()->lt($tx->expiry_date) ? 'Active' : 'Expired',

                'subtotal'           => $tx->subtotal ?? "N/A",
                'discount_amount'    => $tx->discount_amount ?? "N/A",
                'taxable_amount'     => $tx->taxable_amount ?? "N/A",
                'gst_percentage'     => $tx->gst_percentage ?? "N/A",
                'gst_amount'         => $tx->gst_amount ?? "N/A",
                'price_per_card'     => $tx->price_per_card ?? "N/A",

                'coupon_code'        => $tx->coupon_code ?? "N/A",
                'coupon_type'        => $tx->coupon_type ?? "N/A",
                'coupon_value'       => $tx->coupon_value ?? "N/A",
                'is_free_card'       => $tx->is_free_card ?? "N/A",
                'is_nfc_card'       => $tx->is_nfc_card ?? "N/A",
                'nfc_price_per_card'       => $tx->nfc_price_per_card ?? "0",

                'date'               => $tx->created_at->format('Y-m-d'),

                // Proper proof URL
                'payment_proof'      => $tx->payment_proof
                    ? asset('storage/' . $tx->payment_proof)
                    : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $transactions,
        ]);
    }

    private function generateInvoiceNo($id)
    {
        $year  = now()->year;
        $month = now()->month;

        // Financial year calculation (Apr-Mar)
        if ($month >= 4) {
            $startYear = substr($year, -2);     // 26
            $endYear   = substr($year + 1, -2); // 27
        } else {
            $startYear = substr($year - 1, -2); // 25
            $endYear   = substr($year, -2);     // 26
        }

        $fy = $startYear . $endYear;

        return 'MI' . $fy . 'INV-' . str_pad($id, 3, '0', STR_PAD_LEFT);
    }

    // public function paymentHistory(Request $request)
    // {
    //     $orgId = auth()->user()->organization_id;

    //     $transactions = OrganizationCardPurchase::where('organization_id', $orgId)
    //         ->latest()
    //         ->get();

    //     $data = $transactions->map(function ($tx) {

    //         return [
    //             'order_id'     => 'ORD-' . str_pad($tx->id, 4, '0', STR_PAD_LEFT),

    //             'invoice_no'   => $this->generateInvoiceNo($tx->id),

    //             'cards'        => $tx->total_cards,
    //             'amount'       => number_format((float) $tx->total_amount, 0),

    //             'method'       => $tx->payment_method ?? 'N/A',
    //             'payment_type' => $tx->payment_type ?? 'N/A',

    //             'status'       => ucfirst($tx->payment_status),

    //             'date'         => optional($tx->created_at)->format('Y-m-d'),

    //             'receipt'      => $tx->payment_proof
    //                 ? asset('storage/' . $tx->payment_proof)
    //                 : null,

    //             // Organization Details
    //             'organization' => [
    //                 'id'     => $tx->organization?->id,
    //                 'name'   => $tx->organization?->name,
    //                 'email'  => $tx->organization?->email,
    //                 'phone'  => $tx->organization?->phone,
    //                 'slug'   => $tx->organization?->slug,
    //                 'status' => $tx->organization?->status,
    //             ],
    //         ];
    //     });
    //     return response()->json([
    //         'success' => true,
    //         'data'    => $data,
    //     ]);
    // }

    public function paymentHistory(Request $request)
    {
        $orgId = auth()->user()->organization_id;

        $transactions = OrganizationCardPurchase::with('organization')
            ->where('organization_id', $orgId)
            ->latest()
            ->get();

        $data = $transactions->map(function ($tx) {

            return [
                'id'                 => $tx->id,
                'order_id'           => 'ORD-' . $tx->id,
                'invoice_no'         => $this->generateInvoiceNo($tx->id),

                'organization_id'    => $tx->organization?->id,
                'organization'       => $tx->organization?->name,
                'organization_email' => $tx->organization?->email,
                'organization_phone' => $tx->organization?->phone,
                'gst'                => $tx->organization?->GSTNumber,

                'cards'              => $tx->total_cards,
                'amount'             => $tx->total_amount,

                'payment_method'     => $tx->payment_method ?? 'N/A',
                'payment_type'       => $tx->payment_type ?? 'N/A',
                'payment_status'     => $tx->payment_status ?? 'N/A',

                // Card Status
                'card_status'        => (
                    $tx->expiry_date && now()->lt($tx->expiry_date)
                ) ? 'Active' : 'Expired',

                'subtotal'           => $tx->subtotal ?? 'N/A',
                'discount_amount'    => $tx->discount_amount ?? 'N/A',
                'taxable_amount'     => $tx->taxable_amount ?? 'N/A',
                'gst_percentage'     => $tx->gst_percentage ?? 'N/A',
                'gst_amount'         => $tx->gst_amount ?? 'N/A',
                'price_per_card'     => $tx->price_per_card ?? 'N/A',
                'validity_days'     => $tx->validity_days ?? 'N/A',

                'coupon_code'        => $tx->coupon_code ?? 'N/A',
                'coupon_type'        => $tx->coupon_type ?? 'N/A',
                'coupon_value'       => $tx->coupon_value ?? 'N/A',

                'is_free_card'       => $tx->is_free_card ?? false,
                   'is_nfc_card'       => $tx->is_nfc_card ?? false,
                   'nfc_price_per_card'       => $tx->nfc_price_per_card ?? "N/A",


                'date'               => optional($tx->created_at)->format('Y-m-d'),

                'payment_proof'      => $tx->payment_proof
                    ? asset('storage/' . $tx->payment_proof)
                    : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

}
