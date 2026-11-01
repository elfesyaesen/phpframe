<?php

declare(strict_types=1);

namespace System\Container\Tag;

final class TagRegistry
{
    /**
     * @var array<string, list<string>>
     */
    private array $tags = [];

    /**
     * @param list<string> $ids
     */
    public function add(string $tag, array $ids): void
    {
        foreach ($ids as $id) {
            if (!in_array($id, $this->tags[$tag] ?? [], true)) {
                $this->tags[$tag][] = $id;
            }
        }
    }

    /**
     * @return list<string>
     */
    public function members(string $tag): array
    {
        return $this->tags[$tag] ?? [];
    }

    public function has(string $tag): bool
    {
        return isset($this->tags[$tag]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_keys($this->tags);
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    public function tagsOf(string $id): array
    {
        $out = [];

        foreach ($this->tags as $tag => $ids) {
            if (in_array($id, $ids, true)) {
                $out[] = $tag;
            }
        }

        sort($out);

        return $out;
    }

    /**
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        $tags = $this->tags;
        ksort($tags);

        return $tags;
    }

    public function isEmpty(): bool
    {
        return $this->tags === [];
    }

    public function count(): int
    {
        return count($this->tags);
    }
}
