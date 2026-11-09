<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\ErrorLogReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The Error logs screen (owner, 2026-11-09). Reads the server, terminal and browser logs
 * through ErrorLogReader; nothing is stored or changed.
 *
 * Needs logs.view: stack traces name files, servers and terminal addresses, so it is an
 * administrator's screen. The log files are the whole installation's, not one company
 * account's, so a user limited to some companies (CompanyAccess) is refused as well.
 */
class ErrorLogController extends Controller
{
    public function index(Request $request, ErrorLogReader $reader): JsonResponse
    {
        abort_unless($request->user()->can('logs.view'), 403, 'This action is unauthorized.');
        abort_if(\App\Support\CompanyAccess::limited(), 403, 'The error logs cover every company, so a user limited to some companies cannot see them.');

        $f = $request->validate([
            'source' => ['nullable', Rule::in(ErrorLogReader::SOURCES)],
            'level' => ['nullable', Rule::in(ErrorLogReader::LEVELS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:200'],
            'group' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $entries = $reader->entries($f);
        $rows = $request->boolean('group', true) ? $reader->grouped($entries) : $entries;

        $perPage = (int) ($f['per_page'] ?? 50);
        $page = (int) ($f['page'] ?? 1);
        $total = count($rows);

        $counts = ['server' => 0, 'device' => 0, 'browser' => 0, 'error' => 0, 'warning' => 0];
        foreach ($entries as $e) {
            $counts[$e['source']]++;
            $counts[in_array($e['level'], ['emergency', 'alert', 'critical', 'error'], true) ? 'error' : 'warning']++;
        }

        return ApiResponse::success(array_slice($rows, ($page - 1) * $perPage, $perPage), 'Error logs read.', [
            'grouped' => $request->boolean('group', true),
            'entries' => count($entries),
            'counts' => $counts,
            'pagination' => [
                'current_page' => $page, 'per_page' => $perPage, 'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'has_more' => $page * $perPage < $total,
            ],
        ]);
    }
}
