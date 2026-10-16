<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Commerce\Filament;

use Closure;
use DateTimeZone;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Kitsune\Commerce\Models\RetentionPolicy;
use Kitsune\Commerce\Privacy\OrderPrivacy;
use Kitsune\Commerce\Privacy\RetentionPolicies;
use Kitsune\Commerce\Privacy\RetentionPolicySnapshot;
use Kitsune\Commerce\Privacy\RetentionRefused;
use Kitsune\Core\Tenancy\Context;
use Livewire\Attributes\Locked;
use Throwable;

/** Explicit immutable versions, then a separate prospective activation. Every action rechecks owner authority. @internal */
final class RetentionSettings extends Page
{
    use RestrictsFileUploadsToSchemaComponents;

    protected static string $resource = RetentionResource::class;

    protected string $view = 'commerce::retention-settings';

    /** @var array<string,mixed> */
    public array $policy = ['billing_fields' => [], 'legal_basis' => '', 'reason' => '', 'clock' => '', 'duration' => '', 'timezone' => ''];

    public mixed $orderReference = '';

    public mixed $orderPolicyVersion = '';

    public mixed $orderReason = '';

    public mixed $holdReference = '';

    public mixed $reviewDate = '';

    public mixed $confirmed = false;

    public mixed $batchLimit = '100';

    /** @var array{examined:int,minimized:int,purged:int,held:int}|null */
    #[Locked]
    public ?array $maintenanceResult = null;

    #[Locked]
    public bool $previewOnly = true;

    #[Locked]
    public bool $deliveryRequested = false;

    #[Locked]
    public bool $holdsRequested = false;

    public function mount(): void
    {
        self::authorizeResourceAccess();
    }

    public function getTitle(): string
    {
        return __('commerce::retention.title');
    }

    /** @return list<string> */
    public function billingFields(): array
    {
        return RetentionPolicySnapshot::BILLING_FIELDS;
    }

    /** @return list<string> */
    public function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    /** @return list<RetentionPolicySnapshot> */
    public function versions(): array
    {
        self::authorizeResourceAccess();

        try {
            return app(RetentionPolicies::class)->versions((new RetentionPolicy)->getConnection(), $this->org());
        } catch (Throwable) {
            $this->addError('retention', __('commerce::retention.unavailable'));

            return [];
        }
    }

    public function currentVersion(): ?int
    {
        self::authorizeResourceAccess();

        try {
            return app(RetentionPolicies::class)->current((new RetentionPolicy)->getConnection(), $this->org())?->version;
        } catch (Throwable) {
            $this->addError('retention', __('commerce::retention.unavailable'));

            return null;
        }
    }

    public function createPolicy(): void
    {
        self::authorizeResourceAccess();
        $this->resetErrorBag('policy');

        try {
            $config = $this->configuration();
            app(RetentionPolicies::class)->createAsOwner($config['billing_fields'], $config['legal_basis'], $config['reason'],
                $config['clock'], $config['duration'], $config['timezone']);
        } catch (Throwable) {
            $this->addError('policy', __('commerce::retention.policy_refused'));

            return;
        }

        $this->policy = ['billing_fields' => [], 'legal_basis' => '', 'reason' => '', 'clock' => '', 'duration' => '', 'timezone' => ''];
        Notification::make()->title(__('commerce::retention.policy_created'))->success()->send();
    }

    public function activatePolicy(mixed $version): void
    {
        self::authorizeResourceAccess();
        $this->resetErrorBag('retention');

        try {
            app(RetentionPolicies::class)->activateAsOwner(RetentionPolicySnapshot::integer($version));
        } catch (Throwable) {
            $this->addError('retention', __('commerce::retention.activation_refused'));

            return;
        }

        Notification::make()->title(__('commerce::retention.policy_activated'))->success()->send();
    }

    public function adoptPolicy(): void
    {
        $this->orderAction(fn () => app(OrderPrivacy::class)->adoptAsOwner($this->reference(),
            RetentionPolicySnapshot::integer($this->orderPolicyVersion), $this->reason(), $this->confirmation()));
    }

    public function placeHold(): void
    {
        $this->orderAction(function (): void {
            if (! is_string($this->reviewDate)) {
                throw new RetentionRefused;
            }
            $this->holdReference = app(OrderPrivacy::class)->holdAsOwner($this->reference(), $this->reason(), $this->reviewDate, $this->confirmation());
            $this->holdsRequested = true;
        });
    }

    public function releaseHold(): void
    {
        $this->orderAction(function (): void {
            if (! is_string($this->holdReference)) {
                throw new RetentionRefused;
            }
            app(OrderPrivacy::class)->releaseHoldAsOwner($this->reference(), $this->holdReference, $this->reason(), $this->confirmation());
        });
    }

    public function completeDelivery(): void
    {
        $this->orderAction(fn () => app(OrderPrivacy::class)->completeDeliveryAsOwner($this->reference(), $this->reason(), $this->confirmation()));
    }

    public function loadDelivery(): void
    {
        $this->orderAction(function (): void {
            app(OrderPrivacy::class)->deliveryAsOwner($this->reference());
            $this->deliveryRequested = true;
        });
    }

    public function loadHolds(): void
    {
        $this->orderAction(function (): void {
            app(OrderPrivacy::class)->holdsAsOwner($this->reference());
            $this->holdsRequested = true;
        });
    }

    /** @return list<array{reference:string,reason:string,review_at:string}> */
    public function holds(): array
    {
        self::authorizeResourceAccess();
        if (! $this->holdsRequested) {
            return [];
        }

        try {
            return app(OrderPrivacy::class)->holdsAsOwner($this->reference());
        } catch (Throwable) {
            $this->addError('order', __('commerce::retention.order_refused'));

            return [];
        }
    }

    public function selectHold(mixed $reference): void
    {
        $this->orderAction(function () use ($reference): void {
            foreach (app(OrderPrivacy::class)->holdsAsOwner($this->reference()) as $hold) {
                if ($hold['reference'] === $reference) {
                    $this->holdReference = $reference;

                    return;
                }
            }
            throw new RetentionRefused;
        });
    }

    /** Essential details are read only on the owner's explicit request; they never enter buyer/export projections.
     * @return array{contact_email:string,delivery:array<string,mixed>,review_at:string}|null
     */
    public function deliveryDetails(): ?array
    {
        self::authorizeResourceAccess();
        if (! $this->deliveryRequested) {
            return null;
        }

        try {
            return app(OrderPrivacy::class)->deliveryAsOwner($this->reference());
        } catch (Throwable) {
            $this->addError('order', __('commerce::retention.order_refused'));

            return null;
        }
    }

    public function previewOrder(): void
    {
        $this->maintainOrder(true);
    }

    public function applyOrder(): void
    {
        $this->maintainOrder(false);
    }

    public function previewBatch(): void
    {
        $this->maintainBatch(true);
    }

    public function applyBatch(): void
    {
        $this->maintainBatch(false);
    }

    /** No form state becomes authority: services re-read owner, scope, policy and financial custody. */
    private function orderAction(Closure $work): void
    {
        self::authorizeResourceAccess();
        $this->resetErrorBag('order');
        $this->maintenanceResult = null;

        try {
            $work();
        } catch (Throwable) {
            $this->addError('order', __('commerce::retention.order_refused'));

            return;
        } finally {
            $this->confirmed = false;
        }

        Notification::make()->title(__('commerce::retention.order_saved'))->success()->send();
    }

    private function maintainOrder(bool $dryRun): void
    {
        $this->orderAction(function () use ($dryRun): void {
            if (! $dryRun) {
                $this->confirmation();
            }
            $this->maintenanceResult = app(OrderPrivacy::class)->maintainOrder($this->reference(), dryRun: $dryRun);
            $this->previewOnly = $dryRun;
        });
    }

    private function maintainBatch(bool $dryRun): void
    {
        $this->orderAction(function () use ($dryRun): void {
            if ((! is_string($this->batchLimit) && ! is_int($this->batchLimit))
                || preg_match('/\A[1-9][0-9]{0,3}\z/D', (string) $this->batchLimit) !== 1 || (int) $this->batchLimit > 1000) {
                throw new RetentionRefused;
            }
            if (! $dryRun) {
                $this->confirmation();
            }
            $this->maintenanceResult = app(OrderPrivacy::class)->sweep((int) $this->batchLimit, dryRun: $dryRun);
            $this->previewOnly = $dryRun;
        });
    }

    private function reference(): string
    {
        if (! is_string($this->orderReference) || preg_match('/\A[a-f0-9]{32}\z/D', $this->orderReference) !== 1) {
            throw new RetentionRefused;
        }

        return $this->orderReference;
    }

    private function reason(): string
    {
        return is_string($this->orderReason) ? $this->orderReason : throw new RetentionRefused;
    }

    private function confirmation(): bool
    {
        return $this->confirmed === true ? true : throw new RetentionRefused;
    }

    /** @return array{schema_version:int,billing_fields:list<string>,legal_basis:string,reason:string,clock:string,duration:int,timezone:?string} */
    private function configuration(): array
    {
        $keys = ['billing_fields', 'legal_basis', 'reason', 'clock', 'duration', 'timezone'];
        if (count($this->policy) !== count($keys) || array_diff(array_keys($this->policy), $keys) !== []
            || ! is_array($this->policy['billing_fields']) || ! is_string($this->policy['legal_basis'])
            || ! is_string($this->policy['reason']) || ! is_string($this->policy['clock'])
            || (! is_string($this->policy['duration']) && ! is_int($this->policy['duration']))
            || preg_match('/\A(?:0|[1-9][0-9]{0,4})\z/D', (string) $this->policy['duration']) !== 1
            || ! is_string($this->policy['timezone'])) {
            throw new RetentionRefused;
        }

        return RetentionPolicySnapshot::configurationFor($this->policy['billing_fields'], $this->policy['legal_basis'],
            $this->policy['reason'], $this->policy['clock'], (int) $this->policy['duration'],
            $this->policy['clock'] === RetentionPolicySnapshot::CONFIRMATION_DAYS ? null : $this->policy['timezone']);
    }

    private function org(): int
    {
        return app(Context::class)->orgId() ?? throw new RetentionRefused;
    }
}
