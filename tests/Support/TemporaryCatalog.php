<?php

declare(strict_types=1);

namespace Fedale\PackageCatalog\Tests\Support;

/**
 * A catalog checkout in a temporary directory, with versions of the example packages copied
 * into it: acme/shop, and acme/places and acme/people (requiring acme/places ^1.0).
 */
trait TemporaryCatalog
{
    private const string EXAMPLE = __DIR__.'/../Fixtures/shop';
    private const string FIXTURES = __DIR__.'/../Fixtures';

    private string $root;

    private function createCatalog(): void
    {
        $this->root = sys_get_temp_dir().'/package-catalog-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/packages', 0o777, true);
    }

    /**
     * Copies the example package as packages/<name>/<version>/, its manifest saying
     * $manifestVersion (the directory's version by default).
     */
    private function addVersion(string $version, string $name = 'acme/shop', ?string $manifestVersion = null): string
    {
        $directory = 'packages/'.$name.'/'.$version;
        self::copy(self::EXAMPLE, $this->root.'/'.$directory);
        $manifest = $this->root.'/'.$directory.'/package.yaml';
        $yaml = (string) file_get_contents($manifest);
        file_put_contents($manifest, str_replace(['id: acme/shop', 'version: 1.0.0'], ['id: '.$name, 'version: '.($manifestVersion ?? $version)], $yaml));

        return $directory;
    }

    /**
     * As addVersion(), written in format v1, as the versions published before format v2 are:
     * the manifest in template.yaml, of kind Template, a view's source as its template.
     */
    private function addVersionV1(string $version, string $name = 'acme/shop'): string
    {
        $directory = $this->addVersion($version, $name);
        $root = $this->root.'/'.$directory;
        rename($root.'/package.yaml', $root.'/template.yaml');
        // The documents of a package are at its root or one directory down.
        foreach ([...glob($root.'/*.yaml') ?: [], ...glob($root.'/*/*.yaml') ?: []] as $file) {
            $yaml = str_replace(['format: metadata-driven/v2', 'kind: Package'], ['format: metadata-driven/v1', 'kind: Template'], (string) file_get_contents($file));
            // A view's source only: a seed's source (its CSV file) is named so in v1 too.
            file_put_contents($file, str_contains($file, '/views/') ? str_replace("\n  source: ", "\n  template: ", $yaml) : $yaml);
        }

        return $directory;
    }

    /**
     * Copies acme/places or acme/people as packages/acme/<fixture>/<version>/, requiring other
     * packages when given (replacing those of the fixture).
     *
     * @param array<string, string>|null $requires name => constraint
     */
    private function addFixture(string $fixture, string $version, ?array $requires = null): string
    {
        $directory = 'packages/acme/'.$fixture.'/'.$version;
        self::copy(self::FIXTURES.'/'.$fixture, $this->root.'/'.$directory);
        $manifest = $this->root.'/'.$directory.'/package.yaml';
        $yaml = (string) preg_replace('/^  version: .*$/m', '  version: '.$version, (string) file_get_contents($manifest));
        if (null !== $requires) {
            $lines = array_map(static fn (string $name, string $constraint): string => \sprintf('    %s: %s', $name, $constraint), array_keys($requires), $requires);
            $yaml = (string) preg_replace('/^  requires:\n(    .*\n)+/m', "  requires:\n    format: metadata-driven/v2\n".implode('', array_map(static fn (string $line): string => $line."\n", $lines)), $yaml);
        }
        file_put_contents($manifest, $yaml);

        return $directory;
    }

    private function removeCatalog(): void
    {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }

    private static function copy(string $from, string $to): void
    {
        mkdir($to, 0o777, true);
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $target = $to.'/'.substr($entry->getPathname(), \strlen($from) + 1);
            $entry->isDir() ? mkdir($target) : copy($entry->getPathname(), $target);
        }
    }

    /**
     * Every file under a directory of the catalog, as a pull request adding them would list them.
     *
     * @return list<string>
     */
    private function filesOf(string $directory): array
    {
        $files = [];
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root.'/'.$directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $files[] = substr($entry->getPathname(), \strlen($this->root) + 1);
            }
        }
        sort($files);

        return $files;
    }
}
