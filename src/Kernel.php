<?php

declare(strict_types=1);

namespace Liminal;

use function DI\autowire;

use Liminal\Config\Configuration;
use Liminal\Config\ConfigurationLoader;
use Liminal\Container\ContainerFactory;
use Liminal\Http\Middleware\DispatchMiddleware;
use Liminal\Http\Middleware\ErrorHandlerMiddleware;
use Liminal\Http\Middleware\RouterMiddleware;
use Liminal\Http\Pipeline;
use Liminal\Http\Router;
use Liminal\Registry\CommandRegistry;
use Liminal\Registry\Contract\Contributor;
use Liminal\Registry\EntityRegistry;
use Liminal\Registry\MenuRegistry;
use Liminal\Registry\MigrationRegistry;
use Liminal\Registry\PermissionRegistry;
use Liminal\Registry\RegistryCollection;
use Liminal\Registry\RouteRegistry;
use Liminal\Registry\SettingsRegistry;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The kernel is neither a lib nor a module: it only assembles them.
 *
 * boot() is idempotent and performs, in order: load config, build the container,
 * let every contributor fill the registries, then freeze the whole registry
 * collection so the system's shape is fixed for the lifetime of the process.
 */
final class Kernel
{
    private ?ContainerInterface $container = null;

    private ?RegistryCollection $registries = null;

    private ?Configuration $config = null;

    public function __construct(private readonly string $rootDir) {}

    public function boot(): void
    {
        if ($this->container !== null) {
            return;
        }

        $config = (new ConfigurationLoader($this->rootDir . '/config'))->load('app', 'database');
        $registries = $this->createRegistries();

        $this->config = $config;
        $this->registries = $registries;
        $this->container = (new ContainerFactory($config))->create($this->definitions($config));

        $this->contribute($registries, $config);

        // Nothing may extend the system past this point.
        $registries->freeze();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->boot();

        $container = $this->container();

        $pipeline = new Pipeline([
            $this->service($container, ErrorHandlerMiddleware::class),
            $this->service($container, RouterMiddleware::class),
            $this->service($container, DispatchMiddleware::class),
        ]);

        return $pipeline->handle($request);
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
            throw new RuntimeException(sprintf('Container returned an unexpected type for "%s".', $class));
        }

        return $service;
    }

    public function container(): ContainerInterface
    {
        $this->boot();

        return $this->container ?? throw new RuntimeException('Kernel container is unavailable after boot.');
    }

    public function registries(): RegistryCollection
    {
        $this->boot();

        return $this->registries ?? throw new RuntimeException('Kernel registries are unavailable after boot.');
    }

    public function config(): Configuration
    {
        $this->boot();

        return $this->config ?? throw new RuntimeException('Kernel configuration is unavailable after boot.');
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
     * Libs contribute first, in configured order; modules follow in phase 2.
     */
    private function contribute(RegistryCollection $registries, Configuration $config): void
    {
        $container = $this->container ?? throw new RuntimeException('Container must be built before contributing.');

        foreach ($config->stringList('app.libs') as $class) {
            $contributor = $container->get($class);

            if (!$contributor instanceof Contributor) {
                throw new RuntimeException(sprintf(
                    'Lib "%s" must implement %s.',
                    $class,
                    Contributor::class,
                ));
            }

            $contributor->contribute($registries);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function definitions(Configuration $config): array
    {
        $registries = $this->registries ?? throw new RuntimeException('Registries must exist before the container.');

        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();

        return [
            Configuration::class => $config,
            RegistryCollection::class => $registries,
            RouteRegistry::class => $registries->get(RouteRegistry::class),
            MenuRegistry::class => $registries->get(MenuRegistry::class),
            PermissionRegistry::class => $registries->get(PermissionRegistry::class),
            EntityRegistry::class => $registries->get(EntityRegistry::class),
            MigrationRegistry::class => $registries->get(MigrationRegistry::class),
            SettingsRegistry::class => $registries->get(SettingsRegistry::class),
            CommandRegistry::class => $registries->get(CommandRegistry::class),
            ResponseFactoryInterface::class => $psr17,
            \Psr\Http\Message\StreamFactoryInterface::class => $psr17,
            Router::class => fn(): Router => new Router($registries->get(RouteRegistry::class)),
            LoggerInterface::class => static function () use ($config): LoggerInterface {
                $logDir = $config->string('app.log_dir');

                if (!is_dir($logDir) && !mkdir($logDir, 0o775, true) && !is_dir($logDir)) {
                    throw new RuntimeException(sprintf('Unable to create log directory "%s".', $logDir));
                }

                return new Logger('liminal', [new StreamHandler($logDir . '/liminal.log')]);
            },
            ErrorHandlerMiddleware::class => autowire(ErrorHandlerMiddleware::class)
                ->constructorParameter('debug', $config->bool('app.debug')),
        ];
    }
}
