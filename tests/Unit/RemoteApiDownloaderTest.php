<?php

namespace IlBronza\DemoDBReplicator\Tests\Unit;

use IlBronza\DemoDBReplicator\MySqlClient;
use IlBronza\DemoDBReplicator\RemoteApiDownloader;
use IlBronza\DemoDBReplicator\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RemoteApiDownloaderTest extends TestCase
{
    protected function path(): string
    {
        return app(MySqlClient::class)->ensureDirectory() . '/download.sql';
    }

    protected function headers(string $body): array
    {
        return [
            'X-DBReplicator-SHA256' => hash('sha256', $body),
            'X-DBReplicator-Size' => (string) strlen($body),
            'X-DBReplicator-Source' => str_repeat('b', 64),
        ];
    }

    public function test_verified_dump_is_streamed_to_a_private_file_with_server_authentication(): void
    {
        $profile = $this->importProfile();
        $body = "CREATE TABLE example (id INT);\n";
        Http::preventStrayRequests();
        Http::fake(function ($request, $options) use ($profile, $body) {
            $this->assertSame($profile['url'], $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer ' . $profile['token']));
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertFalse($options['decode_content']);
            $this->assertIsResource($options['sink']);

            return Http::response($body, 200, $this->headers($body));
        });

        $path = $this->path();
        $this->assertSame(str_repeat('b', 64), app(RemoteApiDownloader::class)->download($profile, $path));
        $this->assertSame($body, file_get_contents($path));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertFileDoesNotExist($path . '.partial');
    }

    public function test_bad_metadata_and_incomplete_downloads_never_become_importable_files(): void
    {
        $body = 'SQL dump';
        $cases = [
            ['', $this->headers('')],
            [$body, []],
            [$body, array_replace($this->headers($body), ['X-DBReplicator-SHA256' => str_repeat('0', 64)])],
            [$body, array_replace($this->headers($body), ['X-DBReplicator-Size' => '999'])],
            [$body, array_replace($this->headers($body), ['X-DBReplicator-Source' => 'invalid'])],
        ];
        $profile = $this->importProfile();
        $path = $this->path();
        foreach ($cases as [$content, $headers]) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(['*' => Http::response($content, 200, $headers)]);
            try {
                app(RemoteApiDownloader::class)->download($profile, $path);
                $this->fail('Expected rejected transfer.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('incomplete', $exception->getMessage());
            }
            $this->assertFileDoesNotExist($path);
            $this->assertFileDoesNotExist($path . '.partial');
        }
    }

    public function test_http_errors_and_redirects_are_rejected_without_exposing_response_bodies(): void
    {
        foreach ([302, 401, 403, 500] as $status) {
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(['*' => Http::response('secret error details', $status, ['Location' => 'https://elsewhere.test'])]);
            $path = $this->path();
            try {
                app(RemoteApiDownloader::class)->download($this->importProfile(), $path);
                $this->fail('Expected failed transfer.');
            } catch (RuntimeException $exception) {
                $this->assertSame('The production export API returned HTTP ' . $status . '.', $exception->getMessage());
            }
            $this->assertFileDoesNotExist($path);
            $this->assertFileDoesNotExist($path . '.partial');
        }
    }

    public function test_connection_failure_removes_the_partial_file(): void
    {
        Http::fake(static function () {
            throw new \Illuminate\Http\Client\ConnectionException('connection interrupted');
        });
        $path = $this->path();
        try {
            app(RemoteApiDownloader::class)->download($this->importProfile(), $path);
            $this->fail('Expected connection failure.');
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            $this->assertFileDoesNotExist($path . '.partial');
            $this->assertFileDoesNotExist($path);
        }
    }
}
