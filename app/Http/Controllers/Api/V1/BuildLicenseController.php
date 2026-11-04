<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Licensing\BuildLicenseContext;
use App\Licensing\ProductLicenseVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class BuildLicenseController extends Controller
{
    public function __invoke(Request $request, ProductLicenseVerifier $licenses): JsonResponse
    {
        // Do not merge query parameters, bearer tokens, account state or cookies.
        $data = Validator::make($request->json()->all(), [
            'license_key' => ['required', 'string', 'max:48'],
            'api_base_url' => ['required', 'string', 'max:2048'],
            'platform' => ['required', 'string', 'in:android,ios'],
            'application_id' => ['required', 'string', 'max:255', 'regex:/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/i'],
            'app_version' => ['required', 'string', 'max:40', 'regex:/^\d+\.\d+\.\d+$/'],
            'build_nonce' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ])->validate();

        try {
            $context = BuildLicenseContext::configured();
        } catch (Throwable) {
            return $this->failure(503, 'build_license_unavailable', 'Build license verification is unavailable for this installation.');
        }
        try {
            $requestedApi = BuildLicenseContext::normalizeHttpsUrl($data['api_base_url']);
        } catch (Throwable) {
            return $this->failure(422, 'build_license_binding_invalid', 'The build API must match this PlayHub installation.');
        }
        if (! hash_equals($context['api_base_url'], $requestedApi)) {
            return $this->failure(422, 'build_license_binding_invalid', 'The build API must match this PlayHub installation.');
        }

        try {
            // Live upstream verification is authoritative; a local receipt alone is not.
            // The existing verifier fixes product and domain server-side. Never persist
            // or return its purchase code, receipt, license identity or provider messages.
            $licenses->verify($data['license_key']);
        } catch (InvalidArgumentException) {
            return $this->failure(422, 'build_license_not_verified', 'The build license could not be verified.');
        } catch (RuntimeException) {
            return $this->failure(403, 'build_license_not_verified', 'The build license could not be verified.');
        } catch (Throwable) {
            return $this->failure(503, 'build_license_unavailable', 'Build license verification is temporarily unavailable.');
        }

        return response()->json([
            'valid' => true,
            'contract_version' => 1,
            'product_slug' => 'playhub',
            ...$context,
            'platform' => $data['platform'],
            'application_id' => $data['application_id'],
            'app_version' => $data['app_version'],
            'build_nonce' => $data['build_nonce'],
        ]);
    }

    private function failure(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'status' => $status]], $status);
    }
}
