<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The analyser could not be reached or gave an unusable answer.
 *
 * Distinct from a document that failed to parse: this says nothing about the
 * document, so the affected version stays queued rather than being recorded
 * as ERROR against its compliance history.
 */
class AnalyzerUnavailableException extends RuntimeException
{
}
