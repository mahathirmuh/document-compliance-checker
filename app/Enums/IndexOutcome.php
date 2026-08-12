<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What indexing one discovered file actually did.
 *
 * The scan counters are built from these, which is how an operator can tell
 * change detection is working: a second scan over an untouched folder should
 * report every file as UNCHANGED and queue nothing.
 */
enum IndexOutcome: string
{
    case NEW = 'NEW';
    case MODIFIED = 'MODIFIED';
    case UNCHANGED = 'UNCHANGED';

    /** Recognised, but deliberately not indexed - e.g. a blocked extension. */
    case SKIPPED = 'SKIPPED';

    /** Indexing threw. The file stays as it was and the scan continues. */
    case FAILED = 'FAILED';

    /** Whether this outcome should put the document in the analysis queue. */
    public function shouldQueueAnalysis(): bool
    {
        return in_array($this, [self::NEW, self::MODIFIED], true);
    }
}
