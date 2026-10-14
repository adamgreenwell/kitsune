<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Kitsune\Core\Console\CredentialInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

it('reads only the bounded first stdin line and preserves the typed bytes', function (string $ending): void {
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, '  deliberate spaces  '.$ending.'ignored second line');
    rewind($stream);
    try {
        expect(CredentialInput::fromStream($stream)->reveal())->toBe('  deliberate spaces  ');
    } finally {
        fclose($stream);
    }
})->with(["\n", "\r\n"]);

it('accepts the maximum bytes with LF CRLF or EOF without reading an unbounded line', function (string $ending): void {
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, str_repeat('a', CredentialInput::MAX_BYTES).$ending);
    rewind($stream);
    try {
        expect(strlen(CredentialInput::fromStream($stream)->reveal()))->toBe(CredentialInput::MAX_BYTES);
    } finally {
        fclose($stream);
    }
})->with(["\n", "\r\n", '']);

it('refuses empty and oversized stdin without retaining or repeating a value', function (string $value): void {
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $value);
    rewind($stream);
    try {
        CredentialInput::fromStream($stream);
        test()->fail('invalid stdin was accepted');
    } catch (RuntimeException $failure) {
        expect($failure->getMessage())->toBe('No bounded credential value was supplied.')
            ->and($failure->getPrevious())->toBeNull();
        if (strlen($value) > CredentialInput::MAX_BYTES) {
            expect(ftell($stream))->toBeLessThanOrEqual(CredentialInput::MAX_BYTES + 2);
        }
    } finally {
        fclose($stream);
    }
})->with(['', "\n", str_repeat('x', 1025), str_repeat('x', 100000)."\n"]);

it('never silently prompts visibly from a pipe or a noninteractive input', function (bool $interactive): void {
    $input = new ArrayInput([]);
    $input->setInteractive($interactive);
    $input->setStream(fopen('php://memory', 'r+'));
    $output = new BufferedOutput;
    expect(fn () => CredentialInput::read($input, new SymfonyStyle($input, $output), false))->toThrow(RuntimeException::class)
        ->and($output->fetch())->toBe('');
})->with([false, true]);

it('disables visible fallback and trimming on the hidden prompt', function (): void {
    $question = CredentialInput::question();
    expect($question->isHidden())->toBeTrue()
        ->and($question->isHiddenFallback())->toBeFalse()
        ->and($question->isTrimmable())->toBeFalse();
});
