<?php
namespace App\Http\Controllers;
use Carbon\Carbon;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CouponController extends Controller
{
    // List Coupons
    public function index()
    {
        $coupons = Coupon::latest()->get();

        return response()->json([
            'success' => true,
            'data'    => $coupons,
        ]);
    }

    // Create Coupon
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'       => 'required|string|max:255',
            'code'       => 'required|string|unique:coupons,code',
            'type'       => 'required|in:percentage,flat',
            'value'      => 'required|numeric|min:1',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $coupon = Coupon::create([
            'name'       => $request->name,
            'code'       => strtoupper($request->code),
            'type'       => $request->type,
            'value'      => $request->value,
            'start_date' => $request->start_date,
            'end_date'   => $request->end_date,
            'is_active'  => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Coupon created successfully',
            'data'    => $coupon,
        ]);
    }

    // Show Coupon
    public function show(Coupon $coupon)
    {
        return response()->json([
            'success' => true,
            'data'    => $coupon,
        ]);
    }

    // Update Coupon
    public function update(Request $request, Coupon $coupon)
    {
        $validator = Validator::make($request->all(), [
            'name'       => 'required|string|max:255',
            'code'       => 'required|unique:coupons,code,' . $coupon->id,
            'type'       => 'required|in:percentage,flat',
            'value'      => 'required|numeric|min:1',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'is_active'  => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $coupon->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Coupon updated successfully',
            'data'    => $coupon,
        ]);
    }

    // Delete Coupon
    public function destroy(Coupon $coupon)
    {
        $coupon->delete();

        return response()->json([
            'success' => true,
            'message' => 'Coupon deleted successfully',
        ]);
    }


    public function applyCoupon(Request $request)
{
    $validator = Validator::make($request->all(), [
        'coupon_code' => 'required|string',
        'amount'      => 'required|numeric|min:1',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => $validator->errors()->first(),
        ], 422);
    }

    $coupon = Coupon::where('code', strtoupper($request->coupon_code))
        ->where('is_active', true)
        ->first();

    if (! $coupon) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid coupon code',
        ], 404);
    }

    $today = Carbon::today();

    if ($today->lt($coupon->start_date)) {
        return response()->json([
            'success' => false,
            'message' => 'Coupon not started yet',
        ], 422);
    }

    if ($today->gt($coupon->end_date)) {
        return response()->json([
            'success' => false,
            'message' => 'Coupon expired',
        ], 422);
    }

    $amount = $request->amount;

    if ($coupon->type == 'percentage') {
        $discount = ($amount * $coupon->value) / 100;
    } else {
        $discount = $coupon->value;
    }

    // Discount cannot exceed amount
    $discount = min($discount, $amount);

    $finalAmount = $amount - $discount;

    return response()->json([
        'success' => true,
        'message' => 'Coupon applied successfully',
        'data' => [
            'coupon'         => $coupon->code,
            'discount_type'  => $coupon->type,
            'discount_value' => $coupon->value,
            'original_amount'=> $amount,
            'discount'       => round($discount, 2),
            'final_amount'   => round($finalAmount, 2),
        ],
    ]);
    }
}