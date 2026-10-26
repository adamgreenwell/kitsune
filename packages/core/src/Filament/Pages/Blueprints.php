<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Filament\Pages;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Kitsune\Core\Auth\Permissions;
use Kitsune\Core\Blueprints\BlueprintApplier;
use Kitsune\Core\Blueprints\BlueprintDefinition;
use Kitsune\Core\Blueprints\BlueprintRegistry;
use Kitsune\Core\Models\Blueprint;
use Kitsune\Core\Models\Site;
use Kitsune\Core\Tenancy\Context;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * An existing Org's owner applies registered blueprints or reviews a refusal before reversing unused structure.
 *
 * @internal No new public instance handler: Livewire can call those independently of visible buttons. All added
 * handlers are static, reauthorize the real panel/Site/Org and refuse a missing user. Tokens bind a review to that
 * actor, Site, Org, operation, definition and receipt; the kernel remains the only writer and takes its own locks.
 */
final class Blueprints extends Page
{
    use RestrictsFileUploadsToSchemaComponents;

    protected static ?string $slug = 'blueprints';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        $context = app(Context::class);
        $site = $context->site();
        $user = Permissions::currentUser();
        $panel = app()->bound('filament') ? Filament::getCurrentPanel() : null;

        if (! $site instanceof Site || $panel === null || ! $user instanceof FilamentUser || ! $user instanceof HasTenants
            || ! $user->canAccessPanel($panel) || ! $user->canAccessTenant($site)
            || Filament::getTenant()?->getKey() !== $site->getKey()
            || (int) $site->org_id !== $context->orgId()) {
            return false;
        }

        // A modal can outlive role revocation; never let a previous render's once() answer authorize a submit.
        Permissions::forget();

        return Permissions::isOwner($user) && Site::query()->useWritePdo()
            ->whereKey($site->getKey())->where('org_id', $context->orgId())->exists();
    }

    public function getTitle(): string
    {
        return __('kitsune::blueprints.title');
    }

    /** @return list<array{handle: string, available: ?string, installed: ?string, status: string, summary: list<string>}> */
    public static function lines(): array
    {
        abort_unless(self::canAccess(), 403);
        $definitions = app(BlueprintRegistry::class)->all();
        $receipts = Blueprint::query()->useWritePdo()->orderBy('handle')->get()->keyBy('handle');
        $handles = array_unique([...array_keys($definitions), ...$receipts->keys()->all()]);
        sort($handles);
        $lines = [];

        foreach ($handles as $handle) {
            $definition = $definitions[$handle] ?? null;
            $receipt = $receipts->get($handle);
            $lines[] = [
                'handle' => $handle,
                'available' => $definition?->version(),
                'installed' => $receipt?->version,
                'status' => $receipt === null ? 'not_applied' : ($receipt->applied_at !== null ? 'applied' : 'unfinished'),
                'summary' => $definition !== null ? self::summary($definition) : [],
            ];
        }

        return $lines;
    }

    /** @return list<string> */
    private static function summary(BlueprintDefinition $definition): array
    {
        $lines = [];

        foreach ($definition->entryTypes() as $type) {
            $lines[] = __('kitsune::blueprints.type', ['name' => $type->pluralName, 'handle' => $type->handle]);

            foreach ($type->fields as $field) {
                $lines[] = __('kitsune::blueprints.field', ['type' => $type->handle, 'name' => $field->label, 'handle' => $field->handle]);
            }
        }

        foreach ($definition->roles() as $role) {
            $lines[] = __('kitsune::blueprints.role', ['name' => $role->name, 'handle' => $role->handle]);

            foreach ($role->grants as $type => $actions) {
                $lines[] = __('kitsune::blueprints.grant', ['role' => $role->name, 'type' => $type, 'actions' => implode(', ', $actions)]);
            }
        }

        return $lines;
    }

    /** @return array{org: string, confirmation: string, token: string, summary: list<string>, preview: ?array<string, mixed>} */
    public static function review(string $handle, string $operation): array
    {
        abort_unless(self::canAccess(), 403);
        $facts = self::facts($handle, $operation);
        $org = app(Context::class)->org();

        return [
            'org' => (string) $org?->name,
            'confirmation' => $handle.' @ '.(string) $org?->slug,
            'token' => self::reviewToken($facts),
            'summary' => $operation === 'apply' ? self::summary(self::definition($handle)) : [],
            'preview' => $operation === 'reverse' ? BlueprintApplier::previewReverse($handle) : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function facts(string $handle, string $operation): array
    {
        if (! in_array($operation, ['apply', 'reverse'], true)) {
            throw ValidationException::withMessages(['confirmation' => __('kitsune::blueprints.invalid_action')]);
        }

        $definition = $operation === 'apply' ? self::definition($handle) : null;
        $receipt = Blueprint::query()->useWritePdo()->where('handle', $handle)->first();

        if ($operation === 'reverse' && $receipt === null) {
            throw ValidationException::withMessages(['confirmation' => __('kitsune::blueprints.no_receipt')]);
        }

        return [
            'format' => 1,
            'actor' => (string) Permissions::currentUser()?->getAuthIdentifier(),
            'actor_class' => Permissions::currentUser()::class,
            'org' => app(Context::class)->orgId(),
            'site' => app(Context::class)->siteId(),
            'handle' => $handle,
            'operation' => $operation,
            'receipt' => BlueprintApplier::receiptIdentity($receipt),
            'definition' => $definition === null ? null : hash('sha256', serialize([
                $definition::class, $definition->version(), $definition->entryTypes(), $definition->roles(),
            ])),
        ];
    }

    private static function definition(string $handle): BlueprintDefinition
    {
        $definition = app(BlueprintRegistry::class)->get($handle);

        if ($definition === null) {
            throw ValidationException::withMessages(['confirmation' => __('kitsune::blueprints.not_registered')]);
        }

        BlueprintApplier::refuseMalformed($definition);

        return $definition;
    }

    /**
     * Authenticate current facts without sending them to the browser or opening an encrypted value.
     *
     * @param  array<string, mixed>  $facts
     */
    private static function reviewToken(array $facts): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Blueprint review requires the application signing key to be configured.');
        }

        return hash_hmac('sha256', 'kitsune.blueprint.review.v1:'.serialize($facts), $key);
    }

    /** @return array<string, mixed> */
    public static function perform(string $handle, string $operation, string $confirmation, string $token): array
    {
        abort_unless(self::canAccess(), 403);

        $facts = self::facts($handle, $operation);

        if (! hash_equals(self::reviewToken($facts), $token) || $confirmation !== $handle.' @ '.(string) app(Context::class)->org()?->slug) {
            throw ValidationException::withMessages(['confirmation' => __('kitsune::blueprints.review_changed')]);
        }

        // No outer transaction: apply/reverse own their row transactions and their separate DDL phase.
        return $operation === 'apply'
            ? BlueprintApplier::apply(self::definition($handle), expectedReceipt: $facts['receipt'])
            : BlueprintApplier::reverse($handle, expectedReceipt: $facts['receipt']);
    }

    public function content(Schema $schema): Schema
    {
        abort_unless(self::canAccess(), 403);
        $components = [Text::make(__('kitsune::blueprints.intro', ['org' => app(Context::class)->org()?->name]))];

        foreach (self::lines() as $line) {
            $parts = [
                Text::make(__('kitsune::blueprints.versions', [
                    'installed' => $line['installed'] ?? __('kitsune::blueprints.none'),
                    'available' => $line['available'] ?? __('kitsune::blueprints.unavailable'),
                ])),
                Text::make(__('kitsune::blueprints.status.'.$line['status']))->color('gray'),
            ];

            foreach ($line['summary'] as $text) {
                $parts[] = Text::make($text);
            }

            $actions = [];

            if ($line['available'] !== null) {
                $actions[] = self::actionFor($line['handle'], 'apply');
            }

            if ($line['installed'] !== null) {
                $actions[] = self::actionFor($line['handle'], 'reverse');
            }

            $parts[] = Actions::make($actions);
            $components[] = Section::make($line['handle'])->schema($parts);
        }

        return $schema->components($components);
    }

    public static function actionFor(string $handle, string $operation): Action
    {
        return Action::make($operation.'_'.hash('sha256', $handle))
            ->label(__('kitsune::blueprints.action.'.$operation))
            ->color($operation === 'reverse' ? 'danger' : 'primary')
            ->authorize(static fn (): bool => self::canAccess())
            ->databaseTransaction(false)
            // Action schemas can be built before the write. A fresh GET shows the committed receipt and actions.
            ->successRedirectUrl(static fn (): string => self::getUrl())
            ->modalHeading(static fn (): string => __('kitsune::blueprints.review_heading', [
                'handle' => $handle, 'org' => app(Context::class)->org()?->name,
            ]))
            ->modalDescription(__('kitsune::blueprints.review_description'))
            ->modalSubmitActionLabel(__('kitsune::blueprints.confirm_action.'.$operation))
            ->fillForm(static function () use ($handle, $operation): array {
                $review = self::review($handle, $operation);

                return ['review_token' => $review['token']];
            })
            ->schema(static function () use ($handle, $operation): array {
                try {
                    $review = self::review($handle, $operation);
                } catch (RuntimeException|InvalidArgumentException $e) {
                    return [Text::make(self::refusal($e))];
                }

                $parts = [];

                if ($review['preview'] !== null) {
                    foreach (['refusals', 'removed', 'kept', 'gone', 'notes', 'problems'] as $kind) {
                        $items = $review['preview'][$kind];

                        if ($items === []) {
                            continue;
                        }

                        $parts[] = Section::make(__('kitsune::blueprints.preview.'.$kind))->schema(array_map(
                            static fn (string $text): Text => Text::make($text), $items,
                        ));
                    }

                    if ($review['preview']['outcome'] !== 'reversed') {
                        $parts[] = Text::make(__('kitsune::blueprints.receipt_only'));
                    }
                } else {
                    $parts[] = Text::make(__('kitsune::blueprints.apply_review'));

                    foreach ($review['summary'] as $text) {
                        $parts[] = Text::make($text);
                    }
                }

                $parts[] = Hidden::make('review_token')->required();
                $parts[] = TextInput::make('confirmation')
                    ->label(__('kitsune::blueprints.confirmation'))
                    ->helperText(__('kitsune::blueprints.confirmation_help', ['confirmation' => $review['confirmation']]))
                    ->required()->maxLength(mb_strlen($review['confirmation']))->autocomplete(false);

                return $parts;
            })
            ->action(static function (array $data, Action $action) use ($handle, $operation): void {
                try {
                    $result = self::perform($handle, $operation,
                        is_string($data['confirmation'] ?? null) ? $data['confirmation'] : '',
                        is_string($data['review_token'] ?? null) ? $data['review_token'] : '');
                } catch (ValidationException $e) {
                    // Kernel-facing validation uses a plain key; Filament renders the mounted schema's full path.
                    $index = $action->getNestingIndex();

                    if ($index === null) {
                        throw $e;
                    }

                    throw ValidationException::withMessages([
                        "mountedActions.{$index}.data.confirmation" => $e->errors()['confirmation'] ?? [__('kitsune::blueprints.review_changed')],
                    ]);
                } catch (Throwable $e) {
                    Notification::make()->danger()->title(__('kitsune::blueprints.refused'))->body(e(self::refusal($e)))->persistent()->send();
                    $action->halt();

                    return;
                }

                $undropped = $result['undropped'] ?? [];
                Notification::make()->color($undropped === [] ? 'success' : 'warning')
                    ->title(__('kitsune::blueprints.completed'))
                    ->body(e($undropped === []
                        ? __('kitsune::blueprints.completed_body', ['handle' => $handle, 'org' => app(Context::class)->org()?->name])
                        : __('kitsune::blueprints.cleanup_owed', ['columns' => implode(', ', $undropped)])))->send();
            });
    }

    /** A named domain refusal is useful; an interpolated SQL statement, binding or unexpected exception is not. */
    public static function refusal(Throwable $failure): string
    {
        for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof QueryException || $cause instanceof PDOException) {
                return __('kitsune::blueprints.storage_failure');
            }
        }

        return $failure instanceof RuntimeException || $failure instanceof InvalidArgumentException
            ? $failure->getMessage()
            : __('kitsune::blueprints.unexpected_failure');
    }
}
