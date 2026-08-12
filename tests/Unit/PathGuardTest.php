<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\UnsafePathException;
use App\Services\Files\PathGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PathGuard is the boundary between operator input and the filesystem, so it
 * is tested directly rather than only through the scanner.
 */
class PathGuardTest extends TestCase
{
    private PathGuard $guard;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new PathGuard;
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pathguard-'.bin2hex(random_bytes(6));

        mkdir($this->root.DIRECTORY_SEPARATOR.'sub', 0o777, true);
        file_put_contents($this->root.DIRECTORY_SEPARATOR.'sub'.DIRECTORY_SEPARATOR.'doc.txt', 'x');
    }

    protected function tearDown(): void
    {
        @unlink($this->root.DIRECTORY_SEPARATOR.'sub'.DIRECTORY_SEPARATOR.'doc.txt');
        @rmdir($this->root.DIRECTORY_SEPARATOR.'sub');
        @rmdir($this->root);

        parent::tearDown();
    }

    #[Test]
    public function it_accepts_a_real_readable_directory(): void
    {
        $this->assertSame(
            realpath($this->root),
            $this->guard->validateSourceRoot($this->root),
        );
    }

    #[Test]
    public function it_rejects_a_relative_path(): void
    {
        $this->expectException(UnsafePathException::class);

        $this->guard->validateSourceRoot('DocumentControl\\SOP');
    }

    #[Test]
    public function it_rejects_a_path_containing_traversal_segments(): void
    {
        $this->expectException(UnsafePathException::class);

        $this->guard->validateSourceRoot($this->root.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'elsewhere');
    }

    #[Test]
    public function it_rejects_a_path_with_a_null_byte(): void
    {
        $this->expectException(UnsafePathException::class);

        $this->guard->normalize("C:\\DocumentControl\0\\evil");
    }

    #[Test]
    public function it_rejects_an_empty_path(): void
    {
        $this->expectException(UnsafePathException::class);

        $this->guard->normalize('   ');
    }

    #[Test]
    public function it_rejects_system_folders_as_a_source_root(): void
    {
        $this->expectException(UnsafePathException::class);

        $systemPath = PHP_OS_FAMILY === 'Windows' ? 'C:\\Windows\\System32' : '/etc';

        $this->guard->validateSourceRoot($systemPath);
    }

    #[Test]
    public function it_allows_a_file_inside_the_root(): void
    {
        $inside = $this->root.DIRECTORY_SEPARATOR.'sub'.DIRECTORY_SEPARATOR.'doc.txt';

        $this->assertSame(
            realpath($inside),
            $this->guard->assertWithin($inside, $this->root),
        );
    }

    #[Test]
    public function it_blocks_a_traversal_that_escapes_the_root(): void
    {
        $this->expectException(UnsafePathException::class);

        $escaping = $this->root.DIRECTORY_SEPARATOR.'sub'.DIRECTORY_SEPARATOR
            .'..'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'outside.txt';

        $this->guard->assertWithin($escaping, $this->root);
    }

    #[Test]
    public function it_does_not_treat_a_sibling_with_a_shared_prefix_as_contained(): void
    {
        // "/data/docs-archive" must not count as inside "/data/docs".
        $this->expectException(UnsafePathException::class);

        $this->guard->assertWithin($this->root.'-archive', $this->root);
    }

    #[Test]
    public function it_returns_a_forward_slashed_relative_path(): void
    {
        $inside = $this->root.DIRECTORY_SEPARATOR.'sub'.DIRECTORY_SEPARATOR.'doc.txt';

        $this->assertSame('sub/doc.txt', $this->guard->relativeTo($inside, $this->root));
    }

    #[Test]
    #[DataProvider('dangerousFileNames')]
    public function it_sanitises_dangerous_file_names(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->guard->sanitizeFileName($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function dangerousFileNames(): array
    {
        return [
            'strips directory traversal' => ['../../etc/passwd', 'passwd'],
            'strips windows separators' => ['..\\..\\windows\\system.ini', 'system.ini'],
            'replaces reserved characters' => ['re:port<1>.docx', 're_port_1_.docx'],
            'strips leading dots' => ['...hidden.docx', 'hidden.docx'],
            'escapes windows device names' => ['CON.txt', '_CON.txt'],
            'falls back for an empty result' => ['...', 'document'],
        ];
    }

    #[Test]
    public function it_recognises_absolute_paths_on_both_platforms(): void
    {
        $this->assertTrue($this->guard->isAbsolute('D:\\DocumentControl\\SOP'));
        $this->assertTrue($this->guard->isAbsolute('\\\\fileserver\\DocumentControl'));
        $this->assertTrue($this->guard->isAbsolute('/mnt/document-control'));
        $this->assertFalse($this->guard->isAbsolute('DocumentControl/SOP'));
    }
}
