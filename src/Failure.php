<?php
declare(strict_types=1);
namespace Lofrano\Booklet;

final class Failure extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public static function need(bool $condition, string $reason): void
    {
        if (!$condition) {
            throw new self($reason);
        }
    }

    public static function time(int $deadline): void
    {
        self::need(hrtime(true) < $deadline, 'workflow_deadline');
    }

    public static function json(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n";
        } catch (\JsonException) {
            throw new self('json_encoding');
        }
    }

    public static function write(string $path, string $data): void
    {
        self::need(@file_put_contents($path, $data, LOCK_EX) === strlen($data), 'storage_write');
        self::need(@chmod($path, 0600), 'storage_permissions');
    }
}
