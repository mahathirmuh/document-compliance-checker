<?php

declare(strict_types=1);

namespace App\Services\Analyzer;

use App\Exceptions\AnalyzerUnavailableException;
use App\Services\Analyzer\DTO\AnalysisResult;
use App\Services\Analyzer\DTO\DocumentExtraction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
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
    /**
     * @param  array<string, array<string, mixed>>  $rules  Document Control rules to apply
     */
    public function analyze(
        string $filePath,
        int $documentId,
        int $versionId,
        array $rules = [],
    ): AnalysisResult {
        $payload = [
            'file_path' => $filePath,
            'document_id' => $documentId,
            'version_id' => $versionId,
        ];

        // Omitted entirely when nothing is enabled. Sending an empty object
        // would be equivalent, but leaving the key out keeps the request
        // identical to what an older analyzer expects.
        if ($rules !== []) {
            $payload['rules'] = $rules;
        }

        return AnalysisResult::fromArray($this->send('analyze', $payload));
    }

    /**
     * Read one file back, paired up by section and language.
     *
     * Nothing is stored. The result is rendered for whoever asked and then
     * discarded, so the application never holds a second copy of a controlled
     * document (CLAUDE.md 12).
     *
     * @throws AnalyzerUnavailableException when the service cannot be reached
     *                                      or answers with an error
     */
    public function extract(
        string $filePath,
        ?int $documentId = null,
        ?int $versionId = null,
        ?int $maxCharacters = null,
    ): DocumentExtraction {
        $payload = array_filter(
            [
                'file_path' => $filePath,
                'document_id' => $documentId,
                'version_id' => $versionId,
                'max_characters' => $maxCharacters,
            ],
            static fn ($value) => $value !== null,
        );

        return DocumentExtraction::fromArray($this->send('extract', $payload));
    }

    /* ------------------------------------------------------------------ */

    /**
     * Post to one versioned analyzer endpoint and return the decoded body.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws AnalyzerUnavailableException
     */
    private function send(string $endpoint, array $payload): array
    {
        $baseUrl = rtrim((string) config('documents.analyzer.base_url'), '/');
        $version = (string) config('documents.analyzer.api_version', 'v1');

        try {
            $response = $this->request()->post("{$baseUrl}/api/{$version}/{$endpoint}", $payload);
        } catch (ConnectionException $e) {
            // The underlying message is kept for the log only: a connection
            // error can contain the analyser URL, and the operator-facing
            // message stays generic (CLAUDE.md 30).
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

        $body = $response->json();

        if (! is_array($body)) {
            throw new AnalyzerUnavailableException('The document analyzer returned an unreadable response.');
        }

        return $body;
    }

    private function request(): PendingRequest
    {
        $apiKey = config('documents.analyzer.api_key');

        $request = Http::timeout((int) config('documents.analyzer.timeout', 120))
            ->acceptJson()
            ->asJson();

        return is_string($apiKey) && $apiKey !== ''
            ? $request->withToken($apiKey)
            : $request;
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
