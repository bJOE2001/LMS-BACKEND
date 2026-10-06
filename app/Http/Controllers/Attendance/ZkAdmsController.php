<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Services\Attendance\ZkAdmsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Controller handling real-time push events from ZKTeco MB360 biometric terminals.
 * Uses ZKTeco iClock / ADMS protocol.
 */
class ZkAdmsController extends Controller
{
    public function __construct(
        private readonly ZkAdmsService $admsService
    ) {}

    /**
     * Handle incoming cdata requests (Handshake on GET, Log/User/Template push on POST).
     */
    public function cdata(Request $request): Response
    {
        $this->logAdmsDiagnostic($request, 'cdata');

        if ($request->isMethod('POST')) {
            $result = $this->admsService->handleAttendancePush($request);

            return response($result['response'], 200, [
                'Content-Type' => 'text/plain',
            ]);
        }

        // GET request is device handshake/options negotiation
        $responseContent = $this->admsService->handleHandshake($request);

        return response($responseContent, 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Device polling for pending commands from server.
     */
    public function getrequest(Request $request): Response
    {
        // Only log getrequest when it includes non-trivial parameters or options
        if ($request->query('options') || $request->query('info') || $request->query('table')) {
            $this->logAdmsDiagnostic($request, 'getrequest (special query)');
        }

        $responseContent = $this->admsService->handleGetRequest($request);

        return response($responseContent, 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Device reporting command completion back to server.
     */
    public function devicecmd(Request $request): Response
    {
        $this->logAdmsDiagnostic($request, 'devicecmd');

        $responseContent = $this->admsService->handleDeviceCmd($request);

        return response($responseContent, 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Handle incoming query responses (e.g. templates, users) from device callbacks.
     */
    public function querydata(Request $request): Response
    {
        $this->logAdmsDiagnostic($request, 'querydata');

        $responseContent = $this->admsService->handleQueryData($request);

        return response($responseContent, 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Handle incoming face/photo/biometric push from device.
     */
    public function fdata(Request $request): Response
    {
        $this->logAdmsDiagnostic($request, 'fdata');

        $responseContent = $this->admsService->handleFdata($request);

        return response($responseContent, 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Fallback for any other /iclock/* endpoint hit by the terminal.
     */
    public function fallback(Request $request): Response
    {
        $this->logAdmsDiagnostic($request, 'fallback ['.$request->path().']');

        return response("OK\n", 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Safely log diagnostic details of incoming ADMS requests.
     * Redacts sensitive biometric templates to prevent storing raw biometric data in plain logs.
     */
    private function logAdmsDiagnostic(Request $request, string $endpoint): void
    {
        try {
            $rawBody = (string) $request->getContent();
            $contentType = (string) $request->header('Content-Type', '');
            $isBinary = ! mb_check_encoding($rawBody, 'UTF-8');

            $bodyPreview = '';
            if ($isBinary) {
                $bodyPreview = sprintf(
                    '[BINARY PAYLOAD: length=%d bytes, hex_sample=%s]',
                    strlen($rawBody),
                    bin2hex(substr($rawBody, 0, 32))
                );
            } else {
                // Redact/truncate long template strings (template=..., tmp=...)
                $sanitized = preg_replace_callback(
                    '/(template|tmp)=([^\s\t\r\n&]+)/i',
                    function ($matches) {
                        $key = $matches[1];
                        $val = $matches[2];
                        $len = strlen($val);
                        if ($len > 30) {
                            $preview = substr($val, 0, 15);

                            return "{$key}={$preview}...[REDACTED_TEMPLATE len={$len}]";
                        }

                        return "{$key}={$val}";
                    },
                    $rawBody
                );

                $bodyPreview = (string) $sanitized;
                if (strlen($bodyPreview) > 3000) {
                    $bodyPreview = substr($bodyPreview, 0, 3000).' ...[TRUNCATED total='.strlen($bodyPreview).' bytes]';
                }
            }

            Log::channel('single')->info("=== [ADMS DIAGNOSTIC: {$endpoint}] ===", [
                'method' => $request->method(),
                'path' => $request->path(),
                'url' => $request->fullUrl(),
                'query' => $request->query(),
                'content_type' => $contentType,
                'content_length' => $request->header('Content-Length') ?: strlen($rawBody),
                'client_ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'body' => $bodyPreview !== '' ? $bodyPreview : '[EMPTY BODY]',
                'files' => array_keys($request->allFiles()),
            ]);
        } catch (Throwable $e) {
            Log::warning('ZkAdmsDiagnostic: Failed to log request', ['error' => $e->getMessage()]);
        }
    }
}
