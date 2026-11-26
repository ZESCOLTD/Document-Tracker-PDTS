<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class DocumentOcrClient
{
    public function __construct(
        private readonly Factory $http,
        private readonly Repository $config,
    ) {}

    public function extract(UploadedFile $file): array
    {
        $result = $this->review($file);

        return ['text' => $result['text'], 'page_count' => $result['page_count']];
    }

    public function review(UploadedFile $file): array
    {
        $url = rtrim((string) $this->config->get('services.pdts_ai.url'), '/');
        $token = (string) $this->config->get('services.pdts_ai.token');
        $timeout = (int) $this->config->get('services.pdts_ai.timeout', 180);

        if ($url === '' || $token === '' || $timeout < 1) {
            throw new RuntimeException('Document analysis is not configured. You can still enter the document details manually.');
        }

        try {
            $response = $this->http->acceptJson()
                ->withHeaders(['X-Pdts-Ai-Token' => $token])
                ->connectTimeout(10)
                ->timeout($timeout)
                ->withoutRedirecting()
                ->attach('file', $file->get(), basename($file->getClientOriginalName()))
                ->post($url.'/api/ocr');
        } catch (ConnectionException) {
            throw new RuntimeException('The OCR service is unavailable or took too long. Retry analysis or enter the document details manually.');
        }

        if (! $response->successful()) {
            throw new RuntimeException(match ($response->status()) {
                401, 403 => 'The OCR service could not authenticate this request. Contact an administrator or enter the document details manually.',
                413 => 'The scan exceeds the OCR processing limits. Use a smaller scan (up to 50 PDF pages) or enter the details manually.',
                415, 422 => 'The scan could not be read. Use a clear, unencrypted PDF or supported image, or enter the details manually.',
                default => 'Document analysis failed. Retry analysis or enter the document details manually.',
            });
        }

        $text = $response->json('text');
        $pageCount = $response->json('page_count');

        if (! is_string($text) || ! is_int($pageCount) || $pageCount < 1) {
            throw new RuntimeException('The OCR service returned an invalid result. Retry analysis or enter the document details manually.');
        }

        if (trim($text) === '') {
            throw new RuntimeException('No readable text was found. Try a clearer scan or enter the document details manually.');
        }

        if (mb_strlen($text) > 200000) {
            throw new RuntimeException('The extracted text is too large to review here. Use a smaller scan or enter the document details manually.');
        }

        $pages = $response->json('pages', []);
        $suggestions = $response->json('suggestions', []);

        if (! is_array($pages) || ! is_array($suggestions)
            || ! is_array($suggestions['dates'] ?? [])) {
            throw new RuntimeException('The OCR service returned an invalid result. Retry analysis or enter the document details manually.');
        }

        foreach ($pages as $page) {
            if (! is_array($page) || ! is_int($page['page'] ?? null)
                || ! is_string($page['text'] ?? null)
                || ! in_array($page['source'] ?? null, ['ocr', 'embedded_text'], true)
                || (isset($page['confidence']) && (! is_numeric($page['confidence']) || $page['confidence'] < 0 || $page['confidence'] > 1))) {
                throw new RuntimeException('The OCR service returned invalid page analysis. Retry analysis or enter the document details manually.');
            }
        }

        return [
            'text' => trim($text),
            'page_count' => $pageCount,
            'pages' => $pages,
            'suggestions' => [
                'reference_number' => is_string($suggestions['reference_number'] ?? null) ? $suggestions['reference_number'] : null,
                'suggested_subject' => is_string($suggestions['suggested_subject'] ?? null) ? $suggestions['suggested_subject'] : null,
                'dates' => array_values(array_filter($suggestions['dates'] ?? [], 'is_string')),
            ],
        ];
    }
}
