<?php

declare(strict_types=1);

namespace App\Platform\Connect;

/**
 * Everything the quickstart's second step shows for one framework and one app: what to
 * install, the environment file to paste, the code that signs a person in, and how to run it.
 */
final readonly class QuickstartSnippet
{
    public function __construct(
        public string $install,
        /** Where the environment block goes: `.env.local`, `.env`. */
        public string $envFile,
        public string $env,
        /** Where the code goes, in words — a file path. */
        public string $codeFile,
        public string $code,
        public string $run,
    ) {}

    /**
     * @return array{install: string, envFile: string, env: string, codeFile: string, code: string, run: string}
     */
    public function toArray(): array
    {
        return [
            'install' => $this->install,
            'envFile' => $this->envFile,
            'env' => $this->env,
            'codeFile' => $this->codeFile,
            'code' => $this->code,
            'run' => $this->run,
        ];
    }
}
