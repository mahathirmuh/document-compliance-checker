<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Databases the suite is allowed to touch.
     *
     * The suite uses RefreshDatabase, which drops every table. This project's
     * working database lives on a shared internal server, so a misconfigured
     * phpunit.xml or a stray DB_DATABASE in the environment could destroy real
     * compliance history. The allow list makes that impossible: anything not
     * named here aborts the run before a single migration executes.
     *
     * @var array<int, string>
     */
    private const ALLOWED_TEST_DATABASES = [
        'document_compliance_test',
        ':memory:',
    ];

    protected function setUp(): void
    {
        $this->guardAgainstNonTestDatabase();

        parent::setUp();
    }

    /**
     * Read straight from the environment, not from config().
     *
     * This has to run before parent::setUp(), because RefreshDatabase wipes
     * the schema from inside it - and at that point the container, and so
     * config(), does not exist yet.
     */
    private function guardAgainstNonTestDatabase(): void
    {
        $database = $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? getenv('DB_DATABASE');

        if (in_array((string) $database, self::ALLOWED_TEST_DATABASES, true)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run tests against database [%s]. The suite drops every table; '
            .'point DB_DATABASE at one of: %s.',
            (string) $database,
            implode(', ', self::ALLOWED_TEST_DATABASES),
        ));
    }
}
