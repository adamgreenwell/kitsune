<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Credentials;

use Closure;
use Kitsune\Core\Tenancy\Context;
use LogicException;

/**
 * A one-use console capability for one org and one exact credential operation.
 *
 * Being a console process grants nothing by itself. A command opens this lease only around its intended writer
 * call; the writer consumes it at entry and checks it remains active after its audit callbacks. Finally expires it,
 * including a token a callback kept. There is no ambient window another command or request can inherit.
 *
 * @internal First-party credential commands only; never an HTTP authentication or host extension contract.
 */
final class CredentialConsoleAuthority
{
    private bool $active = true;

    private bool $consumed = false;

    private function __construct(
        private readonly int $orgId,
        private readonly string $action,
        private readonly ?string $slot,
        private readonly ?CredentialMode $mode,
        private readonly ?int $credentialId,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(self): T  $operation
     * @return T
     */
    public static function run(Context $context, string $action, #[\SensitiveParameter] ?string $slot,
        ?CredentialMode $mode, ?int $credentialId, Closure $operation): mixed
    {
        $orgId = $context->orgId();
        $valid = match ($action) {
            CredentialRefused::SET, CredentialRefused::REMOVE => $slot !== null && CredentialSlot::isName($slot) && $credentialId === null,
            CredentialRefused::SWITCH => $slot === null && $mode !== null && $credentialId === null,
            'reencrypt' => $slot === null && $mode === null && $credentialId !== null && $credentialId > 0,
            default => false,
        };
        if (PHP_SAPI !== 'cli' || $orgId === null || $orgId < 1 || ! $valid) {
            throw new LogicException('The credential console authority is unavailable for this operation.');
        }
        $authority = new self($orgId, $action, $slot, $mode, $credentialId);

        try {
            return $operation($authority);
        } finally {
            $authority->active = false;
        }
    }

    public function consume(Context $context, string $action, #[\SensitiveParameter] ?string $slot = null,
        ?CredentialMode $mode = null, ?int $credentialId = null): void
    {
        if (PHP_SAPI !== 'cli' || ! $this->active || $this->consumed || $context->orgId() !== $this->orgId
            || $action !== $this->action || $slot !== $this->slot || $mode !== $this->mode || $credentialId !== $this->credentialId) {
            $this->active = false;

            throw new LogicException('The credential console authority does not permit this operation.');
        }
        $this->consumed = true;
    }

    /** The consumed capability still belongs to this org and the same enclosing command operation. */
    public function assertActiveFor(Context $context): void
    {
        if (PHP_SAPI !== 'cli' || ! $this->active || ! $this->consumed || $context->orgId() !== $this->orgId) {
            throw new LogicException('The credential console authority is no longer active.');
        }
    }

    public function __clone(): void
    {
        throw new LogicException('Credential console authority cannot be cloned.');
    }

    /** @return array<never> */
    public function __serialize(): array
    {
        throw new LogicException('Credential console authority cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Credential console authority cannot be unserialized.');
    }
}
