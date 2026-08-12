<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * An upload failed validation.
 *
 * Messages are written for the person at the upload form and are safe to
 * display verbatim - they say what was wrong with the file without revealing
 * how the check works.
 */
class RejectedUploadException extends RuntimeException {}
