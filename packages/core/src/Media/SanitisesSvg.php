<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Media;

use RuntimeException;

/**
 * Make SVG markup safe to store, or refuse it — ADR-041.
 *
 * ⚠️ CORE DECLARES THIS AND IMPLEMENTS NOTHING, WHICH IS STANDING PRINCIPLE #11 APPLIED TO ITS FIRST CASE.
 * ADR-041 settled that SVG sanitising must use a maintained library rather than a hand-rolled walk, because
 * SVG's bypasses "are discovered by other people, continuously" and buying that means buying the population
 * who discover them. The only library that has such a population — `enshrined/svg-sanitize`, 50.9M downloads,
 * 114 dependents, contributors from TYPO3, Automattic and Craft — is GPL-2.0-or-later.
 *
 * Core is MPL-2.0 and ADR-005 kept it plain precisely so GPL code CAN be combined with it (Exhibit B is the
 * opt-out and AGENTS.md rule 7 rejects any PR that adds it). But a GPL dependency in core's own `require`
 * block puts every distributed bundle containing `vendor/` under MPL §3.3's additional-offer obligation, and
 * ADR-004's paid modules are the thing ADR-005 was protecting when it rejected GPL as core's licence. So the
 * dependency lives in `kitsune/svg-sanitizer`, a first-party module, and core holds the seam.
 *
 * ⚠️ WHAT CORE KEEPS IS THE PART ADR-041 SAID AN ORG MUST NOT REACH. `MediaIntake` decides whether `svg` is an
 * accepted extension at all, and it is not — until an implementation of this interface is bound. No setting
 * widens that, because there is no setting: the gate is a container binding an operator makes by installing a
 * module, install-wide, which is exactly the granularity ADR-041's "an org must not be able to widen its own
 * allowlist" asks for.
 *
 * ⚠️ AND THE ESCAPE ADR-041 RESERVED STAYS OPEN. "The escape, if it ever stops being defensible, is refusing
 * SVG — and that stays available because the allowlist is core's." It still is: with nothing bound, core
 * refuses SVG, which is also the default a fresh install gets.
 */
interface SanitisesSvg
{
    /**
     * Return SVG markup with everything executable removed.
     *
     * ⚠️ IT RETURNS MARKUP RATHER THAN WRITING A FILE, so the caller decides where sanitised bytes land and
     * an implementation cannot be talked into writing somewhere. `MediaLibrary` is the only caller and it
     * writes to a temporary file it owns and removes.
     *
     * ⚠️ REFUSING IS A VALID ANSWER AND IS NOT THE SAME AS RETURNING NOTHING. Input that does not parse as
     * XML, or that sanitises down to no drawable content, must throw — a zero-byte `.svg` stored as a
     * successful upload is a broken asset that looks like a working one.
     *
     * @param  string  $svg  the file's own bytes, unmodified
     * @return string markup safe to store and to serve
     *
     * @throws RuntimeException when the input cannot be made safe, naming why
     */
    public function sanitise(string $svg): string;
}
