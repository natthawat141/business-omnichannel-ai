<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\CloudflareImagesUnavailable;
use App\Exceptions\PropertyImageUploadUnavailable;
use App\Http\Controllers\Controller;
use App\Models\ServicePackage;
use App\Services\CloudflareImages;
use App\Services\R2PropertyImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PropertyImageUploadController extends Controller
{
    public function __invoke(Request $request, CloudflareImages $images, R2PropertyImages $r2): JsonResponse
    {
        Gate::authorize('create', ServicePackage::class);

        $contentType = $request->validate([
            'content_type' => ['required', 'string', 'in:image/jpeg,image/png,image/webp'],
        ])['content_type'];

        $driver = (string) config('services.property_images.driver', 'cloudflare_images');

        try {
            $upload = match ($driver) {
                'cloudflare_images' => $images->createDirectUpload(),
                'r2' => $r2->createDirectUpload($contentType),
                default => throw new PropertyImageUploadUnavailable('Unknown property image upload driver.'),
            };
        } catch (CloudflareImagesUnavailable|PropertyImageUploadUnavailable) {
            return response()->json([
                'message' => 'ยังไม่สามารถเริ่มอัปโหลดรูปได้ กรุณาตรวจการตั้งค่าที่เก็บรูป',
            ], 503);
        }

        return response()->json(['data' => $upload], 201);
    }
}
