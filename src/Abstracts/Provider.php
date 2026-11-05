<?php

namespace Larevio\PersianProviders\Abstracts;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Larevio\PersianProviders\Exceptions\AuthenticationException;
use Larevio\PersianProviders\Exceptions\ConfigurationException;
use Larevio\PersianProviders\Exceptions\InvalidResponseException;
use Larevio\PersianProviders\Exceptions\ProviderException;
use Larevio\PersianProviders\Exceptions\TimeoutException;
use Larevio\PersianProviders\Exceptions\TransportException;
use SensitiveParameter;

abstract class Provider
{
    private const SECRET_KEYS = ['token', 'password', 'pin', 'key', 'sign_key', 'secret_key', 'api_key', 'merchant_id', 'gateway_id', 'username', 'proxy'];

    /** The key used in config and factories. */
    protected string $name;

    /** The provider's name as written in messages. */
    protected string $title;

    protected string $baseUrl;

    /** @var array<int|string, string> Documented messages for providers that return only a code. */
    protected array $errors = [];

    public function __construct(#[SensitiveParameter] protected ?array $config = null) {}

    public function __debugInfo(): array
    {
        return ['name' => $this->name];
    }

    public function getConfig(?string $key = null): mixed
    {
        $defaults = config("persianproviders.{$this->getConfigNamespace()}.$this->name", []);
        $config = array_replace(is_array($defaults) ? $defaults : [], $this->config ?? []);

        if ($key === null) {
            return $config;
        }

        if (! array_key_exists($key, $config)) {
            throw new ConfigurationException("Missing '$key' setting for '$this->name'.");
        }

        return $config[$key];
    }

    abstract protected function getConfigNamespace(): string;

    /** The given provider name, or the configured default for $namespace. */
    protected static function providerName(?string $name, string $namespace): string
    {
        $name ??= config("persianproviders.$namespace.default");
        if (! is_string($name) || $name === '') {
            throw new ConfigurationException("No default $namespace provider is configured.");
        }

        return $name;
    }

    protected function credential(string $key): string
    {
        $value = $this->getConfig($key);

        if ((! is_string($value) && ! is_int($value)) || trim((string) $value) === '') {
            throw new ConfigurationException("The '$key' setting for '$this->name' is empty.");
        }

        return (string) $value;
    }

    protected function request(array $headers = []): PendingRequest
    {
        $config = $this->getConfig();

        $request = Http::baseUrl($this->baseUrl)
            ->connectTimeout((int) ($config['connect_timeout'] ?? config('persianproviders.connect_timeout', 5)))
            ->timeout((int) ($config['timeout'] ?? config('persianproviders.timeout', 15)))
            ->withoutRedirecting()
            ->acceptJson()
            ->withHeaders($headers);

        $proxy = $this->resolveProxy();

        return $proxy ? $request->withOptions(['proxy' => $proxy]) : $request;
    }

    protected function resolveProxy(): ?string
    {
        $providerProxy = $this->getConfig()['proxy'] ?? null;

        if ($providerProxy === false) {
            return null;
        }

        if (is_string($providerProxy) && trim($providerProxy) !== '') {
            return $providerProxy;
        }

        $globalProxy = config('persianproviders.proxy');

        return is_string($globalProxy) && trim($globalProxy) !== '' ? $globalProxy : null;
    }

    protected function post(#[SensitiveParameter] string $path, #[SensitiveParameter] array $data = [], #[SensitiveParameter] array $headers = [], bool $form = false): Response
    {
        return $this->send(function () use ($path, $data, $headers, $form): Response {
            $request = $this->request($headers);

            return ($form ? $request->asForm() : $request)->post($path, $data);
        });
    }

    protected function get(#[SensitiveParameter] string $path, #[SensitiveParameter] array $headers = []): Response
    {
        return $this->send(fn (): Response => $this->request($headers)->get($path));
    }

    protected function redact(string $text): string
    {
        foreach (self::SECRET_KEYS as $key) {
            $value = $this->getConfig()[$key] ?? null;
            if ((is_string($value) || is_int($value)) && strlen((string) $value) >= 4) {
                $text = str_replace([(string) $value, rawurlencode((string) $value)], '[redacted]', $text);
            }
        }

        return $text;
    }

    protected function rejected(Response $response, mixed $code = null, mixed $message = null): ProviderException
    {
        $code = is_int($code) || (is_string($code) && $code !== '') ? $code : $response->status();
        $message = is_string($message) && trim($message) !== '' ? $message : ($this->errors[$code] ?? "$this->title rejected the request (code $code).");
        $exception = in_array($response->status(), [401, 403], true) ? AuthenticationException::class : ProviderException::class;

        return new $exception($this->name, $this->redact($message), $code, $response->status());
    }

    protected function json(Response $response): array
    {
        $data = $response->json();

        if (is_array($data)) {
            return $data;
        }

        if ($response->failed()) {
            throw $this->rejected($response);
        }

        throw new InvalidResponseException("$this->title returned an invalid JSON response (HTTP {$response->status()}).");
    }

    protected function requiredString(array $data, string $key): string
    {
        $value = data_get($data, $key);
        if ((! is_string($value) && ! is_int($value)) || trim((string) $value) === '') {
            throw new InvalidResponseException("$this->title returned an invalid '$key' field.");
        }

        return (string) $value;
    }

    /** No retries: a lost response doesn't mean the request failed. */
    private function send(Closure $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            $message = $this->redact($e->getMessage());
            $exception = str_contains($message, 'cURL error 28') || stripos($message, 'timed out') !== false
                ? TimeoutException::class
                : TransportException::class;

            // Laravel's exception quotes the request URL, which holds the API key for some providers.
            throw new $exception("Could not reach $this->title: $message", 0, new ConnectionException($message));
        }
    }
}
