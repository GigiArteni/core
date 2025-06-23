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
            // Normalize all paths to use forward slashes and remove drive letters
            $normalize = function ($p) {
                $p = str_replace(['\\', '/'], '/', $p);
                return preg_replace('/^[A-Za-z]:/', '', $p); // Remove drive letter
            };
            $path = $normalize($path);
            $shared = $normalize(shared_path());
            $containers = $normalize(app_path('Containers'));

            if (Str::contains($path, $shared)) {
                // Return only the last directory (e.g., 'ship')
                return Str::of($shared)
                    ->afterLast('/')
                    ->camel()
                    ->value();
            }

            return Str::of($path)
                ->after($containers . '/')
                ->explode('/')
                ->take(2)
                ->map(static fn (string $part) => Str::camel($part))
                ->implode('@');
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
