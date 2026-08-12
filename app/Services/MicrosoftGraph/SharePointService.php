<?php

declare(strict_types=1);

namespace App\Services\MicrosoftGraph;

use App\Exceptions\GraphException;
use App\Models\DocumentSource;
use App\Services\MicrosoftGraph\DTO\DriveItem;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * SharePoint and OneDrive operations, expressed in this application's terms.
 *
 * Holds the Graph resource paths and the traversal rules; GraphClient holds
 * the transport. A source's configuration supplies only non-sensitive
 * identifiers - site, drive, folder - and never a credential (CLAUDE.md 11).
 */
class SharePointService
{
    /**
     * Requested explicitly so Graph does not return the full driveItem, which
     * carries dozens of fields this application has no use for and would make
     * every page of a large library several times larger than it needs to be.
     */
    private const ITEM_FIELDS = 'id,name,size,eTag,cTag,file,folder,lastModifiedDateTime,webUrl,parentReference';

    public function __construct(
        private readonly GraphClient $client,
        private readonly GraphAuthService $auth,
    ) {}

    /**
     * Every supported file under a source's configured root.
     *
     * Depth-first, one folder at a time, yielding as it goes so a library with
     * tens of thousands of items never materialises in memory.
     *
     * @param  array<int, string>  $allowedExtensions
     * @return Generator<int, DriveItem>
     *
     * @throws GraphException
     */
    public function listFiles(DocumentSource $source, array $allowedExtensions): Generator
    {
        $driveId = $this->resolveDriveId($source);
        $rootPath = $this->rootItemPath($source, $driveId);
        $maxDepth = (int) config('microsoft_graph.max_depth', 12);

        yield from $this->walk($driveId, $rootPath, '', $allowedExtensions, $maxDepth, 0);
    }

    /**
     * One item by id.
     *
     * @throws GraphException
     */
    public function getItem(DocumentSource $source, string $itemId): ?DriveItem
    {
        $driveId = $this->resolveDriveId($source);

        try {
            $payload = $this->client->get(
                "drives/{$driveId}/items/{$itemId}",
                ['$select' => self::ITEM_FIELDS],
            );
        } catch (GraphException $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }

        return DriveItem::fromGraph($payload, $this->relativePathFor($payload, $source));
    }

    /**
     * Stream an item's content to a local path.
     *
     * @throws GraphException
     */
    public function downloadItem(DocumentSource $source, string $itemId, string $destination): void
    {
        $driveId = $this->resolveDriveId($source);

        $this->client->download("drives/{$driveId}/items/{$itemId}/content", $destination);
    }

    /**
     * Check the source is reachable and readable.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(DocumentSource $source): array
    {
        if (! $this->auth->isConfigured()) {
            return [
                'ok' => false,
                'message' => 'Microsoft Graph is not configured on this server. Set the tenant, '
                    .'client and certificate values in the environment.',
            ];
        }

        try {
            $driveId = $this->resolveDriveId($source);
            $rootPath = $this->rootItemPath($source, $driveId);

            $children = iterator_to_array(
                $this->limit($this->client->paginate("{$rootPath}/children", [
                    '$select' => self::ITEM_FIELDS,
                    '$top' => 5,
                ]), 5),
            );
        } catch (GraphException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return [
            'ok' => true,
            'message' => sprintf(
                'Connected using %s authentication. %d item(s) visible in the root folder.',
                $this->auth->credentialType() === 'certificate' ? 'certificate' : 'client secret',
                count($children),
            ),
        ];
    }

    /**
     * Resolve a site by hostname and server-relative path.
     *
     * Used by the discovery command so an administrator does not have to hunt
     * for opaque identifiers by hand.
     *
     * @return array<string, mixed>
     *
     * @throws GraphException
     */
    public function resolveSite(string $hostname, string $sitePath): array
    {
        $sitePath = trim($sitePath, '/');

        return $this->client->get("sites/{$hostname}:/{$sitePath}");
    }

    /**
     * Document libraries on a site.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws GraphException
     */
    public function listDrives(string $siteId): array
    {
        return iterator_to_array($this->client->paginate("sites/{$siteId}/drives"));
    }

    /* ------------------------------------------------------------------ */
    /* Internals */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<int, string>  $allowedExtensions
     * @return Generator<int, DriveItem>
     *
     * @throws GraphException
     */
    private function walk(
        string $driveId,
        string $itemPath,
        string $relativePrefix,
        array $allowedExtensions,
        int $maxDepth,
        int $depth,
    ): Generator {
        if ($depth > $maxDepth) {
            Log::warning('SharePoint traversal stopped at the configured depth limit.', [
                'depth' => $depth,
                'max_depth' => $maxDepth,
            ]);

            return;
        }

        $pageSize = (int) config('microsoft_graph.page_size', 200);

        $children = $this->client->paginate("{$itemPath}/children", [
            '$select' => self::ITEM_FIELDS,
            '$top' => $pageSize,
        ]);

        // Subfolders are collected and descended into *after* this folder's
        // pages are exhausted. Recursing mid-page would interleave paginated
        // reads against the same connection and make a partial failure much
        // harder to reason about.
        $folders = [];

        foreach ($children as $payload) {
            $name = (string) ($payload['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $relativePath = $relativePrefix === '' ? $name : "{$relativePrefix}/{$name}";

            if (is_array($payload['folder'] ?? null)) {
                $folders[] = [$payload['id'] ?? '', $relativePath];

                continue;
            }

            $item = DriveItem::fromGraph($payload, $relativePath);

            if (in_array($item->extension(), $allowedExtensions, true)) {
                yield $item;
            }
        }

        foreach ($folders as [$folderId, $folderPath]) {
            if ($folderId === '') {
                continue;
            }

            yield from $this->walk(
                $driveId,
                "drives/{$driveId}/items/{$folderId}",
                $folderPath,
                $allowedExtensions,
                $maxDepth,
                $depth + 1,
            );
        }
    }

    /**
     * The drive to read, from configuration or the site's default library.
     *
     * @throws GraphException
     */
    private function resolveDriveId(DocumentSource $source): string
    {
        $driveId = $source->config('drive_id');

        if (is_string($driveId) && $driveId !== '') {
            return $driveId;
        }

        $siteId = $source->config('site_id');

        if (! is_string($siteId) || $siteId === '') {
            throw new GraphException(
                'This SharePoint source has no site ID. Edit the source and supply one.',
            );
        }

        $drive = $this->client->get("sites/{$siteId}/drive", ['$select' => 'id,name']);
        $resolved = $drive['id'] ?? null;

        if (! is_string($resolved) || $resolved === '') {
            throw GraphException::notFound('document library');
        }

        return $resolved;
    }

    /**
     * The Graph path addressing the source's root folder.
     *
     * Three ways to point at it, most specific first: an explicit folder item
     * id, a path relative to the library root, or the library root itself.
     */
    private function rootItemPath(DocumentSource $source, string $driveId): string
    {
        $folderItemId = $source->config('folder_item_id');

        if (is_string($folderItemId) && $folderItemId !== '') {
            return "drives/{$driveId}/items/{$folderItemId}";
        }

        $folderPath = $source->config('folder_path');

        if (is_string($folderPath) && trim($folderPath, '/') !== '') {
            $encoded = implode('/', array_map('rawurlencode', explode('/', trim($folderPath, '/'))));

            return "drives/{$driveId}/root:/{$encoded}:";
        }

        return "drives/{$driveId}/root";
    }

    /**
     * Best-effort relative path for a single item fetched by id.
     *
     * Graph gives the parent as a drive-absolute path, so the source's own
     * root prefix is stripped back off to keep stored paths consistent with
     * what the recursive walk produces.
     *
     * @param  array<string, mixed>  $payload
     */
    private function relativePathFor(array $payload, DocumentSource $source): string
    {
        $name = (string) ($payload['name'] ?? '');
        $parentPath = $payload['parentReference']['path'] ?? '';
        $parentPath = is_string($parentPath) ? $parentPath : '';

        // Graph formats this as "/drive/root:/Shared Documents/SOP".
        $parentPath = (string) preg_replace('#^.*?/root:/?#', '', $parentPath);

        $configuredRoot = trim((string) ($source->config('folder_path') ?? ''), '/');

        if ($configuredRoot !== '' && str_starts_with($parentPath, $configuredRoot)) {
            $parentPath = ltrim(substr($parentPath, strlen($configuredRoot)), '/');
        }

        return $parentPath === '' ? $name : "{$parentPath}/{$name}";
    }

    /**
     * @template T
     *
     * @param  Generator<int, T>  $items
     * @return Generator<int, T>
     */
    private function limit(Generator $items, int $max): Generator
    {
        $count = 0;

        foreach ($items as $item) {
            if ($count++ >= $max) {
                return;
            }

            yield $item;
        }
    }
}
