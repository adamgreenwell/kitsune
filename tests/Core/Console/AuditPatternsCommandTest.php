<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kitsune\Core\Fields\Types\TextType;
use Kitsune\Core\Models\FieldStorage;
use Kitsune\Core\Models\Org;
use Kitsune\Core\Tenancy\Context;

/**
 * The migration ADR-031 calls mandatory, which did not exist until review looked for it.
 *
 * ⚠️ THE DOCUMENT REQUIRED THIS AND NOTHING IMPLEMENTED IT. `field-types.md` §3 and ADR-031 both
 * said *"Migration is not optional. Patterns already authored were accepted by the screen, not by
 * the grammar, so any outside it must be found before this lands"* — and there was no command, no
 * migration, and nothing that ran `unpublishable()` over stored rows. A published requirement with
 * no implementation is the same failure as a published rule with no enforcement, which this branch
 * had already made once.
 *
 * ⚠️ WHAT AN UPGRADE ACTUALLY SUFFERS is worse than "some patterns are now invalid". A stored
 * `^(a|aa)+$` keeps being published in the API schema and keeps being enforced server-side, because
 * nothing revalidates a row that is not saved — and then the first UNRELATED edit to that field
 * fails, telling the author their pattern is invalid on a screen where they changed a label.
 */
beforeEach(function (): void {
    $this->org = Org::create(['name' => 'Golfdom', 'slug' => 'golfdom']);
    app(Context::class)->setOrg($this->org);
});

afterEach(fn () => app(Context::class)->forget());

/**
 * A field storage row carrying a configured length, written past the settings guard.
 *
 * ⚠️ Below Eloquent for the reason `storedPattern()` gives, and here the reason IS the subject: the
 * settings guard refuses to create a field wider than the ceiling, so such a row can only exist
 * because it predates it — which is the state an upgraded installation is in and the only state this
 * audit matters in.
 */
function storedLength(int $orgId, string $handle, int $maxLength): FieldStorage
{
    $storage = FieldStorage::create([
        'org_id' => $orgId, 'handle' => $handle, 'type' => 'text',
        'pii_class' => 'none', 'cardinality' => 1,
    ]);

    DB::table('field_storage')
        ->where('id', $storage->getKey())
        ->update(['settings' => json_encode(['maxLength' => $maxLength])]);

    return $storage->refresh();
}

/**
 * The same, for a type whose ceiling is its own — which is the case the audit was applying text's to.
 *
 * ⚠️ CREATED THROUGH THE MODEL, unlike the two fixtures either side of it, and that IS the assertion.
 * `TextareaType` reads `maxLength` with a default of 65,535 and enforces it, so a textarea this wide
 * saves cleanly — no guard to write past, nothing predating anything. A row the audit reports must be
 * a row that cannot be saved, and this one can.
 */
function storedTextareaLength(int $orgId, string $handle, int $maxLength): FieldStorage
{
    return FieldStorage::create([
        'org_id' => $orgId, 'handle' => $handle, 'type' => 'textarea',
        'pii_class' => 'none', 'cardinality' => 1, 'settings' => ['maxLength' => $maxLength],
    ]);
}

/** A field storage row carrying a pattern, written past the settings guard. */
function storedPattern(int $orgId, string $handle, string $pattern): FieldStorage
{
    $storage = FieldStorage::create([
        'org_id' => $orgId, 'handle' => $handle, 'type' => 'text',
        'pii_class' => 'none', 'cardinality' => 1,
    ]);

    /*
     * ⚠️ WRITTEN THROUGH THE QUERY BUILDER, PAST ELOQUENT ENTIRELY, and both halves of that are
     * deliberate. The point of this fixture is a row that PREDATES the grammar, which by definition
     * cannot be created through the model — `guardSettingsAreUsable()` would refuse it, which is the
     * whole reason the audit is needed.
     *
     * `FieldStorage::query()->update()` does not work either, and the failure is the codebase
     * defending itself correctly: `settings` is a per-row guarded column, so `GuardedStorageBuilder`
     * refuses to write it in bulk because its guards depend on the row (ADR-006). A raw table write
     * is the only honest way to stage a legacy row, and it is confined to this helper.
     */
    DB::table('field_storage')
        ->where('id', $storage->getKey())
        ->update(['settings' => json_encode(['pattern' => $pattern])]);

    return $storage->refresh();
}

it('reports nothing when every stored pattern is publishable', function (): void {
    storedPattern($this->org->id, 'slug', '^[a-z0-9-]{3,32}$');
    storedPattern($this->org->id, 'hex', '^#[0-9A-Fa-f]{6}$');

    $this->artisan('kitsune:audit-patterns')
        ->expectsOutputToContain('examined 2 stored patterns')
        ->expectsOutputToContain('Every stored pattern satisfies the published grammar')
        ->assertSuccessful();
});

it('names each unpublishable pattern, its field and the reason', function (): void {
    storedPattern($this->org->id, 'good', '^[a-z]+$');
    storedPattern($this->org->id, 'ambiguous', '^(a|aa)+$');

    $this->artisan('kitsune:audit-patterns')
        ->expectsOutputToContain('ambiguous')
        ->expectsOutputToContain('^(a|aa)+$')
        ->expectsOutputToContain('1 stored pattern is unpublishable.')
        ->assertSuccessful();
});

it('names a multi-value field whose item bound is now narrower than its cardinality', function (): void {
    /*
     * ⚠️ THE THIRD UPGRADE HAZARD IN THIS COMMAND, and the one whose refusal lands on an ENTRY rather
     * than on a field. `TextType::maxItems()` now bounds a multi-value text field whose pattern costs
     * quadratic work per value, because the length ceiling bounds one element and the field publishes
     * an array — 100 maximal values against an accepted `^a*a*b$` measure 3.6 seconds in ECMAScript.
     * A row already holding more elements than the new bound fails its next save.
     *
     * ⚠️ AND THE REMEDY IS NOT THE OBVIOUS ONE, which is why the line says it: cardinality is part of
     * the locked shape once data exists (ADR-006), so what an author can still change is the length or
     * the pattern.
     */
    $storage = storedPattern($this->org->id, 'aliases', '^a*a*b$');
    DB::table('field_storage')->where('id', $storage->getKey())->update([
        'cardinality' => -1,
        'settings' => json_encode(['pattern' => '^a*a*b$', 'maxLength' => 5000]),
    ]);

    $this->artisan('kitsune:audit-patterns')
        ->expectsOutputToContain('aliases')
        ->expectsOutputToContain('unlimited, 1 publishes now')
        ->expectsOutputToContain('Lower `maxLength` or simplify the pattern')
        ->assertSuccessful();
});

it('stays silent when the declared cardinality is inside the bound', function (): void {
    /*
     * ⚠️ The same field at the DEFAULT length, where `(5000 / 255)²` is 384 items — so a cardinality of
     * three is not narrowed at all and the report has nothing to say. Without this row the assertion
     * above would pass on a command that reported every multi-value field.
     */
    $storage = storedPattern($this->org->id, 'aliases', '^a*a*b$');
    DB::table('field_storage')->where('id', $storage->getKey())->update(['cardinality' => 3]);

    $this->artisan('kitsune:audit-patterns')
        ->doesntExpectOutputToContain('publishes now')
        ->assertSuccessful();
});

it('names a stored pattern the anchoring rule newly refuses', function (): void {
    /*
     * ⚠️ THE MIGRATION PROMISE, ASSERTED RATHER THAN CLAIMED. Requiring `^` for the two adjacent
     * variable-width atoms the top level allows made a shape unpublishable that the screen accepted
     * for as long as the screen existed — `a*a*b` measures 60 seconds in ECMAScript at the configured
     * ceiling where `^a*a*b` measures 36 ms. Every rule that narrows the grammar is an upgrade hazard,
     * and the answer this document gives is "the audit finds them before the upgrade does". A promise
     * about a command is worth what a test of the command says it is.
     *
     * The anchored spelling of the same pattern is stored alongside it, so the row that is reported is
     * reported for the anchor and not for the stars.
     */
    storedPattern($this->org->id, 'unanchored', 'a*a*b');
    storedPattern($this->org->id, 'anchored', '^a*a*b$');

    $this->artisan('kitsune:audit-patterns')
        ->expectsOutputToContain('unanchored')
        ->expectsOutputToContain('not anchored')
        ->expectsOutputToContain('1 stored pattern is unpublishable.')
        ->assertSuccessful();

    $this->artisan('kitsune:audit-patterns', ['--strict' => true])->assertFailed();
});

it('exits non-zero under --strict, so a deployment can gate on it', function (): void {
    /*
     * ⚠️ SUCCESS WITHOUT `--strict` IS DELIBERATE. An operator running the audit to find out where
     * they stand should not have the command look like it failed; a deployment that must not proceed
     * asks for the gate explicitly. There is no `--force`, because there is no mechanical repair: a
     * pattern says what a field accepts, and only its owner knows what that should be.
     */
    storedPattern($this->org->id, 'ambiguous', '^(a|aa)+$');

    $this->artisan('kitsune:audit-patterns')->assertSuccessful();
    $this->artisan('kitsune:audit-patterns', ['--strict' => true])->assertFailed();
});

it('examines every org, not only one', function (): void {
    /*
     * ⚠️ THE FAILURE THIS COMMAND EXISTS TO PREVENT, REPRODUCED INSIDE IT. An audit that reported
     * "0 problems" for an installation full of them would be worse than none. `FieldStorage` is
     * `#[Unscoped]`, so a plain query already crosses orgs — asserted rather than assumed, because
     * that attribute could change and this is the test that would notice.
     */
    $other = Org::create(['name' => 'Other', 'slug' => 'other']);

    storedPattern($this->org->id, 'mine', '^[a-z]+$');
    storedPattern($other->id, 'theirs', '^(a|aa)+$');

    $this->artisan('kitsune:audit-patterns')
        ->expectsOutputToContain('theirs')
        ->expectsOutputToContain('examined 2 stored patterns')
        ->assertSuccessful();
});

it('ignores rows with no pattern rather than counting them', function (): void {
    FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'plain', 'type' => 'text',
        'pii_class' => 'none', 'cardinality' => 1,
    ]);

    $this->artisan('kitsune:audit-patterns')
        ->expectsOutputToContain('examined 0 stored patterns')
        ->assertSuccessful();
});

it('holds only a count, however many patterns are unpublishable', function (): void {
    /*
     * ⚠️ IT COLLECTED EVERY FAILING MODEL, which review found. Memory grew with the number of
     * FAILURES rather than with the chunk — `chunkById()` bounds only the database batch — so an audit
     * whose entire purpose is to run before an upgrade on a large installation could exhaust ADR-027's
     * 1 GB floor before printing anything. That is the worst moment to run out of memory: the operator
     * learns nothing and cannot tell whether the audit found nothing or died.
     *
     * ⚠️ ASSERTED ON MEMORY RATHER THAN ON THE OUTPUT, because the output was already correct — it
     * was correct and unbounded. Fifty failing rows is far more than a chunk, and the growth over the
     * walk is what changed.
     */
    foreach (range(1, 50) as $i) {
        storedPattern($this->org->id, "bad{$i}", '^(a|aa)+$');
    }

    $before = memory_get_usage();

    $this->artisan('kitsune:audit-patterns')
        ->expectsOutputToContain('50 stored patterns are unpublishable.')
        ->assertSuccessful();

    $grew = memory_get_usage() - $before;

    /*
     * A generous ceiling on purpose: this is asserting that memory does not scale with the failure
     * count, not measuring an exact figure. Collecting fifty hydrated models with their attributes
     * and original arrays comfortably exceeded this; a count does not approach it.
     */
    expect($grew)->toBeLessThan(2_000_000, 'memory grew with the number of failures rather than the chunk');
});

it('prints each row as it walks, so a long report is not lost on failure', function (): void {
    // ⚠️ The summary moved to the END as a consequence of streaming, which is also the better place
    // for it: an operator reading a long report wants the count where they finish, and a report that
    // dies halfway has still printed everything it found.
    storedPattern($this->org->id, 'first', '^(a|aa)+$');
    storedPattern($this->org->id, 'second', '^(b|bb)+$');

    $this->artisan('kitsune:audit-patterns')
        ->expectsOutputToContain('first')
        ->expectsOutputToContain('second')
        ->expectsOutputToContain('2 stored patterns are unpublishable.')
        ->assertSuccessful();
});

it('names a field configured longer than the limit allows', function (): void {
    /*
     * ⚠️ THE LENGTH CEILING IS AN UPGRADE HAZARD TOO, which review found: this command asked
     * `Pattern::unpublishable()` and nothing else, so an installation carrying a text field configured
     * above `TextType::MAX_CONFIGURABLE_LENGTH` passed the audit and then had the next save of that
     * field refused — a field the author had not touched. That is the failure the command exists to
     * prevent, one setting along, and §4 is explicit that a field which saved yesterday and is refused
     * today is a broken install rather than a fixed one.
     *
     * ⚠️ COUNTED SEPARATELY FROM AN UNPUBLISHABLE PATTERN, because the remedies differ: a pattern has
     * to be rewritten by somebody who knows what the field should accept, and a length is lowered — at
     * the risk of truncating what authors have already stored, which the operator has to be told.
     */
    /*
     * ⚠️ STAGED BELOW ELOQUENT, for the reason `storedPattern()` records at length — and here it is not
     * merely convenient, it is the shape of the problem. The settings guard REFUSES to create a field
     * this wide, which is correct and is exactly why the audit is needed: the row can only exist because
     * it predates the ceiling. An upgraded installation is in precisely this state.
     */
    $wide = storedLength($this->org->id, 'wide', 65535);

    $this->artisan('kitsune:audit-patterns')
        ->expectsOutputToContain('wide')
        ->expectsOutputToContain('maxLength: 65535')
        ->expectsOutputToContain('1 field is configured longer than the limit allows')
        ->assertSuccessful();

    // ⚠️ And --strict is a deployment gate, so it has to fail on this as well as on a pattern.
    $this->artisan('kitsune:audit-patterns', ['--strict' => true])->assertFailed();

    expect($wide->refresh()->settings['maxLength'])->toBe(65535);
});

it('applies the text ceiling to text fields only', function (): void {
    /*
     * ⚠️ THE AUDIT WAS INVENTING THE HAZARD IT EXISTS TO FIND, which review caught. It read
     * `settings['maxLength']` without looking at `$storage->type` and compared it to `TextType`'s
     * ceiling — so a `textarea` configured at 65,535, which `TextareaType` both publishes and enforces,
     * was reported as invalid, `--strict` exited nonzero, and the operator was told to lower a setting
     * that saves cleanly. A false failure in a deployment gate is worse than no gate.
     *
     * ⚠️ AND THE GATE COVERS THE PATTERN CHECK TOO, for the identical reason rather than a similar one:
     * `Pattern::unpublishable()` is reached from `TextType::validateSettings()` and nowhere else, so a
     * `pattern` stored against any other type is never enforced and can never be why a save is refused.
     * Both keys are asserted here because one gate now answers for both.
     */
    $wide = storedTextareaLength($this->org->id, 'notes', 65535);

    /*
     * The pattern is added below Eloquent because `TextareaType` declares no `pattern` setting, so the
     * settings guard refuses it on this type — which is the point: an unenforceable key on a row that
     * cannot be saved with one.
     */
    DB::table('field_storage')
        ->where('id', $wide->getKey())
        ->update(['settings' => json_encode(['maxLength' => 65535, 'pattern' => '^(a|aa)+$'])]);

    $this->artisan('kitsune:audit-patterns', ['--strict' => true])
        ->expectsOutputToContain('Every stored pattern satisfies the published grammar')
        ->assertSuccessful();

    // And a text row alongside it still is reported, so the gate is about the type and not about the key.
    storedLength($this->org->id, 'wide', 65535);

    $this->artisan('kitsune:audit-patterns', ['--strict' => true])->assertFailed();
});

it('reports a length that is not a whole number', function (): void {
    /*
     * ⚠️ THE AUDIT HAS TO ASK THE QUESTION THE GUARD ASKS, and review found it asking a narrower one.
     * `validateSettings()` refuses a `maxLength` that is not a whole number — the ceiling was checked
     * with `is_numeric()` and spent with `(int)`, so `"100000x"` slipped past it and was then read as
     * 100000 — and this command reported only values above the ceiling. A stored `"100000x"` therefore
     * passed `--strict` while the next unrelated save of that field failed, which is precisely the
     * upgrade hazard this command exists to find.
     */
    $storage = FieldStorage::create([
        'org_id' => $this->org->id, 'handle' => 'padded', 'type' => 'text',
        'pii_class' => 'none', 'cardinality' => 1,
    ]);

    // Staged below Eloquent for the reason `storedPattern()` records: the guard refuses it now, so the
    // row can only exist because it predates the guard — which is the state an upgrade is in.
    DB::table('field_storage')
        ->where('id', $storage->getKey())
        ->update(['settings' => json_encode(['maxLength' => '100000x'])]);

    $this->artisan('kitsune:audit-patterns')
        ->expectsOutputToContain('padded')
        ->expectsOutputToContain('not a whole number')
        ->assertSuccessful();

    $this->artisan('kitsune:audit-patterns', ['--strict' => true])->assertFailed();
});

it('stays silent about a length inside the limit', function (): void {
    storedLength($this->org->id, 'narrow', TextType::MAX_CONFIGURABLE_LENGTH);

    $this->artisan('kitsune:audit-patterns', ['--strict' => true])
        ->expectsOutputToContain('every configured length is within its limit')
        ->assertSuccessful();
});
