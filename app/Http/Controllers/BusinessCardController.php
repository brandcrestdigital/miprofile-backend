<?php
namespace App\Http\Controllers;

use Google\Cloud\Vision\V1\AnnotateImageRequest;
use Google\Cloud\Vision\V1\BatchAnnotateImagesRequest;
use Google\Cloud\Vision\V1\Client\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature;
use Google\Cloud\Vision\V1\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BusinessCardController extends Controller
{
    public function scan(Request $request)
    {
        // $request->validate([
        //     'image' => 'required|image|max:10240'
        // ]);

        $validator = Validator::make($request->all(), [
            'image' => 'required|image|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }
        putenv(
            'GOOGLE_APPLICATION_CREDENTIALS=' .
            storage_path('app/google/vision-api.json')
        );

        $client = new ImageAnnotatorClient();

        $imageContent = file_get_contents(
            $request->file('image')->getRealPath()
        );

        $image = (new Image())
            ->setContent($imageContent);

        $feature = (new Feature())
            ->setType(Feature\Type::TEXT_DETECTION);

        $annotateImageRequest = (new AnnotateImageRequest())
            ->setImage($image)
            ->setFeatures([$feature]);

        $batchRequest = (new BatchAnnotateImagesRequest())
            ->setRequests([$annotateImageRequest]);

        $response = $client->batchAnnotateImages($batchRequest);

        $text = '';

        $responses = $response->getResponses();

        if (
            isset($responses[0]) &&
            count($responses[0]->getTextAnnotations()) > 0
        ) {
            $text = $responses[0]
                ->getTextAnnotations()[0]
                ->getDescription();
        }

        $client->close();

        return response()->json([
            'success'  => true,
            'raw_text' => $text,
        ]);
    }

}
