<?php

declare(strict_types=1);

namespace App\Domain\Event\Codec;

use App\Domain\Error\InvalidInput;
use App\Domain\Git\CommitSha;
use App\Domain\Identity\UlidId;

/**
 * Typed fields of a decoded JSON object. A missing nullable field reads as null, and unknown fields are ignored.
 * Every error is an {@see InvalidInput} that names the dotted field, for example `payload.requestIds.1`.
 */
final readonly class PayloadReader
{
    /**
     * @param array<mixed> $data
     */
    private function __construct(
        private array $data,
        private string $path,
    ) {
    }

    /**
     * @param array<mixed> $data a decoded JSON object
     * @param string       $path dotted field of $data, '' for the whole input
     */
    public static function of(array $data, string $path = 'payload'): self
    {
        return new self($data, $path);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function field(string $key): string
    {
        return '' === $this->path ? $key : $this->path.'.'.$key;
    }

    public function string(string $key): string
    {
        return $this->asString($this->required($key), $this->field($key));
    }

    public function nullableString(string $key): ?string
    {
        return $this->nullable($key, $this->string(...));
    }

    public function int(string $key): int
    {
        $value = $this->required($key);

        return \is_int($value) ? $value : throw InvalidInput::field($this->field($key), 'must be an integer');
    }

    public function bool(string $key): bool
    {
        $value = $this->required($key);

        return \is_bool($value) ? $value : throw InvalidInput::field($this->field($key), 'must be a boolean');
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->required($key);
        // `{}` and `{"0": ...}` also decode to a list. Only the codec writes payloads, and it writes lists as JSON arrays.
        if (!\is_array($value) || !array_is_list($value)) {
            throw InvalidInput::field($this->field($key), 'must be a list');
        }

        $strings = [];
        foreach ($value as $index => $item) {
            $strings[] = $this->asString($item, $this->field($key).'.'.$index);
        }

        return $strings;
    }

    public function object(string $key): self
    {
        $value = $this->required($key);
        // `{}` decodes to [], which is also a list.
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            throw InvalidInput::field($this->field($key), 'must be an object');
        }

        return new self($value, $this->field($key));
    }

    public function nullableObject(string $key): ?self
    {
        return $this->nullable($key, $this->object(...));
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enumClass a string-backed enum
     *
     * @return T
     */
    public function enum(string $key, string $enumClass): \BackedEnum
    {
        return $enumClass::tryFrom($this->string($key)) ?? throw InvalidInput::field($this->field($key), 'must be one of '.implode(', ', array_column($enumClass::cases(), 'value')));
    }

    public function dateTime(string $key): \DateTimeImmutable
    {
        return PayloadDateTime::parse($this->string($key), $this->field($key));
    }

    public function nullableDateTime(string $key): ?\DateTimeImmutable
    {
        return $this->nullable($key, $this->dateTime(...));
    }

    /**
     * @template T of UlidId
     *
     * @param class-string<T> $idClass
     *
     * @return T
     */
    public function id(string $key, string $idClass): UlidId
    {
        return $idClass::fromString($this->string($key), $this->field($key));
    }

    /**
     * @template T of UlidId
     *
     * @param class-string<T> $idClass
     *
     * @return T|null
     */
    public function nullableId(string $key, string $idClass): ?UlidId
    {
        return $this->nullable($key, fn (string $key): UlidId => $this->id($key, $idClass));
    }

    /**
     * @template T of UlidId
     *
     * @param class-string<T> $idClass
     *
     * @return list<T>
     */
    public function idList(string $key, string $idClass): array
    {
        $ids = [];
        foreach ($this->stringList($key) as $index => $value) {
            $ids[] = $idClass::fromString($value, $this->field($key).'.'.$index);
        }

        return $ids;
    }

    public function commitSha(string $key): CommitSha
    {
        return CommitSha::fromString($this->string($key), $this->field($key));
    }

    /**
     * For a value object with a `fromString(string $value, string $field)` constructor, for example `BlobSha::fromString(...)`.
     *
     * @template T
     *
     * @param callable(string, string): T $parse gets the value and its dotted field
     *
     * @return T
     */
    public function parsed(string $key, callable $parse): mixed
    {
        return $parse($this->string($key), $this->field($key));
    }

    /**
     * @template T
     *
     * @param callable(string, string): T $parse gets the value and its dotted field
     *
     * @return T|null
     */
    public function nullableParsed(string $key, callable $parse): mixed
    {
        return $this->nullable($key, fn (string $key): mixed => $this->parsed($key, $parse));
    }

    private function required(string $key): mixed
    {
        return \array_key_exists($key, $this->data) ? $this->data[$key] : throw InvalidInput::field($this->field($key), 'is required');
    }

    /**
     * @template T
     *
     * @param \Closure(string): T $read
     *
     * @return T|null
     */
    private function nullable(string $key, \Closure $read): mixed
    {
        return null === ($this->data[$key] ?? null) ? null : $read($key);
    }

    private function asString(mixed $value, string $field): string
    {
        return \is_string($value) ? $value : throw InvalidInput::field($field, 'must be a string');
    }
}
