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
use Liminal\Registry\CommandRegistry;
use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\Contract\DefinitionProvider;
use Liminal\Registry\EntityRegistry;
use Liminal\Registry\MenuRegistry;
use Liminal\Registry\MigrationRegistry;
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
        $registries = $this->createRegistries();
        $contributors = $this->instantiateContributors($config);

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
     * Built once per process: the Pipeline is immutable and every middleware
     * is a container singleton, so per-request construction bought nothing.
     */
    private function pipeline(): Pipeline
    {
        if ($this->pipeline !== null) {
            return $this->pipeline;
        }

        $container = $this->container();

        return $this->pipeline = new Pipeline([
            $this->service($container, ErrorHandlerMiddleware::class),
            $this->service($container, RouterMiddleware::class),
            $this->service($container, DispatchMiddleware::class),
        ]);
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

    private function createRegistries(): RegistryCollection
    {
        return new RegistryCollection([
            new RouteRegistry(),
            new MenuRegistry(),
            new PermissionRegistry(),
            new EntityRegistry(),
            new MigrationRegistry(),
            new SettingsRegistry(),
            new CommandRegistry(),
        ]);
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
     * Kernel-structural ids are reserved; everything else layers last-wins in
     * app.libs order, so a later lib (and, in phase 2, a module) may replace an
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
