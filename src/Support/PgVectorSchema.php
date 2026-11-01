<?php

declare(strict_types=1);

namespace Prism\Memory\Support;

use Prism\Memory\Exceptions\UnsafeMemoryConfiguration;

/** @internal Validated SQL names for the extension, independent of table search_path. */
final readonly class PgVectorSchema
{
    public function __construct(public ?string $name = null)
    {
        if ($name !== null && preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,62}\z/', $name) !== 1) {
            throw UnsafeMemoryConfiguration::invalidExtensionSchema();
        }
    }

    public static function from(mixed $name): self
    {
        if ($name !== null && ! is_string($name)) {
            throw UnsafeMemoryConfiguration::invalidExtensionSchema();
        }

        return new self($name);
    }

    public function type(): string
    {
        return $this->qualify('vector');
    }

    public function cosineOperator(): string
    {
        return $this->name === null ? '<=>' : 'OPERATOR("'.$this->name.'".<=>)';
    }

    public function cosineOpclass(): string
    {
        return $this->qualify('vector_cosine_ops');
    }

    public function installStatement(): string
    {
        return 'CREATE EXTENSION IF NOT EXISTS vector'
            .($this->name === null ? '' : ' WITH SCHEMA "'.$this->name.'"');
    }

    private function qualify(string $object): string
    {
        return $this->name === null ? $object : '"'.$this->name.'".'.$object;
    }
}
