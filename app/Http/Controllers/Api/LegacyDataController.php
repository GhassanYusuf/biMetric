<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Import\ZkaccessImportService;
use App\Services\Import\ZkaccessMdbReader;
use App\Services\Import\ZkaccessSnapshotExportService;
use App\Support\ApiResponse;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * GUI for the ZKAccess legacy import and the backup snapshot export.
 *
 * The pipeline is exactly the CLI's (ZkaccessImportService) but file-in, not
 * path-in: preview parses the upload to canonical JSON and runs a dry run;
 * apply re-reads that stored canonical JSON and runs for real. Storing the
 * canonical JSON between the two calls means the dry run report and the apply
 * run provably describe the same bytes — a re-upload between preview and apply
 * cannot silently change what gets written.
 *
 * Import DEMANDS an acting tenant: the legacy file is a per-company
 * attendance archive and can only be replayed into one company's data. A
 * super-admin spanning all tenants gets a telling 422 instead of a guess.
 *
 * Export is a snapshot download: current departments, machines, users and
 * punches in the same canonical shape the importer consumes, so a backup made
 * here can be restored here (or in another tenant) at any later date.
 */
class LegacyDataController extends Controller
{
    /**
     * Legacy snapshots live in storage/app/legacy/imports as <token>.json.
     */
    private const IMPORT_DIR = 'legacy/imports';

    /**
     * @var array<int,string> TTL for staged imports.
     */
    private const STAGED_IMPORT_TTL_DAYS = 7;

    public function __construct(
        private readonly ZkaccessMdbReader $reader,
        private readonly ZkaccessSnapshotExportService $snapshot,
    ) {}

    /** Parses the upload and runs a dry run. Stores the canonical JSON under a token. */
    public function previewImport(Request $request): Response
    {
        $this->authorize('create', Employee::class);

        $tenantId = Tenancy::id();
        if ($tenantId === null) {
            return ApiResponse::error(
                'Pick one company first: imports replay a legacy archive into a single company and cannot span all tenants.',
                [],
                422,
            );
        }

        $request->validate([
            'file' => ['required', 'file', 'max:102400'],
        ]);

        $file = $request->file('file');
        $original = (string) $file->getClientOriginalName();
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (! in_array($ext, ['mdb', 'json'], true)) {
            return ApiResponse::error('Upload a ZKAccess .mdb backup or a .json snapshot.', [], 422);
        }

        $token = Str::lower(Str::random(24));
        $stored = $file->storeAs(self::IMPORT_DIR, $token.'.'.$ext, 'local');
        if ($stored === false) {
            return ApiResponse::error('Could not store the upload.', [], 500);
        }

        $path = Storage::disk('local')->path(self::IMPORT_DIR.'/'.$token.'.'.$ext);

        try {
            $canonical = $ext === 'mdb'
                ? $this->reader->read($path)
                : $this->decodeJsonSnapshot($path);
        } catch (Throwable $e) {
            Storage::disk('local')->delete(self::IMPORT_DIR.'/'.$token.'.'.$ext);

            return ApiResponse::error($e->getMessage(), [], 422);
        }

        $jsonPath = self::IMPORT_DIR.'/'.$token.'.json';
        Storage::disk('local')->put($jsonPath, json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // The raw .mdb (tens of MB) is no longer needed once canonically parsed.
        if ($ext === 'mdb') {
            Storage::disk('local')->delete(self::IMPORT_DIR.'/'.$token.'.'.$ext);
        }

        try {
            $report = (new ZkaccessImportService)
                ->setData($canonical)
                ->setTenant($tenantId)
                ->setApply(false)
                ->run();
        } catch (Throwable $e) {
            Storage::disk('local')->delete($jsonPath);

            return ApiResponse::error('The dry run failed: '.$e->getMessage(), [], 422);
        }

        $this->pruneStaged();

        $userCount = count($canonical['users'] ?? []);
        $punchCount = count($canonical['punches'] ?? []);

        return ApiResponse::success(
            [
                'token' => $token,
                'source' => (string) ($canonical['source'] ?? $original),
                'counts' => [
                    'departments' => count($canonical['departments'] ?? []),
                    'machines' => count($canonical['machines'] ?? []),
                    'users' => $userCount,
                    'punches' => $punchCount,
                ],
                'report' => $report,
            ],
            "Parsed $userCount users and $punchCount punches. Dry run finished — apply when ready.",
        );
    }

    /** Applies the staged canonical snapshot the token was minted for. */
    public function applyImport(Request $request, string $token): Response
    {
        $this->authorize('create', Employee::class);

        $tenantId = Tenancy::id();
        if ($tenantId === null) {
            return ApiResponse::error('Pick one company first: imports replay a legacy archive into a single company.', [], 422);
        }

        if (! preg_match('/^[a-z0-9]{24}$/', $token)) {
            return ApiResponse::error('That import token is not valid.', [], 422);
        }

        $path = self::IMPORT_DIR.'/'.$token.'.json';
        if (! Storage::disk('local')->exists($path)) {
            return ApiResponse::error('That import has expired. Re-upload the file.', [], 404);
        }

        $raw = Storage::disk('local')->get($path);
        try {
            $canonical = json_decode(is_string($raw) ? $raw : '', true, 512, JSON_THROW_ON_ERROR);
            $this->assertSnapshotShape($canonical);
        } catch (Throwable $e) {
            return ApiResponse::error('The staged import is unreadable: '.$e->getMessage(), [], 422);
        }

        try {
            $report = (new ZkaccessImportService)
                ->setData($canonical)
                ->setTenant($tenantId)
                ->setApply(true)
                ->run();
        } catch (Throwable $e) {
            return ApiResponse::error('The import failed: '.$e->getMessage(), [], 422);
        }

        $inserted = (int) ($report['attendance']['inserted'] ?? 0);
        $created = (int) ($report['employees']['created'] ?? 0);

        return ApiResponse::success(
            ['report' => $report],
            "Done: {$created} employees created, $inserted attendance rows inserted (re-runs are safe).",
        );
    }

    /** Downloads the current state as a re-importable canonical JSON snapshot. */
    public function exportSnapshot(Request $request): Response
    {
        $this->authorize('viewAny', Employee::class);

        $tenantId = Tenancy::id();
        if ($tenantId === null) {
            return ApiResponse::error('Pick one company first: a snapshot belongs to one company.', [], 422);
        }

        $data = $this->snapshot->snapshot($tenantId);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $filename = 'zkaccess-snapshot-'.$tenantId.'-'.date('Y-m-d-His').'.json';

        return response($json, 200)
            ->header('Content-Type', 'application/json; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"')
            ->header('X-Snapshot-Counts', implode('/', [
                count($data['departments'] ?? []),
                count($data['machines'] ?? []),
                count($data['users'] ?? []),
                count($data['punches'] ?? []),
            ]));
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonSnapshot(string $path): array
    {
        $raw = (string) file_get_contents($path);
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('That .json is not valid JSON.');
        }

        $this->assertSnapshotShape($decoded);

        return $decoded;
    }

    private function assertSnapshotShape(mixed $snapshot): void
    {
        if (! is_array($snapshot)) {
            throw new RuntimeException('Not a snapshot: expected a JSON object.');
        }
        foreach (['source', 'exported_at', 'departments', 'machines', 'users', 'punches'] as $key) {
            if (! array_key_exists($key, $snapshot)) {
                throw new RuntimeException("Not a ZKAccess snapshot: missing \"{$key}\".");
            }
        }
        foreach (['departments', 'machines', 'users', 'punches'] as $key) {
            if (! is_array($snapshot[$key])) {
                throw new RuntimeException("\"{$key}\" must be a list.");
            }
        }
    }

    /** Deletes staged imports older than TTL so the import dir cannot fill disk. */
    private function pruneStaged(): void
    {
        $cutoff = now()->subDays(self::STAGED_IMPORT_TTL_DAYS);
        foreach (Storage::disk('local')->files(self::IMPORT_DIR) as $file) {
            if (Storage::disk('local')->lastModified($file) < $cutoff->timestamp) {
                Storage::disk('local')->delete($file);
            }
        }
    }
}
