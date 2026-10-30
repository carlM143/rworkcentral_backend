<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\TimeLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class TimeLogImportController extends Controller
{
    private const REQUIRED_COLUMNS = [
        'emp_id',
        'work_date',
        'time_in',
        'time_out',
        'break_out',
        'break_in',
    ];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'replace_existing' => ['nullable', 'boolean'],
        ]);

        $file = $validated['file'];

        if (strtolower($file->getClientOriginalExtension()) !== 'csv') {
            return response()->json([
                'success' => false,
                'message' => 'Please upload a CSV file.',
            ], 422);
        }

        $replaceExisting = $request->boolean('replace_existing');

        $handle = fopen($file->getRealPath(), 'r');

        if (!$handle) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to read the CSV file.',
            ], 422);
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');

            if (!$header) {
                return response()->json([
                    'success' => false,
                    'message' => 'CSV file is empty.',
                ], 422);
            }

            $header = array_map(function ($column) {
                return strtolower(trim(
                    preg_replace('/^\xEF\xBB\xBF/', '', (string) $column)
                ));
            }, $header);

            if (
                count($header) !== count(array_unique($header)) ||
                array_diff(self::REQUIRED_COLUMNS, $header)
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid CSV headers. Required: ' .
                        implode(', ', self::REQUIRED_COLUMNS),
                ], 422);
            }

            $imported = 0;
            $updated = 0;
            $duplicates = 0;
            $invalid = 0;
            $processed = 0;
            $errors = [];
            $seen = [];
            $employeeCache = [];

            DB::beginTransaction();

            try {
                $line = 1;

                while (
                    ($columns = fgetcsv($handle, 0, ',', '"', ''))
                    !== false
                ) {
                    $line++;

                    if (
                        count($columns) === 1 &&
                        trim((string) $columns[0]) === ''
                    ) {
                        continue;
                    }

                    $processed++;

                    if ($processed > 10000) {
                        throw new \RuntimeException(
                            'CSV cannot exceed 10,000 attendance records.'
                        );
                    }

                    if (count($columns) !== count($header)) {
                        $invalid++;
                        $errors[] = "Row {$line}: Incorrect column count.";
                        continue;
                    }

                    $row = array_combine($header, $columns);

                    $row = array_map(
                        fn ($value) => trim((string) $value),
                        $row
                    );

                    $validation = Validator::make($row, [
                        'emp_id' => ['required', 'string', 'max:100'],
                        'work_date' => ['required', 'date_format:Y-m-d'],
                        'time_in' => ['nullable', 'string'],
                        'time_out' => ['nullable', 'string'],
                        'break_out' => ['nullable', 'string'],
                        'break_in' => ['nullable', 'string'],
                    ]);

                    if ($validation->fails()) {
                        $invalid++;
                        $errors[] = "Row {$line}: " .
                            $validation->errors()->first();
                        continue;
                    }

                    $employeeNumber = $row['emp_id'];
                    $date = $row['work_date'];

                    $key = $employeeNumber . '|' . $date;

                    if (isset($seen[$key])) {
                        $duplicates++;
                        continue;
                    }

                    $seen[$key] = true;

                    if (!array_key_exists($employeeNumber, $employeeCache)) {
                        $employeeCache[$employeeNumber] = Employee::query()
                            ->where('emp_id', $employeeNumber)
                            ->first();
                    }

                    $employee = $employeeCache[$employeeNumber];

                    if (!$employee) {
                        $invalid++;
                        $errors[] = "Row {$line}: Employee {$employeeNumber} not found.";
                        continue;
                    }

                    $timeIn = $this->parseDateTime($date, $row['time_in']);
                    $timeOut = $this->parseDateTime($date, $row['time_out']);
                    $breakOut = $this->parseDateTime($date, $row['break_out']);
                    $breakIn = $this->parseDateTime($date, $row['break_in']);

                    if (
                        ($row['time_in'] !== '' && !$timeIn) ||
                        ($row['time_out'] !== '' && !$timeOut) ||
                        ($row['break_out'] !== '' && !$breakOut) ||
                        ($row['break_in'] !== '' && !$breakIn)
                    ) {
                        $invalid++;
                        $errors[] = "Row {$line}: Invalid time format.";
                        continue;
                    }

                    if (
                        ($breakOut && !$breakIn) ||
                        (!$breakOut && $breakIn)
                    ) {
                        $invalid++;
                        $errors[] = "Row {$line}: Both break times are required.";
                        continue;
                    }

                    if ($timeIn && $timeOut && $timeOut->lt($timeIn)) {
                        $invalid++;
                        $errors[] = "Row {$line}: Time Out precedes Time In.";
                        continue;
                    }

                    if ($breakOut && $breakIn) {
                        if (
                            $breakIn->lte($breakOut) ||
                            !$timeIn ||
                            !$timeOut ||
                            $breakOut->lt($timeIn) ||
                            $breakIn->gt($timeOut)
                        ) {
                            $invalid++;
                            $errors[] = "Row {$line}: Invalid break interval.";
                            continue;
                        }
                    }

                    $late = 0;
                    $undertime = 0;
                    $worked = 0;

                    // Temporary day schedule for demo records.
                    // Replace with assigned work schedule later.
                    $scheduledIn = Carbon::parse("$date 08:00:00");
                    $scheduledOut = Carbon::parse("$date 17:00:00");

                    if ($timeIn && $timeIn->gt($scheduledIn)) {
                        $late = (int) $scheduledIn
                            ->diffInMinutes($timeIn, true);
                    }

                    if ($timeOut && $timeOut->lt($scheduledOut)) {
                        $undertime = (int) $timeOut
                            ->diffInMinutes($scheduledOut, true);
                    }

                    if ($timeIn && $timeOut) {
                        $worked = (int) $timeIn
                            ->diffInMinutes($timeOut, true);

                        if ($breakOut && $breakIn) {
                            $worked -= (int) $breakOut
                                ->diffInMinutes($breakIn, true);
                        }

                        $worked = max(0, $worked);
                    }

                    if (!$timeIn && !$timeOut) {
                        $status = 'Missing';
                    } elseif (!$timeIn || !$timeOut) {
                        $status = 'Incomplete';
                    } elseif ($late > 0) {
                        $status = 'Late';
                    } elseif ($undertime > 0) {
                        $status = 'Undertime';
                    } else {
                        $status = 'Complete';
                    }

                    $existing = TimeLog::query()
                        ->where('employee_id', $employee->id)
                        ->whereDate('work_date', $date)
                        ->first();

                    if ($existing && !$replaceExisting) {
                        $duplicates++;
                        continue;
                    }

                    $values = [
                        'time_in' => $timeIn,
                        'time_out' => $timeOut,
                        'break_out' => $breakOut,
                        'break_in' => $breakIn,
                        'late_minutes' => $late,
                        'undertime_minutes' => $undertime,
                        'worked_minutes' => $worked,
                        'overtime_minutes' => 0,
                        'source' => 'biometric_import',
                        'status' => $status,
                        'remarks' => 'Imported from biometric attendance CSV.',
                    ];

                    if ($existing) {
                        $existing->update($values);
                        $updated++;
                    } else {
                        TimeLog::create(array_merge(
                            [
                                'employee_id' => $employee->id,
                                'work_date' => $date,
                            ],
                            $values
                        ));

                        $imported++;
                    }
                }

                DB::commit();
            } catch (Throwable $exception) {
                DB::rollBack();
                throw $exception;
            }

            return response()->json([
                'success' => true,
                'message' => 'Attendance CSV processed successfully.',
                'data' => [
                    'imported' => $imported,
                    'updated' => $updated,
                    'duplicates' => $duplicates,
                    'invalid' => $invalid,
                    'processed' => $processed,
                    'errors' => array_slice($errors, 0, 50),
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to process CSV. Check the file format and try again.',
            ], 422);
        } finally {
            fclose($handle);
        }
    }

    private function parseDateTime(
        string $date,
        string $time
    ): ?Carbon {
        if ($time === '') {
            return null;
        }

        $formats = [
            'Y-m-d H:i',
            'Y-m-d H:i:s',
            'Y-m-d g:i A',
            'Y-m-d h:i A',
            'Y-m-d g:i:s A',
        ];

        foreach ($formats as $format) {
            try {
                $value = Carbon::createFromFormat(
                    '!' . $format,
                    $date . ' ' . strtoupper($time)
                );

                if (
                    $value &&
                    $value->format($format) ===
                    ($date . ' ' . strtoupper($time))
                ) {
                    return $value;
                }
            } catch (Throwable) {
                // Try another supported format.
            }
        }

        return null;
    }
}
