<?php

namespace App\Http\Middleware;

use App\Models\BiometricDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security middleware for ZKTeco ADMS (/iclock/*) endpoints.
 * Enforces:
 * 1. Private / Whitelisted Subnet restriction.
 * 2. Strict Device Serial Number Whitelisting (rejects unknown/inactive devices with 403).
 * 3. CommKey (Hardware communication password) verification if configured.
 */
class ValidateBiometricSecurity
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $clientIp = (string) $request->ip();

        // 1. Network Subnet / IP Range Check
        if (! $this->isAllowedIp($clientIp)) {
            Log::warning('ZkAdmsSecurity: Blocked unauthorized IP attempting to access biometric endpoints', [
                'ip' => $clientIp,
                'path' => $request->path(),
                'user_agent' => $request->userAgent(),
            ]);

            return response("ERROR: Network access denied\n", 403, [
                'Content-Type' => 'text/plain',
            ]);
        }

        // 2. Extract Device Serial Number
        $serialNumber = trim((string) $request->query('SN', $request->query('sn', '')));
        if ($serialNumber === '') {
            // Check body for SN if POST devicecmd or cdata
            $body = (string) $request->getContent();
            if (preg_match('/SN=([^\s&]+)/i', $body, $matches)) {
                $serialNumber = trim($matches[1]);
            }
        }

        if ($serialNumber === '') {
            return response("ERROR: Missing Device Serial Number\n", 400, [
                'Content-Type' => 'text/plain',
            ]);
        }

        // 3. Strict Device Whitelist Check
        $device = BiometricDevice::query()
            ->where('serial_number', $serialNumber)
            ->first();

        if (! $device) {
            Log::warning('ZkAdmsSecurity: Blocked unregistered rogue biometric device', [
                'sn' => $serialNumber,
                'ip' => $clientIp,
                'user_agent' => $request->userAgent(),
            ]);

            return response("ERROR: Unauthorized Device\n", 403, [
                'Content-Type' => 'text/plain',
            ]);
        }

        if (! $device->is_active) {
            Log::warning('ZkAdmsSecurity: Blocked disabled biometric device', [
                'sn' => $serialNumber,
                'ip' => $clientIp,
            ]);

            return response("ERROR: Device Disabled\n", 403, [
                'Content-Type' => 'text/plain',
            ]);
        }

        // 4. CommKey (Hardware Communication Password) Verification
        if ($device->comm_key !== null && $device->comm_key !== '' && $device->comm_key !== '0') {
            $providedKey = (string) $request->query('key', $request->query('commkey', $request->header('X-Comm-Key', '')));
            if ($providedKey === '') {
                $body = (string) $request->getContent();
                if (preg_match('/key=([^\s&]+)/i', $body, $matches)) {
                    $providedKey = trim($matches[1]);
                }
            }

            if ($providedKey !== (string) $device->comm_key) {
                Log::warning('ZkAdmsSecurity: CommKey mismatch for biometric device', [
                    'sn' => $serialNumber,
                    'ip' => $clientIp,
                ]);

                return response("ERROR: Invalid Communication Key\n", 403, [
                    'Content-Type' => 'text/plain',
                ]);
            }
        }

        // Bind verified device to request
        $request->attributes->set('biometric_device', $device);

        return $next($request);
    }

    /**
     * Check if client IP is within private subnets or explicitly allowed list.
     */
    private function isAllowedIp(string $ip): bool
    {
        // If subnet restriction is disabled in .env, permit (useful for dev/tunnel testing)
        $restrictSubnet = filter_var(env('BIO_RESTRICT_SUBNET', true), FILTER_VALIDATE_BOOLEAN);
        if (! $restrictSubnet) {
            return true;
        }

        // Localhost always permitted
        if (in_array($ip, ['127.0.0.1', '::1', 'localhost'], true)) {
            return true;
        }

        // Check custom allowed IPs from .env (comma-separated, e.g. "192.168.8.*,10.0.*")
        $allowedIpsConfig = env('BIO_ALLOWED_IPS', '');
        if ($allowedIpsConfig !== '') {
            $allowedPatterns = array_map('trim', explode(',', $allowedIpsConfig));
            foreach ($allowedPatterns as $pattern) {
                if ($pattern !== '' && fnmatch($pattern, $ip)) {
                    return true;
                }
            }
        }

        // Allow RFC 1918 Private IP Spaces (LAN / Office intranet / VPN)
        return $this->isPrivateIp($ip);
    }

    /**
     * Verify if IP is in private address spaces (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16).
     */
    private function isPrivateIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
