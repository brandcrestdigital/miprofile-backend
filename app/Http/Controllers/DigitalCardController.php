<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\DigitalCard;
use App\Models\OrganizationCardPurchase;
use Illuminate\Http\Request;
use Str;
use Validator;

class DigitalCardController extends Controller
{
    public function index($organization_id)
    {
        return response()->json([
            'success' => true,
            'data'    => DigitalCard::where('organization_id', $organization_id)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'organization_id' => 'required|exists:organizations,id',
            'user_id'         => 'required|exists:users,id',
            'purchase_id'     => 'required|exists:organization_card_purchases,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()->first()], 422);
        }

        $purchase = OrganizationCardPurchase::findOrFail($request->purchase_id);

        if ($purchase->used_cards >= $purchase->total_cards) {
            return response()->json([
                'message' => 'No cards available',
            ], 400);
        }

        $card = DigitalCard::create([
            'organization_id' => $request->organization_id,
            'user_id'         => $request->user_id,
            'purchase_id'     => $request->purchase_id,
            'card_number'     => strtoupper(Str::random(12)),
            'status'          => 'active',
            'issued_at'       => now(),
            'expires_at'      => $purchase->expiry_date,
        ]);

        $purchase->increment('used_cards');

        return response()->json([
            'success' => true,
            'data'    => $card,
        ], 201);
    }

    public function save(Request $request)
{
    $validator = Validator::make($request->all(), [
        'price_per_card' => 'required|numeric|min:1',
        'minimum_cards'  => 'required|integer|min:1',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => $validator->errors()->first()
        ], 422);
    }

    $pricing = OrganizationCardPurchase::updateOrCreate(
        ['id' => 1],
        [
            'price_per_card' => $request->price_per_card,
            'minimum_cards'  => $request->minimum_cards,
        ]
    );

    return response()->json([
        'success' => true,
        'data' => $pricing
    ]);
}


    public function destroy($id)
    {
        DigitalCard::findOrFail($id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Digital card deleted (soft)',
        ]);
    }
}