<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\FaqRequest;
use App\Http\Resources\FaqResource;
use App\Models\Faq;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class FaqController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Faq::class);

        $search = trim((string) $request->query('search', ''));
        $category = $request->query('category');
        $isActive = $request->query('is_active');

        $faqs = Faq::query()->unarchived()
            ->when($search, fn ($q) => $q->where(function ($sub) use ($search) {
                $sub->where('question_th', 'like', "%{$search}%")
                    ->orWhere('answer_th', 'like', "%{$search}%");
            }))
            ->when($category, fn ($q) => $q->where('category', $category))
            ->when(in_array($isActive, ['1', '0'], true), fn ($q) => $q->where('is_active', $isActive === '1'))
            ->latest('updated_at')
            ->paginate(15)
            ->withQueryString();

        $categories = Faq::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();

        return Inertia::render('Faqs/Index', [
            'faqs' => FaqResource::collection($faqs),
            'categories' => $categories,
            'filters' => ['search' => $search, 'category' => $category, 'is_active' => $isActive],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Faq::class);

        return Inertia::render('Faqs/Form', ['faq' => null]);
    }

    public function store(FaqRequest $request): RedirectResponse
    {
        Gate::authorize('create', Faq::class);

        Faq::create($request->validated());

        return redirect()->route('admin.faqs.index')->with('success', 'เพิ่มคำถามเรียบร้อยแล้ว');
    }

    public function edit(Faq $faq): Response
    {
        Gate::authorize('update', $faq);

        return Inertia::render('Faqs/Form', ['faq' => new FaqResource($faq)]);
    }

    public function update(FaqRequest $request, Faq $faq): RedirectResponse
    {
        Gate::authorize('update', $faq);

        $faq->update($request->validated());

        return redirect()->route('admin.faqs.index')->with('success', 'บันทึกการแก้ไขเรียบร้อยแล้ว');
    }

    public function destroy(Faq $faq): RedirectResponse
    {
        Gate::authorize('delete', $faq);

        $faq->archive();

        return redirect()->route('admin.faqs.index')->with('success', 'เก็บคำถามเข้าคลังแล้ว และกู้คืนได้');
    }
}
