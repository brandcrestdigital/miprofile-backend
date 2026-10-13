<?php

namespace App\Http\Controllers;

use App\Models\CardPricingSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CardPricingSettingController extends Controller
{
    /**
     * Get pricing (single record)
     */
    public function index()
    {
        $pricing = CardPricingSetting::first();

        return response()->json([
            'success' => true,
            'data'    => $pricing,
        ]);
    }

    /**
     * Save / Update (Only one record allowed)
     */
    public function save_old(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'price_per_card' => 'required|numeric|min:1',
            'minimum_cards'  => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        // Always keep only one record (ID = 1)
        $pricing = CardPricingSetting::updateOrCreate(
            ['id' => 1],
            [
                'price_per_card' => $request->price_per_card,
                'minimum_cards'  => $request->minimum_cards,
            ]
        );

        return response()->json([
            'success' => true,
            'data'    => $pricing,
        ]);
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

        $pricing = CardPricingSetting::updateOrCreate(
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

    /**
     * Delete (optional)
     */
    public function destroy()
    {
        $pricing = CardPricingSetting::first();

        if (! $pricing) {
            return response()->json([
                'success' => false,
                'message' => 'Pricing not found',
            ], 404);
        }

        $pricing->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pricing deleted',
        ]);
    }


    /**
     * Summary of cardPricing
     * @return \Illuminate\Http\JsonResponse
     *
     */
    public function cardPricing()
    {
        $pricing = CardPricingSetting::first();

        if (!$pricing) {
            return response()->json([
                'status' => false,
                'message' => 'Card pricing settings not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'price_per_card' => (float) $pricing->price_per_card,
                'minimum_cards' => (int) $pricing->minimum_cards,
            ],
        ]);
    }
}
