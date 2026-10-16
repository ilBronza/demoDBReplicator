<?php

namespace IlBronza\DemoDBReplicator\Tests\Unit;

use IlBronza\DemoDBReplicator\ApiDatabaseImporter;
use IlBronza\DemoDBReplicator\DatabaseReplicator;
use IlBronza\DemoDBReplicator\Http\Controllers\DatabaseImportController;
use IlBronza\DemoDBReplicator\Http\Controllers\DBReplicatorController;
use IlBronza\DemoDBReplicator\ImportConfiguration;
use IlBronza\DemoDBReplicator\MySqlClient;
use IlBronza\DemoDBReplicator\Tests\TestCase;
use Illuminate\Http\Request;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DatabaseApiRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
    }

    public function test_web_import_rejects_a_post_without_csrf_token(): void
    {
        $this->environment('staging');
        $this->importProfile();
        $this->signIn();
        $this->withMiddleware([
            \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
        $service = Mockery::mock(ApiDatabaseImporter::class);
        $service->shouldNotReceive('import');
        $this->app->instance(ApiDatabaseImporter::class, $service);
        $this->post('/dbreplicator/imports/main', ['confirm' => 'main'])->assertStatus(419);
    }

    protected function exportConfiguration(): void
    {
        $this->environment('production');
        config([
            'dbreplicator.export.enabled' => true,
            'dbreplicator.export.databases.main' => ['connection' => 'mysql', 'token' => str_repeat('a', 64)],
            'dbreplicator.export.databases.other' => ['connection' => 'mysql_replications', 'token' => str_repeat('b', 64)],
        ]);
    }

    public function test_export_requires_production_opt_in_tls_and_a_database_specific_token(): void
    {
        $client = Mockery::mock(MySqlClient::class);
        $client->shouldNotReceive('dump');
        $client->shouldNotReceive('ensureDirectory');
        $this->app->instance(MySqlClient::class, $client);
        $this->exportConfiguration();
        $url = 'https://localhost/dbreplicator/api/exports/main';

        $this->postJson($url)->assertUnauthorized();
        $this->withToken('incorrect')->postJson($url)->assertUnauthorized();
        $this->withToken(str_repeat('b', 64))->postJson($url)->assertUnauthorized();
        $this->withToken(str_repeat('a', 64))->postJson('https://localhost/dbreplicator/api/exports/unknown')->assertUnauthorized();
        $this->postJson('http://localhost/dbreplicator/api/exports/main')->assertForbidden();

        config(['dbreplicator.export.enabled' => false]);
        $this->postJson($url)->assertNotFound();
        config(['dbreplicator.export.enabled' => true]);
        $this->environment('staging');
        $this->postJson($url)->assertNotFound();
    }

    public function test_authorized_export_streams_only_allowlisted_connection_and_deletes_remote_dump(): void
    {
        $this->exportConfiguration();
        $client = Mockery::mock(MySqlClient::class)->makePartial();
        $path = null;
        $body = 'CREATE TABLE example (id INT);';
        $client->shouldReceive('databaseIdentity')->once()->with('mysql')->andReturn(str_repeat('c', 64));
        $client->shouldReceive('dump')->once()->with('mysql', Mockery::type('string'))
            ->andReturnUsing(function ($connection, $file) use (&$path, $body) {
                $path = $file;
                file_put_contents($file, $body);
            });
        $client->shouldNotReceive('import');
        $this->app->instance(MySqlClient::class, $client);

        $response = $this->withToken(str_repeat('a', 64))->postJson('https://localhost/dbreplicator/api/exports/main', [
            'connection' => 'mysql_replications', 'database' => 'not_allowlisted',
        ])->assertOk()->assertHeader('X-DBReplicator-SHA256', hash('sha256', $body))
            ->assertHeader('X-DBReplicator-Size', (string) strlen($body))
            ->assertHeader('X-DBReplicator-Source', str_repeat('c', 64));

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($body, $response->streamedContent());
        $this->assertFileDoesNotExist($path);
    }

    public function test_failed_export_does_not_return_partial_sql_and_cleans_the_dump(): void
    {
        $this->exportConfiguration();
        $client = Mockery::mock(MySqlClient::class)->makePartial();
        $client->shouldReceive('databaseIdentity')->andReturn(str_repeat('c', 64));
        $path = null;
        $client->shouldReceive('dump')->andReturnUsing(function ($connection, $file) use (&$path) {
            $path = $file;
            file_put_contents($file, 'partial secret SQL');
            throw new RuntimeException('secret process details');
        });
        $this->app->instance(MySqlClient::class, $client);

        $this->withToken(str_repeat('a', 64))->postJson('https://localhost/dbreplicator/api/exports/main')
            ->assertStatus(503)->assertDontSee('partial secret SQL')->assertDontSee('secret process details');
        $this->assertFileDoesNotExist($path);
    }

    public function test_import_routes_reject_guests_ordinary_users_and_production_before_service_calls(): void
    {
        $this->importProfile();
        $service = Mockery::mock(ApiDatabaseImporter::class);
        $service->shouldNotReceive('import');
        $this->app->instance(ApiDatabaseImporter::class, $service);
        $this->environment('staging');
        $this->getJson('/dbreplicator/imports/main')->assertUnauthorized();
        $this->postJson('/dbreplicator/imports/main', ['confirm' => 'main'])->assertUnauthorized();
        $this->signIn(false);
        $this->getJson('/dbreplicator/imports/main')->assertForbidden();
        $this->postJson('/dbreplicator/imports/main', ['confirm' => 'main'])->assertForbidden();
        $this->signIn();
        $this->environment('production');
        $this->getJson('/dbreplicator/imports/main')->assertForbidden();
        $this->postJson('/dbreplicator/imports/main', ['confirm' => 'main'])->assertForbidden();
        $this->postJson('/dbreplicator/replicate-db')->assertForbidden();
    }

    public function test_get_only_displays_confirmation_and_never_replaces_data(): void
    {
        $this->environment('local');
        $profile = $this->importProfile();
        $this->signIn();
        $service = Mockery::mock(ApiDatabaseImporter::class);
        $service->shouldNotReceive('import');
        $this->app->instance(ApiDatabaseImporter::class, $service);
        $this->get('/dbreplicator/imports/main')->assertOk()
            ->assertSee('Gestionale')->assertSee('local_db')->assertSee('production.example.test')
            ->assertSee('name="_token"', false)->assertSee('method="post"', false)
            ->assertDontSee($profile['token']);
        $this->get('/dbreplicator/replicate-db')->assertStatus(405);
    }

    public function test_post_requires_confirmation_known_profile_and_sessions_outside_the_database(): void
    {
        $this->environment('staging');
        $this->importProfile();
        $this->signIn();
        $service = Mockery::mock(ApiDatabaseImporter::class);
        $service->shouldNotReceive('import');
        $this->app->instance(ApiDatabaseImporter::class, $service);
        $this->postJson('/dbreplicator/imports/unknown', ['confirm' => 'unknown'])->assertNotFound();
        $this->postJson('/dbreplicator/imports/main')->assertUnprocessable();
        $this->postJson('/dbreplicator/imports/main', ['confirm' => 'other'])->assertUnprocessable();

        // Test the controller directly: database session middleware must not open a real connection.
        config(['session.driver' => 'database']);
        $request = Request::create('/', 'POST', ['confirm' => 'main']);
        $request->setUserResolver(fn () => auth()->user());
        try {
            app(DatabaseImportController::class)->import($request, 'main', new ImportConfiguration, $service);
            $this->fail('Expected database session rejection.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_confirmed_post_uses_configured_profile_and_invalidates_old_login_session(): void
    {
        $this->environment('staging');
        $this->importProfile();
        $this->signIn();
        $service = Mockery::mock(ApiDatabaseImporter::class);
        $service->shouldReceive('import')->once()->with('main')->andReturn('/private/backups/previous.sql');
        $this->app->instance(ApiDatabaseImporter::class, $service);
        $this->withSession(['old-login' => 'must disappear'])->post('/dbreplicator/imports/main', [
            'confirm' => 'main', 'destination' => 'production', 'url' => 'https://attacker.test',
        ])->assertOk()->assertSee('/private/backups/previous.sql')->assertSessionMissing('old-login');
    }

    public function test_failed_import_shows_recovery_guidance_without_raw_errors(): void
    {
        $this->environment('staging');
        $this->importProfile();
        $this->signIn();
        $service = Mockery::mock(ApiDatabaseImporter::class);
        $service->shouldReceive('import')->once()->andThrow(new RuntimeException('private SQL data'));
        $this->app->instance(ApiDatabaseImporter::class, $service);
        $this->withSession(['old-login' => 'must disappear'])->post('/dbreplicator/imports/main', ['confirm' => 'main'])
            ->assertStatus(500)->assertSee('recovery')->assertDontSee('private SQL data')
            ->assertSessionMissing('old-login');
    }

    public function test_controllers_also_block_production_without_middleware(): void
    {
        $this->environment('production');
        $request = Request::create('/');
        foreach ([DatabaseImportController::class, DBReplicatorController::class] as $controller) {
            try {
                if ($controller === DBReplicatorController::class) {
                    app($controller)->execute($request);
                } else {
                    app($controller)->show($request, 'main', new ImportConfiguration, new MySqlClient);
                }
                $this->fail('Expected forbidden controller access.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_registered_routes_keep_environment_authentication_and_csrf_boundaries(): void
    {
        $routes = $this->app['router']->getRoutes();
        foreach (['dbreplicator.import.show', 'dbreplicator.import.run'] as $name) {
            $middleware = $routes->getByName($name)->gatherMiddleware();
            $this->assertContains('web', $middleware);
            $this->assertContains('auth', $middleware);
            $this->assertContains('dbreplicator.import', $middleware);
        }
        $route = $routes->getByName('dbreplicator.export');
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(['dbreplicator.export'], $route->gatherMiddleware());
    }
}
