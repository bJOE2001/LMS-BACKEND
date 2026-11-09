<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Services\Attendance\NativeAdmsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NativeAdmsController extends Controller
{
    public function __construct(
        protected NativeAdmsService $admsService
    ) {}

    /**
     * Primary ADMS endpoint (GET /iclock/cdata for handshake, POST /iclock/cdata for punches).
     */
    public function cdata(Request $request): Response
    {
        $sn = $this->extractSerialNumber($request);
        if ($sn === '') {
            return $this->plainTextResponse("ERROR: Missing SN\n", 400);
        }

        if ($request->isMethod('POST')) {
            $result = $this->admsService->handleDataPush($request, $sn);

            return $this->plainTextResponse($result['response_text']."\n");
        }

        $handshake = $this->admsService->handleHandshake($request, $sn);

        return $this->plainTextResponse($handshake);
    }

    /**
     * Device command polling and heartbeat endpoint (GET /iclock/getrequest).
     */
    public function getrequest(Request $request): Response
    {
        $sn = $this->extractSerialNumber($request);
        if ($sn === '') {
            return $this->plainTextResponse("ERROR: Missing SN\n", 400);
        }

        $response = $this->admsService->handleGetRequest($request, $sn);

        return $this->plainTextResponse($response."\n");
    }

    /**
     * Device command acknowledgment endpoint (POST /iclock/devicecmd).
     */
    public function devicecmd(Request $request): Response
    {
        $sn = $this->extractSerialNumber($request);
        if ($sn === '') {
            return $this->plainTextResponse("ERROR: Missing SN\n", 400);
        }

        $response = $this->admsService->handleDeviceCmd($request, $sn);

        return $this->plainTextResponse($response."\n");
    }

    /**
     * Device registry endpoint (GET / POST /iclock/registry).
     */
    public function registry(Request $request): Response
    {
        $sn = $this->extractSerialNumber($request);
        if ($sn === '') {
            return $this->plainTextResponse("ERROR: Missing SN\n", 400);
        }

        $response = $this->admsService->handleRegistry($request, $sn);

        return $this->plainTextResponse($response);
    }

    /**
     * Biometric face / photo data upload endpoint (POST /iclock/fdata).
     */
    public function fdata(Request $request): Response
    {
        return $this->plainTextResponse("OK\n");
    }

    /**
     * Query data upload endpoint (POST /iclock/querydata).
     */
    public function querydata(Request $request): Response
    {
        $sn = $this->extractSerialNumber($request);
        if ($sn === '') {
            return $this->plainTextResponse("ERROR: Missing SN\n", 400);
        }

        $result = $this->admsService->handleDataPush($request, $sn);

        return $this->plainTextResponse($result['response_text']."\n");
    }

    /**
     * Build a minimal ADMS HTTP response with explicit Content-Length and no session cookies.
     * ZKTeco embedded C HTTP clients require Content-Length to read multi-packet responses (>1460 bytes).
     */
    private function plainTextResponse(string $body, int $status = 200): Response
    {
        return response($body, $status, [
            'Content-Type' => 'text/plain',
            'Content-Length' => (string) strlen($body),
            'Pragma' => 'no-cache',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Extract device serial number from query parameters, form input, or headers.
     */
    private function extractSerialNumber(Request $request): string
    {
        $sn = $request->query('SN')
            ?: $request->query('sn')
            ?: $request->input('SN')
            ?: $request->input('sn')
            ?: $request->header('X-Serial-Number')
            ?: '';

        return trim((string) $sn);
    }
}
