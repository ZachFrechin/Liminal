<?php

declare(strict_types=1);

namespace Liminal;

use function DI\autowire;

use Liminal\Config\Configuration;
use Liminal\Config\ConfigurationLoader;
use Liminal\Config\Exception\MissingConfigurationException;
use Liminal\Container\ContainerFactory;
use Liminal\Exception\KernelException;
use Liminal\Http\Middleware\DispatchMiddleware;
use Liminal\Http\Middleware\ErrorHandlerMiddleware;
use Liminal\Http\Middleware\RouterMiddleware;
use Liminal\Http\Pipeline;
use Liminal\Http\Router;
use Liminal\Http\UrlGenerator;
use Liminal\Registry\CommandRegistry;
use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\Contract\Module;
use Liminal\Registry\EntityRegistry;
use Liminal\Registry\MenuRegistry;
use Liminal\Registry\MiddlewareRegistry;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\ModuleRegistry;
use Liminal\Registry\PermissionRegistry;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\RouteRegistry;
use Liminal\Registry\SettingsRegistry;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * The kernel is neither a lib nor a module: it only assembles them.
 *
 * boot() is idempotent and performs, in order: load the configuration, create
 * the registries, instantiate the contributors, collect their container
 * definitions, build the container, let every contributor fill the registries,
 * then freeze the whole collection so the system's shape is fixed for the
 * lifetime of the process.
 *
 * Definitions are collected BEFORE the container is built because a built
 * PHP-DI container is immutable — which is also why contributors are plain
 * `new` instances rather than container-resolved services.
 */
final class Kernel
{
    public const string VERSION = '0.1.0-dev';

    private ?ContainerInterface $container = null;

    private ?RegistryCollection $registries = null;

    private ?Configuration $config = null;

    private ?Pipeline $pipeline = null;

    public function __construct(private readonly string $rootDir) {}

    /**
     * @throws MissingConfigurationException when a config file or required key is absent
     * @throws KernelException when a lib in app.libs cannot be assembled
     */
    public function boot(): void
    {
        if ($this->container !== null) {
            return;
        }

        $config = (new ConfigurationLoader($this->rootDir . '/config'))->load('app', 'database');
        $libs = $this->instantiateContributors($config);
        $modules = $this->instantiateModules($config);
        // Libs first, modules after: definition layering is last-wins, and a
        // module's definitions are documented to beat a lib's.
        $contributors = [...$libs, ...$modules];

        $registries = $this->createRegistries($modules);
        $this->registerKernelMiddleware($registries);

        $this->config = $config;
        $this->registries = $registries;
        $this->container = (new ContainerFactory($config))->create(
            $this->mergeDefinitions($this->definitions($config, $registries), $contributors, $config, $registries),
        );

        foreach ($contributors as $contributor) {
            $contributor->contribute($registries);
        }

        // Nothing may extend the system past this point.
        $registries->freeze();

        $this->assertPipelineAnchors($registries->get(MiddlewareRegistry::class));
        $this->assertModuleMigrations($modules, $registries->get(MigrationRegistry::class));
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->boot();

        return $this->pipeline()->handle($request);
    }

    public function container(): ContainerInterface
    {
        $this->boot();

        return $this->container ?? throw KernelException::unavailable('container');
    }

    public function registries(): RegistryCollection
    {
        $this->boot();

        return $this->registries ?? throw KernelException::unavailable('registries');
    }

    public function config(): Configuration
    {
        $this->boot();

        return $this->config ?? throw KernelException::unavailable('configuration');
    }

    /**
     * Built once per process from the MiddlewareRegistry: the Pipeline is
     * immutable and every middleware is a container singleton, so per-request
     * construction bought nothing.
     */
    private function pipeline(): Pipeline
    {
        if ($this->pipeline !== null) {
            return $this->pipeline;
        }

        $container = $this->container();
        $middleware = [];

        foreach ($this->registries()->get(MiddlewareRegistry::class)->all() as $class) {
            $service = $this->service($container, $class);

            if (!$service instanceof MiddlewareInterface) {
                throw KernelException::notAMiddleware($class);
            }

            $middleware[] = $service;
        }

        return $this->pipeline = new Pipeline($middleware);
    }

    /**
     * Resolves a service while proving its type to the caller: the container's
     * get() is typed mixed, and the kernel refuses to hand out unchecked values.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(ContainerInterface $container, string $class): object
    {
        $service = $container->get($class);

        if (!$service instanceof $class) {
            throw KernelException::unexpectedServiceType($class);
        }

        return $service;
    }

    /**
     * @param list<Module> $modules
     */
    private function createRegistries(array $modules): RegistryCollection
    {
        return new RegistryCollection([
            new RouteRegistry(),
            new MiddlewareRegistry(),
            new MenuRegistry(),
            new PermissionRegistry(),
            new EntityRegistry(),
            new MigrationRegistry(),
            new SettingsRegistry(),
            new CommandRegistry(),
            new ModuleRegistry($modules),
        ]);
    }

    /**
     * The kernel's own middleware goes through the same registry as everyone
     * else's — before the contributors run, so anchors win every priority tie.
     */
    private function registerKernelMiddleware(RegistryCollection $registries): void
    {
        $middleware = $registries->get(MiddlewareRegistry::class);
        $middleware->add(ErrorHandlerMiddleware::class, MiddlewareRegistry::ERROR_HANDLER);
        $middleware->add(RouterMiddleware::class, MiddlewareRegistry::ROUTER);
        $middleware->add(DispatchMiddleware::class, MiddlewareRegistry::DISPATCH);
    }

    /**
     * The two placements no contribution may reach: outside the error handler
     * (exceptions would escape unrendered) and behind the dispatcher (the
     * middleware would silently never run).
     *
     * @throws KernelException naming the offending middleware
     */
    private function assertPipelineAnchors(MiddlewareRegistry $middleware): void
    {
        $ordered = $middleware->all();
        $first = $ordered[0] ?? '';
        $last = $ordered[array_key_last($ordered) ?? 0] ?? '';

        if ($first !== ErrorHandlerMiddleware::class) {
            throw KernelException::middlewareOutsideErrorHandler($first);
        }

        if ($last !== DispatchMiddleware::class) {
            throw KernelException::middlewareBehindDispatcher($last);
        }
    }

    /**
     * Libs are manifests: instantiated with plain `new`, before the container
     * exists, so their definitions can still make it into the build.
     *
     * @return list<Contributor>
     */
    private function instantiateContributors(Configuration $config): array
    {
        $contributors = [];

        foreach ($config->stringList('app.libs') as $class) {
            if (!class_exists($class)) {
                throw KernelException::libMissing($class);
            }

            $constructor = new ReflectionClass($class)->getConstructor();

            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                throw KernelException::libNeedsArguments($class);
            }

            $contributor = new $class();

            if (!$contributor instanceof Contributor) {
                throw KernelException::libNotAContributor($class);
            }

            $contributors[] = $contributor;
        }

        return $contributors;
    }

    /**
     * Modules are manifests exactly like libs — plain `new`, no required
     * constructor arguments — with one more contract: they must implement
     * Module, and their identity must fit the core_module columns before
     * anything boots.
     *
     * @return list<Module>
     *
     * @throws KernelException when a module in app.modules cannot be assembled
     */
    private function instantiateModules(Configuration $config): array
    {
        $modules = [];

        foreach ($config->stringList('app.modules') as $class) {
            if (!class_exists($class)) {
                throw KernelException::moduleMissing($class);
            }

            $constructor = new ReflectionClass($class)->getConstructor();

            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                throw KernelException::moduleNeedsArguments($class);
            }

            $module = new $class();

            if (!$module instanceof Module) {
                throw KernelException::notAModule($class);
            }

            $this->assertManifest($module);
            $modules[] = $module;
        }

        return $modules;
    }

    /**
     * Bounds mirror core_module (name VARCHAR(64), version VARCHAR(32)): a
     * manifest that cannot be stored must fail the boot, not the install.
     *
     * @throws KernelException when the name is not a slug or the version does not fit
     */
    private function assertManifest(Module $module): void
    {
        $name = $module->name();

        if (preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $name) !== 1 || strlen($name) > 64) {
            throw KernelException::moduleName($module::class, $name);
        }

        if ($module->version() === '' || strlen($module->version()) > 32) {
            throw KernelException::moduleVersion($module::class, $module->version());
        }
    }

    /**
     * A manifest naming a namespace its contribute() never registered would
     * make module:install silently plan nothing — the same "wrong shape"
     * class the pipeline anchors assertion exists for.
     *
     * @param list<Module> $modules
     *
     * @throws KernelException naming the module and the unregistered namespace
     */
    private function assertModuleMigrations(array $modules, MigrationRegistry $migrations): void
    {
        $registered = $migrations->all();

        foreach ($modules as $module) {
            $namespace = $module->migrationNamespace();

            if ($namespace !== null && !array_key_exists(trim($namespace, '\\'), $registered)) {
                throw KernelException::moduleMigrationsUnregistered($module->name(), $namespace);
            }
        }
    }

    /**
     * Kernel-structural ids are reserved; everything else layers last-wins in
     * app.libs order, so a later lib — and a module, which contributes after every lib — may replace an
     * earlier one's service — never the registries.
     *
     * @param array<string, mixed> $kernelDefinitions
     * @param list<Contributor>    $contributors
     *
     * @return array<string, mixed>
     */
    private function mergeDefinitions(
        array $kernelDefinitions,
        array $contributors,
        Configuration $config,
        RegistryCollection $registries,
    ): array {
        $reserved = [Configuration::class, RegistryCollection::class];

        foreach ($registries->all() as $registry) {
            $reserved[] = $registry::class;
        }

        $definitions = $kernelDefinitions;

        foreach ($contributors as $contributor) {
            if (!$contributor instanceof DefinitionProvider) {
                continue;
            }

            foreach ($contributor->definitions($config) as $id => $definition) {
                if (in_array($id, $reserved, true)) {
                    throw KernelException::reservedService($contributor::class, $id);
                }

                $definitions[$id] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @return array<string, mixed>
     */
    private function definitions(Configuration $config, RegistryCollection $registries): array
    {
        $psr17 = new Psr17Factory();

        $definitions = [
            Configuration::class => $config,
            RegistryCollection::class => $registries,
            ResponseFactoryInterface::class => $psr17,
            StreamFactoryInterface::class => $psr17,
            Router::class => fn(): Router => new Router($registries->get(RouteRegistry::class)),
            UrlGenerator::class => fn(): UrlGenerator => new UrlGenerator($registries->get(RouteRegistry::class)),
            LoggerInterface::class => static function () use ($config): LoggerInterface {
                $logDir = $config->string('app.log_dir');

                if (!is_dir($logDir) && !mkdir($logDir, 0o775, true) && !is_dir($logDir)) {
                    throw KernelException::logDirectory($logDir);
                }

                return new Logger('liminal', [new StreamHandler($logDir . '/liminal.log')]);
            },
            ErrorHandlerMiddleware::class => autowire(ErrorHandlerMiddleware::class)
                ->constructorParameter('debug', $config->bool('app.debug')),
        ];

        foreach ($registries->all() as $registry) {
            $definitions[$registry::class] = $registry;
        }

        return $definitions;
    }
}
