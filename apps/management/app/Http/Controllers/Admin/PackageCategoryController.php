<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PackageCategoryRequest;
use App\Http\Resources\PackageCategoryResource;
use App\Models\PackageCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PackageCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PackageCategory::class);

        $search = trim((string) $request->query('search', ''));
        $isActive = $request->query('is_active');

        $categories = PackageCategory::query()
            ->withCount('packages')
            ->when($search, fn ($q) => $q->where(function ($sub) use ($search) {
                $sub->where('name_th', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%");
            }))
            ->when(in_array($isActive, ['0', '1'], true), fn ($q) => $q->where('is_active', $isActive))
            ->orderBy('sort_order')
            ->orderBy('name_th')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Categories/Index', [
            'categories' => PackageCategoryResource::collection($categories),
            'filters' => ['search' => $search, 'is_active' => $isActive],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', PackageCategory::class);

        return Inertia::render('Categories/Form', ['category' => null]);
    }

    public function store(PackageCategoryRequest $request): RedirectResponse
    {
        Gate::authorize('create', PackageCategory::class);

        PackageCategory::create($request->validated());

        return redirect()->route('admin.package-categories.index')->with('success', 'เพิ่มประเภททรัพย์เรียบร้อยแล้ว');
    }

    public function edit(PackageCategory $category): Response
    {
        Gate::authorize('update', $category);

        return Inertia::render('Categories/Form', [
            'category' => new PackageCategoryResource($category),
        ]);
    }

    public function update(PackageCategoryRequest $request, PackageCategory $category): RedirectResponse
    {
        Gate::authorize('update', $category);

        $category->update($request->validated());

        return redirect()->route('admin.package-categories.index')->with('success', 'บันทึกการแก้ไขเรียบร้อยแล้ว');
    }

    public function destroy(PackageCategory $category): RedirectResponse
    {
        Gate::authorize('delete', $category);

        $category->delete();

        return redirect()->route('admin.package-categories.index')->with('success', 'ลบประเภททรัพย์เรียบร้อยแล้ว');
    }
}
