<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PackageRequest;
use App\Http\Resources\PackageResource;
use App\Models\PackageCategory;
use App\Models\ServicePackage;
use App\Services\Catalog\CatalogWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PackageController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ServicePackage::class);

        $search = trim((string) $request->query('search', ''));
        $categoryId = $request->query('category_id');
        $isPublished = $request->query('is_published');
        $isActive = $request->query('is_active');

        $packages = ServicePackage::query()->unarchived()
            ->with('category')
            ->when($search, fn ($q) => $q->where(function ($sub) use ($search) {
                $sub->where('name_th', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('keywords', 'like', "%{$search}%");
            }))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when(in_array($isPublished, ['0', '1'], true), fn ($q) => $q->where('is_published', $isPublished))
            ->when(in_array($isActive, ['0', '1'], true), fn ($q) => $q->where('is_active', $isActive))
            ->orderBy('name_th')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Packages/Index', [
            'packages' => PackageResource::collection($packages),
            'categories' => PackageCategory::orderBy('sort_order')->orderBy('name_th')->get(['id', 'name_th']),
            'filters' => [
                'search' => $search,
                'category_id' => $categoryId,
                'is_published' => $isPublished,
                'is_active' => $isActive,
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', ServicePackage::class);

        return Inertia::render('Packages/Form', [
            'pkg' => null,
            'profileSchemas' => app(\App\Services\Catalog\CatalogProfiles::class)->schemas(),
            'categories' => PackageCategory::orderBy('sort_order')->orderBy('name_th')->get(['id', 'name_th']),
        ]);
    }

    public function store(PackageRequest $request): RedirectResponse
    {
        Gate::authorize('create', ServicePackage::class);

        ServicePackage::create($request->validated());

        return redirect()->route('admin.packages.index')->with('success', 'เพิ่มรายการทรัพย์เรียบร้อยแล้ว');
    }

    public function edit(ServicePackage $package): Response
    {
        Gate::authorize('update', $package);

        return Inertia::render('Packages/Form', [
            'pkg' => new PackageResource($package),
            'profileSchemas' => app(\App\Services\Catalog\CatalogProfiles::class)->schemas(),
            'categories' => PackageCategory::orderBy('sort_order')->orderBy('name_th')->get(['id', 'name_th']),
        ]);
    }

    public function update(PackageRequest $request, ServicePackage $package, CatalogWriter $writer): RedirectResponse
    {
        Gate::authorize('update', $package);

        $data = $request->validated();
        $expectedVersion = isset($data['lock_version']) ? (int) $data['lock_version'] : (int) $package->lock_version;
        unset($data['lock_version']);
        $writer->update($package, $data, $expectedVersion);

        return redirect()->route('admin.packages.index')->with('success', 'บันทึกการแก้ไขเรียบร้อยแล้ว');
    }

    public function destroy(ServicePackage $package): RedirectResponse
    {
        Gate::authorize('delete', $package);

        $package->archive();

        return redirect()->route('admin.packages.index')->with('success', 'เก็บรายการทรัพย์เข้าคลังแล้ว และกู้คืนได้');
    }
}
