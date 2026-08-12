<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A path failed validation.
 *
 * Messages on this exception are written to be safe to show an operator:
 * they say what is wrong without echoing the offending path back, so a
 * traversal attempt cannot use the error message to probe the filesystem.
 */
class UnsafePathException extends RuntimeException {}
