<?php

declare(strict_types=1);

namespace Shel\ApiConnector\Service;

/*                                                                        *
 * This script belongs to the Flow package "Shel.ApiConnector".           *
 *                                                                        */

use GuzzleHttp\Psr7\Uri;
use Neos\Cache\Exception as CacheException;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Client\Browser;
use Neos\Flow\Http\Client\CurlEngine;
use Neos\Flow\Http\Client\InfiniteRedirectionException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Shel\ApiConnector\Log\ApiConnectorLoggerInterface;

/**
 * Abstract base class for api connectors.
 *
 * Requires settings like:
 *
 * Vendor:
 *   Package:
 *     <implementationName>:
 *       apiUrl: 'https://my.rest.api/v2'
 *       timeout: 30
 *       parameters: # Are optional and will be added to each request
 *         api_key: 'xyz'
 *         format: 'json'
 *       actions:
 *         xyz: 'my_action.php'
 */
abstract class AbstractApiConnector
{
    protected array $requestEngineOptions = [];

    /**
     * This should be overriden for the implementation.
     *
     * @Flow\InjectConfiguration(path="<implementationName>",package="Your.Package")
     * @var array
     */
    protected $apiSettings;

    /**
     * @Flow\Inject
     * @var VariableFrontend
     */
    protected $apiCache;

    /**
     * @Flow\Inject
     * @var VariableFrontend
     */
    protected $fallbackApiCache;

    protected array $objectCache = [];

    /**
     * @Flow\Inject
     * @var ApiConnectorLoggerInterface
     */
    protected $logger;

    /**
     * Retrieves data from the api.
     */
    public function fetchData(string $actionName, array $additionalParameters = []): ?string
    {
        $requestUri = $this->buildRequestUri($actionName, $additionalParameters);
        $useCache = $this->apiSettings['useFallbackCache'];
        $cacheKey = $this->getCacheKey((string)$requestUri);
        $responseText = null;

        if ($useCache) {
            $responseText = $this->fallbackApiCache->get($cacheKey);
        }

        if (is_string($responseText)) {
            $this->logger->info(sprintf('Using fallback cache for action "%s"', $actionName));
            return $responseText;
        }

        // Get new data if cache is empty or invalid
        $response = $this->fetchDataInternal($requestUri);

        if ($response instanceof ResponseInterface) {
            $responseText = $response->getBody()->getContents();

            if ($useCache) {
                try {
                    $this->logger->info(sprintf('Updated fallback cache for action "%s"', $actionName));
                    $this->fallbackApiCache->set($cacheKey, $responseText);
                } catch (CacheException $e) {
                    $this->logger->error('Could not set fallback cache', [$e->getMessage()]);
                }
            }

            return $responseText;
        }

        return null;
    }

    protected function buildRequestUri(string $actionName, array $additionalParameters = []): Uri
    {
        $requestUri = new Uri($this->apiSettings['apiUrl']);
        return $requestUri
            ->withPath($requestUri->getPath() . $this->apiSettings['actions'][$actionName])
            ->withQuery(http_build_query(array_merge($this->apiSettings['parameters'], $additionalParameters)));
    }

    /**
     * Creates a valid cache identifier.
     */
    protected function getCacheKey(string $identifier): string
    {
        return sha1(self::class . '__' . $identifier);
    }

    protected function fetchDataInternal(UriInterface $requestUri): bool|ResponseInterface
    {
        $browser = $this->getBrowser();
        try {
            $response = $browser->request($requestUri, 'GET');
        } catch (\Exception $e) {
            $this->logger->error('Get request to Api failed with exception', [$e->getMessage()]);
            $response = false;
        }

        if ($response !== false && $response->getStatusCode() !== 200) {
            $this->logger->error('Get request to Api failed with code', [$response->getStatusCode()]);
        }

        return $response;
    }

    /**
     * Returns a browser instance with curlengine and authentication parameters set.
     * Authorization parameters will be added if defined in the configuration.
     */
    protected function getBrowser(): Browser
    {
        $browser = new Browser();
        $curlEngine = new CurlEngine();
        foreach ($this->requestEngineOptions as $option => $value) {
            $curlEngine->setOption($option, $value);
        }
        $curlEngine->setOption(CURLOPT_CONNECTTIMEOUT, (int)$this->apiSettings['timeout']);
        $browser->setRequestEngine($curlEngine);

        if (array_key_exists('username', $this->apiSettings) && !empty($this->apiSettings['username'])
            && array_key_exists('password', $this->apiSettings) && !empty($this->apiSettings['password'])
        ) {
            $browser->addAutomaticRequestHeader('Authorization',
                'Basic ' . base64_encode($this->apiSettings['username'] . ':' . $this->apiSettings['password']));
        }

        return $browser;
    }

    /**
     * Json encodes data and posts it to the api
     */
    public function postJsonData(
        string $actionName,
        array $additionalParameters = [],
        array $data = []
    ): bool {
        $browser = $this->getBrowser();
        $browser->addAutomaticRequestHeader('Content-Type', 'application/json');
        $requestUri = $this->buildRequestUri($actionName, $additionalParameters);
        try {
            $response = $browser->request($requestUri, 'POST', [], [], [], json_encode($data));
        } catch (InfiniteRedirectionException $e) {
            $this->logger->error('Post request to Api failed with an infinite redirection', [$e]);
            return false;
        }

        if (!in_array($response->getStatusCode(), [200, 204])) {
            $this->logger->error('Post request to Api failed with message', [$response->getStatusCode()]);
            return false;
        }
        return true;
    }

    protected function getItem(string $cacheKey): mixed
    {
        if (array_key_exists($cacheKey, $this->objectCache)) {
            return $this->objectCache[$cacheKey];
        }
        $item = $this->apiCache->get($cacheKey);
        $this->objectCache[$cacheKey] = $item;

        return $item;
    }

    /**
     * @throws CacheException
     */
    protected function setItem(string $cacheKey, mixed $value, array $tags = []): void
    {
        $this->objectCache[$cacheKey] = $value;
        $this->apiCache->set($cacheKey, $value, $tags);
    }

    protected function unsetItem(string $cacheKey): void
    {
        unset($this->objectCache[$cacheKey]);
        $this->apiCache->remove($cacheKey);
    }
}
