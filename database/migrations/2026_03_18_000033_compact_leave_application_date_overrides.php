<?php

use App\Models\LeaveApplication;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tblLeaveApplications')) {
            return;
        }

        if (
            !Schema::hasColumn('tblLeaveApplications', 'selected_dates')
            || !Schema::hasColumn('tblLeaveApplications', 'selected_date_pay_status')
            || !Schema::hasColumn('tblLeaveApplications', 'selected_date_coverage')
            || !Schema::hasColumn('tblLeaveApplications', 'pay_mode')
        ) {
            return;
        }

        LeaveApplication::query()
            ->select([
                'id',
                'start_date',
                'end_date',
                'total_days',
                'is_monetization',
                'pay_mode',
                'selected_dates',
                'selected_date_pay_status',
                'selected_date_coverage',
            ])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    if (!$row instanceof LeaveApplication) {
                        continue;
                    }

                    $isMonetization = (bool) $row->is_monetization;
                    $normalizedPayMode = $this->normalizePayMode($row->pay_mode, $isMonetization);

                    $resolvedSelectedDates = $isMonetization
                        ? null
                        : LeaveApplication::resolveSelectedDates(
                            $row->start_date,
                            $row->end_date,
                            $row->selected_dates,
                            $row->total_days
                        );

                    $normalizedPayStatus = $isMonetization
                        ? null
                        : $this->normalizeSelectedDatePayStatusMap($row->selected_date_pay_status);
                    $normalizedCoverage = $isMonetization
                        ? null
                        : $this->normalizeSelectedDateCoverageMap($row->selected_date_coverage);

                    $compactedPayStatus = $isMonetization
                        ? null
                        : $this->compactSelectedDatePayStatusMap(
                            $normalizedPayStatus,
                            $resolvedSelectedDates,
                            $normalizedPayMode
                        );
                    $compactedCoverage = $isMonetization
                        ? null
                        : $this->compactSelectedDateCoverageMap(
                            $normalizedCoverage,
                            $resolvedSelectedDates
                        );

                    $updates = [];

                    if ($this->normalizeStoredPayMode($row->pay_mode) !== $normalizedPayMode) {
                        $updates['pay_mode'] = $normalizedPayMode;
                    }

                    if ($this->jsonColumnNeedsUpdate($row->selected_dates, $resolvedSelectedDates)) {
                        $updates['selected_dates'] = $this->encodeJsonOrNull($resolvedSelectedDates);
                    }

                    if ($this->jsonColumnNeedsUpdate($row->selected_date_pay_status, $compactedPayStatus)) {
                        $updates['selected_date_pay_status'] = $this->encodeJsonOrNull($compactedPayStatus);
                    }

                    if ($this->jsonColumnNeedsUpdate($row->selected_date_coverage, $compactedCoverage)) {
                        $updates['selected_date_coverage'] = $this->encodeJsonOrNull($compactedCoverage);
                    }

                    if ($updates === []) {
                        continue;
                    }

                    DB::table('tblLeaveApplications')
                        ->where('id', (int) $row->id)
                        ->update($updates);
                }
            });
    }

    public function down(): void
    {
        // Irreversible cleanup migration (normalization + compaction only).
    }

    private function normalizePayMode(mixed $payMode, bool $isMonetization = false): string
    {
        if ($isMonetization) {
            return LeaveApplication::PAY_MODE_WITH_PAY;
        }

        $normalized = strtoupper(trim((string) ($payMode ?? LeaveApplication::PAY_MODE_WITH_PAY)));
        if (in_array($normalized, [LeaveApplication::PAY_MODE_WITH_PAY, LeaveApplication::PAY_MODE_WITHOUT_PAY], true)) {
            return $normalized;
        }

        return LeaveApplication::PAY_MODE_WITH_PAY;
    }

    private function normalizeStoredPayMode(mixed $payMode): ?string
    {
        $normalized = strtoupper(trim((string) $payMode));
        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }

    private function resolvePayModeFromStatusValue(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(['_', '-'], ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        if (in_array($normalized, ['wop', 'without pay', 'withoutpay', 'unpaid', 'no pay'], true)) {
            return LeaveApplication::PAY_MODE_WITHOUT_PAY;
        }

        if (in_array($normalized, ['wp', 'with pay', 'withpay', 'paid'], true)) {
            return LeaveApplication::PAY_MODE_WITH_PAY;
        }

        return null;
    }

    private function normalizeSelectedDatePayStatusMap(mixed $value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (!is_array($value) || $value === []) {
            return null;
        }

        $normalized = [];
        foreach ($value as $rawDate => $rawStatus) {
            $dateKey = trim((string) $rawDate);
            if ($dateKey === '') {
                continue;
            }

            $resolvedMode = $this->resolvePayModeFromStatusValue($rawStatus);
            if ($resolvedMode === null) {
                continue;
            }

            $normalized[$dateKey] = $resolvedMode;
        }

        if ($normalized === []) {
            return null;
        }

        ksort($normalized);
        return $normalized;
    }

    private function normalizeSelectedDateCoverageMap(mixed $value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (!is_array($value) || $value === []) {
            return null;
        }

        $normalized = [];
        foreach ($value as $rawDate => $rawCoverage) {
            $dateKey = trim((string) $rawDate);
            if ($dateKey === '') {
                continue;
            }

            $coverage = strtolower(trim((string) $rawCoverage));
            if ($coverage === 'half') {
                $normalized[$dateKey] = 'half';
                continue;
            }

            if ($coverage === 'whole') {
                $normalized[$dateKey] = 'whole';
            }
        }

        if ($normalized === []) {
            return null;
        }

        ksort($normalized);
        return $normalized;
    }

    private function compactSelectedDatePayStatusMap(
        ?array $selectedDatePayStatus,
        ?array $selectedDates,
        string $payMode
    ): ?array {
        if (!is_array($selectedDatePayStatus) || $selectedDatePayStatus === []) {
            return null;
        }

        $defaultMode = $this->normalizePayMode($payMode, false);
        $dateSet = [];
        if (is_array($selectedDates)) {
            foreach ($selectedDates as $rawDate) {
                $dateKey = trim((string) $rawDate);
                if ($dateKey === '') {
                    continue;
                }

                $dateSet[$dateKey] = true;
            }
        }
        $restrictToSelectedDates = $dateSet !== [];

        $compacted = [];
        foreach ($selectedDatePayStatus as $rawDate => $rawStatus) {
            $dateKey = trim((string) $rawDate);
            if ($dateKey === '') {
                continue;
            }

            if ($restrictToSelectedDates && !array_key_exists($dateKey, $dateSet)) {
                continue;
            }

            $resolvedMode = $this->resolvePayModeFromStatusValue($rawStatus);
            if ($resolvedMode === null) {
                continue;
            }

            if ($restrictToSelectedDates && $resolvedMode === $defaultMode) {
                continue;
            }

            $compacted[$dateKey] = $resolvedMode;
        }

        if ($compacted === []) {
            return null;
        }

        ksort($compacted);
        return $compacted;
    }

    private function compactSelectedDateCoverageMap(
        ?array $selectedDateCoverage,
        ?array $selectedDates
    ): ?array {
        if (!is_array($selectedDateCoverage) || $selectedDateCoverage === []) {
            return null;
        }

        $dateSet = [];
        if (is_array($selectedDates)) {
            foreach ($selectedDates as $rawDate) {
                $dateKey = trim((string) $rawDate);
                if ($dateKey === '') {
                    continue;
                }

                $dateSet[$dateKey] = true;
            }
        }
        $restrictToSelectedDates = $dateSet !== [];

        $compacted = [];
        foreach ($selectedDateCoverage as $rawDate => $rawCoverage) {
            $dateKey = trim((string) $rawDate);
            if ($dateKey === '') {
                continue;
            }

            if ($restrictToSelectedDates && !array_key_exists($dateKey, $dateSet)) {
                continue;
            }

            $coverage = strtolower(trim((string) $rawCoverage));
            if ($coverage === 'half') {
                $compacted[$dateKey] = 'half';
                continue;
            }

            if (!$restrictToSelectedDates && $coverage === 'whole') {
                $compacted[$dateKey] = 'whole';
                continue;
            }
        }

        if ($compacted === []) {
            return null;
        }

        ksort($compacted);
        return $compacted;
    }

    private function jsonColumnNeedsUpdate(mixed $currentValue, ?array $targetValue): bool
    {
        $currentNormalized = $this->normalizeStoredJsonArray($currentValue);
        return $this->encodeJsonOrNull($currentNormalized) !== $this->encodeJsonOrNull($targetValue);
    }

    private function normalizeStoredJsonArray(mixed $value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (!is_array($value) || $value === []) {
            return null;
        }

        return $value;
    }

    private function encodeJsonOrNull(?array $value): ?string
    {
        if (!is_array($value) || $value === []) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
};
