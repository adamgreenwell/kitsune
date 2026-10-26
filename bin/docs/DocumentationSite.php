<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Tools\Documentation;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;
use RuntimeException;

/** @internal Development-only documentation tooling; no application boot or extension contract. */
final class DocumentationSite
{
    private const ROOT_DOCUMENTS = ['README.md', 'CONTRIBUTING.md', 'GOVERNANCE.md', 'SECURITY.md', 'CODE_OF_CONDUCT.md', 'CLA.md'];

    private const REPOSITORY = 'https://github.com/adamgreenwell/kitsune';

    private MarkdownParser $parser;

    private HtmlRenderer $renderer;

    /** @var array<string, string> */
    private array $sources = [];

    /** @var array<string, array{source: string, title: string, document: Document}> */
    private array $pages = [];

    /** @var array<string, array<string, string>> */
    private array $anchors = [];

    private ?string $ref = null;

    private int $links = 0;

    public function __construct(private readonly string $root)
    {
        $environment = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $this->parser = new MarkdownParser($environment);
        $this->renderer = new HtmlRenderer($environment);
    }

    /** @return array{snapshot: string, commit: string, documents: int, pages: int, links: int, search_sections: int} */
    public function build(string $output, string $version = 'development', ?string $ref = null): array
    {
        $this->need(preg_match('/\A[a-z0-9][a-z0-9.-]{0,63}\z/D', $version) === 1, 'invalid version label');
        $this->need($output !== '' && ! is_link($output), 'invalid output directory');
        $this->ref = $ref === null ? null : trim($this->git(['rev-parse', '--verify', '--end-of-options', $ref.'^{commit}']));
        $commit = $this->ref ?? trim($this->git(['rev-parse', 'HEAD']));
        $this->need(preg_match('/\A[0-9a-f]{40}\z/D', $commit) === 1, 'source commit unavailable');
        $this->sources = [];
        $this->pages = [];
        $this->anchors = [];
        $this->links = 0;
        $this->collect();
        $this->parse();
        $search = [];
        $rendered = [];
        foreach ($this->pages as $id => $page) {
            foreach ($page['document']->iterator() as $node) {
                if ($node instanceof Link) {
                    $node->setUrl($this->destination($page['source'], $node->getUrl()));
                    $this->links++;
                } elseif ($node instanceof Image) {
                    throw new RuntimeException('Images in source documents need an explicit local asset mapping: '.$page['source']);
                }
            }
            $rendered[$id] = str_replace(
                ['<table>', '</table>', '<pre>'],
                ['<div class="table-scroll" tabindex="0" role="region" aria-label="Scrollable table"><table>', '</table></div>', '<pre tabindex="0" role="region" aria-label="Code sample">'],
                (string) $this->renderer->renderDocument($page['document']),
            );
            $search = array_merge($search, $this->searchSections($id, $page));
        }

        $assets = [];
        foreach (['styles.css', 'site.js'] as $name) {
            $assets[$name] = $this->file(__DIR__.'/assets/'.$name);
        }
        $assets['brand.svg'] = $this->file($this->root.'/brand/source/kitsune-lockup-compact.svg');
        $pins = array_map(static fn (string $bytes): string => hash('sha256', $bytes), $this->sources);
        $buildPins = array_map(static fn (string $bytes): string => hash('sha256', $bytes), $assets);
        $buildPins['builder'] = hash_file('sha256', __FILE__);
        $digest = hash('sha256', $this->json([$version, $commit, $pins, $buildPins]));
        $snapshot = $version.'-'.substr($digest, 0, 12);
        $dirty = $this->ref === null && trim($this->git(['status', '--porcelain', '--untracked-files=normal'])) !== '';
        $metadata = ['id' => $snapshot, 'label' => $version, 'commit' => $commit, 'working_copy' => $this->ref === null, 'dirty' => $dirty, 'pages' => array_keys($this->pages)];
        $metadata['home'] = isset($this->pages['docs-index']) ? 'docs-index' : 'project-readme';
        $files = [];
        foreach ($this->pages as $id => $page) {
            $files[$id.'.html'] = $this->page($id, $page, $rendered[$id], $metadata);
        }
        foreach ($assets as $name => $bytes) {
            $files['assets/'.$name] = $bytes;
        }
        foreach ($this->sources as $name => $bytes) {
            $files['sources/'.$name.'.txt'] = $bytes;
        }
        $files['search-index.json'] = $this->json($search);
        $files['manifest.json'] = $this->json([
            'schema' => 1, 'snapshot' => $metadata, 'source_sha256' => $pins, 'build_sha256' => $buildPins,
            'files_sha256' => array_map(static fn (string $bytes): string => hash('sha256', $bytes), $files),
        ]);
        $this->publishLocal($output, $snapshot, $files, $metadata);

        return ['snapshot' => $snapshot, 'commit' => $commit, 'documents' => count($this->sources), 'pages' => count($this->pages), 'links' => $this->links, 'search_sections' => count($search)];
    }

    private function collect(): void
    {
        $candidates = $this->ref === null
            ? array_merge(array_map(static fn (string $p): string => 'docs/'.basename($p), glob($this->root.'/docs/*.md') ?: []), self::ROOT_DOCUMENTS, ['deploy/runbook/README.md'])
            : explode("\n", trim($this->git(['ls-tree', '-r', '--name-only', $this->ref])));
        sort($candidates);
        foreach ($candidates as $path) {
            if (! in_array($path, self::ROOT_DOCUMENTS, true) && $path !== 'deploy/runbook/README.md' && preg_match('~\Adocs/[^/]+\.md\z~D', $path) !== 1) {
                continue;
            }
            $this->sources[$path] = $this->source($path);
        }
        $this->need(isset($this->sources['README.md'], $this->sources['docs/decision-log.md']), 'required source documents missing');
    }

    private function parse(): void
    {
        foreach ($this->sources as $source => $markdown) {
            $document = $this->parser->parse($markdown);
            $seen = [];
            foreach ($document->iterator() as $node) {
                if (! $node instanceof Heading) {
                    continue;
                }
                $base = str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{N} _-]+/u', '', mb_strtolower($this->text($node))));
                $anchor = $base;
                for ($i = 1; isset($seen[$anchor]); $i++) {
                    $anchor = $base.'-'.$i;
                }
                $seen[$anchor] = true;
                $node->data->set('attributes/id', $anchor);
            }
            $id = $this->pageId($source);
            $this->need(! isset($this->pages[$id]), 'document page identity collision');
            $this->pages[$id] = ['source' => $source, 'title' => basename($source, '.md'), 'document' => new Document];
            foreach (iterator_to_array($document->children()) as $block) {
                if ($source === 'docs/decision-log.md' && $block instanceof Heading && $block->getLevel() === 2 && preg_match('/\A(ADR-\d{3})\b/', $this->text($block), $match) === 1) {
                    $id = strtolower($match[1]);
                    $this->need(! isset($this->pages[$id]), 'duplicate ADR identity');
                    $this->pages[$id] = ['source' => $source, 'title' => $this->text($block), 'document' => new Document];
                    $block->setLevel(1);
                }
                if ($block instanceof Heading && count(iterator_to_array($this->pages[$id]['document']->children())) === 0) {
                    $this->pages[$id]['title'] = $this->text($block);
                }
                $this->pages[$id]['document']->appendChild($block);
                foreach ($block->iterator() as $node) {
                    if ($node instanceof Heading) {
                        $this->anchors[$source][$node->data->get('attributes/id')] = $id;
                    }
                }
            }
        }
    }

    private function destination(string $source, string $url): string
    {
        $repositoryPrefix = self::REPOSITORY.'/blob/main/';
        if (str_starts_with($url, $repositoryPrefix)) {
            $local = substr($url, strlen($repositoryPrefix));
            $path = explode('#', $local, 2)[0];
            if (isset($this->sources[$path]) && ! preg_match('/#L\d/', $local)) {
                return $this->destination($path, str_contains($local, '#') ? '#'.explode('#', $local, 2)[1] : '');
            }
        }
        if (preg_match('~\A(?:https?://|mailto:)~i', $url) === 1) {
            return $url;
        }
        $this->need(! str_starts_with($url, '//') && ! str_contains($url, "\0") && ! str_contains($url, '?') && ! preg_match('~\A[a-z][a-z0-9+.-]*:~i', $url), 'unsupported link in '.$source.': '.$url);
        [$file, $fragment] = array_pad(explode('#', $url, 2), 2, '');
        $target = $file === '' ? $source : $this->normalise(dirname($source).'/'.rawurldecode($file));
        $fragment = rawurldecode($fragment);
        if (isset($this->sources[$target])) {
            $id = $this->pageId($target);
            if ($fragment !== '') {
                $this->need(isset($this->anchors[$target][$fragment]), 'broken fragment in '.$source.': '.$url);
                $id = $this->anchors[$target][$fragment];
            }

            return $id.'.html'.($fragment === '' ? '' : '#'.rawurlencode($fragment));
        }
        // A repository link still names a real file or directory in the chosen source.
        $kind = $this->ref === null
            ? (is_dir($this->root.'/'.$target) ? 'tree' : 'blob')
            : trim($this->git(['cat-file', '-t', $this->ref.':'.$target]));
        if ($this->ref === null) {
            $real = realpath($this->root.'/'.$target);
            $this->need($real !== false && str_starts_with($real, realpath($this->root).'/') && ! is_link($this->root.'/'.$target), 'missing repository link: '.$target);
        }

        return self::REPOSITORY.'/'.($kind === 'tree' ? 'tree' : 'blob').'/'.($this->ref ?? trim($this->git(['rev-parse', 'HEAD']))).'/'.$target.($fragment === '' ? '' : '#'.rawurlencode($fragment));
    }

    private function normalise(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                $this->need($parts !== [], 'link escapes repository');
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    private function source(string $path): string
    {
        $this->need($path !== '' && $path === $this->normalise($path), 'invalid source path');
        if ($this->ref !== null) {
            return $this->git(['show', $this->ref.':'.$path]);
        }
        $absolute = $this->root.'/'.$path;
        $real = realpath($absolute);
        $this->need($real !== false && str_starts_with($real, realpath($this->root).'/') && is_file($real) && ! is_link($absolute), 'missing or unsafe source: '.$path);

        return $this->file($absolute);
    }

    private function pageId(string $source): string
    {
        $id = str_replace(['/', '.md'], ['-', ''], str_starts_with($source, 'docs/') || str_contains($source, '/') ? strtolower($source) : 'project-'.strtolower($source));
        $this->need(preg_match('/\A[a-z0-9_-]+\z/D', $id) === 1, 'source filename cannot form a safe page identity: '.$source);

        return $id;
    }

    private function text(Node $node): string
    {
        $text = '';
        foreach ($node->iterator() as $child) {
            if ($child instanceof StringContainerInterface) {
                $text .= $child->getLiteral();
            }
        }

        return $text;
    }

    /** @param array{source: string, title: string, document: Document} $page
     * @return list<array{title: string, section: string, body: string, href: string}>
     */
    private function searchSections(string $id, array $page): array
    {
        $records = [];
        $current = ['title' => $page['title'], 'section' => $page['title'], 'body' => '', 'href' => $id.'.html'];
        foreach ($page['document']->children() as $block) {
            if ($block instanceof Heading) {
                if ($current['body'] !== '') {
                    $records[] = $current;
                }
                $current = ['title' => $page['title'], 'section' => $this->text($block), 'body' => '', 'href' => $id.'.html#'.rawurlencode($block->data->get('attributes/id'))];
            }
            $current['body'] .= $this->text($block)."\n";
        }
        if ($current['body'] !== '') {
            $records[] = $current;
        }

        return $records;
    }

    /** @param array{source: string, title: string, document: Document} $page
     * @param  array<string, mixed>  $metadata
     */
    private function page(string $id, array $page, string $content, array $metadata): string
    {
        $title = $this->escape($page['title']);
        $label = $this->escape($metadata['label']);
        $commit = $this->escape(substr($metadata['commit'], 0, 12));
        $state = $metadata['dirty'] ? 'Working copy · uncommitted changes' : 'Source snapshot';
        $nav = '';
        $groups = [
            'Start here' => ['docs-index', 'docs-install', 'docs-upgrades', 'docs-backup-restore'],
            'Understand Kitsune' => ['docs-architecture', 'docs-field-types', 'docs-decision-log', 'docs-roadmap'],
            'Operate commerce' => ['docs-credential-operations', 'docs-paid-orders', 'docs-commerce-exports', 'docs-purchased-access'],
            'Project' => ['project-security', 'project-contributing', 'docs-documentation-site', 'project-readme'],
        ];
        $listed = [];
        foreach ($groups as $name => $ids) {
            $links = '';
            foreach ($ids as $target) {
                if (isset($this->pages[$target])) {
                    $listed[$target] = true;
                    $links .= '<li><a href="'.$target.'.html"'.($target === $id ? ' aria-current="page"' : '').'>'.$this->escape($this->pages[$target]['title']).'</a></li>';
                }
            }
            $nav .= '<section><h2>'.$name.'</h2><ul>'.$links.'</ul></section>';
        }
        $other = '';
        $adrs = '';
        foreach ($this->pages as $target => $entry) {
            if (isset($listed[$target])) {
                continue;
            }
            $link = '<li><a href="'.$target.'.html"'.($target === $id ? ' aria-current="page"' : '').'>'.$this->escape($entry['title']).'</a></li>';
            if (str_starts_with($target, 'adr-')) {
                $adrs .= $link;
            } else {
                $other .= $link;
            }
        }
        $nav .= '<details><summary>More guides</summary><ul>'.$other.'</ul></details><details'.(str_starts_with($id, 'adr-') ? ' open' : '').'><summary>All decisions</summary><ul>'.$adrs.'</ul></details>';
        if ($id === 'docs-decision-log') {
            $content .= '<section><h2 id="all-decisions">Read a decision</h2><ul class="decision-index">'.$adrs.'</ul></section>';
        }
        $toc = '';
        foreach ($page['document']->iterator() as $heading) {
            if ($heading instanceof Heading && $heading->getLevel() <= 3) {
                $toc .= '<li><a href="#'.rawurlencode($heading->data->get('attributes/id')).'">'.$this->escape($this->text($heading)).'</a></li>';
            }
        }
        $source = $this->escape($page['source']);
        $home = $metadata['home'].'.html';

        return <<<HTML
            <!doctype html>
            <html lang="en" data-page="{$id}" data-snapshot="{$metadata['id']}">
            <head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'">
            <title>{$title} · Kitsune documentation</title><link rel="stylesheet" href="assets/styles.css"><script src="assets/site.js" defer></script></head>
            <body><a class="skip" href="#main">Skip to content</a>
            <header class="masthead"><a class="brand" href="{$home}"><img src="assets/brand.svg" alt="Kitsune" width="138" height="32"><span>Documentation</span></a>
            <details class="versions"><summary>{$label} <span class="commit">{$commit}</span></summary><p>{$state}</p><ul id="versions"><li><a href="../index.html">All built snapshots</a></li></ul></details></header>
            <div class="reading-state">Development documentation <span>Read alongside the code you run.</span></div>
            <div class="layout"><aside class="sidebar"><details class="browse" open><summary>Browse documentation</summary><nav aria-label="Documentation">{$nav}</nav></details></aside>
            <main id="main" tabindex="-1"><div class="source-line"><span>{$label} / {$commit}</span><a href="sources/{$source}.txt">Read source</a></div>
            <article>{$content}</article><footer>Source: <code>{$source}</code>. A snapshot is not a support promise. <a href="../index.html">Choose a snapshot</a>.</footer></main>
            <aside class="page-tools"><form role="search"><label for="search">Search this snapshot</label><input id="search" type="search" placeholder="A task, command or decision" autocomplete="off" aria-describedby="search-help"><p id="search-help">Search stays in your browser. Press / to focus.</p></form><p id="search-status" role="status"></p><ol id="search-results"></ol><nav class="toc" aria-label="On this page"><h2>On this page</h2><ul>{$toc}</ul></nav></aside></div>
            </body></html>
            HTML;
    }

    /** @param array<string, string> $files
     * @param  array<string, mixed>  $metadata
     */
    private function publishLocal(string $output, string $snapshot, array $files, array $metadata): void
    {
        $marker = rtrim($output, '/').'/.kitsune-docs.json';
        $catalogue = [];
        if (is_dir($output)) {
            $this->need(is_file($marker) && ! is_link($marker), 'existing output has no documentation ownership marker');
            $owned = json_decode($this->file($marker), true, 512, JSON_THROW_ON_ERROR);
            $this->need(($owned['purpose'] ?? null) === 'kitsune-local-documentation', 'foreign output marker');
            $this->need(array_keys($owned['catalogue_sha256'] ?? []) === ['versions.json', 'index.html'], 'invalid output catalogue names');
            foreach ($owned['catalogue_sha256'] as $name => $hash) {
                $this->need(hash('sha256', $this->file($output.'/'.$name)) === $hash, 'changed output catalogue');
            }
            $catalogue = json_decode($this->file($output.'/versions.json'), true, 512, JSON_THROW_ON_ERROR);
        } else {
            $this->need(! file_exists($output) && mkdir($output, 0755, true), 'cannot create output');
        }
        $directory = $output.'/'.$snapshot;
        if (file_exists($directory)) {
            throw new RuntimeException('snapshot already exists; preserve it and choose a fresh output for a reproduction check');
        }
        $this->need(mkdir($directory, 0755), 'cannot create snapshot');
        foreach ($files as $name => $bytes) {
            $parent = dirname($directory.'/'.$name);
            if (! is_dir($parent)) {
                $this->need(mkdir($parent, 0755, true), 'cannot create snapshot subdirectory');
            }
            $this->need(file_put_contents($directory.'/'.$name, $bytes, LOCK_EX) === strlen($bytes), 'short documentation write');
        }
        array_unshift($catalogue, $metadata);
        $list = '';
        foreach ($catalogue as $entry) {
            $list .= '<li><a href="'.$this->escape($entry['id'].'/'.$entry['home'].'.html').'">'.$this->escape($entry['label']).'</a> <code>'.$this->escape(substr($entry['commit'], 0, 12)).'</code></li>';
        }
        $rootFiles = [
            'versions.json' => $this->json($catalogue),
            'index.html' => '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kitsune documentation snapshots</title><link rel="stylesheet" href="'.$snapshot.'/assets/styles.css"></head><body><main class="snapshot-index"><h1>Documentation snapshots</h1><p>Choose the source you mean to read. These labels do not declare a release or security support.</p><ul>'.$list.'</ul></main></body></html>',
        ];
        foreach ($rootFiles as $name => $bytes) {
            $this->need(file_put_contents($output.'/'.$name, $bytes, LOCK_EX) === strlen($bytes), 'short catalogue write');
        }
        file_put_contents($marker, $this->json(['purpose' => 'kitsune-local-documentation', 'catalogue_sha256' => array_map(static fn (string $bytes): string => hash('sha256', $bytes), $rootFiles)]), LOCK_EX);
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        $process = proc_open(array_merge(['git', '-C', $this->root], $arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->need(is_resource($process), 'cannot read source revision');
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->need(proc_close($process) === 0 && $out !== false && $error === '', 'Git source read failed');

        return $out;
    }

    private function file(string $path): string
    {
        $this->need(is_file($path) && ! is_link($path), 'missing or linked file: '.$path);
        $bytes = file_get_contents($path);
        $this->need($bytes !== false, 'cannot read file');

        return $bytes;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    private function need(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
