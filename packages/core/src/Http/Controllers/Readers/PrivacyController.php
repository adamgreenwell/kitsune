<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Controllers\Readers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kitsune\Core\Readers\ConsentPurpose;
use Kitsune\Core\Readers\GlobalPrivacyControl;
use Kitsune\Core\Readers\ReaderConsents;
use Kitsune\Core\Readers\ReaderForm;
use Kitsune\Core\Readers\ReaderLinks;
use Kitsune\Core\Readers\ReaderPage;
use Kitsune\Core\Tenancy\Context;

/** @internal Signed-in readers manage choices for the Site that resolved the request. */
final class PrivacyController
{
    public function show(Request $request, Context $context, ReaderConsents $consents): Response
    {
        $site = $context->site() ?? abort(404);
        $choices = [];
        foreach (ConsentPurpose::cases() as $purpose) {
            $choices[] = [
                'purpose' => $purpose->value,
                'policy' => ReaderConsents::policy($site, $purpose),
                'effective' => $consents->allows($request, $purpose),
                'saved' => $consents->hasSavedGrant($purpose),
            ];
        }

        return ReaderPage::render($site, 'privacy', 'privacy.title', [
            'account' => ReaderLinks::path($site),
            'action' => ReaderLinks::path($site, ReaderLinks::PRIVACY),
            'choices' => $choices,
            'globalPrivacyControl' => GlobalPrivacyControl::requested($request),
            'saved' => $request->session()->get('kitsune.readers.privacy_saved') === true,
        ]);
    }

    public function store(Request $request, Context $context, ReaderConsents $consents): RedirectResponse
    {
        $site = $context->site() ?? abort(404);
        abort_unless(ReaderForm::tokenMatches($request), 419);

        $value = ReaderForm::field($request, 'purpose');
        $purpose = $value === null ? null : ConsentPurpose::tryFrom($value);
        $decision = ReaderForm::field($request, 'decision');
        $notice = ReaderForm::field($request, 'notice');

        abort_unless($purpose !== null && in_array($decision, ['grant', 'withdraw'], true), 422);
        abort_unless($consents->record($request, $purpose, $decision === 'grant', $notice), 409);

        $request->session()->flash('kitsune.readers.privacy_saved', true);

        return new RedirectResponse(ReaderLinks::path($site, ReaderLinks::PRIVACY), 303);
    }
}
