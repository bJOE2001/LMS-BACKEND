<?php

namespace App\Services;

use App\Models\RecycleBin;
use Illuminate\Database\Eloquent\Model;

class RecycleBinService
{
    private const REDACTED_KEYS = [
        'password',
        'remember_token',
        'token',
        'access_token',
        'current_access_token',
    ];

    public function storeDeletedModel(Model $model, mixed $deletedBy = null, array $context = []): RecycleBin
    {
        $snapshot = $context['snapshot'] ?? $model->toArray();
        $sanitizedSnapshot = $this->sanitizeValue($snapshot);

        return RecycleBin::create([
            'entity_type' => (string) ($context['entity_type'] ?? class_basename($model)),
            'table_name' => (string) ($context['table_name'] ?? $model->getTable()),
            'record_primary_key' => (string) ($context['record_primary_key'] ?? $model->getKeyName()),
            'record_primary_value' => (string) ($context['record_primary_value'] ?? $model->getKey()),
            'record_title' => $this->resolveRecordTitle($model, $sanitizedSnapshot, $context),
            'deleted_by_type' => $this->resolveActorType($deletedBy),
            'deleted_by_id' => $this->resolveActorId($deletedBy),
            'deleted_by_name' => $this->resolveActorName($deletedBy),
            'delete_source' => $this->trimNullableString($context['delete_source'] ?? null),
            'delete_reason' => $this->trimNullableString($context['delete_reason'] ?? null),
            'record_snapshot' => $this->encodeSnapshot($sanitizedSnapshot),
            'deleted_at' => $context['deleted_at'] ?? now(),
        ]);
    }

    private function resolveRecordTitle(Model $model, mixed $snapshot, array $context): ?string
    {
        $explicitTitle = $this->trimNullableString($context['record_title'] ?? null);
        if ($explicitTitle !== null) {
            return $explicitTitle;
        }

        $candidates = [
            data_get($snapshot, 'full_name'),
            data_get($snapshot, 'employee_name'),
            data_get($snapshot, 'name'),
            data_get($snapshot, 'title'),
            data_get($snapshot, 'username'),
            data_get($snapshot, 'message'),
            $model->getAttribute('full_name'),
            $model->getAttribute('employee_name'),
            $model->getAttribute('name'),
            $model->getAttribute('title'),
            $model->getAttribute('username'),
        ];

        foreach ($candidates as $candidate) {
            $value = $this->trimNullableString($candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return (string) $model->getKey();
    }

    private function resolveActorType(mixed $actor): ?string
    {
        if ($actor instanceof Model) {
            return class_basename($actor);
        }

        if (is_object($actor)) {
            return class_basename($actor);
        }

        return null;
    }

    private function resolveActorId(mixed $actor): ?string
    {
        if ($actor instanceof Model) {
            return (string) $actor->getKey();
        }

        if (is_object($actor)) {
            $id = $actor->id ?? $actor->control_no ?? null;
            return $id !== null ? (string) $id : null;
        }

        return null;
    }

    private function resolveActorName(mixed $actor): ?string
    {
        if (!is_object($actor)) {
            return null;
        }

        $nameCandidates = [
            $actor->full_name ?? null,
            $actor->name ?? null,
            trim((string) (($actor->firstname ?? '') . ' ' . ($actor->surname ?? ''))),
            $actor->username ?? null,
        ];

        foreach ($nameCandidates as $candidate) {
            $value = $this->trimNullableString($candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function sanitizeValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && in_array(strtolower($key), self::REDACTED_KEYS, true)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $itemKey => $itemValue) {
                $sanitized[$itemKey] = $this->sanitizeValue($itemValue, is_string($itemKey) ? $itemKey : null);
            }

            return $sanitized;
        }

        return $value;
    }

    private function encodeSnapshot(mixed $snapshot): string
    {
        $encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            return json_encode([
                'error' => 'Failed to encode recycle bin snapshot.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"error":"Failed to encode recycle bin snapshot."}';
        }

        return $encoded;
    }

    private function trimNullableString(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));
        return $trimmed === '' ? null : $trimmed;
    }
}
