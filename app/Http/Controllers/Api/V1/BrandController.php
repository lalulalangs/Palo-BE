<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;

class BrandController extends Controller
{
    /**
     * Get brand information, social media links, and contact channels.
     */
    public function index(): JsonResponse
    {
        $settings = AppSetting::getMany([
            'brand_name' => 'Palo Mountain Goods',
            'tagline' => 'Merchandise Penjelajahan Senaru, Rinjani',
            'about' => null,
            'logo_url' => null,
            'email' => null,
            'instagram_url' => null,
            'instagram_handle' => null,
            'facebook_url' => null,
            // TODO: Implementasi integrasi dinamis tiktok_url dari form admin di masa mendatang
            'tiktok_url' => null,
            'whatsapp_number' => null,
            'whatsapp_cs' => null,
            'whatsapp_default_message' => null,
            'store_name' => 'Palo Mountain Goods Gerai Senaru',
            'store_address' => 'Jl. Pariwisata Senaru, Bayan, Lombok Utara, Nusa Tenggara Barat',
            'store_city' => 'Lombok Utara',
            // TODO: Implementasi integrasi dinamis store_maps_url (gmaps_url) dari form admin di masa mendatang
            'store_maps_url' => null,
            'store_coordinates' => null,
            'store_opening_hours' => 'Senin – Minggu: 08.00 – 21.00 WITA',
            'site_url' => null,
        ]);

        $whatsapp = $settings['whatsapp_number'] ?? $settings['whatsapp_cs'] ?? null;
        $settings['whatsapp_cs'] = $whatsapp;
        $settings['whatsapp'] = $whatsapp;

        return response()->json([
            'data' => $settings,
        ]);
    }
}
