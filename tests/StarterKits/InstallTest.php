<?php

namespace Tests\StarterKits;

use Facades\Statamic\Console\Processes\Composer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\YAML;
use Statamic\Support\Arr;
use Tests\TestCase;

class InstallTest extends TestCase
{
    use Concerns\BacksUpSite;

    protected $files;

    public function setUp(): void
    {
        parent::setUp();

        $this->files = app(Filesystem::class);

        $this->restoreSite();
        $this->backupSite();
        $this->prepareRepo();

        Composer::swap(new FakeComposer($this));
    }

    public function tearDown(): void
    {
        $this->restoreSite();

        parent::tearDown();
    }

    /** @test */
    public function it_installs_starter_kit()
    {
        $this->assertFileNotExists($this->kitVendorPath());
        $this->assertFileNotExists(base_path('copied.md'));

        $this->installCoolRunnings();

        $this->assertFileNotExists($this->kitVendorPath());
        $this->assertFileExists(base_path('copied.md'));
    }

    /** @test */
    public function it_fails_if_starter_kit_config_does_not_exist()
    {
        $this->files->delete($this->kitRepoPath('starter-kit.yaml'));

        $this->installCoolRunnings();

        $this->assertFileNotExists(base_path('copied.md'));
    }

    /** @test */
    public function it_fails_if_an_export_path_doesnt_exist()
    {
        $this->setConfig([
            'export_paths' => [
                'config',
                'does_not_exist',
            ],
        ]);

        $this->installCoolRunnings();

        $this->assertFileNotExists(base_path('copied.md'));
    }

    /** @test */
    public function it_merges_folders()
    {
        $this->files->put($this->preparePath(base_path('content/collections/pages/contact.md')), 'Contact');

        $this->assertFileExists(base_path('content/collections/pages/contact.md'));
        $this->assertFileNotExists(base_path('content/collections/pages/home.md'));

        $this->installCoolRunnings();

        $this->assertFileExists(base_path('content/collections/pages/contact.md'));
        $this->assertFileExists(base_path('content/collections/pages/home.md'));
    }

    /** @test */
    public function it_doesnt_copy_files_not_defined_as_export_paths()
    {
        $this->assertFileNotExists(base_path('copied.md'));
        $this->assertFileNotExists(base_path('not-copied.md'));

        $this->installCoolRunnings();

        $this->assertFileExists(base_path('copied.md'));
        $this->assertFileNotExists(base_path('not-copied.md'));
    }

    /** @test */
    public function it_overwrites_files()
    {
        $this->assertFileExists(config_path('filesystems.php'));
        $this->assertFileDoesntHaveContent('bobsled_pics', config_path('filesystems.php'));

        $this->installCoolRunnings();

        $this->assertFileHasContent('bobsled_pics', config_path('filesystems.php'));
    }

    /** @test */
    public function it_doesnt_copy_starter_kit_config_by_default()
    {
        $this->installCoolRunnings();

        $this->assertFileNotExists(base_path('starter-kit.yaml'));
    }

    /** @test */
    public function it_copies_starter_kit_config_when_option_is_passed()
    {
        $this->installCoolRunnings(['--with-config' => true]);

        $this->assertFileExists(base_path('starter-kit.yaml'));
    }

    /** @test */
    public function it_overwrites_starter_kit_config_when_option_is_passed()
    {
        $this->files->put($configPath = base_path('starter-kit.yaml'), 'old config');

        $this->installCoolRunnings(['--with-config' => true]);

        $this->assertFileDoesntHaveContent('old config', $configPath);
        $this->assertFileHasContent('export_paths', $configPath);
    }

    /** @test */
    public function it_doesnt_clear_site_by_default()
    {
        $this->files->put($this->preparePath(base_path('content/collections/pages/contact.md')), 'Contact');
        $this->files->put($this->preparePath(base_path('content/collections/blog/article.md')), 'Article');

        $this->installCoolRunnings();

        $this->assertFileExists(base_path('content/collections/pages/home.md'));
        $this->assertFileExists(base_path('content/collections/pages/contact.md'));
        $this->assertFileExists(base_path('content/collections/blog/article.md'));
    }

    /** @test */
    public function it_clears_site_when_option_is_passed()
    {
        $this->files->put($this->preparePath(base_path('content/collections/pages/contact.md')), 'Contact');
        $this->files->put($this->preparePath(base_path('content/collections/blog/article.md')), 'Article');

        $this->installCoolRunnings(['--clear-site' => true]);

        $this->assertFileExists(base_path('content/collections/pages/home.md'));
        $this->assertFileNotExists(base_path('content/collections/pages/contact.md'));
        $this->assertFileNotExists(base_path('content/collections/blog'));
    }

    /** @test */
    public function it_installs_dependencies()
    {
        $this->setConfig([
            'export_paths' => [
                'config',
            ],
            'dependencies' => [
                'statamic/seo-pro' => '^0.2.0',
                'bobsled/speed-calculator' => '^1.0.0',
            ],
        ]);

        Http::fake([
            'repo.packagist.org/*' => Http::response('', 200),
        ]);

        $this->assertFileNotExists(base_path('vendor/statamic/cool-runnings'));
        $this->assertFileNotExists(base_path('vendor/statamic/seo-pro'));
        $this->assertComposerJsonDoesntHave('statamic/seo-pro');
        $this->assertFileNotExists(base_path('vendor/bobsled/speed-calculator'));
        $this->assertComposerJsonDoesntHave('bobsled/speed-calculator');

        $this->installCoolRunnings();

        $this->assertFileNotExists(base_path('vendor/statamic/cool-runnings'));
        $this->assertFileExists(base_path('vendor/statamic/seo-pro'));
        $this->assertComposerJsonHasPackageVersion('require', 'statamic/seo-pro', '^0.2.0');
        $this->assertFileExists(base_path('vendor/bobsled/speed-calculator'));
        $this->assertComposerJsonHasPackageVersion('require', 'bobsled/speed-calculator', '^1.0.0');
    }

    /** @test */
    public function it_installs_dev_dependencies()
    {
        $this->setConfig([
            'export_paths' => [
                'config',
            ],
            'dependencies_dev' => [
                'statamic/ssg' => '*',
            ],
        ]);

        Http::fake([
            'repo.packagist.org/*' => Http::response('', 200),
        ]);

        $this->assertFileNotExists(base_path('vendor/statamic/cool-runnings'));
        $this->assertFileNotExists(base_path('vendor/statamic/ssg'));
        $this->assertComposerJsonDoesntHave('statamic/ssg');

        $this->installCoolRunnings();

        $this->assertFileNotExists(base_path('vendor/statamic/cool-runnings'));
        $this->assertFileExists(base_path('vendor/statamic/ssg'));
        $this->assertComposerJsonHasPackageVersion('require-dev', 'statamic/ssg', '*');
    }

    /** @test */
    public function it_installs_both_types_of_dependencies()
    {
        $this->setConfig([
            'export_paths' => [
                'config',
            ],
            'dependencies' => [
                'statamic/seo-pro' => '^0.2.0',
                'bobsled/speed-calculator' => '^1.0.0',
            ],
            'dependencies_dev' => [
                'statamic/ssg' => '*',
            ],
        ]);

        Http::fake([
            'repo.packagist.org/*' => Http::response('', 200),
        ]);

        $this->assertFileNotExists(base_path('vendor/statamic/cool-runnings'));
        $this->assertFileNotExists(base_path('vendor/statamic/seo-pro'));
        $this->assertComposerJsonDoesntHave('statamic/seo-pro');
        $this->assertFileNotExists(base_path('vendor/bobsled/speed-calculator'));
        $this->assertComposerJsonDoesntHave('bobsled/speed-calculator');
        $this->assertFileNotExists(base_path('vendor/statamic/ssg'));
        $this->assertComposerJsonDoesntHave('statamic/ssg');

        $this->installCoolRunnings();

        $this->assertFileNotExists(base_path('vendor/statamic/cool-runnings'));
        $this->assertFileExists(base_path('vendor/statamic/seo-pro'));
        $this->assertComposerJsonHasPackageVersion('require', 'statamic/seo-pro', '^0.2.0');
        $this->assertFileExists(base_path('vendor/bobsled/speed-calculator'));
        $this->assertComposerJsonHasPackageVersion('require', 'bobsled/speed-calculator', '^1.0.0');
        $this->assertFileExists(base_path('vendor/statamic/ssg'));
        $this->assertComposerJsonHasPackageVersion('require-dev', 'statamic/ssg', '*');
    }

    /** @test */
    public function it_doesnt_create_custom_repositories_when_all_dependencies_are_found_on_packagist()
    {
        $this->setConfig([
            'export_paths' => [
                'config',
                'copied.md',
            ],
            'dependencies' => [
                'statamic/seo-pro' => '^0.2.0',
                'bobsled/speed-calculator' => '^1.0.0',
            ],
            'dependencies_dev' => [
                'statamic/ssg' => '*',
            ],
        ]);

        Http::fake([
            'repo.packagist.org/*' => Http::response('', 200),
        ]);

        $this->installCoolRunnings();

        $this->assertComposerJsonDoesntHave('repositories');
    }

    /** @test */
    public function it_leaves_behind_existing_custom_repositories_when_all_dependencies_are_found_on_packagist()
    {
        $this->setConfig([
            'export_paths' => [
                'config',
                'copied.md',
            ],
            'dependencies' => [
                'statamic/seo-pro' => '^0.2.0',
                'bobsled/speed-calculator' => '^1.0.0',
            ],
            'dependencies_dev' => [
                'statamic/ssg' => '*',
            ],
        ]);

        $composerJson = json_decode($this->files->get(base_path('composer.json')), true);

        $repositories = [
            [
                'type' => 'path',
                'url' => base_path('repos/some-repo'),
            ],
            [
                'type' => 'vcs',
                'url' => 'https://github.com/hansolo/kessel-run',
            ],
        ];

        $composerJson['repositories'] = $repositories;

        $this->files->put(
            base_path('composer.json'),
            json_encode($composerJson, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        Http::fake([
            'repo.packagist.org/*' => Http::response('', 200),
        ]);

        $this->installCoolRunnings();

        $composerJson = json_decode($this->files->get(base_path('composer.json')), true);

        $this->assertEquals($repositories, $composerJson['repositories']);
    }

    /** @test */
    public function it_creates_custom_repositories_if_not_available_through_packagist()
    {
        $this->setConfig([
            'export_paths' => [
                'config',
                'copied.md',
            ],
            'dependencies' => [
                'statamic/seo-pro' => '^0.2.0',
                'bobsled/speed-calculator' => '^1.0.0',
            ],
            'dependencies_dev' => [
                'llama/jump-calculator' => '^1.0.0',
                'rhino/impact-calculator' => '^1.0.0',
            ],
        ]);

        Http::fake([
            'repo.packagist.org/p2/statamic/*' => Http::response('', 200),
            'github.com/bobsled/*' => Http::response('', 200),
            'bitbucket.org/llama/*' => Http::response('', 200),
            'gitlab.com/rhino/*' => Http::response('', 200),
        ]);

        $this->installCoolRunnings();

        $expected = [
            [
                'type' => 'vcs',
                'url' => 'https://github.com/bobsled/speed-calculator',
            ],
            [
                'type' => 'vcs',
                'url' => 'https://bitbucket.org/llama/jump-calculator',
            ],
            [
                'type' => 'vcs',
                'url' => 'https://gitlab.com/rhino/impact-calculator',
            ],
        ];

        $this->assertEquals($expected, json_decode($this->files->get(base_path('composer.json')), true)['repositories']);
    }

    /** @test */
    public function it_merges_in_custom_repositories_if_not_available_through_packagist()
    {
        $this->setConfig([
            'export_paths' => [
                'config',
                'copied.md',
            ],
            'dependencies' => [
                'statamic/seo-pro' => '^0.2.0',
                'bobsled/speed-calculator' => '^1.0.0',
            ],
            'dependencies_dev' => [
                'llama/jump-calculator' => '^1.0.0',
                'rhino/impact-calculator' => '^1.0.0',
            ],
        ]);

        $composerJson = json_decode($this->files->get(base_path('composer.json')), true);

        $repositories = [
            [
                'type' => 'path',
                'url' => base_path('repos/some-repo'),
            ],
            [
                'type' => 'vcs',
                'url' => 'https://github.com/hansolo/kessel-run',
            ],
        ];

        $composerJson['repositories'] = $repositories;

        $this->files->put(
            base_path('composer.json'),
            json_encode($composerJson, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        Http::fake([
            'repo.packagist.org/p2/statamic/*' => Http::response('', 200),
            'github.com/bobsled/*' => Http::response('', 200),
            'bitbucket.org/llama/*' => Http::response('', 200),
            'gitlab.com/rhino/*' => Http::response('', 200),
        ]);

        $this->installCoolRunnings();

        $expected = [
            [
                'type' => 'path',
                'url' => base_path('repos/some-repo'),
            ],
            [
                'type' => 'vcs',
                'url' => 'https://github.com/hansolo/kessel-run',
            ],
            [
                'type' => 'vcs',
                'url' => 'https://github.com/bobsled/speed-calculator',
            ],
            [
                'type' => 'vcs',
                'url' => 'https://bitbucket.org/llama/jump-calculator',
            ],
            [
                'type' => 'vcs',
                'url' => 'https://gitlab.com/rhino/impact-calculator',
            ],
        ];

        $this->assertEquals($expected, json_decode($this->files->get(base_path('composer.json')), true)['repositories']);
    }

    /** @test */
    public function it_restores_existing_custom_repositories_if_any_cannot_be_found()
    {
        $this->setConfig([
            'export_paths' => [
                'config',
                'copied.md',
            ],
            'dependencies' => [
                'statamic/seo-pro' => '^0.2.0',
            ],
        ]);

        $composerJson = json_decode($this->files->get(base_path('composer.json')), true);

        $repositories = [
            [
                'type' => 'path',
                'url' => base_path('repos/some-repo'),
            ],
            [
                'type' => 'vcs',
                'url' => 'https://github.com/hansolo/kessel-run',
            ],
        ];

        $composerJson['repositories'] = $repositories;

        $this->files->put(
            base_path('composer.json'),
            json_encode($composerJson, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        Http::fake([
            '*' => Http::response('', 404),
        ]);

        $this->installCoolRunnings();

        $expected = [
            [
                'type' => 'path',
                'url' => base_path('repos/some-repo'),
            ],
            [
                'type' => 'vcs',
                'url' => 'https://github.com/hansolo/kessel-run',
            ],
        ];

        $this->assertEquals($expected, json_decode($this->files->get(base_path('composer.json')), true)['repositories']);
    }

    private function kitRepoPath($path = null)
    {
        return collect([base_path('repo/cool-runnings'), $path])->filter()->implode('/');
    }

    protected function kitVendorPath($path = null)
    {
        return collect([base_path('vendor/statamic/cool-runnings'), $path])->filter()->implode('/');
    }

    private function prepareRepo()
    {
        $this->files->copyDirectory(__DIR__.'/__fixtures__/cool-runnings', $this->kitRepoPath());
    }

    private function setConfig($config)
    {
        $this->files->put($this->kitRepoPath('starter-kit.yaml'), YAML::dump($config));
    }

    private function preparePath($path)
    {
        $folder = preg_replace('/(.*)\/[^\/]+\.[^\/]+/', '$1', $path);

        if (! $this->files->exists($folder)) {
            $this->files->makeDirectory($folder, 0755, true);
        }

        return $path;
    }

    private function installCoolRunnings($options = [])
    {
        $this->artisan('statamic:starter-kit:install', array_merge([
            'package' => 'statamic/cool-runnings',
            '--no-interaction' => true,
        ], $options));
    }

    private function assertFileHasContent($expected, $path)
    {
        $this->assertFileExists($path);

        $this->assertStringContainsString($expected, $this->files->get($path));
    }

    private function assertFileDoesntHaveContent($expected, $path)
    {
        $this->assertFileExists($path);

        $this->assertStringNotContainsString($expected, $this->files->get($path));
    }

    private function assertComposerJsonHasPackageVersion($requireKey, $package, $version)
    {
        $composerJson = json_decode($this->files->get(base_path('composer.json')), true);

        $this->assertEquals($version, $composerJson[$requireKey][$package]);
    }

    private function assertComposerJsonDoesntHave($package)
    {
        $this->assertFileDoesntHaveContent($package, base_path('composer.json'));
    }
}

class FakeComposer
{
    public function __construct()
    {
        $this->files = app(Filesystem::class);
    }

    public function require($package, $version = null)
    {
        $this->fakeInstallComposerJson('require', $package, $version);
        $this->fakeInstallVendorFiles($package);
    }

    public function requireDev($package, $version = null)
    {
        $this->fakeInstallComposerJson('require-dev', $package, $version);
        $this->fakeInstallVendorFiles($package);
    }

    public function remove($package)
    {
        $this->removeFromComposerJson($package);
        $this->removeFromVendorFiles($package);
    }

    public function runAndOperateOnOutput($args, $callback)
    {
        $args = collect($args);

        if (! $args->contains('require')) {
            return;
        }

        $requireMethod = $args->contains('--dev')
            ? 'requireDev'
            : 'require';

        $package = $args->first(function ($arg) {
            return preg_match('/[^\/]+\/[^\/]+/', $arg);
        });

        $version = $args->first(function ($arg) {
            return preg_match('/\./', $arg);
        });

        $this->{$requireMethod}($package, $version);
    }

    private function fakeInstallComposerJson($requireKey, $package, $version)
    {
        $composerJson = json_decode($this->files->get(base_path('composer.json')), true);

        $composerJson[$requireKey][$package] = $version ?? '*';

        $this->files->put(
            base_path('composer.json'),
            json_encode($composerJson, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    private function removeFromComposerJson($package)
    {
        $composerJson = json_decode($this->files->get(base_path('composer.json')), true);

        Arr::forget($composerJson, "require.{$package}");
        Arr::forget($composerJson, "require-dev.{$package}");

        $this->files->put(
            base_path('composer.json'),
            json_encode($composerJson, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    private function fakeInstallVendorFiles($package)
    {
        if ($package === 'statamic/cool-runnings') {
            $this->files->copyDirectory(base_path('repo/cool-runnings'), base_path("vendor/{$package}"));
        } else {
            $this->files->makeDirectory(base_path("vendor/{$package}"), 0755, true);
        }
    }

    private function removeFromVendorFiles($package)
    {
        if ($this->files->exists($path = base_path("vendor/{$package}"))) {
            $this->files->deleteDirectory($path);
        }
    }

    public function __call($method, $args)
    {
        return $this;
    }
}