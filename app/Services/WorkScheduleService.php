<?php

namespace App\Services;

use App\Models\EmployeeWorkScheduleOverride;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\WorkScheduleSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class WorkScheduleService
{
    public const STANDARD_WORKDAY_HOURS = 8.0;
    public const DEDUCTION_PRECISION = 3;

    /**
     * @return array{
     *   work_start_time:string,
     *   work_end_time:string,
     *   break_start_time:?string,
     *   break_end_time:?string,
     *   working_hours_per_day:float,
     *   whole_day_leave_deduction:float,
     *   half_day_leave_deduction:float,
     *   notes:?string
     * }
     */
    public function defaultValues(): array
    {
        $defaultDeductions = $this->calculateDeductionWeights(self::STANDARD_WORKDAY_HOURS);

        return [
            'work_start_time' => '08:00',
            'work_end_time' => '17:00',
            'break_start_time' => '12:00',
            'break_end_time' => '13:00',
            'working_hours_per_day' => self::STANDARD_WORKDAY_HOURS,
            'whole_day_leave_deduction' => $defaultDeductions['whole_day_leave_deduction'],
            'half_day_leave_deduction' => $defaultDeductions['half_day_leave_deduction'],
            'notes' => null,
        ];
    }

    public function getDefaultSetting(): WorkScheduleSetting
    {
        return WorkScheduleSetting::query()->firstOrCreate(
            ['setting_key' => WorkScheduleSetting::GLOBAL_SETTING_KEY],
            $this->defaultValues()
        );
    }

    public function getResolvedDefaultSchedule(): array
    {
        return $this->formatResolvedSchedule(
            $this->getDefaultSetting()->loadMissing('updatedByHr')->toArray(),
            'default'
        );
    }

    public function saveDefaultSetting(array $payload, ?HRAccount $updatedBy = null): WorkScheduleSetting
    {
        $computedPayload = $this->normalizeSchedulePayload($payload, $updatedBy);

        $setting = $this->getDefaultSetting();
        $setting->fill($computedPayload);
        $setting->save();

        return $setting->fresh(['updatedByHr']) ?? $setting;
    }

    public function listEmployeeOverrides(): array
    {
        return EmployeeWorkScheduleOverride::query()
            ->with('updatedByHr:id,full_name')
            ->orderBy('employee_name')
            ->orderBy('employee_control_no')
            ->get()
            ->map(fn (EmployeeWorkScheduleOverride $override): array => $this->formatOverride($override))
            ->values()
            ->all();
    }

    public function createEmployeeOverride(string $employeeControlNo, array $payload, ?HRAccount $updatedBy = null): EmployeeWorkScheduleOverride
    {
        $employee = HrisEmployee::findByControlNo($employeeControlNo, true);
        if (!$employee) {
            throw new InvalidArgumentException('Employee not found in active HRIS records.');
        }

        $override = new EmployeeWorkScheduleOverride();
        $override->fill($this->normalizeSchedulePayload($payload, $updatedBy));
        $override->employee_control_no = trim((string) ($employee->control_no ?? $employeeControlNo));
        $override->employee_name = $this->formatEmployeeFullName($employee);
        $override->office = trim((string) ($employee->office ?? '')) ?: null;
        $override->designation = trim((string) ($employee->designation ?? '')) ?: null;
        $override->status = trim((string) ($employee->status ?? '')) ?: null;
        $override->is_active = Arr::get($payload, 'is_active', true) !== false;
        $override->save();

        return $override->fresh(['updatedByHr']) ?? $override;
    }

    public function updateEmployeeOverride(EmployeeWorkScheduleOverride $override, array $payload, ?HRAccount $updatedBy = null): EmployeeWorkScheduleOverride
    {
        $employee = HrisEmployee::findByControlNo((string) $override->employee_control_no, true);
        if ($employee) {
            $override->employee_name = $this->formatEmployeeFullName($employee);
            $override->office = trim((string) ($employee->office ?? '')) ?: null;
            $override->designation = trim((string) ($employee->designation ?? '')) ?: null;
            $override->status = trim((string) ($employee->status ?? '')) ?: null;
        }

        $override->fill($this->normalizeSchedulePayload($payload, $updatedBy));
        if (array_key_exists('is_active', $payload)) {
            $override->is_active = filter_var($payload['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $override->is_active;
        }
        $override->save();

        return $override->fresh(['updatedByHr']) ?? $override;
    }

    public function resolveScheduleForEmployee(?string $employeeControlNo = null): array
    {
        $default = $this->getResolvedDefaultSchedule();
        $controlNo = trim((string) ($employeeControlNo ?? ''));
        if ($controlNo === '') {
            return $default;
        }

        $employee = HrisEmployee::findByControlNo($controlNo);
        $lookupControlNo = trim((string) ($employee->control_no ?? $controlNo));

        $override = EmployeeWorkScheduleOverride::query()
            ->active()
            ->where('employee_control_no', $lookupControlNo)
            ->first();

        if (!$override) {
            return $default;
        }

        return $this->formatResolvedSchedule(array_merge($default, $override->toArray()), 'employee', $override);
    }

    public function resolveCoverageDeductionDays(mixed $coverage, ?string $employeeControlNo = null): float
    {
        $schedule = $this->resolveScheduleForEmployee($employeeControlNo);
        $normalizedCoverage = strtolower(trim((string) $coverage));

        return $normalizedCoverage === 'half'
            ? (float) ($schedule['half_day_leave_deduction'] ?? 0.5)
            : (float) ($schedule['whole_day_leave_deduction'] ?? 1.0);
    }

    public function formatOverride(EmployeeWorkScheduleOverride $override): array
    {
        return $this->formatResolvedSchedule($override->toArray(), 'employee', $override);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function normalizeSchedulePayload(array $payload, ?HRAccount $updatedBy = null): array
    {
        $workStartTime = $this->normalizeTimeValue($payload['work_start_time'] ?? null);
        $workEndTime = $this->normalizeTimeValue($payload['work_end_time'] ?? null);
        $breakStartTime = $this->normalizeNullableTimeValue($payload['break_start_time'] ?? null);
        $breakEndTime = $this->normalizeNullableTimeValue($payload['break_end_time'] ?? null);

        if ($workStartTime === null || $workEndTime === null) {
            throw new InvalidArgumentException('Work start time and end time are required.');
        }

        $workingHoursPerDay = $this->calculateWorkingHoursPerDay(
            $workStartTime,
            $workEndTime,
            $breakStartTime,
            $breakEndTime
        );

        $deductions = $this->calculateDeductionWeights($workingHoursPerDay);

        return [
            'work_start_time' => $workStartTime,
            'work_end_time' => $workEndTime,
            'break_start_time' => $breakStartTime,
            'break_end_time' => $breakEndTime,
            'working_hours_per_day' => $workingHoursPerDay,
            'whole_day_leave_deduction' => $deductions['whole_day_leave_deduction'],
            'half_day_leave_deduction' => $deductions['half_day_leave_deduction'],
            'notes' => $this->trimNullableString($payload['notes'] ?? null),
            'updated_by_hr_account_id' => $updatedBy?->id,
        ];
    }

    public function calculateWorkingHoursPerDay(
        string $workStartTime,
        string $workEndTime,
        ?string $breakStartTime = null,
        ?string $breakEndTime = null
    ): float {
        $workStart = CarbonImmutable::createFromFormat('H:i', $workStartTime);
        $workEnd = CarbonImmutable::createFromFormat('H:i', $workEndTime);
        if (!$workStart || !$workEnd || $workEnd->lessThanOrEqualTo($workStart)) {
            throw new InvalidArgumentException('Work end time must be later than the work start time.');
        }

        $minutes = $workStart->diffInMinutes($workEnd);

        if (($breakStartTime === null) !== ($breakEndTime === null)) {
            throw new InvalidArgumentException('Break start and break end time must both be provided when using a break.');
        }

        if ($breakStartTime !== null && $breakEndTime !== null) {
            $breakStart = CarbonImmutable::createFromFormat('H:i', $breakStartTime);
            $breakEnd = CarbonImmutable::createFromFormat('H:i', $breakEndTime);

            if (
                !$breakStart ||
                !$breakEnd ||
                $breakEnd->lessThanOrEqualTo($breakStart) ||
                $breakStart->lessThan($workStart) ||
                $breakEnd->greaterThan($workEnd)
            ) {
                throw new InvalidArgumentException('Break hours must fall within the work schedule and end after they start.');
            }

            $minutes -= $breakStart->diffInMinutes($breakEnd);
        }

        if ($minutes <= 0) {
            throw new InvalidArgumentException('Computed working hours must be greater than zero.');
        }

        return round($minutes / 60, 2);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function formatResolvedSchedule(
        array $data,
        string $source,
        ?EmployeeWorkScheduleOverride $override = null
    ): array {
        $workingHoursPerDay = round((float) ($data['working_hours_per_day'] ?? 0), 2);
        $deductions = $workingHoursPerDay > 0
            ? $this->calculateDeductionWeights($workingHoursPerDay)
            : [
                'whole_day_leave_deduction' => round((float) ($data['whole_day_leave_deduction'] ?? 1), 2),
                'half_day_leave_deduction' => round((float) ($data['half_day_leave_deduction'] ?? 0.5), 2),
            ];

        return [
            'id' => isset($data['id']) ? (int) $data['id'] : null,
            'source' => $source,
            'setting_key' => $data['setting_key'] ?? WorkScheduleSetting::GLOBAL_SETTING_KEY,
            'employee_control_no' => $data['employee_control_no'] ?? null,
            'employee_name' => $data['employee_name'] ?? null,
            'office' => $data['office'] ?? null,
            'designation' => $data['designation'] ?? null,
            'status' => $data['status'] ?? null,
            'work_start_time' => $this->normalizeNullableTimeValue($data['work_start_time'] ?? null),
            'work_end_time' => $this->normalizeNullableTimeValue($data['work_end_time'] ?? null),
            'break_start_time' => $this->normalizeNullableTimeValue($data['break_start_time'] ?? null),
            'break_end_time' => $this->normalizeNullableTimeValue($data['break_end_time'] ?? null),
            'working_hours_per_day' => $workingHoursPerDay,
            'whole_day_leave_deduction' => $deductions['whole_day_leave_deduction'],
            'half_day_leave_deduction' => $deductions['half_day_leave_deduction'],
            'notes' => $this->trimNullableString($data['notes'] ?? null),
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
            'updated_at' => isset($data['updated_at']) && $data['updated_at'] !== null
                ? CarbonImmutable::parse((string) $data['updated_at'])->toIso8601String()
                : null,
            'updated_by_hr' => $override?->updatedByHr?->full_name
                ?? (($data['updated_by_hr']['full_name'] ?? null) ?: null),
        ];
    }

    private function normalizeTimeValue(mixed $value): ?string
    {
        $normalized = $this->normalizeNullableTimeValue($value);
        return $normalized !== null ? $normalized : null;
    }

    private function normalizeNullableTimeValue(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        $formats = ['H:i', 'H:i:s', 'g:i a', 'g:i A', 'h:i a', 'h:i A'];
        foreach ($formats as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $raw);
                if ($date !== false) {
                    return $date->format('H:i');
                }
            } catch (\Throwable) {
                continue;
            }
        }

        throw new InvalidArgumentException('Invalid time format. Please use HH:MM.');
    }

    private function trimNullableString(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));
        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @return array{whole_day_leave_deduction: float, half_day_leave_deduction: float}
     */
    private function calculateDeductionWeights(float $workingHoursPerDay): array
    {
        if ($workingHoursPerDay <= 0) {
            throw new InvalidArgumentException('Working hours per day must be greater than zero.');
        }

        $wholeDayLeaveDeduction = round($workingHoursPerDay / self::STANDARD_WORKDAY_HOURS, self::DEDUCTION_PRECISION);
        $halfDayLeaveDeduction = round($wholeDayLeaveDeduction / 2, self::DEDUCTION_PRECISION);

        return [
            'whole_day_leave_deduction' => $wholeDayLeaveDeduction,
            'half_day_leave_deduction' => $halfDayLeaveDeduction,
        ];
    }

    private function formatEmployeeFullName(object $employee): string
    {
        $parts = array_filter([
            trim((string) ($employee->surname ?? '')),
            trim((string) ($employee->firstname ?? '')),
            trim((string) ($employee->middlename ?? '')),
        ]);

        return implode(', ', array_filter([
            array_shift($parts),
            implode(' ', $parts),
        ]));
    }
}
