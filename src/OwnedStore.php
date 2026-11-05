<?php
declare(strict_types=1);
namespace Lofrano\Booklet;

/** @internal Cooperative local-filesystem namespace, not a hostile-filesystem boundary. */
final class OwnedStore
{
    public const APP = 'lofrano-booklet-imposition-pack/1';
    private mixed $lock = null;
    private array $identity;
    public readonly string $root;

    public function __construct(string $root, int $deadline)
    {
        $this->root = self::local($root, true);
        $lockPath = $this->root . '/.booklet.lock';
        if (file_exists($lockPath) || is_link($lockPath)) {
            Failure::need(!is_link($lockPath) && is_file($lockPath), 'namespace_lock');
        }
        $this->lock = @fopen($lockPath, 'c+b');
        Failure::need(is_resource($this->lock), 'namespace_lock');
        while (!flock($this->lock, LOCK_EX | LOCK_NB)) {
            Failure::time($deadline);
            usleep(10000);
        }
        @chmod($lockPath, 0600);
        $stat = stat($this->root);
        Failure::need($stat !== false, 'namespace_stat');
        $path = $this->root . '/.booklet-root.json';
        $expected = ['app' => self::APP, 'root_hash' => hash('sha256', $this->root), 'device' => $stat['dev'], 'inode' => $stat['ino']];
        if (file_exists($path) || is_link($path)) {
            $identity = self::smallJson($path);
            foreach ($expected as $key => $value) {
                Failure::need(($identity[$key] ?? null) === $value, 'namespace_identity');
            }
            Failure::need(is_string($identity['token'] ?? null) && preg_match('/^[a-f0-9]{48}$/D', $identity['token']) === 1, 'namespace_identity');
            $this->identity = $identity;
        } else {
            $this->identity = $expected + ['token' => bin2hex(random_bytes(24))];
            Failure::write($path, Failure::json($this->identity));
        }
    }

    public function __destruct()
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
    }

    public static function local(string $path, bool $directory = false): string
    {
        Failure::need($path !== '' && strlen($path) <= 4096 && !str_contains($path, "\0") && !str_contains($path, '://'), 'local_path');
        if ($path[0] !== '/') {
            $cwd = getcwd();
            Failure::need($cwd !== false, 'local_path');
            $path = $cwd . '/' . $path;
        }
        $parts = explode('/', substr($path, 1));
        $current = '';
        foreach ($parts as $part) {
            Failure::need($part !== '' && $part !== '.' && $part !== '..', 'local_path');
            $current .= '/' . $part;
            Failure::need(!is_link($current) && @lstat($current) !== false, 'local_path');
        }
        Failure::need($directory ? is_dir($current) : is_file($current), 'local_path');
        return $current;
    }

    private static function smallJson(string $path): array
    {
        Failure::need(!is_link($path) && is_file($path) && filesize($path) <= 4096, 'namespace_identity');
        try {
            $j = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Failure('namespace_identity');
        }
        Failure::need(is_array($j), 'namespace_identity');
        return $j;
    }

    public function available(string $name): void
    {
        Failure::need(preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $name) === 1, 'bundle_name');
        Failure::need(!file_exists($this->root . '/' . $name) && !is_link($this->root . '/' . $name), 'bundle_exists');
    }

    public function stage(): string
    {
        $name = '.booklet-stage-' . bin2hex(random_bytes(16));
        $path = $this->root . '/' . $name;
        Failure::need(@mkdir($path, 0700), 'storage_create');
        Failure::write($path . '/.booklet-stage.json', Failure::json(['app' => self::APP, 'token' => $this->identity['token'], 'stage' => $name]));
        Failure::need(@mkdir($path . '/work', 0700), 'storage_create');
        return $path;
    }

    public function copySource(string $source, string $stage, int $deadline): string
    {
        $source = self::local($source);
        Failure::need($source !== $this->root && !str_starts_with($source, $this->root . '/'), 'source_namespace');
        $before = @stat($source);
        Failure::need($before !== false && $before['size'] > 0 && $before['size'] <= 16777216, 'input_size');
        $in = @fopen($source, 'rb');
        $out = @fopen($stage . '/work/input.pdf', 'xb');
        Failure::need(is_resource($in) && is_resource($out), 'input_copy');
        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (!feof($in)) {
                Failure::time($deadline);
                $data = fread($in, 65536);
                Failure::need($data !== false, 'input_copy');
                $size += strlen($data);
                Failure::need($size <= 16777216, 'input_size');
                hash_update($hash, $data);
                Failure::need(fwrite($out, $data) === strlen($data), 'input_copy');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
        $after = @stat($source);
        Failure::need($after !== false && $size === $before['size'] && $after['size'] === $before['size'] && $after['ino'] === $before['ino'] && $after['mtime'] === $before['mtime'], 'source_changed');
        return hash_final($hash);
    }

    private static function safeTree(string $path): bool
    {
        if (is_link($path)) {
            return false;
        }
        if (is_file($path)) {
            return true;
        }
        if (!is_dir($path)) {
            return false;
        }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!self::safeTree($entry->getPathname())) {
                return false;
            }
        }
        return true;
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path)) {
            foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
                self::removeTree($entry->getPathname());
            }
            Failure::need(@rmdir($path), 'storage_cleanup');
        } else {
            Failure::need(@unlink($path), 'storage_cleanup');
        }
    }

    public function publish(string $stage, string $name, int $deadline): string
    {
        $this->available($name);
        Failure::time($deadline);
        Failure::need(dirname($stage) === $this->root && self::safeTree($stage . '/work'), 'storage_cleanup');
        self::removeTree($stage . '/work');
        $marker = $stage . '/.booklet-stage.json';
        Failure::need(@rename($marker, $stage . '/.booklet-complete.json'), 'storage_publish');
        if (!@rename($stage, $this->root . '/' . $name)) {
            @rename($stage . '/.booklet-complete.json', $marker);
            throw new Failure('storage_publish');
        }
        return $this->root . '/' . $name;
    }

    public function recover(): int
    {
        $count = 0;
        foreach (new \FilesystemIterator($this->root, \FilesystemIterator::SKIP_DOTS) as $entry) {
            $name = $entry->getFilename();
            if (preg_match('/^\.booklet-stage-[a-f0-9]{32}$/D', $name) !== 1 || $entry->isLink() || !$entry->isDir()) {
                continue;
            }
            try {
                $marker = self::smallJson($entry->getPathname() . '/.booklet-stage.json');
            } catch (Failure) {
                continue;
            }
            if (($marker['app'] ?? null) !== self::APP || ($marker['token'] ?? null) !== $this->identity['token'] || ($marker['stage'] ?? null) !== $name || !self::safeTree($entry->getPathname())) {
                continue;
            }
            self::removeTree($entry->getPathname());
            ++$count;
        }
        return $count;
    }
}
