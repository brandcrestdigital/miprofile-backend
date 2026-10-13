<?php
namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Organization;
use App\Services\WebpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BrandController extends Controller
{
    public function update(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'brand_name'            => 'required|string|max:255',
            'logo'                  => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
            'cover_page'            => 'nullable|image|mimes:jpeg,jpg,png,webp|max:4096',
            'template_change'       => 'required|boolean',
            'cover_change'          => 'required|boolean',
            'custom_community_logo' => 'required|boolean',

            'remove_logo'           => 'nullable|boolean',
            'remove_cover'          => 'nullable|boolean',
            'gst_number'            => 'nullable|string',
            'company_name'          => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $user = auth()->user();

        // ✅ Get existing brand
        $brand = Brand::where('organization_id', $user->organization_id)->first();

        // ✅ If not exists, create new instance (no insert yet)
        if (! $brand) {
            $brand                  = new Brand();
            $brand->organization_id = $user->organization_id;
        }

        if ($request->boolean('remove_logo')) {

            if (
                $brand->logo &&
                file_exists(public_path('storage/' . $brand->logo))
            ) {
                unlink(public_path('storage/' . $brand->logo));
            }

            $brand->logo = null;
        }

        if ($request->boolean('remove_cover')) {

            if (
                $brand->cover_page &&
                file_exists(public_path('storage/' . $brand->cover_page))
            ) {
                unlink(public_path('storage/' . $brand->cover_page));
            }

            $brand->cover_page = null;
        }

        /* ================= LOGO ================= */
        if ($request->hasFile('logo')) {

            if ($brand->logo && file_exists(public_path('storage/' . $brand->logo))) {
                unlink(public_path('storage/' . $brand->logo));
            }

            $file     = $request->file('logo');
            $filename = 'logo_' . Str::random(10) . '.webp';
            $destPath = public_path('storage/brands/logo/' . $filename);

            WebpService::convert(
                $file->getPathname(),
                $destPath,
                75,
                400,
                400
            );

            $brand->logo = 'brands/logo/' . $filename;
        }

        /* ================= COVER ================= */
        if ($request->hasFile('cover_page')) {

            if ($brand->cover_page && file_exists(public_path('storage/' . $brand->cover_page))) {
                unlink(public_path('storage/' . $brand->cover_page));
            }

            $file     = $request->file('cover_page');
            $filename = 'cover_' . Str::random(10) . '.webp';
            $destPath = public_path('storage/brands/cover/' . $filename);

            WebpService::convert(
                $file->getPathname(),
                $destPath,
                80,
                1400,
                600
            );

            $brand->cover_page = 'brands/cover/' . $filename;
        }

        /* ================= SAVE DATA ================= */

        $brand->brand_name            = $request->brand_name;
        $brand->template_change       = $request->template_change;
        $brand->cover_change          = $request->cover_change;
        $brand->custom_community_logo = $request->custom_community_logo;

        $brand->save();

        $organization = Organization::findOrFail($user->organization_id);

        $baseSlug = Str::slug($request->brand_name);
        $slug     = $baseSlug;
        $count    = 1;

        while (
            Organization::where('slug', $slug)
            ->where('id', '!=', $organization->id)
            ->exists()
        ) {
            $slug = $baseSlug . '-' . $count++;
        }

        $organization->update([
            'name'      => $request->brand_name,
            'slug'      => $slug,
            'LegalName' => $request->company_name,
            'GSTNumber' => $request->gst_number,
        ]);

        // Update user table name
        $user->update([
            'name' => $request->brand_name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Brand updated successfully',
            'data'    => [
                'brand_name'            => $brand->brand_name,
                'logo_url'              => $brand->logo
                    ? asset('storage/' . $brand->logo)
                    : null,
                'cover_url'             => $brand->cover_page
                    ? asset('storage/' . $brand->cover_page)
                    : null,
                'template_change'       => $brand->template_change,
                'cover_change'          => $brand->cover_change,
                'custom_community_logo' => $brand->custom_community_logo,
            ],
        ]);
    }

    public function show()
    {
        $user = auth()->user();

        $brand = Brand::where(
            'organization_id',
            $user->organization_id
        )->first();

        $organization = Organization::find($user->organization_id);

        if (! $brand) {
            return response()->json([
                'success' => false,
                'message' => 'Brand not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'brand_name'            => $brand->brand_name,
                'logo_url'              => $brand->logo
                    ? asset('storage/' . $brand->logo)
                    : null,
                'cover_url'             => $brand->cover_page
                    ? asset('storage/' . $brand->cover_page)
                    : null,
                'template_change'       => $brand->template_change,
                'cover_change'          => $brand->cover_change,
                'custom_community_logo' => $brand->custom_community_logo,

                // Organization Details
                'organization'          => [
                    'id'           => $organization?->id,
                    'company_name' => $organization?->LegalName,
                    'gst_number'   => $organization?->GSTNumber,

                ],
            ],
        ]);
    }
}
