<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentChangeSet;
use App\Services\Agent\AgentChangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Human review gate for immutable proposals submitted by external agents. */
class AgentChangeController extends Controller
{
    public function __construct(private readonly AgentChangeService $changes) {}

    public function index(Request $request): Response
    {
        $this->assertAdmin($request);
        $status = $request->string('status')->toString();
        $sets = AgentChangeSet::query()
            ->with('operations')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('AgentChanges/Index', [
            'changeSets' => $sets->through(fn (AgentChangeSet $set) => $this->summary($set)),
            'filter' => $status,
        ]);
    }

    public function show(Request $request, AgentChangeSet $agentChange): Response
    {
        $this->assertAdmin($request);
        $agentChange->load('operations');

        return Inertia::render('AgentChanges/Show', [
            'changeSet' => $this->detail($agentChange),
        ]);
    }

    public function approve(Request $request, AgentChangeSet $agentChange): RedirectResponse
    {
        $this->assertAdmin($request);
        try {
            $this->changes->approve($agentChange, $request->user());
        } catch (ValidationException|ConflictHttpException) {
            return back()->with('error', 'ยังอนุมัติชุดการเปลี่ยนแปลงนี้ไม่ได้ กรุณาตรวจสถานะและคีย์ต้นทางอีกครั้ง');
        }

        return back()->with('success', 'อนุมัติแล้ว ขั้นถัดไปคือกดใช้งานชุดข้อมูลนี้');
    }

    public function apply(Request $request, AgentChangeSet $agentChange): RedirectResponse
    {
        $this->assertAdmin($request);
        try {
            $this->changes->apply($agentChange, $request->user());
        } catch (ValidationException|ConflictHttpException) {
            return back()->with('error', 'ใช้ชุดการเปลี่ยนแปลงไม่ได้ เพราะข้อมูลหรือเวอร์ชันเปลี่ยนไปแล้ว ไม่มีรายการใดถูกบันทึก');
        }

        return back()->with('success', 'บันทึกชุดการเปลี่ยนแปลงครบถ้วนแล้ว รายการใหม่ยังเป็น draft');
    }

    public function bulkApply(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);
        $data = $request->validate([
            'change_sets' => ['required', 'array', 'min:1', 'max:25'],
            'change_sets.*' => ['required', 'uuid', 'distinct'],
        ]);

        try {
            $applied = $this->changes->applyMany($data['change_sets'], $request->user());
        } catch (ValidationException|ConflictHttpException) {
            return back()->with('error', 'ยังใช้ชุดที่เลือกไม่ได้ เพราะข้อมูลหรือสถานะเปลี่ยนไปแล้ว ไม่มีข้อมูลธุรกิจชุดใดถูกบันทึก');
        }

        return back()->with('success', 'บันทึกชุดการเปลี่ยนแปลง '.count($applied).' ชุดครบถ้วนแล้ว รายการใหม่ยังเป็น draft');
    }

    public function reject(Request $request, AgentChangeSet $agentChange): RedirectResponse
    {
        $this->assertAdmin($request);
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000']]);
        try {
            $this->changes->reject($agentChange, $request->user(), $data['review_note'] ?? null);
        } catch (ValidationException) {
            return back()->with('error', 'ปฏิเสธชุดการเปลี่ยนแปลงนี้ไม่ได้ เพราะสถานะถูกเปลี่ยนแล้ว');
        }

        return back()->with('success', 'ปฏิเสธข้อเสนอแล้ว โดยไม่มีข้อมูลธุรกิจถูกแก้ไข');
    }

    private function assertAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }

    /** @return array<string, mixed> */
    private function summary(AgentChangeSet $set): array
    {
        return [
            'id' => $set->id,
            'status' => $set->status,
            'operation_count' => $set->operation_count,
            'created_at' => $set->created_at?->toIso8601String(),
            'reviewed_at' => $set->reviewed_at?->toIso8601String(),
            'operations' => $set->operations->map(fn ($operation) => ['entity' => $operation->entity_type, 'action' => $operation->action])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(AgentChangeSet $set): array
    {
        return array_replace($this->summary($set), [
            'review_note' => $set->review_note,
            'schema_versions' => $set->schema_versions,
            'applied_at' => $set->applied_at?->toIso8601String(),
            'suspended_at' => $set->suspended_at?->toIso8601String(),
            'operations' => $set->operations->map(fn ($operation) => [
                'sequence' => $operation->sequence,
                'entity' => $operation->entity_type,
                'action' => $operation->action,
                'target_id' => $operation->target_id,
                'client_ref' => $operation->client_ref,
                'parent_client_ref' => $operation->parent_client_ref,
                'expected_version' => $operation->expected_version,
                'payload' => $operation->payload,
                'sources' => $operation->sources,
                'preview' => $operation->preview,
            ])->values(),
        ]);
    }
}
