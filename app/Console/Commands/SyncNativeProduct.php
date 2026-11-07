<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\NativeProduct\NativeProductClient;
use App\Platform\NativeProduct\NativeProductManifest;
use Cbox\Id\AccessControl\Contracts\AppManifests;
use Illuminate\Console\Command;

final class SyncNativeProduct extends Command
{
    protected $signature = 'id:native-product:sync';

    protected $description = 'Publish native ID product roles and permissions without assigning access';

    public function handle(NativeProductClient $client, NativeProductManifest $definition, AppManifests $manifests): int
    {
        $client->synchronize($manifests, $definition);
        $this->info('Native ID product definitions synchronized. Role assignments remain manual.');

        return self::SUCCESS;
    }
}
