<?php

namespace App\Console\Commands;

use App\Services\Ai\AzureOpenAiClient;
use Illuminate\Console\Command;
use RuntimeException;

class AzureOpenAiCheckCommand extends Command
{
    protected $signature = 'azure:check';

    protected $description = 'Verify Azure OpenAI with one short, billable test prompt and no document data.';

    public function handle(AzureOpenAiClient $client): int
    {
        $this->info('Checking Azure OpenAI with a short test prompt. No document data will be sent.');

        try {
            $client->complete([
                ['role' => 'user', 'content' => 'This is a connection test. Reply with only OK.'],
            ]);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Azure OpenAI connection successful. The deployment returned a complete text response.');

        return self::SUCCESS;
    }
}
