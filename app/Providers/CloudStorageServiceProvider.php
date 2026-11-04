<?php

namespace App\Providers;

use Aws\CommandInterface;
use Aws\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class CloudStorageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Storage::extend('playhub_s3', function ($app, array $config) {
            $disk = $app['filesystem']->createS3Driver($config);
            if ($config['omit_acl'] ?? true) {
                // Flysystem adds an object ACL even when no visibility is given.
                // Bucket-policy/uniform-access configurations reject that header.
                $disk->getClient()->getHandlerList()->appendInit(Middleware::mapCommand(
                    function (CommandInterface $command): CommandInterface {
                        if (in_array($command->getName(), ['PutObject', 'CreateMultipartUpload', 'CopyObject'], true)) {
                            unset($command['ACL']);
                        }
                        return $command;
                    }
                ), 'playhub_bucket_policy_access');
            }
            return $disk;
        });
    }
}
