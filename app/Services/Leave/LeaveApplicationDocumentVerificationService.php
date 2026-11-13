<?php

namespace App\Services;

use App\Models\LeaveApplication;
use DateTimeInterface;
use JsonException;
use RuntimeException;

class LeaveApplicationDocumentVerificationService
{
    private const TOKEN_PREFIX = 'LMS-LEAVE';

    private const TOKEN_VERSION = '1';

    private const FINGERPRINT_LENGTH = 32;

    private const SIGNATURE_LENGTH_BYTES = 16;

    public function __construct(private ?string $signingKey = null) {}

    /**
     * @return array{token: string, reference: string, format_version: int}
     */
    public function issue(LeaveApplication $application): array
    {
        $applicationId = (int) $application->getKey();
        if ($applicationId < 1) {
            throw new RuntimeException('A persisted leave application is required to issue a verification token.');
        }

        $fingerprint = $this->fingerprint($application);
        $signedPayload = implode(':', [
            self::TOKEN_PREFIX,
            self::TOKEN_VERSION,
            (string) $applicationId,
            $fingerprint,
        ]);

        return [
            'token' => $signedPayload.':'.$this->signature($signedPayload),
            'reference' => $this->reference($applicationId, $fingerprint),
            'format_version' => (int) self::TOKEN_VERSION,
        ];
    }

    /**
     * @return array{application_id: int, fingerprint: string, format_version: int}|null
     */
    public function decode(string $token): ?array
    {
        $parts = explode(':', trim($token));
        if (count($parts) !== 5) {
            return null;
        }

        [$prefix, $version, $applicationId, $fingerprint, $providedSignature] = $parts;
        if (
            $prefix !== self::TOKEN_PREFIX
            || $version !== self::TOKEN_VERSION
            || ! ctype_digit($applicationId)
            || (int) $applicationId < 1
            || ! preg_match('/^[a-f0-9]{'.self::FINGERPRINT_LENGTH.'}$/', $fingerprint)
            || ! preg_match('/^[A-Za-z0-9_-]{22}$/', $providedSignature)
        ) {
            return null;
        }

        $signedPayload = implode(':', [$prefix, $version, $applicationId, $fingerprint]);
        if (! hash_equals($this->signature($signedPayload), $providedSignature)) {
            return null;
        }

        return [
            'application_id' => (int) $applicationId,
            'fingerprint' => $fingerprint,
            'format_version' => (int) $version,
        ];
    }

    public function isCurrent(LeaveApplication $application, string $fingerprint): bool
    {
        return hash_equals($this->fingerprint($application), $fingerprint);
    }

    public function reference(int $applicationId, string $fingerprint): string
    {
        return sprintf('LA-%d-%s', $applicationId, strtoupper(substr($fingerprint, 0, 8)));
    }

    public function fingerprint(LeaveApplication $application): string
    {
        try {
            $encodedFields = json_encode(
                $this->canonicalDocumentFields($application),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode the leave application verification fields.', previous: $exception);
        }

        return substr(hash('sha256', $encodedFields), 0, self::FINGERPRINT_LENGTH);
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalDocumentFields(LeaveApplication $application): array
    {
        return [
            'application_id' => (int) $application->getKey(),
            'employee_control_no' => trim((string) ($application->employee_control_no ?? '')),
            'employee_name' => trim((string) ($application->employee_name ?? '')),
            'leave_type_id' => (int) ($application->leave_type_id ?? 0),
            'start_date' => $this->normalizeValue($application->start_date),
            'end_date' => $this->normalizeValue($application->end_date),
            'selected_dates' => $this->normalizeValue($application->selected_dates),
            'total_days' => number_format((float) ($application->total_days ?? 0), 3, '.', ''),
            'reason' => trim((string) ($application->reason ?? '')),
            'details_of_leave' => $this->normalizeValue($application->details_of_leave),
            'selected_date_pay_status' => $this->normalizeValue($application->selected_date_pay_status),
            'selected_date_coverage' => $this->normalizeValue($application->selected_date_coverage),
            'selected_date_half_day_portion' => $this->normalizeValue($application->selected_date_half_day_portion),
            'commutation' => trim((string) ($application->commutation ?? '')),
            'pay_mode' => trim((string) ($application->pay_mode ?? '')),
            'is_monetization' => (bool) ($application->is_monetization ?? false),
            'monetization_leave_credits' => $this->normalizeValue($application->monetization_leave_credits),
            'created_at' => $this->normalizeValue($application->created_at),
        ];
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }

        if (is_string($value)) {
            $trimmedValue = trim($value);
            if ($trimmedValue !== '' && in_array($trimmedValue[0], ['[', '{'], true)) {
                try {
                    return $this->normalizeValue(json_decode($trimmedValue, true, flags: JSON_THROW_ON_ERROR));
                } catch (JsonException) {
                    return $trimmedValue;
                }
            }

            return $trimmedValue;
        }

        if (! is_array($value)) {
            return $value;
        }

        $normalizedValue = [];
        foreach ($value as $key => $item) {
            $normalizedValue[$key] = $this->normalizeValue($item);
        }

        if (! array_is_list($normalizedValue)) {
            ksort($normalizedValue);
        }

        return $normalizedValue;
    }

    private function signature(string $payload): string
    {
        $signature = substr(
            hash_hmac('sha256', $payload, $this->resolveSigningKey(), true),
            0,
            self::SIGNATURE_LENGTH_BYTES
        );

        return rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    private function resolveSigningKey(): string
    {
        $key = trim((string) ($this->signingKey ?? config('app.key')));
        if ($key === '') {
            throw new RuntimeException('The application encryption key is required for leave document verification.');
        }

        return $key;
    }
}
