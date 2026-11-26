<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use RuntimeException;

class AzureOpenAiClient
{
    public function __construct(
        private readonly Factory $http,
        private readonly Repository $config,
    ) {}

    public function complete(array $messages, ?int $maxCompletionTokens = null): string
    {
        $settings = $this->config->get('services.azure_openai', []);
        $endpoint = rtrim(trim((string) ($settings['endpoint'] ?? '')), '/');
        $deployment = trim((string) ($settings['deployment'] ?? ''));
        $key = trim((string) ($settings['api_key'] ?? ''));
        $version = trim((string) ($settings['api_version'] ?? ''));

        if ($endpoint === '' || $deployment === '' || $key === '') {
            throw new RuntimeException('The AI service is not configured. Contact an administrator to configure the endpoint, deployment and API key.');
        }

        $parts = parse_url($endpoint);
        $path = $parts['path'] ?? '';

        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($path, ['', '/openai/v1'], true)) {
            throw new RuntimeException('Use an HTTPS AI resource endpoint or /openai/v1 base URL, without credentials, query parameters or a full request path.');
        }

        if ($version !== '' && $path !== '') {
            throw new RuntimeException('For a versioned AI API, use the resource root endpoint. For /openai/v1, leave the API version setting empty.');
        }

        $url = $version === ''
            ? $endpoint.($path === '' ? '/openai/v1' : '').'/chat/completions'
            : $endpoint.'/openai/deployments/'.rawurlencode($deployment).'/chat/completions?'.http_build_query(['api-version' => $version]);

        $timeout = (int) ($settings['timeout'] ?? 90);
        $maxTokens = $maxCompletionTokens ?? (int) ($settings['max_completion_tokens'] ?? 1024);

        if ($timeout < 1 || $maxTokens < 1 || $messages === []) {
            throw new RuntimeException('The AI service requires messages and positive timeout and completion token limits.');
        }

        try {
            $response = $this->http->acceptJson()
                ->asJson()
                ->withHeaders(['api-key' => $key])
                ->connectTimeout(10)
                ->timeout($timeout)
                ->withoutRedirecting()
                ->post($url, [
                    'model' => $deployment,
                    'messages' => $messages,
                    'max_completion_tokens' => $maxTokens,
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not reach the AI service. Check network access, the endpoint, TLS configuration and the request timeout.');
        }

        if (! $response->successful()) {
            throw new RuntimeException(match ($response->status()) {
                401, 403 => 'The AI service denied access. Check the API key, resource permissions and network restrictions.',
                404 => 'The AI deployment or endpoint was not found. Check the endpoint, deployment name and API version.',
                429 => 'The AI service quota or rate limit was reached. Try again later or check your deployment quota.',
                default => 'The AI request failed (HTTP '.$response->status().'). Check the deployment, API version and model compatibility.',
            });
        }

        $choice = $response->json('choices.0');

        if (! is_array($choice) || ($choice['finish_reason'] ?? null) !== 'stop'
            || ! empty($choice['message']['refusal'])
            || ! is_string($choice['message']['content'] ?? null)
            || trim($choice['message']['content']) === '') {
            throw new RuntimeException('The AI service did not return a complete text answer. The response may be refused, filtered, truncated or incompatible.');
        }

        return trim($choice['message']['content']);
    }
}
