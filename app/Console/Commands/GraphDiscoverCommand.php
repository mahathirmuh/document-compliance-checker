<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\GraphException;
use App\Services\MicrosoftGraph\GraphAuthService;
use App\Services\MicrosoftGraph\SharePointService;
use Illuminate\Console\Command;

/**
 * Finds the identifiers a SharePoint source needs.
 *
 * Site and drive ids are long opaque strings that are genuinely awkward to
 * obtain from the SharePoint UI. Without this, registering a source means
 * hand-crafting Graph Explorer queries, which is where typos and
 * wrong-library mistakes come from.
 *
 * Read-only, and prints no credential.
 */
class GraphDiscoverCommand extends Command
{
    protected $signature = 'graph:discover
                            {hostname : SharePoint host, e.g. contoso.sharepoint.com}
                            {site-path : Server-relative site path, e.g. /sites/DocumentControl}';

    protected $description = 'Look up the site and drive IDs needed to register a SharePoint source';

    public function handle(GraphAuthService $auth, SharePointService $sharePoint): int
    {
        if (! $auth->isConfigured()) {
            $this->error('Microsoft Graph is not configured.');
            $this->line('Set MS_GRAPH_TENANT_ID, MS_GRAPH_CLIENT_ID and either');
            $this->line('MS_GRAPH_CERTIFICATE_PATH (preferred) or MS_GRAPH_CLIENT_SECRET.');

            return self::FAILURE;
        }

        $hostname = (string) $this->argument('hostname');
        $sitePath = (string) $this->argument('site-path');

        $this->line("Authenticating with a {$auth->credentialType()}...");

        try {
            $site = $sharePoint->resolveSite($hostname, $sitePath);
            $siteId = (string) ($site['id'] ?? '');

            $this->newLine();
            $this->info('Site');
            $this->line('  name    : '.($site['displayName'] ?? '—'));
            $this->line('  site_id : '.$siteId);

            $drives = $sharePoint->listDrives($siteId);
        } catch (GraphException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($drives === []) {
            $this->warn('No document libraries are visible on that site.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Document libraries');
        $this->table(
            ['Name', 'drive_id'],
            array_map(
                fn (array $drive) => [$drive['name'] ?? '—', $drive['id'] ?? '—'],
                $drives,
            ),
        );

        $this->newLine();
        $this->line('Register a source with the site_id and the drive_id of the library you want,');
        $this->line('optionally narrowing it with a folder path such as "General/SOP".');

        return self::SUCCESS;
    }
}
