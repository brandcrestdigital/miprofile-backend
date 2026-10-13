<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Services\WebpService;

class BrandingController extends Controller
{
    /**
     * Get Branding
     */
    public function show()
    {
        $branding = Branding::first();

        if (! $branding) {
            return response()->json([
                'success' => true,
                'data'    => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'id'         => $branding->id,
                'brand_name' => $branding->brand_name,
                'logo'       => $branding->logo
                    ? asset('storage/' . $branding->logo)
                    : null,
                'favicon'    => $branding->favicon
                    ? asset('storage/' . $branding->favicon)
                    : null,
            ],
        ]);
    }

    /**
     * Create or Update Branding (Single Record)
     */
   public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'brand_name' => 'required|string|max:255',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096',
            'favicon' => 'nullable|image|mimes:jpeg,png,jpg,webp,ico|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $branding = Branding::first();

        if (!$branding) {
            $branding = new Branding();
        }

        $branding->brand_name = $request->brand_name;

        /*
        |--------------------------------------------------------------------------
        | LOGO Upload + Convert to WEBP
        |--------------------------------------------------------------------------
        */
        if ($request->hasFile('logo')) {

            // Delete old logo
            if ($branding->logo && Storage::disk('public')->exists($branding->logo)) {
                Storage::disk('public')->delete($branding->logo);
            }

            $file = $request->file('logo');

            $filename = 'logo_' . time() . '.webp';
            $destination = storage_path('app/public/branding/' . $filename);

            WebpService::convert(
                $file->getRealPath(),
                $destination,
                70,        // quality
                300,       // width
                300        // height
            );

            $branding->logo = 'branding/' . $filename;
        }

        /*
        |--------------------------------------------------------------------------
        | FAVICON Upload + Convert to WEBP
        |--------------------------------------------------------------------------
        */
        if ($request->hasFile('favicon')) {

            // Delete old favicon
            if ($branding->favicon && Storage::disk('public')->exists($branding->favicon)) {
                Storage::disk('public')->delete($branding->favicon);
            }

            $file = $request->file('favicon');

            $filename = 'favicon_' . time() . '.webp';
            $destination = storage_path('app/public/branding/' . $filename);

            WebpService::convert(
                $file->getRealPath(),
                $destination,
                80,
                64,
                64
            );

            $branding->favicon = 'branding/' . $filename;
        }

        $branding->save();

        return response()->json([
            'success' => true,
            'message' => 'Branding updated successfully',
            'data' => [
                'brand_name' => $branding->brand_name,
                'logo' => $branding->logo
                    ? asset('storage/' . $branding->logo)
                    : null,
                'favicon' => $branding->favicon
                    ? asset('storage/' . $branding->favicon)
                    : null,
            ]
        ]);
    }
}