<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Dom\HTMLDocument;
use Kitsune\Tools\Documentation\DocumentationSite;

require_once dirname(__DIR__, 2).'/bin/docs/DocumentationSite.php';

function documentationGit(string $root, array $arguments): string
{
    $process = proc_open(array_merge(['git', '-C', $root], $arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $result = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $error);

    return $result;
}

function documentationFiles(string $root): array
{
    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $found[substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($found);

    return $found;
}

beforeEach(function (): void {
    $this->docsRoot = dirname(__DIR__, 2).'/build/owned-documentation-test-'.bin2hex(random_bytes(8));
    mkdir($this->docsRoot.'/docs', 0755, true);
    mkdir($this->docsRoot.'/brand/source', 0755, true);
    copy(dirname(__DIR__, 2).'/brand/source/kitsune-lockup-compact.svg', $this->docsRoot.'/brand/source/kitsune-lockup-compact.svg');
    foreach (['CONTRIBUTING.md', 'GOVERNANCE.md', 'SECURITY.md', 'CODE_OF_CONDUCT.md', 'CLA.md', 'TRADEMARK.md'] as $name) {
        file_put_contents($this->docsRoot.'/'.$name, '# '.basename($name, '.md')."\n");
    }
    mkdir($this->docsRoot.'/deploy/runbook', 0755, true);
    file_put_contents($this->docsRoot.'/deploy/runbook/README.md', "# Runbook\n");
    file_put_contents($this->docsRoot.'/README.md', "# Fixture\n\n[Second repeated heading](docs/decision-log.md#same-1)\n\n[Unicode](docs/guide.md#org--site--共通)\n");
    file_put_contents($this->docsRoot.'/docs/guide.md', "# Guide\n\n## Org / Site — 共通\n\nKeep `APP_KEY` for recovery.\n\n[First repeated heading](decision-log.md#same)\n");
    file_put_contents($this->docsRoot.'/docs/decision-log.md', "# Decisions\n\n## ADR-001 — First\n\n### Same\n\nFirst meaning.\n\n## ADR-002 — Second\n\n### Same\n\nSecond meaning; [first](#same).\n");
    documentationGit($this->docsRoot, ['init', '-q', '--initial-branch=main']);
    documentationGit($this->docsRoot, ['add', '.']);
    documentationGit($this->docsRoot, ['-c', 'user.name=Documentation test', '-c', 'user.email=docs@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '-qm', 'Documentation fixture.']);
    $this->docsCommit = trim(documentationGit($this->docsRoot, ['rev-parse', 'HEAD']));
});

afterEach(function (): void {
    // Only this test's random owned tree, never links into shared inputs.
    $root = $this->docsRoot;
    expect(str_starts_with($root, dirname(__DIR__, 2).'/build/owned-documentation-test-'))->toBeTrue();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir() && ! $file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($root);
});

it('keeps original document-wide duplicate and Unicode fragments across split ADR pages', function (): void {
    $result = (new DocumentationSite($this->docsRoot))->build($this->docsRoot.'/artifact');
    $path = $this->docsRoot.'/artifact/'.$result['snapshot'];
    expect(file_get_contents($path.'/project-readme.html'))->toContain('adr-002.html#same-1', 'docs-guide.html#org--site--%E5%85%B1%E9%80%9A')
        ->and(file_get_contents($path.'/adr-002.html'))->toContain('id="same-1"', 'adr-001.html#same', '<h1 id="adr-002--second">')
        ->and(file_get_contents($path.'/sources/docs/decision-log.md.txt'))->toBe(file_get_contents($this->docsRoot.'/docs/decision-log.md'));
    $records = json_decode(file_get_contents($path.'/search-index.json'), true, 512, JSON_THROW_ON_ERROR);
    $matches = array_filter($records, static fn (array $row): bool => str_contains($row['body'], 'APP_KEY'));
    expect($matches)->toHaveCount(1)
        ->and(array_values($matches)[0]['href'])->toBe('docs-guide.html#org--site--%E5%85%B1%E9%80%9A');
});

function documentationAssertLinks(string $path): int
{
    $links = [];
    $ids = [];
    foreach (glob($path.'/*.html') as $file) {
        $doc = HTMLDocument::createFromString(file_get_contents($file));
        $ids[$file] = [];
        foreach ($doc->getElementsByTagName('*') as $element) {
            if ($element->hasAttribute('id')) {
                expect($ids[$file])->not->toHaveKey($element->getAttribute('id'));
                $ids[$file][$element->getAttribute('id')] = true;
            }
            if ($element->hasAttribute('href')) {
                $links[] = [$file, $element->getAttribute('href')];
            }
        }
    }
    expect(count($links))->toBeGreaterThan(40);
    foreach ($links as [$file, $href]) {
        if (str_starts_with($href, 'https://') || str_starts_with($href, 'mailto:')) {
            continue;
        }
        [$target, $fragment] = array_pad(explode('#', $href, 2), 2, '');
        $resolved = $target === '' ? $file : realpath(dirname($file).'/'.$target);
        expect($resolved)->not->toBeFalse($file.' -> '.$href);
        if ($fragment !== '') {
            expect($ids[$resolved])->toHaveKey(rawurldecode($fragment));
        }
    }

    return count($links);
}

it('checks every generated local destination and fragment against the actual artifact', function (): void {
    $result = (new DocumentationSite($this->docsRoot))->build($this->docsRoot.'/artifact');
    documentationAssertLinks($this->docsRoot.'/artifact/'.$result['snapshot']);
});

it('builds the authoritative repository corpus and checks every rendered link and exact source copy', function (): void {
    $root = dirname(__DIR__, 2);
    $result = (new DocumentationSite($root))->build($this->docsRoot.'/artifact');
    $path = $this->docsRoot.'/artifact/'.$result['snapshot'];
    expect($result['documents'])->toBeGreaterThan(25)
        ->and($result['pages'])->toBeGreaterThan(65)
        ->and($result['search_sections'])->toBeGreaterThan(450)
        ->and(documentationAssertLinks($path))->toBeGreaterThan(4000);
    $manifest = json_decode(file_get_contents($path.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($manifest['source_sha256'] as $source => $hash) {
        expect(hash_file('sha256', $root.'/'.$source))->toBe($hash)
            ->and(file_get_contents($path.'/sources/'.$source.'.txt'))->toBe(file_get_contents($root.'/'.$source));
    }
});

it('refuses a broken source fragment before creating an output tree', function (): void {
    file_put_contents($this->docsRoot.'/docs/guide.md', "# Guide\n\n[Missing](decision-log.md#not-there)\n");
    expect(fn () => (new DocumentationSite($this->docsRoot))->build($this->docsRoot.'/artifact'))->toThrow(RuntimeException::class, 'broken fragment');
    expect(file_exists($this->docsRoot.'/artifact'))->toBeFalse();
});

it('does not adopt or overwrite an unrelated output directory', function (): void {
    mkdir($this->docsRoot.'/artifact');
    file_put_contents($this->docsRoot.'/artifact/protected', 'keep this');
    expect(fn () => (new DocumentationSite($this->docsRoot))->build($this->docsRoot.'/artifact'))->toThrow(RuntimeException::class, 'ownership marker');
    expect(file_get_contents($this->docsRoot.'/artifact/protected'))->toBe('keep this');
});

it('keeps the older snapshot exact while building changed working content and a resolved historical ref', function (): void {
    $site = new DocumentationSite($this->docsRoot);
    $old = $site->build($this->docsRoot.'/artifact', 'reference', $this->docsCommit);
    $before = documentationFiles($this->docsRoot.'/artifact/'.$old['snapshot']);
    file_put_contents($this->docsRoot.'/docs/guide.md', "\nNew working-copy recovery instructions.\n", FILE_APPEND);
    $new = $site->build($this->docsRoot.'/artifact', 'development');
    $catalogue = json_decode(file_get_contents($this->docsRoot.'/artifact/versions.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($catalogue)->toHaveCount(2)
        ->and($catalogue[0]['id'])->toBe($new['snapshot'])
        ->and($catalogue[0]['dirty'])->toBeTrue()
        ->and($catalogue[1]['commit'])->toBe($this->docsCommit)
        ->and(documentationFiles($this->docsRoot.'/artifact/'.$old['snapshot']))->toBe($before)
        ->and(file_get_contents($this->docsRoot.'/artifact/'.$old['snapshot'].'/docs-guide.html'))->not->toContain('New working-copy')
        ->and(file_get_contents($this->docsRoot.'/artifact/'.$new['snapshot'].'/docs-guide.html'))->toContain('New working-copy');
    expect(fn () => $site->build($this->docsRoot.'/artifact', 'reference', $this->docsCommit))->toThrow(RuntimeException::class, 'snapshot already exists');
    expect(documentationFiles($this->docsRoot.'/artifact/'.$old['snapshot']))->toBe($before);
});

it('refuses an unresolved ref or linked source without following it into the artifact', function (): void {
    expect(fn () => (new DocumentationSite($this->docsRoot))->build($this->docsRoot.'/artifact', 'development', 'not-a-revision'))->toThrow(RuntimeException::class, 'Git source read failed');
    unlink($this->docsRoot.'/docs/guide.md');
    symlink('../README.md', $this->docsRoot.'/docs/guide.md');
    expect(fn () => (new DocumentationSite($this->docsRoot))->build($this->docsRoot.'/artifact'))->toThrow(RuntimeException::class, 'unsafe source');
    expect(file_exists($this->docsRoot.'/artifact'))->toBeFalse();
});
