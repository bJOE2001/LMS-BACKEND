<?php

namespace App\Services\Attendance;

use App\Models\BiometricDevice;
use App\Models\Department;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ZkBioTerminalProvisioningService
{
    /**
     * Normalize a terminal IP address, treating empty or ZKBio placeholder '0.0.0.0' as null.
     */
    public function normalizeIp(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        $trimmed = trim($ip);
        if ($trimmed === '' || $trimmed === '0.0.0.0') {
            return null;
        }

        return $trimmed;
    }

    /**
     * Resolve an existing ZKBio Time Area for the department, or create a new Area via the ZKBio REST API.
     * Never returns or assigns Area 1 (Default non-synchronizing area).
     *
     * @return array{
     *     success: bool,
     *     area_id: ?int,
     *     area_code: ?string,
     *     area_name: ?string,
     *     created: bool,
     *     message: string
     * }
     */
    public function resolveOrCreateAreaForDepartment(Department $department, ?int $requestedAreaId = null): array
    {
        if ($requestedAreaId === 1) {
            return [
                'success' => false,
                'area_id' => null,
                'area_code' => null,
                'area_name' => null,
                'created' => false,
                'message' => 'Area 1 (Default) cannot be used for office biometric synchronization.',
            ];
        }

        $token = $this->getZkBioToken();
        $allAreas = $this->fetchAllAreas($token);

        if ($requestedAreaId !== null && $requestedAreaId > 1) {
            foreach ($allAreas as $area) {
                if ($area['id'] === $requestedAreaId) {
                    if ($area['is_default']) {
                        return [
                            'success' => false,
                            'area_id' => null,
                            'area_code' => null,
                            'area_name' => null,
                            'created' => false,
                            'message' => 'The default ZKBio Area cannot be used for office biometric synchronization.',
                        ];
                    }

                    return [
                        'success' => true,
                        'area_id' => $area['id'],
                        'area_code' => $area['area_code'],
                        'area_name' => $area['area_name'],
                        'created' => false,
                        'message' => "Using existing ZKBio Area {$area['area_code']} ({$area['area_name']}).",
                    ];
                }
            }

            return [
                'success' => true,
                'area_id' => $requestedAreaId,
                'area_code' => (string) $requestedAreaId,
                'area_name' => null,
                'created' => false,
                'message' => "Using ZKBio Area ID {$requestedAreaId}.",
            ];
        }

        $matchedArea = $this->findMatchingArea($department, $allAreas);
        if ($matchedArea !== null) {
            return [
                'success' => true,
                'area_id' => $matchedArea['id'],
                'area_code' => $matchedArea['area_code'],
                'area_name' => $matchedArea['area_name'],
                'created' => false,
                'message' => "Matched existing ZKBio Area {$matchedArea['area_code']} ({$matchedArea['area_name']}).",
            ];
        }

        if (! $token) {
            return [
                'success' => false,
                'area_id' => null,
                'area_code' => null,
                'area_name' => null,
                'created' => false,
                'message' => 'Unable to authenticate with ZKBio Time API to create an office Area.',
            ];
        }

        $areaName = mb_substr(trim((string) ($department->acronym ?: $department->name)), 0, 30);
        $nextCode = $this->computeNextAreaCode($allAreas);

        try {
            $response = $this->client($token)->post($this->zkBioUrl('personnel/api/areas/'), [
                'area_code' => $nextCode,
                'area_name' => $areaName,
            ]);

            if (! $response->successful()) {
                Log::error('ZkBioTerminalProvisioningService: Failed to create ZKBio Area', [
                    'department_id' => $department->id,
                    'area_code' => $nextCode,
                    'area_name' => $areaName,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'area_id' => null,
                    'area_code' => null,
                    'area_name' => null,
                    'created' => false,
                    'message' => "ZKBio Time returned HTTP {$response->status()} when creating Area '{$areaName}'.",
                ];
            }

            $createdId = (int) $response->json('id');

            return [
                'success' => true,
                'area_id' => $createdId,
                'area_code' => (string) ($response->json('area_code') ?: $nextCode),
                'area_name' => (string) ($response->json('area_name') ?: $areaName),
                'created' => true,
                'message' => "Created new ZKBio Area {$nextCode} ({$areaName}).",
            ];
        } catch (Throwable $e) {
            Log::error('ZkBioTerminalProvisioningService: Exception creating ZKBio Area', [
                'department_id' => $department->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'area_id' => null,
                'area_code' => null,
                'area_name' => null,
                'created' => false,
                'message' => 'Failed to create ZKBio Area: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Create or update an ADMS biometric terminal inside ZKBio Time via its official REST API.
     *
     * @return array{
     *     success: bool,
     *     zkbio_terminal_id: ?int,
     *     action: string,
     *     message: string
     * }
     */
    public function syncTerminalToZkBio(BiometricDevice $device, int $areaId): array
    {
        if ($areaId <= 1) {
            return [
                'success' => false,
                'zkbio_terminal_id' => null,
                'action' => 'rejected',
                'message' => 'Cannot assign terminal to prohibited Default Area 1.',
            ];
        }

        $token = $this->getZkBioToken();
        if (! $token) {
            return [
                'success' => false,
                'zkbio_terminal_id' => null,
                'action' => 'skipped',
                'message' => 'Unable to authenticate with ZKBio Time API.',
            ];
        }

        $serialNumber = trim((string) $device->serial_number);
        $client = $this->client($token);

        try {
            $existing = $this->findTerminalBySerial($client, $serialNumber);
            $ipForZkBio = $this->normalizeIp($device->ip_address)
                ?? $this->normalizeIp($existing['ip_address'] ?? null)
                ?? '0.0.0.0';

            if ($existing !== null) {
                $zkId = (int) $existing['id'];
                $patchResponse = $client->patch($this->zkBioUrl("iclock/api/terminals/{$zkId}/"), [
                    'alias' => $device->device_name,
                    'area' => $areaId,
                    'ip_address' => $ipForZkBio,
                    'terminal_tz' => 8,
                ]);

                if (! $patchResponse->successful()) {
                    Log::error('ZkBioTerminalProvisioningService: PATCH terminal failed', [
                        'serial_number' => $serialNumber,
                        'zkbio_id' => $zkId,
                        'status' => $patchResponse->status(),
                        'body' => $patchResponse->body(),
                    ]);

                    return [
                        'success' => false,
                        'zkbio_terminal_id' => $zkId,
                        'action' => 'failed',
                        'message' => "ZKBio Time returned HTTP {$patchResponse->status()} while updating terminal.",
                    ];
                }

                return [
                    'success' => true,
                    'zkbio_terminal_id' => $zkId,
                    'action' => 'updated',
                    'message' => "Terminal '{$device->device_name}' updated in ZKBio Time.",
                ];
            }

            $createResponse = $client->post($this->zkBioUrl('iclock/api/terminals/'), [
                'sn' => $serialNumber,
                'alias' => $device->device_name,
                'ip_address' => $ipForZkBio,
                'area' => $areaId,
                'terminal_tz' => 8,
            ]);

            if (! $createResponse->successful()) {
                Log::error('ZkBioTerminalProvisioningService: CREATE terminal failed', [
                    'serial_number' => $serialNumber,
                    'area_id' => $areaId,
                    'status' => $createResponse->status(),
                    'body' => $createResponse->body(),
                ]);

                return [
                    'success' => false,
                    'zkbio_terminal_id' => null,
                    'action' => 'failed',
                    'message' => "ZKBio Time returned HTTP {$createResponse->status()} while registering terminal.",
                ];
            }

            $createdId = (int) $createResponse->json('id');

            return [
                'success' => true,
                'zkbio_terminal_id' => $createdId,
                'action' => 'created',
                'message' => "Terminal '{$device->device_name}' registered in ZKBio Time.",
            ];
        } catch (Throwable $e) {
            Log::error('ZkBioTerminalProvisioningService: Exception syncing terminal to ZKBio Time', [
                'serial_number' => $serialNumber,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'zkbio_terminal_id' => null,
                'action' => 'failed',
                'message' => 'Failed to sync terminal with ZKBio Time: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Delete a terminal from ZKBio Time by exact serial number match when removed from ALMS.
     *
     * @return array{
     *     success: bool,
     *     deleted: bool,
     *     message: string
     * }
     */
    public function deleteTerminalFromZkBio(string $serialNumber): array
    {
        $cleanSn = trim($serialNumber);
        if ($cleanSn === '') {
            return [
                'success' => true,
                'deleted' => false,
                'message' => 'Empty serial number.',
            ];
        }

        $token = $this->getZkBioToken();
        if (! $token) {
            return [
                'success' => false,
                'deleted' => false,
                'message' => 'Unable to authenticate with ZKBio Time API.',
            ];
        }

        $client = $this->client($token);

        try {
            $existing = $this->findTerminalBySerial($client, $cleanSn);
            if ($existing === null) {
                return [
                    'success' => true,
                    'deleted' => false,
                    'message' => 'Terminal not present in ZKBio Time.',
                ];
            }

            $zkId = (int) $existing['id'];
            $deleteResponse = $client->delete($this->zkBioUrl("iclock/api/terminals/{$zkId}/"));

            if (! $deleteResponse->successful()) {
                Log::warning('ZkBioTerminalProvisioningService: DELETE terminal failed', [
                    'serial_number' => $cleanSn,
                    'zkbio_id' => $zkId,
                    'status' => $deleteResponse->status(),
                    'body' => $deleteResponse->body(),
                ]);

                return [
                    'success' => false,
                    'deleted' => false,
                    'message' => "ZKBio Time returned HTTP {$deleteResponse->status()} while deleting terminal.",
                ];
            }

            return [
                'success' => true,
                'deleted' => true,
                'message' => 'Terminal removed from ZKBio Time.',
            ];
        } catch (Throwable $e) {
            Log::warning('ZkBioTerminalProvisioningService: Exception deleting terminal from ZKBio Time', [
                'serial_number' => $cleanSn,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'deleted' => false,
                'message' => 'Failed to remove terminal from ZKBio Time: '.$e->getMessage(),
            ];
        }
    }

    private function zkBioUrl(string $path): string
    {
        return rtrim((string) config('services.zkbio.base_url', 'http://127.0.0.1'), '/').'/'.ltrim($path, '/');
    }

    private function getZkBioToken(): ?string
    {
        $username = config('services.zkbio.api_user');
        $password = config('services.zkbio.api_pass');

        if (empty($username) || empty($password)) {
            return null;
        }

        try {
            return Http::timeout(10)->post($this->zkBioUrl('api-token-auth/'), [
                'username' => $username,
                'password' => $password,
            ])->json('token');
        } catch (Throwable $e) {
            Log::warning('ZkBioTerminalProvisioningService: Token request failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function client(string $token): PendingRequest
    {
        return Http::timeout(15)->withHeaders([
            'Authorization' => 'Token '.$token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * Find a terminal in ZKBio Time using an exact case-insensitive serial number check.
     *
     * @return array<string, mixed>|null
     */
    private function findTerminalBySerial(PendingRequest $client, string $serialNumber): ?array
    {
        $response = $client->get($this->zkBioUrl('iclock/api/terminals/'), [
            'sn' => $serialNumber,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $items = $response->json('data');
        if (! is_array($items)) {
            return null;
        }

        foreach ($items as $item) {
            if (is_array($item) && strcasecmp(trim((string) ($item['sn'] ?? '')), $serialNumber) === 0) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Fetch all ZKBio Time areas from REST API with read-only DB fallback.
     *
     * @return array<int, array{id: int, area_code: string, area_name: string, is_default: bool}>
     */
    private function fetchAllAreas(?string $token = null): array
    {
        if ($token) {
            try {
                $response = $this->client($token)->get($this->zkBioUrl('personnel/api/areas/'), [
                    'page_size' => 500,
                ]);

                if ($response->successful() && is_array($response->json('data'))) {
                    $mapped = [];
                    foreach ($response->json('data') as $row) {
                        $id = (int) ($row['id'] ?? 0);
                        if ($id > 0) {
                            $mapped[] = [
                                'id' => $id,
                                'area_code' => (string) ($row['area_code'] ?? $id),
                                'area_name' => (string) ($row['area_name'] ?? ''),
                                'is_default' => $id === 1 || (bool) ($row['is_default'] ?? false),
                            ];
                        }
                    }

                    return $mapped;
                }
            } catch (Throwable) {
                // Fall back to direct read-only query
            }
        }

        try {
            return DB::connection('zkbio')
                ->table('personnel_area')
                ->orderBy('id')
                ->get()
                ->map(fn ($row): array => [
                    'id' => (int) $row->id,
                    'area_code' => (string) ($row->area_code ?: $row->id),
                    'area_name' => (string) $row->area_name,
                    'is_default' => (int) $row->id === 1 || (int) ($row->is_default ?? 0) === 1,
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Match a department to an existing non-prohibited ZKBio Area by acronym or name.
     *
     * @param  array<int, array{id: int, area_code: string, area_name: string, is_default: bool}>  $areas
     * @return array{id: int, area_code: string, area_name: string, is_default: bool}|null
     */
    private function findMatchingArea(Department $department, array $areas): ?array
    {
        $validAreas = array_values(array_filter(
            $areas,
            fn (array $a): bool => $a['id'] > 1 && ! $a['is_default']
        ));

        $acronym = mb_strtolower(trim((string) $department->acronym));
        $name = mb_strtolower(trim((string) $department->name));

        if ($acronym !== '') {
            foreach ($validAreas as $area) {
                if (mb_strtolower(trim($area['area_name'])) === $acronym) {
                    return $area;
                }
            }

            foreach ($validAreas as $area) {
                $areaLower = mb_strtolower(trim($area['area_name']));
                if ($areaLower !== '' && (str_contains($areaLower, $acronym) || str_contains($acronym, $areaLower))) {
                    return $area;
                }
            }
        }

        if ($name !== '') {
            foreach ($validAreas as $area) {
                $areaLower = mb_strtolower(trim($area['area_name']));
                if ($areaLower !== '' && (str_contains($name, $areaLower) || str_contains($areaLower, $name))) {
                    return $area;
                }
            }
        }

        return null;
    }

    /**
     * Compute the next unused numeric area_code for ZKBio Time.
     *
     * @param  array<int, array{id: int, area_code: string, area_name: string, is_default: bool}>  $areas
     */
    private function computeNextAreaCode(array $areas): string
    {
        $usedCodes = [];
        $maxNumeric = 1;

        foreach ($areas as $area) {
            $code = trim($area['area_code']);
            $usedCodes[mb_strtolower($code)] = true;
            if (ctype_digit($code)) {
                $maxNumeric = max($maxNumeric, (int) $code);
            }
        }

        $candidate = $maxNumeric + 1;
        while (isset($usedCodes[(string) $candidate])) {
            $candidate++;
        }

        return (string) $candidate;
    }
}
