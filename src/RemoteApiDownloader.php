<?php

namespace IlBronza\DemoDBReplicator;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class RemoteApiDownloader
{
    public function download(array $profile, string $path): string
    {
        $partial = $path . '.partial';
        $output = fopen($partial, 'xb');
        if ($output === false) {
            throw new RuntimeException('Cannot create the downloaded dump.');
        }

        try {
            if (! chmod($partial, 0600)) {
                throw new RuntimeException('Cannot protect the downloaded dump.');
            }

            $response = Http::withToken($profile['token'])
                ->accept('application/sql')
                ->withHeaders(['Accept-Encoding' => 'identity'])
                ->connectTimeout(15)
                ->timeout(config('dbreplicator.timeout', 1800))
                ->withOptions(['verify' => true, 'allow_redirects' => false, 'decode_content' => false])
                ->sink($output)
                ->post($profile['url']);

            // Do not include remote response bodies or authorization in exceptions/logs.
            if ($response->status() !== 200) {
                throw new RuntimeException('The production export API returned HTTP ' . $response->status() . '.');
            }

            if (is_resource($output)) {
                fflush($output);
                fclose($output);
            }
            $output = null;
            clearstatcache(true, $partial);
            $size = filesize($partial);
            $checksum = $response->header('X-DBReplicator-SHA256');
            $source = $response->header('X-DBReplicator-Source');
            $expectedSize = $response->header('X-DBReplicator-Size');

            if (! $size || ! ctype_digit($expectedSize) || (string) $size !== $expectedSize
                || ! preg_match('/^[a-f0-9]{64}$/D', $checksum)
                || ! preg_match('/^[a-f0-9]{64}$/D', $source)
                || ! hash_equals($checksum, hash_file('sha256', $partial))) {
                throw new RuntimeException('The downloaded dump is incomplete or its checksum/metadata is invalid.');
            }

            if (! rename($partial, $path)) {
                throw new RuntimeException('Cannot save the verified production dump.');
            }

            return $source;
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_file($partial)) {
                unlink($partial);
            }
        }
    }
}
