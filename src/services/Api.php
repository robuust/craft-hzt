<?php

namespace robuust\hzt\services;

use Craft;
use craft\helpers\Json;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;
use robuust\hzt\models\Settings;
use robuust\hzt\Plugin;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Authenticated access to the HZT integration API.
 */
class Api extends Component
{
    private Settings $settings;
    private Client $client;

    /**
     * Configure the HTTP client without requesting an access token yet.
     */
    public function init(): void
    {
        parent::init();

        $this->settings = Plugin::getInstance()->getSettings();

        if (!$this->settings->validate(['host', 'apiVersion', 'clientId', 'clientSecret'])) {
            throw new InvalidConfigException('Configure the HZT host, API version, client ID, and client secret in config/hzt.php.');
        }

        $this->client = Craft::createGuzzleClient([
            'base_uri' => rtrim($this->settings->host, '/').'/',
            'headers' => ['Accept' => 'application/json'],
            'connect_timeout' => 10,
            'timeout' => 30,
            'allow_redirects' => false,
            'http_errors' => true,
        ]);
    }

    /**
     * Send an API request, renewing a rejected access token once.
     *
     * @param string               $method
     * @param string               $path
     * @param array<string, mixed> $options
     *
     * @return ResponseInterface
     */
    public function request(string $method, string $path, array $options = []): ResponseInterface
    {
        $path = 'api/'.$this->settings->apiVersion.'/'.$path;
        $options['headers']['Authorization'] = 'Bearer '.$this->getAccessToken();

        try {
            $response = $this->client->request($method, $path, $options);
        } catch (ClientException $exception) {
            if ($exception->getResponse()->getStatusCode() !== 401) {
                throw $exception;
            }

            Craft::$app->getCache()->delete($this->getTokenCacheKey());
            $options['headers']['Authorization'] = 'Bearer '.$this->getAccessToken();

            $response = $this->client->request($method, $path, $options);
        }

        if ($response->getStatusCode() >= 300) {
            throw new RequestException('The HZT API returned an unexpected redirect.', new Request($method, $path), $response);
        }

        return $response;
    }

    /**
     * Reuse a client credentials token until shortly before its expiry.
     *
     * @return string
     */
    private function getAccessToken(): string
    {
        $cache = Craft::$app->getCache();
        $key = $this->getTokenCacheKey();
        $token = $cache->get($key);

        if ($token !== false) {
            return $token;
        }

        $response = $this->client->post('oauth/token', [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->settings->clientId,
                'client_secret' => $this->settings->clientSecret,
            ],
        ]);

        if ($response->getStatusCode() >= 300) {
            throw new RequestException('The HZT token endpoint returned an unexpected redirect.', new Request('POST', 'oauth/token'), $response);
        }

        $data = Json::decode((string) $response->getBody());
        $duration = (int) $data['expires_in'] - 30;

        if ($duration > 0) {
            $cache->set($key, $data['access_token'], $duration);
        }

        return $data['access_token'];
    }

    /**
     * Separate tokens by API host and client credentials.
     *
     * @return array{string, string, string, string}
     */
    private function getTokenCacheKey(): array
    {
        return [self::class, $this->settings->host, $this->settings->clientId, hash('sha256', $this->settings->clientSecret)];
    }
}
