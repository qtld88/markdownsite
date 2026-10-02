<?php

declare(strict_types=1);

namespace OCA\MarkdownSite\Service;

use OCA\MarkdownSite\Db\SearchEntry;
use OCA\MarkdownSite\Db\SearchMapper;
use OCA\MarkdownSite\Db\Site;
use OCA\MarkdownSite\Db\SiteMapper;
use OCA\MarkdownSite\Search\Normalizer;
use OCA\MarkdownSite\Search\PlainText;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/** Keeps markdownsite_search in step with a site's files. */
class SearchIndexer {
	public function __construct(
		private ContentService $content,
		private SearchMapper $index,
		private SiteMapper $sites,
	) {
	}

	/**
	 * Brings the site's index up to date. Pages are only read when their etag
	 * changed; nothing is read when the root folder's etag did not change.
	 */
	public function refresh(Site $site, Folder $root): void {
		$rootEtag = $root->getEtag();
		if ($rootEtag === $site->getSearchEtag()) {
			return;
		}
		$current = $this->content->listMarkdownEtags($root);
		$stored = $this->index->etagsBySite($site->getId());
		foreach ($current as $path => $etag) {
			if (($stored[$path] ?? null) !== $etag) {
				$this->indexPage($site, $root, (string) $path);
			}
		}
		$gone = array_diff_key($stored, $current);
		if ($gone !== []) {
			$this->index->deletePaths($site->getId(), array_map('strval', array_keys($gone)));
		}
		$site->setSearchEtag($rootEtag);
		$this->sites->update($site);
	}

	/** (Re)indexes one page; removes it from the index when it no longer exists. */
	public function indexPage(Site $site, Folder $root, string $path): void {
		try {
			$file = $this->content->getChild($root, $path);
		} catch (NotFoundException) {
			$this->removePage($site, $path);
			return;
		}
		if (!$file instanceof File) {
			return;
		}
		$text = PlainText::fromMarkdown($file->getContent(), preg_replace('/\.md$/i', '', basename($path)) ?? $path);
		$entry = new SearchEntry();
		$entry->setSiteId($site->getId());
		$entry->setPath($path);
		$entry->setEtag($file->getEtag());
		$entry->setTitle(mb_substr($text['title'], 0, 255));
		$entry->setTitleNorm(mb_substr(Normalizer::normalize($text['title']), 0, 255));
		$entry->setAliasesNorm(Normalizer::normalize(implode(' ', $text['aliases'])));
		$entry->setHeadingsNorm(Normalizer::normalize(implode("\n", $text['headings'])));
		$entry->setBody($text['body']);
		$entry->setBodyNorm(Normalizer::normalize($text['body']));
		$this->index->upsert($entry);
	}

	public function removePage(Site $site, string $path): void {
		$this->index->deletePaths($site->getId(), [$path]);
	}
}
