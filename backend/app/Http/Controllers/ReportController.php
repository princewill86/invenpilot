<?php

namespace App\Http\Controllers;

use App\Services\Reporting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function dashboard(Request $request, Reporting $reporting): JsonResponse
    {
        $filters = $this->filters($request);
        $filters['to'] ??= today()->toDateString();
        $filters['from'] ??= today()->parse($filters['to'])->subDays(29)->toDateString();

        return response()->json($reporting->dashboard($request->attributes->get('business')->id, $filters));
    }

    public function show(Request $request, Reporting $reporting, int $business, string $report): JsonResponse|StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $reporting->report($request->attributes->get('business')->id, $report, $filters);
        if (($filters['format'] ?? 'json') === 'json') {
            return response()->json($query->paginate(50));
        }

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');
            $first = true;
            foreach ($query->cursor() as $row) {
                $columns = (array) $row;
                if ($first) {
                    fputcsv($output, array_keys($columns), escape: '');
                    $first = false;
                }
                // Neutralize spreadsheet formulas in business-supplied text fields.
                $fields = array_map(static fn ($value) => is_string($value) && ! is_numeric($value) && preg_match('/^[\s]*[=+@-]|^[\t\r\n]/', $value)
                    ? "'".$value : $value, $columns);
                fputcsv($output, $fields, escape: '');
            }
            fclose($output);
        }, $report.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'search' => ['nullable', 'string', 'max:160'],
            'format' => ['nullable', 'in:json,csv'],
        ]);
    }
}
