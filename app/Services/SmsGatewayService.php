<?php

namespace App\Services;

use App\Models\COCApplication;
use App\Models\DepartmentAdmin;
use App\Models\LeaveApplication;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SmsGatewayService
{
    private const HR_CONNECTION = 'hr';
    private const HR_PERSONAL_ADDT_TABLE = 'xPersonalAddt';

    public function sendLeaveApprovedMessage(LeaveApplication $application): bool
    {
        $controlNo = $this->resolveApplicationControlNo($application);
        if ($controlNo === null) {
            Log::warning('Unable to resolve employee control number for leave approval SMS.', [
                'leave_application_id' => (int) ($application->id ?? 0),
            ]);

            return false;
        }

        return $this->sendToEmployeeControlNo(
            $controlNo,
            $this->buildLeaveApprovedMessage($application),
            [
                'leave_application_id' => (int) ($application->id ?? 0),
                'employee_control_no' => $controlNo,
            ]
        );
    }

    public function sendCocApprovedMessage(COCApplication $application, ?float $creditedDays = null): bool
    {
        $controlNo = trim((string) ($application->employee_control_no ?? ''));
        if ($controlNo === '') {
            Log::warning('Unable to resolve employee control number for COC approval SMS.', [
                'coc_application_id' => (int) ($application->id ?? 0),
            ]);

            return false;
        }

        return $this->sendToEmployeeControlNo(
            $controlNo,
            $this->buildCocApprovedMessage($application, $creditedDays),
            [
                'coc_application_id' => (int) ($application->id ?? 0),
                'employee_control_no' => $controlNo,
            ]
        );
    }

    public function sendLeaveRejectedMessage(LeaveApplication $application): bool
    {
        $controlNo = $this->resolveApplicationControlNo($application);
        if ($controlNo === null) {
            Log::warning('Unable to resolve employee control number for leave rejection SMS.', [
                'leave_application_id' => (int) ($application->id ?? 0),
            ]);

            return false;
        }

        return $this->sendToEmployeeControlNo(
            $controlNo,
            $this->buildLeaveRejectedMessage($application),
            [
                'leave_application_id' => (int) ($application->id ?? 0),
                'employee_control_no' => $controlNo,
            ]
        );
    }

    public function sendCocRejectedMessage(COCApplication $application): bool
    {
        $controlNo = trim((string) ($application->employee_control_no ?? ''));
        if ($controlNo === '') {
            Log::warning('Unable to resolve employee control number for COC rejection SMS.', [
                'coc_application_id' => (int) ($application->id ?? 0),
            ]);

            return false;
        }

        return $this->sendToEmployeeControlNo(
            $controlNo,
            $this->buildCocRejectedMessage($application),
            [
                'coc_application_id' => (int) ($application->id ?? 0),
                'employee_control_no' => $controlNo,
            ]
        );
    }

    /**
     * @param array<string, scalar|null> $params
     * @return array{success: bool, status: int, body: string, error: string|null}
     */
    private function sendViaCurl(string $gatewayUrl, array $params): array
    {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $separator = str_contains($gatewayUrl, '?') ? '&' : '?';
        $requestUrl = $gatewayUrl . $separator . $query;

        $ch = curl_init($requestUrl);
        if ($ch === false) {
            return [
                'success' => false,
                'status' => 0,
                'body' => '',
                'error' => 'Unable to initialize cURL.',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds(),
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSeconds()),
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $rawResponse = curl_exec($ch);
        $curlErrorNo = curl_errno($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($rawResponse === false) {
            return [
                'success' => false,
                'status' => $status,
                'body' => '',
                'error' => $curlErrorNo > 0 ? "cURL error {$curlErrorNo}: {$curlError}" : 'Unknown cURL failure.',
            ];
        }

        $body = $headerSize > 0 ? (string) substr((string) $rawResponse, $headerSize) : (string) $rawResponse;
        $isSuccess = $status >= 200 && $status < 300;

        return [
            'success' => $isSuccess,
            'status' => $status,
            'body' => $body,
            'error' => null,
        ];
    }

    private function isEnabled(): bool
    {
        return (bool) config('sms.enabled', false);
    }

    private function gatewayUrl(): ?string
    {
        $gatewayUrl = trim((string) config('sms.gateway_url', ''));
        return $gatewayUrl !== '' ? $gatewayUrl : null;
    }

    private function timeoutSeconds(): int
    {
        return max(1, (int) config('sms.timeout_seconds', 8));
    }

    private function resolveApplicationControlNo(LeaveApplication $application): ?string
    {
        $controlNo = trim((string) ($application->employee_control_no ?? ''));
        if ($controlNo !== '') {
            return $controlNo;
        }

        $application->loadMissing('applicantAdmin');
        $applicantAdmin = $application->applicantAdmin;
        if (!$applicantAdmin instanceof DepartmentAdmin) {
            return null;
        }

        $adminControlNo = trim((string) ($applicantAdmin->employee_control_no ?? ''));
        return $adminControlNo !== '' ? $adminControlNo : null;
    }

    private function resolveEmployeePhoneNumberFromHris(string $controlNo): ?string
    {
        $controlNoCandidates = $this->controlNoCandidates($controlNo);
        if ($controlNoCandidates === []) {
            return null;
        }

        $query = DB::connection(self::HR_CONNECTION)
            ->table(self::HR_PERSONAL_ADDT_TABLE)
            ->selectRaw('LTRIM(RTRIM(CONVERT(VARCHAR(64), CellphoneNo))) as cellphone_no');

        $query->where(function ($nestedQuery) use ($controlNoCandidates): void {
            foreach ($controlNoCandidates as $candidate) {
                $nestedQuery->orWhereRaw(
                    'LTRIM(RTRIM(CONVERT(VARCHAR(64), ControlNo))) = ?',
                    [$candidate]
                );
            }
        });

        $row = $query->first();
        $phoneNumber = trim((string) ($row->cellphone_no ?? ''));

        return $phoneNumber !== '' ? $phoneNumber : null;
    }

    private function resolveDestinationNumber(?string $rawPhoneNumber): ?string
    {
        $destination = $this->normalizePhilippineMobile($rawPhoneNumber);
        if ($destination !== null) {
            return $destination;
        }

        Log::warning('Unable to resolve a valid mobile destination for employee SMS.', [
            'raw_phone' => $rawPhoneNumber,
        ]);

        return null;
    }

    /**
     * Normalize to 11-digit local format (09XXXXXXXXX) used by the gateway.
     */
    private function normalizePhilippineMobile(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '63') && strlen($digits) === 12) {
            $digits = '0' . substr($digits, 2);
        } elseif (str_starts_with($digits, '9') && strlen($digits) === 10) {
            $digits = '0' . $digits;
        }

        return (strlen($digits) === 11 && str_starts_with($digits, '09'))
            ? $digits
            : null;
    }

    /**
     * @return array<int, string>
     */
    private function controlNoCandidates(string $controlNo): array
    {
        $raw = trim($controlNo);
        if ($raw === '') {
            return [];
        }

        $normalized = ltrim($raw, '0');
        if ($normalized === '') {
            $normalized = '0';
        }

        return array_values(array_unique([$raw, $normalized]));
    }

    private function buildLeaveApprovedMessage(LeaveApplication $application): string
    {
        $application->loadMissing('leaveType');

        $leaveTypeName = trim((string) ($application->leaveType?->name ?? 'Leave'));
        $isMonetization = (bool) ($application->is_monetization ?? false);
        $totalDays = (float) ($application->total_days ?? 0);
        $daysLabel = $this->formatApprovedDayTotal($totalDays);

        if ($isMonetization) {
            return "Good day! This is the CHRMO. Your monetization request for a total of {$daysLabel} has been approved.";
        }

        $dateBlock = $this->buildApplicationInclusiveDateBlock($application);
        if ($dateBlock !== '') {
            return "Good day! This is the CHRMO. Your application for {$leaveTypeName} for a total of {$daysLabel} has been approved on the following dates:\n\n{$dateBlock}\n\nPlease take note of your approved leave schedule. Thank you.";
        }

        return "Good day! This is the CHRMO. Your application for {$leaveTypeName} for a total of {$daysLabel} has been approved.";
    }

    private function buildCocApprovedMessage(COCApplication $application, ?float $creditedDays = null): string
    {
        return 'Good day! This is CHRMO. Your COC application has been approved.';
    }

    private function buildLeaveRejectedMessage(LeaveApplication $application): string
    {
        $application->loadMissing('leaveType');

        $leaveTypeName = trim((string) ($application->leaveType?->name ?? 'Leave'));
        $isMonetization = (bool) ($application->is_monetization ?? false);
        $totalDays = (float) ($application->total_days ?? 0);
        $daysLabel = $this->formatApprovedDayTotal($totalDays);

        if ($isMonetization) {
            return "Good day! This is the CHRMO. Your monetization request for a total of {$daysLabel} has been rejected.";
        }

        $dateBlock = $this->buildApplicationInclusiveDateBlock($application);
        if ($dateBlock !== '') {
            return "Good day! This is the CHRMO. Your application for {$leaveTypeName} for a total of {$daysLabel} has been rejected on the following dates:\n\n{$dateBlock}\n\nPlease take note of the rejection of your leave application. Thank you.";
        }

        return "Good day! This is the CHRMO. Your application for {$leaveTypeName} for a total of {$daysLabel} has been rejected.";
    }

    private function buildCocRejectedMessage(COCApplication $application): string
    {
        return 'Good day! This is CHRMO. Your COC application has been rejected.';
    }

    private function formatDays(float $days): string
    {
        $roundedDays = round($days, 2);
        $display = $roundedDays === (float) ((int) $roundedDays)
            ? (string) ((int) $roundedDays)
            : rtrim(rtrim(number_format($roundedDays, 2, '.', ''), '0'), '.');

        return "{$display} day" . ($roundedDays === 1.0 ? '' : 's');
    }

    private function formatApprovedDayTotal(float $days): string
    {
        $roundedDays = round($days, 2);
        $isWholeNumber = abs($roundedDays - (float) round($roundedDays)) < 0.00001;
        $dayUnit = abs($roundedDays - 1.0) < 0.00001 ? 'day' : 'days';

        if ($isWholeNumber) {
            $integerDays = (int) round($roundedDays);
            return $this->numberToWords($integerDays) . " ({$integerDays}) {$dayUnit}";
        }

        $display = rtrim(rtrim(number_format($roundedDays, 2, '.', ''), '0'), '.');
        return "{$display} {$dayUnit}";
    }

    private function numberToWords(int $number): string
    {
        $smallNumbers = [
            0 => 'zero',
            1 => 'one',
            2 => 'two',
            3 => 'three',
            4 => 'four',
            5 => 'five',
            6 => 'six',
            7 => 'seven',
            8 => 'eight',
            9 => 'nine',
            10 => 'ten',
            11 => 'eleven',
            12 => 'twelve',
            13 => 'thirteen',
            14 => 'fourteen',
            15 => 'fifteen',
            16 => 'sixteen',
            17 => 'seventeen',
            18 => 'eighteen',
            19 => 'nineteen',
        ];

        $tensNumbers = [
            20 => 'twenty',
            30 => 'thirty',
            40 => 'forty',
            50 => 'fifty',
            60 => 'sixty',
            70 => 'seventy',
            80 => 'eighty',
            90 => 'ninety',
        ];

        if ($number < 0) {
            return 'minus ' . $this->numberToWords(abs($number));
        }

        if (array_key_exists($number, $smallNumbers)) {
            return $smallNumbers[$number];
        }

        if ($number < 100) {
            $tens = intdiv($number, 10) * 10;
            $remainder = $number % 10;
            return $remainder === 0
                ? $tensNumbers[$tens]
                : $tensNumbers[$tens] . '-' . $smallNumbers[$remainder];
        }

        if ($number < 1000) {
            $hundreds = intdiv($number, 100);
            $remainder = $number % 100;
            $prefix = $smallNumbers[$hundreds] . ' hundred';

            return $remainder === 0
                ? $prefix
                : $prefix . ' ' . $this->numberToWords($remainder);
        }

        if ($number < 1000000) {
            $thousands = intdiv($number, 1000);
            $remainder = $number % 1000;
            $prefix = $this->numberToWords($thousands) . ' thousand';

            return $remainder === 0
                ? $prefix
                : $prefix . ' ' . $this->numberToWords($remainder);
        }

        return (string) $number;
    }

    private function truncateResponseBody(string $body, int $maxLength = 500): string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return '';
        }

        return strlen($trimmed) > $maxLength
            ? substr($trimmed, 0, $maxLength) . '...'
            : $trimmed;
    }

    private function buildApplicationInclusiveDateBlock(LeaveApplication $application): string
    {
        $selectedDates = $this->resolveSelectedLeaveDates($application);
        if ($selectedDates !== []) {
            $formattedSelectedDates = $this->formatSelectedLeaveDatesForSms($selectedDates);
            if ($formattedSelectedDates !== '') {
                return $formattedSelectedDates;
            }
        }

        return $this->buildApplicationDateLabel($application);
    }

    /**
     * @param array<int, CarbonImmutable> $selectedDates
     */
    private function formatSelectedLeaveDatesForSms(array $selectedDates): string
    {
        $segments = $this->groupConsecutiveLeaveDates($selectedDates);
        if ($segments === []) {
            return '';
        }

        $lines = [];
        foreach ($segments as [$start, $end]) {
            if (!$start instanceof CarbonImmutable || !$end instanceof CarbonImmutable) {
                continue;
            }

            $lines[] = '• ' . $this->formatSmsDateRangeLabel($start, $end);
        }

        return implode("\n", $lines);
    }

    private function formatSmsDateRangeLabel(CarbonImmutable $start, CarbonImmutable $end): string
    {
        if ($start->isSameDay($end)) {
            return $start->format('F j, Y');
        }

        if ($start->year === $end->year) {
            if ($start->month === $end->month) {
                return $start->format('F j') . '–' . $end->format('j') . ', ' . $start->format('Y');
            }

            return $start->format('F j') . ' – ' . $end->format('F j') . ', ' . $start->format('Y');
        }

        return $start->format('F j, Y') . ' – ' . $end->format('F j, Y');
    }

    /**
     * @param array<int, CarbonImmutable> $selectedDates
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function groupConsecutiveLeaveDates(array $selectedDates): array
    {
        $dates = collect($selectedDates)
            ->filter(static fn (mixed $date): bool => $date instanceof CarbonImmutable)
            ->map(static fn (CarbonImmutable $date): CarbonImmutable => $date->startOfDay())
            ->unique(static fn (CarbonImmutable $date): string => $date->toDateString())
            ->sortBy(static fn (CarbonImmutable $date): string => $date->toDateString())
            ->values();

        if ($dates->isEmpty()) {
            return [];
        }

        $segments = [];
        $segmentStart = $dates->first();
        $segmentEnd = $segmentStart;

        if (!$segmentStart instanceof CarbonImmutable) {
            return [];
        }

        foreach ($dates->slice(1) as $date) {
            if (!$date instanceof CarbonImmutable) {
                continue;
            }

            if ($segmentEnd->addDay()->isSameDay($date)) {
                $segmentEnd = $date;
                continue;
            }

            $segments[] = [$segmentStart, $segmentEnd];
            $segmentStart = $date;
            $segmentEnd = $date;
        }

        $segments[] = [$segmentStart, $segmentEnd];

        return $segments;
    }

    private function buildApplicationDateLabel(LeaveApplication $application): string
    {
        $selectedDates = $this->resolveSelectedLeaveDates($application);
        if ($selectedDates !== []) {
            $uniqueDates = collect($selectedDates)
                ->map(static fn (CarbonImmutable $date): string => $date->toDateString())
                ->unique()
                ->sort()
                ->values()
                ->map(static fn (string $date): CarbonImmutable => CarbonImmutable::parse($date))
                ->values();

            if ($uniqueDates->isNotEmpty()) {
                if ($uniqueDates->count() === 1) {
                    /** @var CarbonImmutable $singleDate */
                    $singleDate = $uniqueDates->first();
                    return 'on ' . $singleDate->format('F j, Y');
                }

                /** @var CarbonImmutable $firstDate */
                $firstDate = $uniqueDates->first();
                /** @var CarbonImmutable $lastDate */
                $lastDate = $uniqueDates->last();

                $sameMonthAndYear = $uniqueDates->every(
                    static fn (CarbonImmutable $date): bool => $date->year === $firstDate->year && $date->month === $firstDate->month
                );

                if ($sameMonthAndYear) {
                    $dayList = $uniqueDates
                        ->map(static fn (CarbonImmutable $date): string => (string) $date->day)
                        ->join(',');

                    return 'on ' . $firstDate->format('F') . ' ' . $dayList . ' ' . $firstDate->format('Y');
                }

                return 'from ' . $firstDate->format('F j, Y') . ' to ' . $lastDate->format('F j, Y');
            }
        }

        $startDate = $application->start_date;
        $endDate = $application->end_date;

        if (!$startDate instanceof CarbonInterface && !$endDate instanceof CarbonInterface) {
            return '';
        }

        if ($startDate instanceof CarbonInterface && $endDate instanceof CarbonInterface) {
            if ($startDate->isSameDay($endDate)) {
                return 'on ' . $startDate->format('M j, Y');
            }

            return 'from ' . $startDate->format('M j, Y') . ' to ' . $endDate->format('M j, Y');
        }

        if ($startDate instanceof CarbonInterface) {
            return 'starting ' . $startDate->format('M j, Y');
        }

        return 'until ' . $endDate->format('M j, Y');
    }

    /**
     * @return array<int, CarbonImmutable>
     */
    private function resolveSelectedLeaveDates(LeaveApplication $application): array
    {
        $resolvedDates = $application->resolvedSelectedDates();
        if (!is_array($resolvedDates) || $resolvedDates === []) {
            return [];
        }

        $dates = [];
        foreach ($resolvedDates as $rawDate) {
            $normalizedDate = trim((string) ($rawDate ?? ''));
            if ($normalizedDate === '') {
                continue;
            }

            try {
                $dates[] = CarbonImmutable::parse($normalizedDate)->startOfDay();
            } catch (Throwable) {
                continue;
            }
        }

        return $dates;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function sendToEmployeeControlNo(string $controlNo, string $message, array $context): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $gatewayUrl = $this->gatewayUrl();
        if ($gatewayUrl === null) {
            Log::warning('SMS gateway URL is not configured; skipping SMS send.', $context);
            return false;
        }

        $destination = null;

        try {
            $rawPhoneNumber = $this->resolveEmployeePhoneNumberFromHris($controlNo);
            $destination = $this->resolveDestinationNumber($rawPhoneNumber);

            if ($destination === null) {
                return false;
            }

            $gatewayResult = $this->sendViaCurl($gatewayUrl, [
                'destination' => $destination,
                'content' => $message,
            ]);

            if (!(bool) ($gatewayResult['success'] ?? false)) {
                Log::warning('SMS gateway returned a non-success status.', array_merge($context, [
                    'destination' => $destination,
                    'status' => (int) ($gatewayResult['status'] ?? 0),
                    'response_body' => $this->truncateResponseBody((string) ($gatewayResult['body'] ?? '')),
                    'error' => $gatewayResult['error'] ?? null,
                ]));

                return false;
            }
        } catch (Throwable $exception) {
            Log::warning('Failed to send SMS.', array_merge($context, [
                'destination' => $destination,
                'error' => $exception->getMessage(),
            ]));

            return false;
        }

        Log::info('SMS sent successfully.', array_merge($context, [
            'destination' => $destination,
        ]));

        return true;
    }
}
