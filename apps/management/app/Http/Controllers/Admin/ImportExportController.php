<?php

namespace App\Http\Controllers\Admin;

use App\Exports\PackagesExport;
use App\Exports\TemplateExport;
use App\Http\Controllers\Controller;
use App\Imports\PackagesImport;
use App\Models\ImportRecord;
use App\Models\ServicePackage;
use App\Services\PackageImportPreview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ImportExportController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ServicePackage::class);

        $history = ImportRecord::query()
            ->where('resource', 'packages')
            ->latest()
            ->limit(20)
            ->get(['id', 'filename', 'status', 'rows_imported', 'rows_failed', 'rows_skipped', 'created_at'])
            ->map(fn (ImportRecord $record) => [
                'id' => $record->id,
                'filename' => $record->filename,
                'status' => $record->status,
                'rows_imported' => $record->rows_imported,
                'rows_failed' => $record->rows_failed,
                'rows_skipped' => $record->rows_skipped,
                'created_at' => $record->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('Imports/Index', [
            'columns' => PackageImportPreview::COLUMNS,
            'history' => $history,
            'preview' => $request->session()->get('package_import_preview'),
        ]);
    }

    public function preview(Request $request, PackageImportPreview $previewer): RedirectResponse
    {
        Gate::authorize('viewAny', ServicePackage::class);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ]);

        $file = $validated['file'];
        $token = (string) Str::uuid();
        $extension = strtolower($file->getClientOriginalExtension() ?: 'xlsx');
        $storedPath = $file->storeAs('import-previews', "{$token}.{$extension}", 'local');

        try {
            $preview = $previewer->analyze(Storage::disk('local')->path($storedPath));
        } catch (Throwable $e) {
            Storage::disk('local')->delete($storedPath);
            Log::warning('Package import preview failed', ['failure_class' => $e::class]);

            return redirect()->route('admin.imports.index')->with('error', $e->getMessage());
        }

        $batch = [
            'token' => $token,
            'path' => $storedPath,
            'filename' => $file->getClientOriginalName(),
            'created_at' => now()->toIso8601String(),
        ];

        $request->session()->put("package_imports.{$token}", $batch);

        return redirect()->route('admin.imports.index')->with('package_import_preview', $preview + [
            'token' => $token,
            'filename' => $batch['filename'],
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', ServicePackage::class);

        $validated = $request->validate(['token' => ['required', 'uuid']]);
        $batch = $request->session()->pull("package_imports.{$validated['token']}");

        if (! is_array($batch) || ! Storage::disk('local')->exists($batch['path'])) {
            return redirect()->route('admin.imports.index')->with('error', 'ไฟล์ตรวจสอบหมดอายุ กรุณาเลือกไฟล์และตรวจสอบใหม่');
        }

        try {
            $import = new PackagesImport();
            DB::transaction(fn () => Excel::import($import, Storage::disk('local')->path($batch['path'])));

            $errors = collect($import->failures())->map(fn ($failure) => [
                'row' => $failure->row(),
                'attribute' => $failure->attribute(),
                'errors' => $failure->errors(),
                'values' => $failure->values(),
            ])->values()->all();

            ImportRecord::create([
                'user_id' => $request->user()?->id,
                'resource' => 'packages',
                'filename' => $batch['filename'],
                'status' => 'completed',
                'rows_imported' => $import->imported,
                'rows_failed' => count($errors),
                'rows_skipped' => $import->skipped,
                'errors' => $errors,
            ]);

            return redirect()->route('admin.imports.index')->with(
                'success',
                "เพิ่ม {$import->imported} รายการเป็นฉบับร่าง, ข้ามรหัสซ้ำ {$import->skipped} รายการ, ผิดพลาด ".count($errors).' รายการ'
            );
        } catch (Throwable $e) {
            Log::error('Package import failed', ['failure_class' => $e::class]);

            return redirect()->route('admin.imports.index')->with('error', 'นำเข้าไม่สำเร็จและไม่มีการเขียนทับข้อมูลเดิม');
        } finally {
            Storage::disk('local')->delete($batch['path']);
        }
    }

    public function cancel(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', ServicePackage::class);

        $validated = $request->validate(['token' => ['required', 'uuid']]);
        $batch = $request->session()->pull("package_imports.{$validated['token']}");

        if (is_array($batch)) {
            Storage::disk('local')->delete($batch['path']);
        }

        return redirect()->route('admin.imports.index')->with('success', 'ยกเลิกแล้ว ไม่มีข้อมูลถูกเพิ่ม');
    }

    public function template(): BinaryFileResponse
    {
        Gate::authorize('viewAny', ServicePackage::class);

        return Excel::download(new TemplateExport(PackageImportPreview::COLUMNS), 'packages-promotions-template.xlsx');
    }

    public function export(): BinaryFileResponse
    {
        Gate::authorize('viewAny', ServicePackage::class);

        return Excel::download(new PackagesExport(), 'packages-promotions-'.now()->toDateString().'.xlsx');
    }
}
