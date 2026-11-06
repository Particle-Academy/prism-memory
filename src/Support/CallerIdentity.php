<?php

declare(strict_types=1);

namespace Prism\Memory\Support;

use Prism\Memory\Enums\MemoryKind;
use Prism\Memory\Exceptions\UnstorableMemory;

/** @internal A caller namespace joined to content identity, never a raw stored key. */
final readonly class CallerIdentity
{
    public function __construct(#[\SensitiveParameter] private string $key)
    {
        if ($key === '' || strlen($key) > 512 || preg_match('//u', $key) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $key) === 1
            || preg_match('/\A[\p{Z}\s]*\z/u', $key) === 1) {
            throw UnstorableMemory::invalidCallerIdentity();
        }
    }

    public function leaf(string $content, ?string $role, MemoryKind $kind, string $tool, string $call): string
    {
        return hash('sha256', self::frame([$kind->value, $role ?? '', $tool, $call, $content]));
    }

    public function identify(string $collection, string $leaf, int $occurrence): string
    {
        return 'caller:'.hash('sha256', self::frame([
            'prism-memory:caller-identity:v1', $collection, $this->key, $leaf, (string) $occurrence,
        ]));
    }

    /** @param list<string> $parts */
    private static function frame(array $parts): string
    {
        $framed = '';
        foreach ($parts as $part) {
            $framed .= strlen($part).':'.$part;
        }

        return $framed;
    }
}
