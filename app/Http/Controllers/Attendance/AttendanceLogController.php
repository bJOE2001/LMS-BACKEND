<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Attendance\AttendanceRawLog;
use App\Models\Attendance\BiometricDevice;
use App\Models\Attendance\DailyTimeRecord;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\HrisOffice;
use App\Services\Attendance\DtrCalculationService;
use App\Traits\FiltersEmployeeControlNos;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AttendanceLogController extends Controller
{
    use FiltersEmployeeControlNos;

    /** @var array<int, string> */
    private const VERIFY_TYPE_LABELS = [
        AttendanceRawLog::VERIFY_PASSWORD => 'Password',
        AttendanceRawLog::VERIFY_FINGERPRINT => 'Fingerprint',
        AttendanceRawLog::VERIFY_CARD => 'Card',
        AttendanceRawLog::VERIFY_FACE => 'Face',
    ];

    /** @var array<int, string> */
    private const ALLOWED_SORT_COLUMNS = [
        'punch_time',
        'id',
        'employee_control_no',
        'biometric_pin',
        'device_serial_number',
        'sync_source',
        'punch_state',
        'verify_type',
        'processed_at',
        'voided_at',
    ];

    /**
     * Retrieve paginated raw biometric attendance punch logs for HR monitoring.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'device_serial_number' => ['nullable', 'string', 'max:50'],
            'department_id' => ['nullable', 'integer', 'min:1'],
            'sync_source' => ['nullable', 'string', 'max:20'],
            'punch_state' => ['nullable', 'integer', 'min:0', 'max:255'],
            'verify_type' => ['nullable', 'integer', 'min:0', 'max:50'],
            'processed' => ['nullable', 'in:all,processed,unprocessed'],
            'status' => ['nullable', 'in:active,voided,all'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'sort_by' => ['nullable', 'in:'.implode(',', self::ALLOWED_SORT_COLUMNS)],
            'sort_dir' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $devices = BiometricDevice::query()
            ->with(['department:id,name'])
            ->orderBy('device_name')
            ->orderBy('serial_number')
            ->get();

        $deviceDirectory = $this->buildDeviceDirectory($devices);

        $query = AttendanceRawLog::query();
        $this->applyFilters($query, $validated, $deviceDirectory['by_serial']);

        $sortBy = (string) ($validated['sort_by'] ?? 'punch_time');
        $sortDir = (string) ($validated['sort_dir'] ?? 'desc');
        $query->orderBy($sortBy, $sortDir);
        if ($sortBy !== 'id') {
            $query->orderByDesc('id');
        }

        $perPage = (int) ($validated['per_page'] ?? 20);
        $logs = $query->paginate($perPage);

        $pageLogs = collect($logs->items());
        $employeeDirectory = $this->loadEmployeeDirectoryForLogs($pageLogs);
        $voidedByNames = $this->loadVoidedByNames($pageLogs);

        $mappedLogs = $pageLogs->map(
            fn (AttendanceRawLog $log): array => $this->formatAttendanceLog(
                $log,
                $deviceDirectory['by_serial'],
                $employeeDirectory,
                $voidedByNames
            )
        )->values();

        return response()->json([
            'logs' => $mappedLogs,
            'total' => $logs->total(),
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'per_page' => $logs->perPage(),
            'filters' => [
                'devices' => $deviceDirectory['device_options'],
                'offices' => $deviceDirectory['office_options'],
            ],
        ]);
    }

    /**
     * Soft-void an erroneous raw biometric punch and recalculate that employee's DTR.
     */
    public function voidLog(
        Request $request,
        int $id,
        DtrCalculationService $dtrCalculationService
    ): JsonResponse {
        $user = $request->user();
        if (! ($user instanceof HRAccount)) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'void_reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $reason = trim((string) $validated['void_reason']);
        if (mb_strlen($reason) < 3) {
            return response()->json([
                'message' => 'Void reason must be at least 3 characters.',
                'errors' => [
                    'void_reason' => ['Void reason must be at least 3 characters.'],
                ],
            ], 422);
        }

        $log = AttendanceRawLog::query()->find($id);
        if (! $log) {
            return response()->json(['message' => 'Punch log not found.'], 404);
        }

        if ($log->isVoided()) {
            return response()->json(['message' => 'Punch log is already voided.'], 422);
        }

        $log->update([
            'voided_at' => now(),
            'voided_by_hr_account_id' => $user->id,
            'void_reason' => $reason,
        ]);

        $log->refresh();
        $dtr = $this->recalculateDtrForLog($log, $dtrCalculationService);
        $isAdjusted = (bool) ($dtr?->is_adjusted ?? false);

        $message = $isAdjusted
            ? 'Punch log voided. Note: this date has a manual DTR adjustment that was preserved.'
            : ($dtr !== null
                ? 'Punch log voided and DTR recalculated.'
                : 'Punch log voided.');

        return response()->json([
            'message' => $message,
            'log' => $this->formatSingleLog($log),
            'dtr_recalculated' => $dtr !== null,
            'dtr_is_adjusted' => $isAdjusted,
        ]);
    }

    /**
     * Restore a previously voided raw biometric punch and recalculate that employee's DTR.
     */
    public function restoreLog(
        Request $request,
        int $id,
        DtrCalculationService $dtrCalculationService
    ): JsonResponse {
        $user = $request->user();
        if (! ($user instanceof HRAccount)) {
            abort(403, 'Unauthorized.');
        }

        $log = AttendanceRawLog::query()->find($id);
        if (! $log) {
            return response()->json(['message' => 'Punch log not found.'], 404);
        }

        if (! $log->isVoided()) {
            return response()->json(['message' => 'Punch log is not voided.'], 422);
        }

        $log->update([
            'voided_at' => null,
            'voided_by_hr_account_id' => null,
            'void_reason' => null,
        ]);

        $log->refresh();
        $dtr = $this->recalculateDtrForLog($log, $dtrCalculationService);
        $log->refresh();
        $isAdjusted = (bool) ($dtr?->is_adjusted ?? false);

        $message = $isAdjusted
            ? 'Punch log restored. Note: this date has a manual DTR adjustment that was preserved.'
            : ($dtr !== null
                ? 'Punch log restored and DTR recalculated.'
                : 'Punch log restored.');

        return response()->json([
            'message' => $message,
            'log' => $this->formatSingleLog($log),
            'dtr_recalculated' => $dtr !== null,
            'dtr_is_adjusted' => $isAdjusted,
        ]);
    }

    /**
     * Build device lookup metadata and filter dropdown options enriched with HRIS office acronyms.
     *
     * @param  Collection<int, BiometricDevice>  $devices
     * @return array{
     *   by_serial: array<string, array{serial_number: string, device_name: string, department_id: ?int, office_name: ?string, office_acronym: ?string}>,
     *   device_options: array<int, array{serial_number: string, device_name: string, department_id: ?int, office_name: ?string, office_acronym: ?string}>,
     *   office_options: array<int, array{department_id: int, office_name: string, office_acronym: ?string}>
     * }
     */
    private function buildDeviceDirectory(Collection $devices): array
    {
        $bySerial = [];
        $deviceOptions = [];
        $officesById = [];

        foreach ($devices as $device) {
            $serial = trim((string) $device->serial_number);
            if ($serial === '') {
                continue;
            }

            $officeName = $device->department?->name ?: ($device->department_name ?: null);
            $officeAcronym = HrisOffice::acronymForDepartment($device->department, $device->department_name);
            $deviceName = trim((string) ($device->device_name ?: $serial));
            $departmentId = $device->department_id !== null ? (int) $device->department_id : null;

            $entry = [
                'serial_number' => $serial,
                'device_name' => $deviceName,
                'department_id' => $departmentId,
                'office_name' => $officeName,
                'office_acronym' => $officeAcronym,
            ];

            $bySerial[strtoupper($serial)] = $entry;
            $deviceOptions[] = $entry;

            if ($departmentId !== null && $officeName !== null && ! isset($officesById[$departmentId])) {
                $officesById[$departmentId] = [
                    'department_id' => $departmentId,
                    'office_name' => $officeName,
                    'office_acronym' => $officeAcronym,
                ];
            }
        }

        usort(
            $officesById,
            static fn (array $a, array $b): int => strcasecmp(
                (string) ($a['office_acronym'] ?: $a['office_name']),
                (string) ($b['office_acronym'] ?: $b['office_name'])
            )
        );

        return [
            'by_serial' => $bySerial,
            'device_options' => $deviceOptions,
            'office_options' => array_values($officesById),
        ];
    }

    /**
     * Apply validated query filters to the raw attendance log query.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, array{serial_number: string, device_name: string, department_id: ?int, office_name: ?string, office_acronym: ?string}>  $devicesBySerial
     */
    private function applyFilters(Builder $query, array $validated, array $devicesBySerial): void
    {
        if (! empty($validated['device_serial_number'])) {
            $query->where('device_serial_number', trim((string) $validated['device_serial_number']));
        }

        if (isset($validated['department_id'])) {
            $departmentId = (int) $validated['department_id'];
            $matchingSerials = collect($devicesBySerial)
                ->filter(static fn (array $device): bool => $device['department_id'] === $departmentId)
                ->pluck('serial_number')
                ->values()
                ->all();

            if ($matchingSerials === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('device_serial_number', $matchingSerials);
            }
        }

        if (! empty($validated['sync_source'])) {
            $query->where('sync_source', trim((string) $validated['sync_source']));
        }

        if (array_key_exists('punch_state', $validated) && $validated['punch_state'] !== null && $validated['punch_state'] !== '') {
            $query->where('punch_state', (int) $validated['punch_state']);
        }

        if (array_key_exists('verify_type', $validated) && $validated['verify_type'] !== null && $validated['verify_type'] !== '') {
            $query->where('verify_type', (int) $validated['verify_type']);
        }

        $processed = (string) ($validated['processed'] ?? 'all');
        if ($processed === 'processed') {
            $query->whereNotNull('processed_at');
        } elseif ($processed === 'unprocessed') {
            $query->whereNull('processed_at');
        }

        $status = (string) ($validated['status'] ?? 'all');
        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'voided') {
            $query->voided();
        }

        if (! empty($validated['start_date'])) {
            $query->where('punch_time', '>=', CarbonImmutable::parse((string) $validated['start_date'])->startOfDay());
        }

        if (! empty($validated['end_date'])) {
            $query->where('punch_time', '<=', CarbonImmutable::parse((string) $validated['end_date'])->endOfDay());
        }

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $this->applySearchFilter($query, $search, $devicesBySerial);
        }
    }

    /**
     * Match raw log fields, resolved devices/offices, and HRIS employee names safely without N+1 queries.
     *
     * @param  array<string, array{serial_number: string, device_name: string, department_id: ?int, office_name: ?string, office_acronym: ?string}>  $devicesBySerial
     */
    private function applySearchFilter(Builder $query, string $search, array $devicesBySerial): void
    {
        $matchingDeviceSerials = $this->findMatchingDeviceSerials($search, $devicesBySerial);
        $matchingControlNos = $this->findMatchingEmployeeControlNos($search);

        $query->where(function (Builder $nested) use ($search, $matchingDeviceSerials, $matchingControlNos): void {
            $nested->where('employee_control_no', 'like', "%{$search}%")
                ->orWhere('biometric_pin', 'like', "%{$search}%")
                ->orWhere('device_serial_number', 'like', "%{$search}%")
                ->orWhere('sync_source', 'like', "%{$search}%");

            if ($matchingDeviceSerials !== []) {
                $nested->orWhereIn('device_serial_number', $matchingDeviceSerials);
            }

            if ($matchingControlNos !== []) {
                $nested->orWhere(function (Builder $controlQuery) use ($matchingControlNos): void {
                    $this->whereInEmployeeControlNos($controlQuery, $matchingControlNos, 'employee_control_no');
                });
                $nested->orWhere(function (Builder $pinQuery) use ($matchingControlNos): void {
                    $this->whereInEmployeeControlNos($pinQuery, $matchingControlNos, 'biometric_pin');
                });
            }
        });
    }

    /**
     * @param  array<string, array{serial_number: string, device_name: string, department_id: ?int, office_name: ?string, office_acronym: ?string}>  $devicesBySerial
     * @return array<int, string>
     */
    private function findMatchingDeviceSerials(string $search, array $devicesBySerial): array
    {
        $needle = mb_strtolower($search);
        $serials = [];

        foreach ($devicesBySerial as $device) {
            $haystack = mb_strtolower(implode(' ', array_filter([
                $device['serial_number'],
                $device['device_name'],
                $device['office_name'],
                $device['office_acronym'],
            ])));

            if ($haystack !== '' && str_contains($haystack, $needle)) {
                $serials[] = $device['serial_number'];
            }
        }

        return $serials;
    }

    /**
     * Resolve control number candidates from HRIS directory matching a name or control number search.
     *
     * @return array<int, string>
     */
    private function findMatchingEmployeeControlNos(string $search): array
    {
        $needle = mb_strtolower($search);
        $controlNos = [];

        foreach (HrisEmployee::allCached() as $employee) {
            $controlNo = trim((string) ($employee->control_no ?? ''));
            if ($controlNo === '') {
                continue;
            }

            $surname = trim((string) ($employee->surname ?? ''));
            $firstname = trim((string) ($employee->firstname ?? ''));
            $middlename = trim((string) ($employee->middlename ?? ''));

            $haystack = mb_strtolower(implode(' ', array_filter([
                $controlNo,
                $firstname,
                $middlename,
                $surname,
                "{$surname}, {$firstname}",
            ])));

            if (! str_contains($haystack, $needle)) {
                continue;
            }

            $controlNos[$controlNo] = $controlNo;
            if (preg_match('/^\d+$/', $controlNo) === 1) {
                $unpadded = ltrim($controlNo, '0');
                $unpadded = $unpadded !== '' ? $unpadded : '0';
                $controlNos[$unpadded] = $unpadded;
            }
        }

        return array_values($controlNos);
    }

    /**
     * @param  Collection<int, AttendanceRawLog>  $logs
     * @return array<string, object>
     */
    private function loadEmployeeDirectoryForLogs(Collection $logs): array
    {
        $controlNos = $logs
            ->flatMap(static fn (AttendanceRawLog $log): array => [
                $log->employee_control_no,
                $log->biometric_pin,
            ])
            ->filter(static fn (mixed $value): bool => trim((string) ($value ?? '')) !== '')
            ->unique()
            ->values()
            ->all();

        if ($controlNos === []) {
            return [];
        }

        return HrisEmployee::directoryByControlNos($controlNos);
    }

    /**
     * Format a single raw log with device, HRIS employee, and void actor metadata.
     *
     * @return array<string, mixed>
     */
    private function formatSingleLog(AttendanceRawLog $log): array
    {
        $devices = $log->device_serial_number
            ? BiometricDevice::query()
                ->with(['department:id,name'])
                ->where('serial_number', $log->device_serial_number)
                ->get()
            : collect();

        $deviceDirectory = $this->buildDeviceDirectory($devices);
        $singleCollection = collect([$log]);
        $employeeDirectory = $this->loadEmployeeDirectoryForLogs($singleCollection);
        $voidedByNames = $this->loadVoidedByNames($singleCollection);

        return $this->formatAttendanceLog(
            $log,
            $deviceDirectory['by_serial'],
            $employeeDirectory,
            $voidedByNames
        );
    }

    /**
     * Recalculate the DTR for the date of a voided or restored punch when a valid employee or DTR exists.
     */
    private function recalculateDtrForLog(
        AttendanceRawLog $log,
        DtrCalculationService $dtrCalculationService
    ): ?DailyTimeRecord {
        if ($log->punch_time === null) {
            return null;
        }

        $candidate = trim((string) ($log->employee_control_no ?: $log->biometric_pin ?: ''));
        if ($candidate === '') {
            return null;
        }

        $punchDate = $log->punch_time->format('Y-m-d');
        $employee = HrisEmployee::findByControlNo($candidate, true)
            ?? HrisEmployee::findByControlNo($candidate, false);
        $canonicalControlNo = $employee?->control_no ?? trim((string) ($log->employee_control_no ?? ''));

        if ($canonicalControlNo === '') {
            $variants = $dtrCalculationService->resolveControlNoVariants($candidate);
            $hasExistingDtr = DailyTimeRecord::query()
                ->whereIn('employee_control_no', $variants)
                ->where('record_date', $punchDate)
                ->exists();

            if (! $hasExistingDtr) {
                return null;
            }

            $canonicalControlNo = $candidate;
        }

        return $dtrCalculationService->calculateForEmployeeDate(
            $canonicalControlNo,
            $punchDate,
            cachedEmployee: $employee
        );
    }

    /**
     * Batch-load HR account full names for voided attendance logs.
     *
     * @param  Collection<int, AttendanceRawLog>  $logs
     * @return array<int, string>
     */
    private function loadVoidedByNames(Collection $logs): array
    {
        $voidedByIds = $logs
            ->pluck('voided_by_hr_account_id')
            ->filter(static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($voidedByIds === []) {
            return [];
        }

        return HRAccount::query()
            ->whereIn('id', $voidedByIds)
            ->pluck('full_name', 'id')
            ->all();
    }

    /**
     * @param  array<string, array{serial_number: string, device_name: string, department_id: ?int, office_name: ?string, office_acronym: ?string}>  $devicesBySerial
     * @param  array<string, object>  $employeeDirectory
     * @param  array<int, string>  $voidedByNames
     * @return array<string, mixed>
     */
    private function formatAttendanceLog(
        AttendanceRawLog $log,
        array $devicesBySerial,
        array $employeeDirectory,
        array $voidedByNames = []
    ): array {
        $serialKey = strtoupper(trim((string) ($log->device_serial_number ?? '')));
        $device = $serialKey !== '' ? ($devicesBySerial[$serialKey] ?? null) : null;

        $employee = $this->resolveDirectoryEmployee($log, $employeeDirectory);
        $employeeName = $this->formatEmployeeName($employee);
        $designation = $employee ? trim((string) ($employee->designation ?? '')) : '';
        $employeeOfficeName = $employee ? trim((string) ($employee->office ?? '')) : '';
        $employeeOfficeAcronym = $employee
            ? (trim((string) ($employee->officeAcronym ?? '')) ?: HrisOffice::acronymForName($employeeOfficeName))
            : null;

        $verifyType = $log->verify_type !== null ? (int) $log->verify_type : null;

        return [
            'id' => $log->id,
            'punch_time' => $log->punch_time?->toIso8601String(),
            'employee_control_no' => $log->employee_control_no,
            'biometric_pin' => $log->biometric_pin,
            'employee_name' => $employeeName,
            'designation' => $designation !== '' ? $designation : null,
            'employee_office_name' => $employeeOfficeName !== '' ? $employeeOfficeName : null,
            'employee_office_acronym' => $employeeOfficeAcronym,
            'device_serial_number' => $log->device_serial_number,
            'device_name' => $device['device_name'] ?? null,
            'department_id' => $device['department_id'] ?? null,
            'office_name' => $device['office_name'] ?? null,
            'office_acronym' => $device['office_acronym'] ?? null,
            'punch_state' => $log->punch_state !== null ? (int) $log->punch_state : null,
            'verify_type' => $verifyType,
            'verify_type_label' => $verifyType !== null ? (self::VERIFY_TYPE_LABELS[$verifyType] ?? "Mode {$verifyType}") : null,
            'sync_source' => $log->sync_source,
            'processed_at' => $log->processed_at?->toIso8601String(),
            'is_voided' => $log->isVoided(),
            'voided_at' => $log->voided_at?->toIso8601String(),
            'voided_by_hr_account_id' => $log->voided_by_hr_account_id,
            'voided_by_name' => $log->voided_by_hr_account_id !== null
                ? ($voidedByNames[(int) $log->voided_by_hr_account_id] ?? null)
                : null,
            'void_reason' => $log->void_reason,
        ];
    }

    /**
     * @param  array<string, object>  $employeeDirectory
     */
    private function resolveDirectoryEmployee(AttendanceRawLog $log, array $employeeDirectory): ?object
    {
        $candidates = array_filter([
            trim((string) ($log->employee_control_no ?? '')),
            trim((string) ($log->biometric_pin ?? '')),
        ], static fn (string $value): bool => $value !== '');

        foreach ($candidates as $candidate) {
            if (isset($employeeDirectory[$candidate])) {
                return $employeeDirectory[$candidate];
            }

            if (preg_match('/^\d+$/', $candidate) === 1) {
                $normalized = ltrim($candidate, '0');
                $normalized = $normalized !== '' ? $normalized : '0';
                if (isset($employeeDirectory[$normalized])) {
                    return $employeeDirectory[$normalized];
                }
            }
        }

        return null;
    }

    private function formatEmployeeName(?object $employee): ?string
    {
        if ($employee === null) {
            return null;
        }

        $firstname = trim((string) ($employee->firstname ?? ''));
        $middlename = trim((string) ($employee->middlename ?? ''));
        $surname = trim((string) ($employee->surname ?? ''));
        $middleInitial = $middlename !== '' ? mb_substr($middlename, 0, 1).'. ' : '';

        $formatted = trim("{$firstname} {$middleInitial}{$surname}");

        return $formatted !== '' ? $formatted : null;
    }
}
