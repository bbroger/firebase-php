<?php

namespace Kreait\Firebase;

use Firebase\Auth\Token\Cache\InMemoryCache;
use Firebase\Auth\Token\Domain\Generator;
use Firebase\Auth\Token\Generator as CustomTokenGenerator;
use Firebase\Auth\Token\HttpKeyStore;
use Firebase\Auth\Token\Verifier;
use Google\Auth\Credentials\GCECredentials;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\Middleware\AuthTokenMiddleware;
use Google\Cloud\Core\ServiceBuilder;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use function GuzzleHttp\Psr7\uri_for;
use Kreait\Firebase;
use Kreait\Firebase\Auth\CustomTokenViaGoogleIam;
use Kreait\Firebase\Exception\InvalidArgumentException;
use Kreait\Firebase\Exception\RuntimeException;
use Kreait\Firebase\Http\Middleware;
use Kreait\Firebase\ServiceAccount\Discoverer;
use Kreait\GcpMetadata;
use Psr\Http\Message\UriInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;

class Factory
{
    /**
     * @var UriInterface|null
     */
    protected $databaseUri;

    /**
     * @var string
     */
    protected $defaultStorageBucket;

    /**
     * @var ServiceAccount|null
     */
    protected $serviceAccount;

    /**
     * @var Discoverer
     */
    protected $serviceAccountDiscoverer;

    /**
     * @var string|null
     */
    protected $uid;

    /**
     * @var array
     */
    protected $claims = [];

    /**
     * @var CacheInterface|null
     */
    protected $verifierCache;

    /**
     * @var array
     */
    protected $httpClientConfig = [];

    /**
     * @var array
     */
    protected $httpClientMiddlewares = [];

    protected static $databaseUriPattern = 'https://%s.firebaseio.com';

    protected static $storageBucketNamePattern = '%s.appspot.com';

    public function withServiceAccount(ServiceAccount $serviceAccount): self
    {
        $factory = clone $this;
        $factory->serviceAccount = $serviceAccount;

        return $factory;
    }

    public function withServiceAccountDiscoverer(Discoverer $discoverer): self
    {
        $factory = clone $this;
        $factory->serviceAccountDiscoverer = $discoverer;

        return $factory;
    }

    public function withDatabaseUri($uri): self
    {
        $factory = clone $this;
        $factory->databaseUri = uri_for($uri);

        return $factory;
    }

    public function withDefaultStorageBucket($name): self
    {
        $factory = clone $this;
        $factory->defaultStorageBucket = $name;

        return $factory;
    }

    /**
     * @param CacheInterface $cache
     *
     * @throws InvalidArgumentException
     *
     * @return self
     */
    public function withVerifierCache(CacheInterface $cache): self
    {
        $factory = clone $this;
        $factory->verifierCache = $cache;

        return $factory;
    }

    public function withHttpClientConfig(array $config): self
    {
        $factory = clone $this;
        $factory->httpClientConfig = $config;

        return $factory;
    }

    public function withHttpClientMiddlewares(array $middlewares): self
    {
        $factory = clone $this;
        $factory->httpClientMiddlewares = $middlewares;

        return $factory;
    }

    public function asUser(string $uid, array $claims = null): self
    {
        $factory = clone $this;
        $factory->uid = $uid;
        $factory->claims = $claims ?? [];

        return $factory;
    }

    public function create(): Firebase
    {
        $database = $this->createDatabase();
        $auth = $this->createAuth();
        $storage = $this->createStorage();
        $remoteConfig = $this->createRemoteConfig();
        $messaging = $this->createMessaging();

        return $this->instantiate(Firebase::class, $database, $auth, $storage, $remoteConfig, $messaging);
    }

    protected function getServiceAccountDiscoverer(): Discoverer
    {
        return $this->serviceAccountDiscoverer ?? new Discoverer();
    }

    protected function getServiceAccount(): ServiceAccount
    {
        if (!$this->serviceAccount) {
            $this->serviceAccount = $this->getServiceAccountDiscoverer()->discover();
        }

        return $this->serviceAccount;
    }

    protected function getDatabaseUri(): UriInterface
    {
        return $this->databaseUri ?: $this->getDatabaseUriFromServiceAccount($this->getServiceAccount());
    }

    protected function getStorageBucketName(): string
    {
        return $this->defaultStorageBucket ?: $this->getStorageBucketNameFromServiceAccount($this->getServiceAccount());
    }

    protected function getDatabaseUriFromServiceAccount(ServiceAccount $serviceAccount): UriInterface
    {
        return uri_for(sprintf(self::$databaseUriPattern, $serviceAccount->getSanitizedProjectId()));
    }

    protected function getStorageBucketNameFromServiceAccount(ServiceAccount $serviceAccount): string
    {
        return sprintf(self::$storageBucketNamePattern, $serviceAccount->getSanitizedProjectId());
    }

    protected function createAuth(): Auth
    {
        $http = $this->createApiClient([
            'base_uri' => 'https://www.googleapis.com/identitytoolkit/v3/relyingparty/',
        ]);

        $customTokenGenerator = $this->createCustomTokenGenerator();
        $keyStore = new HttpKeyStore(new Client(), $this->verifierCache ?: new InMemoryCache());
        $verifier = new Verifier($this->getServiceAccount()->getSanitizedProjectId(), $keyStore);

        return $this->instantiate(Auth::class, $http, $customTokenGenerator, $verifier);
    }

    public function createCustomTokenGenerator(): Generator
    {
        $serviceAccount = $this->getServiceAccount();

        if ($serviceAccount->hasPrivateKey()) {
            return new CustomTokenGenerator($serviceAccount->getClientEmail(), $serviceAccount->getPrivateKey());
        }

        $http = $this->createApiClient(null, ['https://www.googleapis.com/auth/iam']);

        return new CustomTokenViaGoogleIam($serviceAccount->getClientEmail(), $http);
    }

    protected function createDatabase(): Database
    {
        $http = $this->createApiClient();

        $middlewares = [
            'json_suffix' => Firebase\Http\Middleware::ensureJsonSuffix(),
        ];

        if ($this->uid) {
            $authOverride = new Http\Auth\CustomToken($this->uid, $this->claims);

            $middlewares['auth_override'] = Middleware::overrideAuth($authOverride);
        }

        /** @var HandlerStack $handler */
        $handler = $http->getConfig('handler');

        foreach ($middlewares as $name => $middleware) {
            $handler->push($middleware, $name);
        }

        return $this->instantiate(Database::class, $this->getDatabaseUri(), $http);
    }

    protected function createRemoteConfig(): RemoteConfig
    {
        $projectId = $this->getServiceAccount()->getSanitizedProjectId();

        $http = $this->createApiClient([
            'base_uri' => 'https://firebaseremoteconfig.googleapis.com/v1/projects/'.$this->getServiceAccount()->getSanitizedProjectId().'/remoteConfig',
        ]);

        return $this->instantiate(RemoteConfig::class, $projectId, $http);
    }

    protected function createMessaging(): Messaging
    {
        $projectId = $this->getServiceAccount()->getSanitizedProjectId();

        $httpClient = $this->createApiClient();

        return $this->instantiate(Messaging::class, $projectId, $httpClient);
    }

    public function createApiClient(array $config = null, array $additionalScopes = null): Client
    {
        $config = $config ?? [];
        $additionalScopes = $additionalScopes ?? [];

        $googleAuthTokenMiddleware = $this->createGoogleAuthTokenMiddleware($additionalScopes);

        $stack = HandlerStack::create();
        foreach ($this->httpClientMiddlewares as $middleware) {
            $stack->push($middleware);
        }
        $stack->push($googleAuthTokenMiddleware);

        $config = array_merge($this->httpClientConfig, $config, [
            'handler' => $stack,
            'auth' => 'google_auth',
        ]);

        return new Client($config);
    }

    protected function createGoogleAuthTokenMiddleware(array $additionalScopes = null): AuthTokenMiddleware
    {
        $serviceAccount = $this->getServiceAccount();

        $scopes = [
            'https://www.googleapis.com/auth/cloud-platform',
            'https://www.googleapis.com/auth/firebase',
            'https://www.googleapis.com/auth/firebase.database',
            'https://www.googleapis.com/auth/firebase.messaging',
            'https://www.googleapis.com/auth/firebase.remoteconfig',
            'https://www.googleapis.com/auth/userinfo.email',
        ] + ($additionalScopes ?? []);

        if ($serviceAccount->hasClientId() && $serviceAccount->hasPrivateKey()) {
            $credentials = new ServiceAccountCredentials($scopes, [
                'client_email' => $serviceAccount->getClientEmail(),
                'client_id' => $serviceAccount->getClientId(),
                'private_key' => $serviceAccount->getPrivateKey(),
            ]);
        } elseif ((new GcpMetadata())->isAvailable()) {
            $credentials = new GCECredentials();
        } else {
            throw new RuntimeException('Unable to determine credentials.');
        }

        return new AuthTokenMiddleware($credentials);
    }

    protected function createStorage(): Storage
    {
        $builder = $this->getGoogleCloudServiceBuilder();

        $storageClient = $builder->storage([
            'projectId' => $this->getServiceAccount()->getSanitizedProjectId(),
        ]);

        return $this->instantiate(Storage::class, $storageClient, $this->getStorageBucketName());
    }

    protected function getGoogleCloudServiceBuilder(): ServiceBuilder
    {
        $serviceAccount = $this->getServiceAccount();

        $config = [
            'projectId' => $serviceAccount->getProjectId(),
        ];

        if ($serviceAccount->hasClientId() && $serviceAccount->hasPrivateKey()) {
            $config = [
                'keyFile' => [
                    'client_email' => $serviceAccount->getClientEmail(),
                    'client_id' => $serviceAccount->getClientId(),
                    'private_key' => $serviceAccount->getPrivateKey(),
                    'type' => 'service_account',
                ],
            ];
        }

        return new ServiceBuilder($config);
    }

    private function instantiate(string $class, ...$arguments)
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $rc = new ReflectionClass($class);
        $constructor = $rc->getConstructor();

        if (!$constructor) {
            return $rc->newInstanceWithoutConstructor();
        }

        $constructor->setAccessible(true);
        $instance = $rc->newInstanceWithoutConstructor();
        $constructor->invoke($instance, ...$arguments);

        return $instance;
    }
}
