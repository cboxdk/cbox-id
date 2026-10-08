<?php

declare(strict_types=1);

namespace App\Platform\Connect;

/**
 * Everything the quickstart's second step shows for one framework and one app: what to
 * install, the environment file to paste, the code that signs a person in, and how to run
 * it — each a list of blocks, in the order the framework's quickstart page gives them.
 */
final readonly class QuickstartSnippet
{
    /**
     * @param  list<QuickstartBlock>  $install
     * @param  list<QuickstartBlock>  $code
     * @param  list<QuickstartBlock>  $run
     */
    public function __construct(
        public array $install,
        /** Where the environment block goes: `.env.local`, `.env`. */
        public string $envFile,
        public string $env,
        public array $code,
        public array $run,
    ) {}

    /** The same snippet with its environment block replaced — filled in for one app. */
    public function withEnv(string $env): self
    {
        return new self($this->install, $this->envFile, $env, $this->code, $this->run);
    }

    /**
     * The variable names the environment block sets, in order — commented-out lines left out.
     *
     * @return list<string>
     */
    public function envNames(): array
    {
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', $this->env, $matches);

        return $matches[1];
    }

    /**
     * @return array{install: list<array{code: string, file: string|null, note: string|null}>, envFile: string, env: string, code: list<array{code: string, file: string|null, note: string|null}>, run: list<array{code: string, file: string|null, note: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'install' => self::blocks($this->install),
            'envFile' => $this->envFile,
            'env' => $this->env,
            'code' => self::blocks($this->code),
            'run' => self::blocks($this->run),
        ];
    }

    /**
     * @param  list<QuickstartBlock>  $blocks
     * @return list<array{code: string, file: string|null, note: string|null}>
     */
    private static function blocks(array $blocks): array
    {
        return array_map(static fn (QuickstartBlock $block): array => $block->toArray(), $blocks);
    }
}
