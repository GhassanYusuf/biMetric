<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\Employees\DuplicateScanService;
use App\Services\Employees\EmployeeMergeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only duplicate scanning.
 *
 * Every endpoint here is a READ, and authorises on view/viewAny rather than the
 * update+delete pair the merge itself needs. That is deliberate: the Scan button
 * is offered to every user who can see the roster, because noticing the roster
 * is wrong does not require permission to fix it — and seeing what a merge would
 * do is exactly the step that stops somebody merging the wrong two records.
 *
 * NOTHING IS WRITTEN AND NO DEVICE IS CONTACTED. The only write in this
 * workstream is POST employees/merge, which has not changed.
 */
class DuplicateScanController extends Controller
{
    public function __construct(
        private readonly DuplicateScanService $scan,
        private readonly EmployeeMergeService $merger,
    ) {}

    /**
     * The whole roster: every candidate group, strongest evidence first.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        return ApiResponse::success($this->scan->scan(), 'Scan complete.');
    }

    /**
     * Only the groups this employee belongs to — what the row menu's Scan does.
     *
     * An employee with no duplicates returns an empty list rather than an error,
     * because "we looked and found nothing" is the answer the operator wants.
     */
    public function forEmployee(Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        return ApiResponse::success($this->scan->scan($employee->id), 'Scan complete.');
    }

    /**
     * What folding these records into that one would actually change.
     *
     * Answered separately from the scan because it depends on which record is
     * kept, and that is the operator's choice — switching the keeper must change
     * what is shown, or the screen would be promising the wrong fields.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'keep_id' => ['required', 'integer'],
            'merge_ids' => ['required', 'array', 'min:1'],
            'merge_ids.*' => ['integer'],
        ]);

        // Tenant-scoped lookups: another company's employee id cannot be named
        // here, so another company's profile cannot be read through this form.
        //
        // A record the scope hides is reported as MISSING rather than quietly
        // dropped. Dropping it answers "merging would change nothing" when the
        // truth is "one of those records is not yours", and the operator would
        // go on believing the preview covered what they actually asked about.
        $ids = array_values(array_unique(array_filter(
            $data['merge_ids'],
            fn ($id) => (int) $id !== (int) $data['keep_id'],
        )));

        $keeper = Employee::findOrFail($data['keep_id']);
        $folded = Employee::whereIn('id', $ids)->get();

        if ($folded->count() !== count($ids)) {
            abort(404, 'One of the records to merge no longer exists.');
        }

        $this->authorize('view', $keeper);
        foreach ($folded as $employee) {
            $this->authorize('view', $employee);
        }

        return ApiResponse::success(
            $this->merger->preview($keeper->id, $folded->pluck('id')->all()),
        );
    }
}
