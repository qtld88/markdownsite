<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Wiki;

class WikilinkIndex {
	/** @var string[] all .md paths relative to root */
	private array $paths;
	/** @var array<string,string> lowercased basename (no ext) => path (first wins for ambiguity map; full list kept separately) */
	private array $byBasename = [];
	/** @var array<string,string> lowercased alias => path */
	private array $byAlias = [];
	/** @var array<string,string> lowercased full path (no ext) => path */
	private array $byPath = [];

	/**
	 * @param string[] $mdPaths           .md paths relative to root
	 * @param array<string,string[]> $aliases  path => alias list (from frontmatter)
	 */
	public function __construct(array $mdPaths, array $aliases = []) {
		$this->paths = $mdPaths;
		foreach ($mdPaths as $p) {
			$noExt = $this->stripExt($p);
			$this->byPath[mb_strtolower($noExt)] = $p;
			$base = mb_strtolower(basename($noExt));
			// keep first for the simple map; ambiguity handled by scanning $paths
			$this->byBasename[$base] ??= $p;
		}
		foreach ($aliases as $path => $list) {
			foreach ($list as $alias) {
				$this->byAlias[mb_strtolower(trim($alias))] = $path;
			}
		}
	}

	/**
	 * Resolve a wikilink target from $currentDir. Returns the .md path relative
	 * to root, or null if unresolved.
	 */
	public function resolve(string $currentDir, string $link): ?string {
		$link = trim($link);
		$key = mb_strtolower($this->stripExt($link));

		// 1. exact subpath match (link contains a slash or matches a full path)
		if (isset($this->byPath[$key])) {
			return $this->byPath[$key];
		}
		// 2. alias
		if (isset($this->byAlias[$key])) {
			return $this->byAlias[$key];
		}
		// 3. basename, with ambiguity resolution
		$candidates = [];
		foreach ($this->paths as $p) {
			if (mb_strtolower(basename($this->stripExt($p))) === $key) {
				$candidates[] = $p;
			}
		}
		if (count($candidates) === 0) {
			return null;
		}
		if (count($candidates) === 1) {
			return $candidates[0];
		}
		return $this->pickNearest($currentDir, $candidates);
	}

	/** @param string[] $candidates */
	private function pickNearest(string $currentDir, array $candidates): string {
		usort($candidates, function (string $a, string $b) use ($currentDir): int {
			$da = $this->distance($currentDir, $a);
			$db = $this->distance($currentDir, $b);
			if ($da !== $db) {
				return $da <=> $db;
			}
			return strlen($a) <=> strlen($b);
		});
		return $candidates[0];
	}

	/** Number of path segments between $currentDir and the file's directory. */
	private function distance(string $currentDir, string $path): int {
		$cur = $currentDir === '' ? [] : explode('/', $currentDir);
		$dir = dirname($path);
		$fileDir = $dir === '.' ? [] : explode('/', $dir);
		$i = 0;
		while ($i < count($cur) && $i < count($fileDir) && $cur[$i] === $fileDir[$i]) {
			$i++;
		}
		return (count($cur) - $i) + (count($fileDir) - $i);
	}

	private function stripExt(string $p): string {
		return preg_replace('/\.md$/i', '', $p) ?? $p;
	}
}
