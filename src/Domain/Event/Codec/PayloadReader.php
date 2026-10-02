<?php

declare(strict_types=1);

namespace App\Domain\Event\Codec;

use App\Domain\Error\InvalidInput;
use App\Domain\Identity\Identifier;

/**
 * Typed reads from decoded stored JSON. Decision D37: every event type and stored value has an explicit codec,
 * without Serializer and without reflection, so the stored format cannot drift.
 *
 * A missing nullable field reads as null. Any other missing field, a wrong type, or a value that a domain rule
 * rejects throws a CorruptPayload that names the field. Unknown fields are ignored, so an older release reads newer
 * payloads.
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
     * @param array<mixed> $payload
     */
    public static function of(array $payload): self
    {
        return new self($payload, '');
    }

    public function string(string $key): string
    {
        $value = $this->required($key);

        return is_string($value) ? $value : throw $this->violation($key, 'Must be a string.');
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return null === $value || is_string($value) ? $value : throw $this->violation($key, 'Must be a string or null.');
    }

    public function bool(string $key): bool
    {
        $value = $this->required($key);

        return is_bool($value) ? $value : throw $this->violation($key, 'Must be a boolean.');
    }

    public function int(string $key): int
    {
        $value = $this->required($key);

        return is_int($value) ? $value : throw $this->violation($key, 'Must be an integer.');
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->required($key);

        if (!is_array($value) || !array_is_list($value)) {
            throw $this->violation($key, 'Must be a list of strings.');
        }

        $strings = [];

        foreach ($value as $index => $item) {
            $strings[] = is_string($item) ? $item : throw $this->violation("{$key}.{$index}", 'Must be a string.');
        }

        return $strings;
    }

    public function object(string $key): self
    {
        return $this->asObject($key, $this->required($key));
    }

    public function nullableObject(string $key): ?self
    {
        $value = $this->data[$key] ?? null;

        return null === $value ? null : $this->asObject($key, $value);
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum A string-backed enum
     *
     * @return T
     */
    public function enum(string $key, string $enum): \BackedEnum
    {
        $case = $enum::tryFrom($this->string($key));

        if (null === $case) {
            $values = array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enum::cases());

            throw $this->violation($key, 'Must be one of: '.implode(', ', $values).'.');
        }

        return $case;
    }

    public function dateTime(string $key): \DateTimeImmutable
    {
        return TimeCodec::decode($this->string($key)) ?? throw $this->timeViolation($key);
    }

    public function nullableDateTime(string $key): ?\DateTimeImmutable
    {
        $value = $this->nullableString($key);

        return null === $value ? null : (TimeCodec::decode($value) ?? throw $this->timeViolation($key));
    }

    /**
     * @template T of Identifier
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function id(string $key, string $class): Identifier
    {
        $value = $this->string($key);

        return $this->build(static fn () => $class::fromString($value, $key));
    }

    /**
     * @template T of Identifier
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function nullableId(string $key, string $class): ?Identifier
    {
        $value = $this->nullableString($key);

        return null === $value ? null : $this->build(static fn () => $class::fromString($value, $key));
    }

    /**
     * @template T of Identifier
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public function idList(string $key, string $class): array
    {
        $ids = [];

        foreach ($this->stringList($key) as $index => $value) {
            $ids[] = $this->build(static fn () => $class::fromString($value, "{$key}.{$index}"));
        }

        return $ids;
    }

    /**
     * Reports an InvalidInput of a value object or an event rule as a CorruptPayload under the path of this reader.
     *
     * @template T
     *
     * @param \Closure(): T $build
     *
     * @return T
     */
    public function build(\Closure $build): mixed
    {
        try {
            return $build();
        } catch (InvalidInput $error) {
            throw new CorruptPayload(array_map(fn (array $violation): array => ['field' => $this->fieldPath($violation['field']), 'message' => $violation['message']], $error->details()['violations']), $error);
        }
    }

    private function required(string $key): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : throw $this->violation($key, 'Is required.');
    }

    private function asObject(string $key, mixed $value): self
    {
        if (!is_array($value) || ([] !== $value && array_is_list($value))) {
            throw $this->violation($key, 'Must be an object.');
        }

        return new self($value, $this->fieldPath($key));
    }

    private function fieldPath(string $key): string
    {
        return '' === $this->path || '' === $key ? $this->path.$key : "{$this->path}.{$key}";
    }

    private function violation(string $key, string $message): CorruptPayload
    {
        return CorruptPayload::field($this->fieldPath($key), $message);
    }

    private function timeViolation(string $key): CorruptPayload
    {
        return $this->violation($key, 'Must be an RFC 3339 UTC time with second precision, for example 2026-09-29T07:31:12Z.');
    }
}
