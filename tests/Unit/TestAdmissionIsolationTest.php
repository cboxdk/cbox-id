<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('keeps deployment admission settings out of the test environment loaded from dotenv', function (): void {
    $directory = sys_get_temp_dir().'/id-admission-bootstrap-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    file_put_contents($directory.'/.env', "CBOX_ID_PRODUCT_ACCESS_ENABLED=true\nCBOX_ID_PRODUCT_BILLING_TOKEN=deployment-fixture\nCBOX_WAITLISTS_ENABLED=true\nCBOX_WAITLISTS_BILLING_TOKEN=deployment-fixture\n");

    try {
        $script = 'require $argv[1]; Dotenv\\Dotenv::createImmutable($argv[2])->load(); echo json_encode(['
            .'Illuminate\\Support\\Env::get("CBOX_ID_PRODUCT_ACCESS_ENABLED"),'
            .'Illuminate\\Support\\Env::get("CBOX_ID_PRODUCT_BILLING_TOKEN"),'
            .'Illuminate\\Support\\Env::get("CBOX_WAITLISTS_ENABLED"),'
            .'Illuminate\\Support\\Env::get("CBOX_WAITLISTS_BILLING_TOKEN")]);';
        $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__).'/bootstrap.php', $directory]);
        $process->mustRun();
        expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([false, '', false, '']);
    } finally {
        unlink($directory.'/.env');
        rmdir($directory);
    }
});
