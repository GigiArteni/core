<?php

namespace Apiato\Foundation\Configuration;

use Illuminate\Support\Str;

final class View
{
    protected static \Closure $namespaceBuilder;
    /** @var string[] */
    protected array $paths = [];

    public function __construct()
    {
        $this->buildNamespaceUsing(function (string $path): string {
            $realPath = realpath($path) ?: $path;
            // Normalize path separators for cross-platform compatibility
            $realPath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $realPath);
            $shared = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, shared_path());
            $containerBase = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, app_path('Containers') . DIRECTORY_SEPARATOR);

            if (Str::contains($realPath, $shared)) {
                // Return the namespace as 'app/Ship' for shared_path
                return 'app/Ship';
            }

            // Remove the container base path and split the rest
            $relative = Str::of($realPath)->after($containerBase);
            $parts = collect(explode(DIRECTORY_SEPARATOR, $relative))
                ->filter(fn($part) => $part !== '' && $part !== '.' && $part !== '..')
                ->take(2)
                ->map(static fn (string $part) => Str::camel($part));
            return $parts->implode('@');
        });
    }

    /**
     * @param \Closure(string): string $callback
     */
    public function buildNamespaceUsing(\Closure $callback): self
    {
        self::$namespaceBuilder = $callback;

        return $this;
    }

    /**
     * @return string[]
     */
    public function paths(): array
    {
        return $this->paths;
    }

    public function loadFrom(string ...$paths): self
    {
        $this->paths = $paths;

        return $this;
    }

    public function buildNamespaceFor(string $path): string
    {
        return app()->call(self::$namespaceBuilder, ['path' => $path]);
    }
}
