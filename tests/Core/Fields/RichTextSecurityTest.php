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
