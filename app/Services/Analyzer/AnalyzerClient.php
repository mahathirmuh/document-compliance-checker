<?php

declare(strict_types=1);

namespace App\Services\Analyzer;

use App\Exceptions\AnalyzerUnavailableException;
use App\Services\Analyzer\DTO\AnalysisResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The only thing in Laravel that talks to the Python analyser.
 *
 * The endpoint is versioned (`/api/{version}/analyze`) and the response is
 * immediately parsed into AnalysisResult, so the rest of the application
 * never sees the wire format. Swapping the Python service for a different
 * implementation should mean changing this class and nothing else
 * (CLAUDE.md 14, 35.8).
 *
 * Phase 1 ships with `documents.analyzer.enabled` false: nothing calls this
 * yet, and isEnabled() is what the queue job checks before it tries.
 */
class AnalyzerClient
{
    public function isEnabled(): bool
    {
        return (bool) config('documents.analyzer.enabled', false);
    }

    /**
     * Ask the analyser to process one file.
     *
     * @throws AnalyzerUnavailableException when the service cannot be reached
     *                                      or answers with an error
     */
    public function analyze(string $filePath, int $documentId, int $versionId): AnalysisResult
    {
        $baseUrl = rtrim((string) config('documents.analyzer.base_url'), '/');
        $version = (string) config('documents.analyzer.api_version', 'v1');
        $apiKey = config('documents.analyzer.api_key');

        $request = Http::timeout((int) config('documents.analyzer.timeout', 120))
            ->acceptJson()
            ->asJson();

        if (is_string($apiKey) && $apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        try {
            $response = $request->post("{$baseUrl}/api/{$version}/analyze", [
                'file_path' => $filePath,
                'document_id' => $documentId,
                'version_id' => $versionId,
            ]);
        } catch (ConnectionException $e) {
            // The message is logged, not surfaced: a connection error can
            // contain the analyser URL, and the operator-facing message stays
            // generic (CLAUDE.md 30).
            throw new AnalyzerUnavailableException(
                'The document analyzer service could not be reached.',
                previous: $e,
            );
        }

        if ($response->failed()) {
            throw new AnalyzerUnavailableException(sprintf(
                'The document analyzer service returned HTTP %d.',
                $response->status(),
            ));
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new AnalyzerUnavailableException('The document analyzer returned an unreadable response.');
        }

        return AnalysisResult::fromArray($payload);
    }

    /**
     * Liveness check for the settings screen.
     *
     * @return array{ok: bool, message: string}
     */
    public function ping(): array
    {
        if (! $this->isEnabled()) {
            return ['ok' => false, 'message' => 'The analyzer is disabled in configuration.'];
        }

        $baseUrl = rtrim((string) config('documents.analyzer.base_url'), '/');

        try {
            $response = Http::timeout(5)->get("{$baseUrl}/health");
        } catch (ConnectionException) {
            return ['ok' => false, 'message' => 'The analyzer service is not reachable.'];
        }

        return $response->successful()
            ? ['ok' => true, 'message' => 'Analyzer is reachable.']
            : ['ok' => false, 'message' => 'Analyzer responded with HTTP '.$response->status().'.'];
    }
}
