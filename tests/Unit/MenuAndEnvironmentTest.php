<?php

namespace IlBronza\DemoDBReplicator\Tests\Unit;

use IlBronza\DemoDBReplicator\DemoDBReplicator;
use IlBronza\DemoDBReplicator\Http\Middleware\DemoDBReplicatorMiddlewareRolesPermissions;
use IlBronza\DemoDBReplicator\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Mockery;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MenuAndEnvironmentTest extends TestCase
{
    public function test_each_database_has_a_menu_entry_under_settings_in_staging_and_local(): void
    {
        $this->importProfile();
        config(['dbreplicator.imports.second' => ['label' => 'Archivio'], 'dbreplicator.imports.third' => ['label' => 'Catalogo']]);
        $menu = Mockery::mock();
        $parent = Mockery::mock();
        $group = Mockery::mock();
        $menu->shouldReceive('provideButton')->twice()->with(Mockery::on(function ($parameters) {
            return $parameters['name'] === 'settings';
        }))->andReturn($parent);
        $menu->shouldReceive('createButton')->twice()->with(Mockery::on(function ($parameters) {
            return $parameters['name'] === 'databaseImports'
                && ! isset($parameters['href'])
                && $parameters['roles'] === ['superadmin'];
        }))->andReturn($group);
        foreach (['main' => 'Gestionale', 'second' => 'Archivio', 'third' => 'Catalogo'] as $name => $label) {
            $child = Mockery::mock();
            $menu->shouldReceive('createButton')->twice()->with(Mockery::on(function ($parameters) use ($name, $label) {
                return $parameters['name'] === 'databaseImport-' . $name
                    && $parameters['href'] === route('dbreplicator.import.show', ['database' => $name])
                    && $parameters['text'] === $label && $parameters['roles'] === ['superadmin'];
            }))->andReturn($child);
            $group->shouldReceive('addChild')->twice()->with($child);
        }
        $parent->shouldReceive('addChild')->twice()->with($group);
        $this->app->instance('menu', $menu);

        foreach (['staging', 'local'] as $environment) {
            $this->environment($environment);
            app(DemoDBReplicator::class)->manageMenuButtons();
        }
    }

    public function test_menu_is_absent_in_production_and_unknown_environments_even_with_profiles(): void
    {
        $this->importProfile();
        $menu = Mockery::mock();
        $menu->shouldNotReceive('provideButton');
        $menu->shouldNotReceive('createButton');
        $this->app->instance('menu', $menu);

        foreach (['production', 'testing', 'preview'] as $environment) {
            $this->environment($environment);
            app(DemoDBReplicator::class)->manageMenuButtons();
        }

        $this->assertTrue(true);
    }

    public function test_menu_is_absent_without_configured_databases(): void
    {
        $this->environment('staging');
        $menu = Mockery::mock();
        $menu->shouldNotReceive('provideButton');
        $menu->shouldNotReceive('createButton');
        $this->app->instance('menu', $menu);
        app(DemoDBReplicator::class)->manageMenuButtons();
        $this->assertTrue(true);
    }

    public function test_direct_web_access_is_blocked_outside_staging(): void
    {
        foreach (['production', 'local', 'testing'] as $environment) {
            $this->environment($environment);

            try {
                (new DemoDBReplicatorMiddlewareRolesPermissions)->handle(Request::create('/'), function () {
                    $this->fail('The route must not execute outside staging.');
                });
                $this->fail('Expected forbidden web access.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
    }

    public function test_staging_web_access_still_requires_the_configured_role(): void
    {
        $this->environment('staging');
        $request = Request::create('/');
        $request->setRouteResolver(static function () {
            return (new Route('GET', '/', []))->name('replicate-db');
        });
        $user = new class {
            public function hasAnyRole(array $roles): bool
            {
                return $roles === ['superadmin'];
            }
        };
        $request->setUserResolver(static function () use ($user) {
            return $user;
        });

        $response = (new DemoDBReplicatorMiddlewareRolesPermissions)->handle($request, static function () {
            return new Response('allowed');
        });

        $this->assertSame('allowed', $response->getContent());
    }

    public function test_registered_web_route_uses_environment_and_role_middleware(): void
    {
        $route = $this->app['router']->getRoutes()->getByName('replicate-db');

        $this->assertNotNull($route);
        $this->assertContains('dbreplicator.roles', $route->gatherMiddleware());
        $this->assertSame(DemoDBReplicatorMiddlewareRolesPermissions::class, $this->app['router']->getMiddleware()['dbreplicator.roles']);
    }

    public function test_staging_web_access_is_denied_without_an_allowed_role(): void
    {
        $this->environment('staging');
        $request = Request::create('/');
        $request->setUserResolver(static function () {
            return new class {
                public function hasAnyRole(array $roles): bool
                {
                    return false;
                }
            };
        });
        $this->expectException(HttpException::class);
        (new DemoDBReplicatorMiddlewareRolesPermissions)->handle($request, function () {
            $this->fail('Unauthorized user must not execute the action.');
        });
    }
}
