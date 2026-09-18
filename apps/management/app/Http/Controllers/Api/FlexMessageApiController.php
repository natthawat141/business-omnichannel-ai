<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\ServicePackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Generates official LINE Flex Message (Bubble & Carousel) JSON for properties and core business services.
 */
class FlexMessageApiController extends Controller
{
    public function show(ServicePackage $package): JsonResponse
    {
        $package = ServicePackage::query()
            ->publicOffer()->active()
            ->published()
            ->effective()
            ->where('availability', 'available')
            ->findOrFail($package->getKey());
        $package->load('category:id,name_th,slug');

        $bubble = $this->buildPropertyBubble($package);

        $priceLabel = $package->price !== null ? ' - ฿'.number_format((float) $package->price) : '';

        return response()->json([
            'type' => 'flex',
            'altText' => "🏡 {$package->name_th}{$priceLabel}",
            'contents' => $bubble,
        ]);
    }

    public function carousel(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', 5), 10);
        $categorySlug = $request->query('category_slug');

        $query = ServicePackage::query()
            ->publicOffer()->active()->published()->effective()
            ->with('category:id,name_th,slug')
            ->where('availability', 'available');

        if ($categorySlug) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $categorySlug));
        }

        $items = $query->latest('updated_at')->take($limit)->get();

        $bubbles = $items->map(fn (ServicePackage $pkg) => $this->buildPropertyBubble($pkg))->values()->all();

        return response()->json([
            'type' => 'flex',
            'altText' => '🏢 รายการอสังหาริมทรัพย์แนะนำจาก บิว Property',
            'contents' => [
                'type' => 'carousel',
                'contents' => $bubbles,
            ],
        ]);
    }

    /**
     * Build a carousel from the exact bounded result set returned by Catalog Search.
     * The caller controls presentation order but cannot bypass catalog eligibility.
     */
    public function exactCarousel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:10'],
            'item_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        $itemIds = $validated['item_ids'];
        $itemsById = ServicePackage::query()
            ->publicOffer()->active()
            ->published()
            ->effective()
            ->where('availability', 'available')
            ->whereIn('id', $itemIds)
            ->with('category:id,name_th,slug')
            ->get()
            ->keyBy('id');

        $items = collect($itemIds)
            ->map(fn (int $id) => $itemsById->get($id))
            ->filter()
            ->values();

        abort_if($items->isEmpty(), 404);

        return response()->json([
            'type' => 'flex',
            'altText' => 'รายการอสังหาริมทรัพย์ที่ตรงกับการค้นหา',
            'contents' => [
                'type' => 'carousel',
                'contents' => $items
                    ->map(fn (ServicePackage $package) => $this->buildPropertyBubble($package))
                    ->all(),
            ],
        ]);
    }

    public function loan(): JsonResponse
    {
        return response()->json([
            'type' => 'flex',
            'altText' => '💰 บริการปรึกษาสินเชื่อบ้าน - บิว Property',
            'contents' => [
                'type' => 'bubble',
                'size' => 'kilo',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'backgroundColor' => '#0F172A',
                    'paddingAll' => '16px',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => '💰 ปรึกษาสินเชื่อบ้าน & กู้ธนาคาร',
                            'color' => '#FFFFFF',
                            'size' => 'md',
                            'weight' => 'bold',
                        ],
                        [
                            'type' => 'text',
                            'text' => 'บริการเช็กวงเงินและประสานงานสถาบันการเงินฟรี',
                            'color' => '#94A3B8',
                            'size' => 'xs',
                            'margin' => 'xs',
                        ],
                    ],
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'sm',
                    'paddingAll' => '16px',
                    'contents' => [
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'spacing' => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '🏦', 'size' => 'sm', 'flex' => 0],
                                ['type' => 'text', 'text' => 'พันธมิตรธนาคารชั้นนำ วงเงินกู้สูงสุด 100%', 'size' => 'xs', 'color' => '#334155', 'weight' => 'bold', 'wrap' => true],
                            ],
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'spacing' => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '📑', 'size' => 'sm', 'flex' => 0],
                                ['type' => 'text', 'text' => 'ตรวจเช็กความพร้อมเอกสารและเครดิตบูโรเบื้องต้น', 'size' => 'xs', 'color' => '#334155', 'wrap' => true],
                            ],
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'spacing' => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '⚡', 'size' => 'sm', 'flex' => 0],
                                ['type' => 'text', 'text' => 'ทราบผลเบื้องต้นไว ไม่มีค่าใช้จ่ายในการให้คำปรึกษา', 'size' => 'xs', 'color' => '#334155', 'wrap' => true],
                            ],
                        ],
                    ],
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'sm',
                    'paddingAll' => '12px',
                    'contents' => [
                        [
                            'type' => 'button',
                            'style' => 'primary',
                            'color' => '#0F172A',
                            'height' => 'sm',
                            'action' => [
                                'type' => 'message',
                                'label' => 'ปรึกษาวงเงินกับเจ้าหน้าที่',
                                'text' => 'สนใจปรึกษายื่นกู้สินเชื่อบ้าน ขอคุยกับเจ้าหน้าที่ครับ',
                            ],
                        ],
                        [
                            'type' => 'button',
                            'style' => 'secondary',
                            'height' => 'sm',
                            'action' => [
                                'type' => 'message',
                                'label' => 'เอกสารที่ต้องใช้มีอะไรบ้าง',
                                'text' => 'ยื่นกู้ซื้อบ้านต้องใช้เอกสารอะไรบ้างครับ',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function consignment(): JsonResponse
    {
        return response()->json([
            'type' => 'flex',
            'altText' => '📝 บริการฝากขาย-ฝากเช่า - บิว Property',
            'contents' => [
                'type' => 'bubble',
                'size' => 'kilo',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'backgroundColor' => '#0F172A',
                    'paddingAll' => '16px',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => '📝 บริการฝากขาย - ฝากเช่า',
                            'color' => '#FFFFFF',
                            'size' => 'md',
                            'weight' => 'bold',
                        ],
                        [
                            'type' => 'text',
                            'text' => 'ดูแลการตลาดและเอกสารสัญญาครบวงจร',
                            'color' => '#94A3B8',
                            'size' => 'xs',
                            'margin' => 'xs',
                        ],
                    ],
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'sm',
                    'paddingAll' => '16px',
                    'contents' => [
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'spacing' => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '📸', 'size' => 'sm', 'flex' => 0],
                                ['type' => 'text', 'text' => 'ถ่ายภาพและโปรโมททรัพย์ฟรีทุกแพลตฟอร์ม', 'size' => 'xs', 'color' => '#334155', 'weight' => 'bold', 'wrap' => true],
                            ],
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'spacing' => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '🤝', 'size' => 'sm', 'flex' => 0],
                                ['type' => 'text', 'text' => 'คัดกรองผู้ซื้อ-ผู้เช่า และนัดหมายพาชมสถานที่จริง', 'size' => 'xs', 'color' => '#334155', 'wrap' => true],
                            ],
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'spacing' => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '⚖️', 'size' => 'sm', 'flex' => 0],
                                ['type' => 'text', 'text' => 'จัดทำสัญญามาตรฐานและพาโอนกรรมสิทธิ์ ณ กรมที่ดิน', 'size' => 'xs', 'color' => '#334155', 'wrap' => true],
                            ],
                        ],
                    ],
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'sm',
                    'paddingAll' => '12px',
                    'contents' => [
                        [
                            'type' => 'button',
                            'style' => 'primary',
                            'color' => '#0F172A',
                            'height' => 'sm',
                            'action' => [
                                'type' => 'message',
                                'label' => 'ต้องการฝากขายทรัพย์',
                                'text' => 'อยากฝากขายบ้านหรือคอนโดครับ',
                            ],
                        ],
                        [
                            'type' => 'button',
                            'style' => 'secondary',
                            'height' => 'sm',
                            'action' => [
                                'type' => 'message',
                                'label' => 'ต้องการฝากปล่อยเช่า',
                                'text' => 'อยากฝากปล่อยเช่าบ้านหรือคอนโดครับ',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function about(): JsonResponse
    {
        $profile = BusinessProfile::current();

        return response()->json([
            'type' => 'flex',
            'altText' => "🏢 ข้อมูลบริการ & เวลาทำการ - {$profile->business_name}",
            'contents' => [
                'type' => 'bubble',
                'size' => 'kilo',
                'header' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'backgroundColor' => '#0F172A',
                    'paddingAll' => '16px',
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => "🏢 {$profile->business_name}",
                            'color' => '#FFFFFF',
                            'size' => 'md',
                            'weight' => 'bold',
                        ],
                        [
                            'type' => 'text',
                            'text' => 'ตัวแทนและที่ปรึกษาด้านอสังหาริมทรัพย์ครบวงจร',
                            'color' => '#94A3B8',
                            'size' => 'xs',
                            'margin' => 'xs',
                        ],
                    ],
                ],
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'sm',
                    'paddingAll' => '16px',
                    'contents' => [
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'spacing' => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '🕒', 'size' => 'sm', 'flex' => 0],
                                ['type' => 'text', 'text' => $profile->business_hours ?: 'เปิดบริการทุกวัน 09:00 - 19:00 น.', 'size' => 'xs', 'color' => '#334155', 'weight' => 'bold', 'wrap' => true],
                            ],
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'spacing' => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '📍', 'size' => 'sm', 'flex' => 0],
                                ['type' => 'text', 'text' => $profile->service_areas ?: 'กรุงเทพฯ และปริมณฑล', 'size' => 'xs', 'color' => '#334155', 'wrap' => true],
                            ],
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'horizontal',
                            'spacing' => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '📞', 'size' => 'sm', 'flex' => 0],
                                ['type' => 'text', 'text' => $profile->contact_channels ?: 'LINE: @billproperty | 081-234-5678', 'size' => 'xs', 'color' => '#334155', 'wrap' => true],
                            ],
                        ],
                    ],
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'sm',
                    'paddingAll' => '12px',
                    'contents' => [
                        [
                            'type' => 'button',
                            'style' => 'primary',
                            'color' => '#0F172A',
                            'height' => 'sm',
                            'action' => [
                                'type' => 'message',
                                'label' => 'ติดต่อแอดมิน / เจ้าหน้าที่',
                                'text' => 'ขอคุยกับเจ้าหน้าที่ครับ',
                            ],
                        ],
                        [
                            'type' => 'button',
                            'style' => 'secondary',
                            'height' => 'sm',
                            'action' => [
                                'type' => 'message',
                                'label' => 'ดูคอนโดและบ้านเดี่ยว',
                                'text' => 'สนใจดูคอนโดครับ มีโครงการไหนแนะนำบ้าง',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Build an elegant LINE Flex Bubble for a property.
     *
     * @return array<string, mixed>
     */
    private function buildPropertyBubble(ServicePackage $item): array
    {
        $categoryName = $item->category?->name_th ?? 'อสังหาริมทรัพย์';
        $transactionBadge = $item->transaction_type === 'rent' ? 'สำหรับเช่า' : 'สำหรับขาย';
        $badgeColor = $item->transaction_type === 'rent' ? '#2563EB' : '#D97706';
        $priceText = $item->price ? '฿'.number_format($item->price) : 'ติดต่อสอบถาม';
        if ($item->transaction_type === 'rent' && $item->price) {
            $priceText .= '/เดือน';
        }

        $specs = [];
        if ($item->bedrooms) {
            $specs[] = "🛏️ {$item->bedrooms} นอน";
        }
        if ($item->bathrooms) {
            $specs[] = "🚿 {$item->bathrooms} น้ำ";
        }
        if ($item->usable_area_sqm) {
            $specs[] = "📐 {$item->usable_area_sqm} ตร.ม.";
        }
        if ($item->land_area_sqw) {
            $specs[] = "🌳 {$item->land_area_sqw} ตร.ว.";
        }
        $specText = implode('  |  ', $specs);

        $bubble = [
            'type' => 'bubble',
            'size' => 'kilo',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'backgroundColor' => '#0F172A',
                'paddingTop' => '14px',
                'paddingBottom' => '14px',
                'paddingStart' => '16px',
                'paddingEnd' => '16px',
                'contents' => [
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => $categoryName,
                                'color' => '#94A3B8',
                                'size' => 'xs',
                                'weight' => 'bold',
                                'flex' => 1,
                            ],
                            [
                                'type' => 'text',
                                'text' => $transactionBadge,
                                'color' => '#FFFFFF',
                                'size' => 'xxs',
                                'weight' => 'bold',
                                'align' => 'end',
                                'backgroundColor' => $badgeColor,
                                'cornerRadius' => '4px',
                                'paddingStart' => '6px',
                                'paddingEnd' => '6px',
                            ],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => $item->project_name ?: $item->name_th,
                        'color' => '#FFFFFF',
                        'size' => 'md',
                        'weight' => 'bold',
                        'margin' => 'sm',
                        'wrap' => true,
                    ],
                ],
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'md',
                'paddingAll' => '16px',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => $priceText,
                        'size' => 'xl',
                        'weight' => 'bold',
                        'color' => '#D97706',
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'spacing' => 'xs',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => '📍 '.($item->location_text ?: ($item->district.' '.$item->province)),
                                'size' => 'xs',
                                'color' => '#64748B',
                                'wrap' => true,
                            ],
                            [
                                'type' => 'text',
                                'text' => $specText ?: 'สอบถามรายละเอียดเพิ่มเติม',
                                'size' => 'xs',
                                'color' => '#334155',
                                'weight' => 'bold',
                                'margin' => 'xs',
                            ],
                        ],
                    ],
                ],
            ],
            'footer' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'sm',
                'paddingAll' => '12px',
                'contents' => [
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'color' => '#0F172A',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'message',
                            'label' => 'ดูรายละเอียดตัวนี้',
                            'text' => "ขอดูรายละเอียด {$item->name_th} (รหัส {$item->code}) ครับ",
                        ],
                    ],
                    [
                        'type' => 'button',
                        'style' => 'secondary',
                        'height' => 'sm',
                        'action' => [
                            'type' => 'message',
                            'label' => 'นัดชม / ติดต่อแอดมิน',
                            'text' => "สนใจนัดชมห้อง {$item->name_th} ขอคุยกับเจ้าหน้าที่ครับ",
                        ],
                    ],
                ],
            ],
        ];

        if ($item->primary_image_url) {
            $bubble['hero'] = [
                'type' => 'image',
                'url' => $item->primary_image_url,
                'size' => 'full',
                'aspectRatio' => '20:13',
                'aspectMode' => 'cover',
            ];
        }

        return $bubble;
    }
}
