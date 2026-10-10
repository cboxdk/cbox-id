<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(is_string($appUrl = env('APP_URL', 'http://localhost')) ? $appUrl : 'http://localhost', '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
         * Cloudflare R2 — storage every pod shares, on a deployment of more than one.
         *
         * An audit-log CSV export is written by the worker and downloaded through a web
         * pod; a compliance JSONL bundle is written by the scheduler. On `local` each pod
         * has its own disk, so a scaled-out deployment points `CBOX_ID_AUDIT_LOGS_EXPORT_DISK`
         * (and, with the JSONL sink, `CBOX_ID_COMPLIANCE_JSONL_DISK`) here. `cbox-id:doctor`
         * says which of them is still local, and fails this disk without a bucket.
         *
         * PRIVATE, and it has to be. Nothing on it is linked to: an export is streamed
         * through the application's own signed, short-lived URL. R2 also refuses the
         * `public-read` ACL Flysystem sends for a public disk (`NotImplemented`), so
         * `private` is the visibility it can be written with. `R2_URL` is only for a bucket
         * served on a public custom domain, and nothing in Cbox ID needs one.
         *
         * Path-style addressing, because the account endpoint
         * (`https://<account-id>.r2.cloudflarestorage.com`) is one host for every bucket;
         * region `auto`, which is what R2 signs for. `throw`, so a failed write fails the
         * job that made it — the export is marked failed, the compliance cursor holds and
         * re-offers the batch — instead of returning a `false` nobody reads.
         *
         * The checksum options keep the AWS SDK from adding a checksum to every request
         * whose operation does not require one, the default that has broken uploads to
         * S3-compatible stores before.
         */
        'r2' => [
            'driver' => 's3',
            'key' => env('R2_ACCESS_KEY_ID'),
            'secret' => env('R2_SECRET_ACCESS_KEY'),
            'region' => 'auto',
            'bucket' => env('R2_BUCKET'),
            'url' => env('R2_URL'),
            'endpoint' => env('R2_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
