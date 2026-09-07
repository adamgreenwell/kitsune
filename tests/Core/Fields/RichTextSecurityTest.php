<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Fields\Types\JsonType;
use Kitsune\Core\Fields\Types\RichTextType;

/*
 * field-types.md §6 names these the two types needing security review.
 * rich_text is the only field type in v1 that is an XSS vector.
 */

beforeEach(function (): void {
    $this->richText = new RichTextType;
    $this->json = new JsonType;
    $this->config = configFor('rich_text');
});

describe('rich_text sanitisation', function (): void {
    it('strips a script tag AND its contents', function (): void {
        // Removing only the tags would leave the script body as visible text.
        $out = $this->richText->sanitize('<p>Hi</p><script>alert(1)</script>');

        expect($out)->toBe('<p>Hi</p>');
    });

    it('strips style blocks, which can exfiltrate as well as deface', function (): void {
        expect($this->richText->sanitize('<style>body{display:none}</style><p>Hi</p>'))->toBe('<p>Hi</p>');
    });

    it('strips iframes', function (): void {
        expect($this->richText->sanitize('<iframe src="https://evil.test"></iframe><p>Hi</p>'))->toBe('<p>Hi</p>');
    });

    it('removes event handler attributes, which survive tag allowlisting', function (): void {
        $out = $this->richText->sanitize('<p onclick="steal()">Hi</p>');

        expect($out)->not->toContain('onclick');
        expect($out)->toContain('Hi');
    });

    it('neutralises javascript: URLs', function (): void {
        $out = $this->richText->sanitize('<a href="javascript:alert(1)">Click</a>');

        expect($out)->not->toContain('javascript:');
    });

    it('keeps legitimate formatting', function (): void {
        $html = '<p>A <strong>bold</strong> and <em>italic</em> <a href="https://example.test">link</a>.</p>';

        expect($this->richText->sanitize($html))->toBe($html);
    });

    it('uses an allowlist, so an unknown tag is dropped', function (): void {
        // A denylist is a promise to have thought of every tag, and nobody
        // has. An unrecognised element must fail closed.
        expect($this->richText->sanitize('<marquee>Hi</marquee>'))->toBe('Hi');
    });

    it('sanitises on write, so the stored value is already safe', function (): void {
        $stored = $this->richText->toStorage('<p>Hi</p><script>alert(1)</script>', $this->config);

        expect($stored)->toBe('<p>Hi</p>');
    });
});

describe('json escape hatch', function (): void {
    it('rejects a value over the size cap', function (): void {
        $big = ['blob' => str_repeat('x', JsonType::MAX_BYTES + 100)];

        expect(fn () => $this->json->toStorage($big, configFor('json')))
            ->toThrow(RuntimeException::class, 'exceeds');
    });

    it('rejects malformed JSON rather than storing a string', function (): void {
        expect(fn () => $this->json->toStorage('{not json', configFor('json')))
            ->toThrow(RuntimeException::class, 'not valid JSON');
    });

    it('accepts a reasonable structure', function (): void {
        expect($this->json->toStorage('{"a":1}', configFor('json')))->toBe(['a' => 1]);
    });

    it('is not indexable, because if you want to query it you wanted a real type', function (): void {
        expect($this->json->isIndexable())->toBeFalse();
    });
});

/*
 * ⚠️ Matching the literal string `javascript:` is not enough, and every case
 * below walked past the previous rule while still executing in a browser.
 * Reported by review; each one verified against the sanitiser before the fix.
 */
describe('URL schemes are allowlisted, not denylisted', function (): void {
    it('neutralises an obfuscated script URL', function (string $html): void {
        $out = (new RichTextType)->sanitize($html);

        // Compare the way a browser would read it: entities decoded, and the
        // characters a URL parser discards removed.
        $asBrowserSeesIt = (string) preg_replace(
            '#[\x00-\x20]#',
            '',
            html_entity_decode(html_entity_decode($out, ENT_QUOTES | ENT_HTML5), ENT_QUOTES | ENT_HTML5),
        );

        expect($asBrowserSeesIt)->not->toContain('javascript:')
            ->and($asBrowserSeesIt)->not->toContain('vbscript:')
            ->and($asBrowserSeesIt)->not->toContain('data:');
    })->with([
        'plain' => ['<a href="javascript:alert(1)">x</a>'],
        'hex entity' => ['<a href="java&#x73;cript:alert(1)">x</a>'],
        'decimal entity' => ['<a href="&#106;avascript:alert(1)">x</a>'],
        'double encoded' => ['<a href="java&amp;#x73;cript:alert(1)">x</a>'],
        'tab in scheme' => ["<a href=\"java\tscript:alert(1)\">x</a>"],
        'newline in scheme' => ["<a href=\"java\nscript:alert(1)\">x</a>"],
        'null byte' => ["<a href=\"java\0script:alert(1)\">x</a>"],
        'leading whitespace' => ['<a href="  javascript:alert(1)">x</a>'],
        'mixed case' => ['<a href="JaVaScRiPt:alert(1)">x</a>'],
        'vbscript' => ['<a href="vbscript:msgbox(1)">x</a>'],
        'data document' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>'],
        'img src' => ['<img src="javascript:alert(1)">'],
        'unquoted' => ['<a href=javascript:alert(1)>x</a>'],
        'single quoted' => ["<a href='javascript:alert(1)'>x</a>"],
    ]);

    it('leaves a legitimate URL alone', function (string $html): void {
        // A sanitiser that breaks ordinary links gets switched off, which is
        // the real failure mode.
        expect((new RichTextType)->sanitize($html))->toBe($html);
    })->with([
        'https' => ['<a href="https://example.com/x?a=1">x</a>'],
        'mailto' => ['<a href="mailto:a@b.test">x</a>'],
        'tel' => ['<a href="tel:+15551234">x</a>'],
        'root relative' => ['<a href="/about">x</a>'],
        'anchor' => ['<a href="#top">x</a>'],
        'protocol relative' => ['<a href="//cdn.example.com/a.png">x</a>'],
        'image' => ['<img src="https://example.com/a.png" alt="a">'],
    ]);
});

it('suggests `personal` for rich text, matching what its own comment says', function (): void {
    // It explained that `none` was the wrong default and then returned it —
    // which would have nudged every rich text field out of subject-access and
    // erasure handling (ADR-020).
    expect((new RichTextType)->suggestedPiiClass())->toBe('personal');
});

/*
 * ⚠️ `strip_tags()` keeps every attribute on an allowed tag, and removing
 * event handlers alone was a denylist wearing an allowlist's docblock.
 */
describe('attributes are allowlisted too, not only tags', function (): void {
    it('strips an attribute that is not on the list', function (string $html, string $gone): void {
        expect((new RichTextType)->sanitize($html))->not->toContain($gone);
    })->with([
        // The one that matters: position:fixed;inset:0 covers a public page
        // with an attacker-controlled link.
        'style overlay' => ['<a href="https://x.test" style="position:fixed;inset:0;z-index:9999">hi</a>', 'style'],
        'class' => ['<p class="evil">t</p>', 'class'],
        'id' => ['<p id="x">t</p>', 'id'],
        'data attribute' => ['<p data-x="1">t</p>', 'data-x'],
        'event handler' => ['<img src="https://x.test/a.png" onerror="alert(1)">', 'onerror'],
    ]);

    it('keeps the attributes a document actually needs', function (): void {
        expect((new RichTextType)->sanitize('<a href="https://example.com/x?a=1&amp;b=2" title="t">x</a>'))
            ->toBe('<a href="https://example.com/x?a=1&amp;b=2" title="t">x</a>');
    });

    it('keeps alt text, which is an accessibility requirement not a nicety', function (): void {
        expect((new RichTextType)->sanitize('<img src="https://x.test/a.png" alt="A cat">'))
            ->toContain('alt="A cat"');
    });
});
