<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Fields\Internal;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Gives every text-bearing block inside a rich text value its own direction.
 *
 * @internal Not part of the field-type contract, and not a seam a plugin may bind to. Review is right
 *           that a tag is not a visibility — this codebase has already rejected `@internal` twice for
 *           exactly that reason — so the tag is the weaker half and the namespace is the other: nothing
 *           a plugin imports lives in `Fields\Internal`, and no plugin-facing class mentions this one.
 *           `Entry` applies it from a PRIVATE method, which is the construction
 *           `conversionLostSomething()` settled on as *"the first version a plugin cannot reach"*.
 *
 *           What that still does not prevent is a plugin calling this class by name. PHP has no
 *           package-private, so the remaining options are 600 lines of DOM walking inside `Entry` or
 *           this — and unlike the stopgaps that reasoning was written for, this is not due for removal
 *           at v1.2, so binding to it creates no compatibility trap to spring.
 *
 * ⚠️ IT LIVES HERE RATHER THAN IN `RichTextType` BECAUSE ADR-029 SAYS IT MUST, and review found the
 * ADR claiming a guarantee this implementation did not keep. That ADR's own test is: *"can a new field
 * type be added without text direction, and is that expressible at all?"* — and its answer is no,
 * *"because direction is derived by the renderer from the control's kind and is not a property a field
 * type can decline to set"*. While this pass was a private method on one concrete class, the answer was
 * yes: a module registering another type that returns `Control::RichText` got no per-block direction,
 * and `FieldValueRenderer` deliberately adds none for `PerBlock` because the direction belongs in the
 * stored bytes.
 *
 * ⚠️ AND THE ADR IS THE SIDE THAT WAS RIGHT, which is why this moved rather than the ADR being amended.
 * Its load-bearing argument is that *"issue #39 has already shipped that attribute three times and been
 * short of complete twice, both times because the reach of a correct rule depended on somebody
 * enumerating call sites"* — and this branch is the sixth round of exactly that. Amending would have
 * conceded the one claim the seam exists to make.
 *
 * `BaseFieldType::toStorage()` applies it to every control whose `ValueDirection` is `PerBlock`, so a
 * type cannot decline it by omission — only by returning a different control, which is a visible
 * decision rather than a forgotten one.
 *
 * ⚠️ NOT A SECURITY BOUNDARY. `RichTextType::sanitize()` is that (field-types.md §6) and this runs
 * AFTER it, on a value already known to be safe. The two steps are separate for the reason
 * `castToStorage()` records: threading a presentation concern through the sanitiser means every future
 * direction change edits security expectations, and a reviewer cannot tell which half is which.
 */
final class BlockDirection
{
    /**
     * The only values that establish a direction.
     *
     * ⚠️ PRIVATE, LIKE THE TWO TAG LISTS BELOW. Review pointed out that a public constant is three
     * more symbols on the pre-v1.2 extension surface — a plugin can depend on a list this
     * implementation has to stay free to change, and `CONTRIBUTING.md` lists new public API before
     * v1.2 among the things that will not merge. They are used only by private methods in this class.
     *
     * ⚠️ `dir` IS ALLOWED AND ITS VALUE WAS NEVER CHECKED, which review found: `<p dir="">` and
     * `<p dir="banana">` survive sanitising, and an attribute that establishes nothing was still
     * enough to block the stamp. A malformed value is not a choice to respect.
     */
    private const DIRECTIONS = ['ltr', 'rtl', 'auto'];

    /**
     * The tags that hold a run of text, and therefore have a direction of their own.
     *
     * ⚠️ `ul`, `ol` and `figure` are absent on purpose: they contain blocks rather than text, so a
     * direction on them would be inherited by children that should each resolve their own. `li` and
     * `figcaption` are the text-bearing halves of those pairs and are here.
     *
     * ⚠️ `br` and the inline tags are absent for the opposite reason — `dir` on a fragment of a
     * sentence resolves from a fragment, which is how you get one clause of a paragraph pointing the
     * wrong way.
     */
    private const BLOCK_TAGS = [
        'p', 'li', 'h2', 'h3', 'h4', 'blockquote', 'pre', 'figcaption',
    ];

    /**
     * Tags that are blocks at the top level, so a run beside one is a separate run.
     *
     * ⚠️ `ul`, `ol` and `figure` are here although they are NOT in `BLOCK_TAGS`: they carry no
     * direction of their own — their children each resolve one — but they are still blocks, so text
     * before and after a list is two runs rather than one.
     */
    private const CONTAINER_TAGS = [
        'p', 'li', 'h2', 'h3', 'h4', 'blockquote', 'pre', 'figure', 'figcaption', 'ul', 'ol',
    ];

    /**
     * Allowed tags that hold FLOW content, so a loose run inside one can be wrapped in a `<p>`.
     *
     * ⚠️ THE SET IS DECIDED BY WHAT A `<p>` MAY LEGALLY SIT INSIDE, not by which tags hold text —
     * which is why it is not `CONTAINER_TAGS` and not `BLOCK_TAGS`. `ul` and `ol` take only `li`,
     * `p` and the headings take phrasing content, and `pre` takes phrasing content AND is
     * whitespace-significant. Wrapping in any of those would fix a direction by producing markup no
     * browser should be handed.
     *
     * ⚠️ Named separately from the two lists above BECAUSE it is nearly one of them, and a reader
     * who assumed it was would reintroduce exactly the over-reach this avoids.
     */
    private const FLOW_CONTAINER_TAGS = ['figure', 'blockquote', 'li', 'figcaption'];

    /**
     * Allowed tags whose only valid child is `li`, so loose text inside one cannot be wrapped.
     *
     * ⚠️ NAMED AS A THIRD SET because it is the complement of `FLOW_CONTAINER_TAGS` within
     * `CONTAINER_TAGS` for a reason that is easy to lose: these two hold blocks, so they are boundaries
     * between runs, and they take no `<p>`, so the run pass must skip them. That left malformed input
     * with loose text here handled by nothing at all, which is what review found. They are the only
     * allowed tags in that position, so the set is closed by the allowlist rather than by judgement.
     */
    private const LIST_TAGS = ['ul', 'ol'];

    /**
     * Every tag this implementation can stamp a direction ON, and therefore every tag it must be able
     * to take one back OFF.
     *
     * ⚠️ DERIVED FROM THE TWO STAMPING SETS rather than written out, because review found the fifth
     * face of this defect in the gap between them: the previous round taught the pass to stamp
     * `LIST_TAGS` and left the yielding pass reading `BLOCK_TAGS` alone, so a generated list direction
     * survived an author's later `dir="rtl"` on an ancestor and overrode it. The `<p>` beside it yielded
     * correctly, which is the tell — the rule was present and its coverage was not.
     *
     * A union cannot drift from its parts. Adding a third stamping set adds it here by construction.
     */
    private const STAMPED_TAGS = [...self::BLOCK_TAGS, ...self::LIST_TAGS];

    /**
     * Give every text-bearing block its own `dir="auto"`, so each resolves from its own content.
     *
     * ⚠️ A SINGLE `dir` ON THE FIELD IS WORSE THAN NONE, which is why this is per block. `dir="auto"`
     * on the editor resolves from the FIRST strong directional character in the whole document, so a
     * body that opens in English and continues in Arabic renders every Arabic paragraph
     * left-to-right — and looks handled, which is the failure mode issue #39 exists to stop.
     *
     * ⚠️ ON THE WAY TO STORAGE, not at render time. A value arriving from the API, a seeder or an
     * import gets the same treatment as one typed into the panel, and the stored bytes and the
     * displayed bytes cannot drift apart — which is the kind of difference that survives for years
     * because both halves look right on their own.
     *
     * ⚠️ ONLY WHEN A VALID DIRECTION IS ABSENT. `dir` is in ALLOWED_ATTRIBUTES, so an author who
     * wrote `dir="rtl"` has said something more specific than `auto` — a paragraph of Arabic opening
     * with a Latin brand name, where `auto` would resolve from the brand name and be wrong. This
     * fills a gap rather than overruling a decision.
     *
     * ⚠️ BUT `hasAttribute()` ALONE WAS THE WRONG TEST, which review found. `<p dir="">` and
     * `<p dir="banana">` survive sanitising — `dir` is allowed and its VALUE was never checked — and
     * an attribute that establishes no direction blocked the stamp while doing nothing itself, so an
     * Arabic block still inherited the chrome. Only `ltr`, `rtl` and `auto` are directions; anything
     * else is replaced rather than respected, because it expresses no choice to respect.
     *
     * ⚠️ AND A VALUE WITH NO BLOCK IN IT GOT NOTHING AT ALL, which review also found. `مرحبا` and
     * `<strong>مرحبا</strong>` are shapes the sanitiser deliberately preserves — it refuses to wrap
     * loose text, because reshaping content that arrived as a bare string is data loss of its own —
     * so this loop found no block and added no direction. A top-level run is a paragraph in
     * everything but markup, and it is wrapped in one so that it has somewhere to carry a direction.
     * That is a reshape, and it is confined to the case where the alternative is no direction at all.
     */
    public static function stampedInto(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // The same wrapper-and-meta parsing aid `sanitize()` documents at length: the meta declares
        // UTF-8, and the wrapper stops libxml wrapping loose top-level text in an implied `<p>`.
        $document->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        /*
         * ⚠️ A GENERATED `auto` MUST NOT BLOCK A LATER ANCESTOR CHOICE, and this pass runs FIRST for
         * that reason — review found the fourth face of the same defect. The first save stamps
         * `dir="auto"` on a block with nothing in force; the author then declares `dir="rtl"` on the
         * figure and saves the stored HTML again, and the caption's `auto` — which this implementation
         * wrote, not them — reads as a choice to respect and resolves from `ACME` for ever.
         *
         * `auto` is the default here and a fixed direction is a decision. That is the same sentence as
         * the two rules below: `auto` does not inherit to a child, and `auto` does not suppress a
         * wrapper's own. This is it applied to a block that already carries one.
         *
         * ⚠️ IT CANNOT BE DISTINGUISHED FROM AN AUTHOR'S `auto`, and the cost is stated rather than
         * hidden: somebody who deliberately writes `dir="auto"` on one block inside a `dir="rtl"`
         * container loses it. Marking generated attributes would need an attribute outside
         * ALLOWED_ATTRIBUTES, which is §6's published contract — a bigger change than the defect.
         *
         * ⚠️ AND IT RUNS BEFORE THE STAMPING PASS, so that pass's "stop at the nearest direction" is
         * true when it looks: after this, no generated `auto` stands between a block and a fixed
         * ancestor. The walk here skips `auto` ancestors for the same reason it is removing one — each
         * of them may be generated too, so none of them may block.
         */
        foreach (self::STAMPED_TAGS as $tag) {
            foreach (iterator_to_array($document->getElementsByTagName($tag)) as $element) {
                if (self::ownDirection($element) === 'auto' && self::fixedAncestorDirection($element) !== null) {
                    $element->removeAttribute('dir');
                }
            }
        }

        foreach (self::BLOCK_TAGS as $tag) {
            foreach (iterator_to_array($document->getElementsByTagName($tag)) as $element) {
                // No `instanceof` guard: `getElementsByTagName()` yields elements by definition,
                // and static analysis correctly calls the check dead.
                if (self::ownDirection($element) !== null) {
                    continue;
                }

                /*
                 * ⚠️ `auto` FILLS A GAP AND MUST NOT CLOSE A CHOICE ONE LEVEL UP, which review found
                 * — the third time this exact mistake has appeared on this branch, each time one step
                 * further from where it was fixed. `<figure dir="rtl"><figcaption>ACME مرحبا</…>` had
                 * `auto` stamped on the caption, and `auto` resolves from `ACME`: an explicit `rtl`
                 * was overridden by a default. Inheritance was giving the right answer.
                 *
                 * ⚠️ A FIXED ancestor is inherited; an `auto` one is not, and that asymmetry is the
                 * whole rule. `ltr` and `rtl` are decisions and reach every descendant that states
                 * none. `auto` is not a direction — it is an instruction to resolve from content —
                 * so a child under it must resolve from ITS OWN content, which is `auto` again and is
                 * exactly what issue #39 is about.
                 */
                /*
                 * ⚠️ AND THE FIX FOR THAT WROTE THE ANCESTOR'S DIRECTION ONTO THE CHILD, which review
                 * then found is a one-way door. A materialised `dir="rtl"` is indistinguishable from an
                 * author's, so editing the FIGURE to `ltr` and saving again left the caption `rtl`
                 * forever — measured, and no later save can undo it, because the value now looks like a
                 * choice to respect.
                 *
                 * So a child under a FIXED ancestor gets nothing at all. Inheritance was already giving
                 * the right answer, exactly as it was for the wrapper one rule along, and the answer is
                 * again to write nothing rather than to write the right thing.
                 */
                if (self::nearestDirection($element) !== null) {
                    continue;
                }

                $element->setAttribute('dir', 'auto');
            }
        }

        /*
         * ⚠️ AND A LIST HOLDING LOOSE TEXT, which no pass above can reach and no wrapper may fix.
         * Review found it: `<ul>مرحبا<li>English</li></ul>` is malformed and `sanitize()` preserves it
         * deliberately — §6 parses rather than pattern-matches, and an importer or the API can submit
         * it. `ul` and `ol` are absent from `BLOCK_TAGS` because they contain blocks rather than text,
         * and absent from `FLOW_CONTAINER_TAGS` because a `<p>` inside a list is markup no browser
         * should be handed. So the Arabic run was stored with no direction at all while the `li` beside
         * it got one, and it inherited the page.
         *
         * ⚠️ THE CONTAINER TAKES THE DIRECTION AND ITS CHILDREN KEEP THEIRS, which is what makes this
         * correct rather than a trade. `dir="auto"` resolves from the element's text EXCLUDING any
         * descendant that carries a `dir` of its own, and every `li` carries one by the time this runs —
         * so the list's `auto` reads the loose text only, and each item still resolves its own.
         *
         * ⚠️ AND WITH MORE THAN ONE LOOSE RUN THE FIRST DECIDES FOR ALL OF THEM, which is the limit of
         * what a container-level direction can do and is stated rather than hidden. One attribute
         * resolves once. Giving each run its own would need a wrapper, and the only element valid here
         * is `li` — which would turn a stray sentence into a list item and add a bullet to it. So this
         * is never worse than inheriting the page and often better, and it reshapes nothing.
         *
         * ⚠️ AND ONLY WHERE NOTHING IS IN FORCE, the same rule as both passes above: a list inside
         * `<blockquote dir="rtl">` already gives its loose text the author's direction, and stamping
         * `auto` over that would replace a decision with a default.
         */
        foreach (self::LIST_TAGS as $tag) {
            foreach (iterator_to_array($document->getElementsByTagName($tag)) as $list) {
                if (self::ownDirection($list) !== null || self::nearestDirection($list) !== null) {
                    continue;
                }

                if (! self::carriesText(self::looseChildren($list))) {
                    continue;
                }

                $list->setAttribute('dir', 'auto');
            }
        }

        /*
         * ⚠️ THE WRAPPER'S CHILDREN, NOT THE DOCUMENT'S, and the first version of this returned an
         * empty string by getting that wrong. Under `LIBXML_HTML_NOIMPLIED` the charset `<meta>`
         * becomes the document ELEMENT and the wrapper `<div>` is not among `$document->childNodes`
         * at all — measured: `childNodes` holds one node, the meta, while
         * `getElementsByTagName('p')` finds both paragraphs.
         *
         * `sanitize()` never notices because `clean()` UNWRAPS the div — `div` is not an allowed tag
         * — which lifts its children to document level on the way past. This method does not clean,
         * so it has to address the wrapper itself.
         *
         * ⚠️ THE FIRST `div` IS RELIABLY THE WRAPPER, and that is an argument rather than a guess:
         * this runs on output that has already been through `sanitize()`, and `div` is not in
         * ALLOWED_TAGS, so no `div` can have survived from the input.
         */
        $wrapper = $document->getElementsByTagName('div')->item(0);

        if ($wrapper === null) {
            return $html;
        }

        self::wrapLooseRuns($document, $wrapper);

        /*
         * ⚠️ AND INSIDE EVERY FLOW-CONTENT CONTAINER, which the outer pass cannot reach. Review found
         * it twice, and the second time because the first fix was too narrow.
         *
         * `<figure><strong>مرحبا</strong><figcaption>English</figcaption></figure>` is valid rich
         * text, and the Arabic run got no direction at all while the caption did — so it inherited
         * the page. Restricting the pass to `figure` then left the identical defect in the other
         * three: `<blockquote>English<p>عربي</p>עברית</blockquote>` gave the trailing Hebrew run no
         * wrapper, so it resolved from the blockquote's own `auto` — which reads `English` first.
         *
         * ⚠️ THESE FOUR AND NO MORE, because the set is decided by what a `<p>` may legally sit
         * inside rather than by which tags happen to hold text. A wrapper is only correct where flow
         * content is:
         *
         *   figure, blockquote, li, figcaption   flow content — a `<p>` is valid
         *   ul, ol                               only `li`; a `<p>` here is markup no browser
         *                                        should be handed, and the sanitiser produces no
         *                                        loose text there from valid input
         *   p, h2, h3, h4                        phrasing content only, and each already carries
         *                                        its own direction from the block pass above
         *   pre                                  phrasing only, AND whitespace-significant, so
         *                                        inserting an element would change the content
         */
        foreach (self::FLOW_CONTAINER_TAGS as $tag) {
            foreach (iterator_to_array($document->getElementsByTagName($tag)) as $container) {
                self::wrapLooseRuns($document, $container);
            }
        }

        return self::serialize($wrapper);
    }

    /**
     * Wrap top-level text and inline runs in a paragraph, so they can carry a direction.
     *
     * ⚠️ A VALUE NEED NOT CONTAIN A BLOCK, which review found and I had assumed away. `مرحبا` and
     * `<strong>مرحبا</strong>` are shapes `sanitize()` deliberately preserves, and neither has any
     * element that a direction could sit on — so the per-block guarantee did not reach them and they
     * inherited the chrome, which for API and import input is the ordinary case rather than an edge
     * one.
     *
     * ⚠️ THIS RESHAPES, AND THAT IS THE TRADE. `sanitize()` refuses to wrap loose text because
     * reshaping content that arrived as a bare string is data loss of its own — the reason its
     * parsing wrapper exists at all. The alternative here is no direction at all for those values,
     * and a top-level run is a paragraph in everything except markup. So the reshape is confined to
     * exactly the nodes that have nowhere else to carry one, and adjacent runs are collected into ONE
     * paragraph rather than one each, because `a <strong>b</strong> c` is one sentence.
     */
    private static function wrapLooseRuns(DOMDocument $document, DOMNode $wrapper): void
    {
        /*
         * ⚠️ WHITESPACE CONTINUES A RUN BUT CANNOT START ONE, and getting only the second half right
         * corrupted content. The first version skipped every whitespace-only text node, so the space
         * in `<strong>hello</strong> <em>world</em>` was left OUTSIDE the paragraph while both
         * elements moved into it — stored as `<p><strong>hello</strong><em>world</em></p> ` and
         * rendered as `helloworld`. A sanitiser that silently joins two words is worse than one that
         * misses an attribute. Found by review.
         *
         * The distinction is position, not content: whitespace BETWEEN blocks is formatting, and
         * whitespace INSIDE a run is a word boundary. So a run in progress takes it, a run not yet
         * started does not, and a run closing at a block drops whatever trailing whitespace only
         * separated it from that block.
         *
         * ⚠️ No closure holds `$current` by reference, deliberately: the first version used one and
         * static analysis could not follow the type through it, which is a signal about the shape
         * rather than about the analyser.
         *
         */
        $runs = [];
        $current = [];

        foreach (iterator_to_array($wrapper->childNodes) as $child) {
            $isBlock = $child instanceof DOMElement
                && in_array(strtolower($child->nodeName), self::CONTAINER_TAGS, true);

            if ($isBlock) {
                $runs[] = $current;
                $current = [];

                continue;
            }

            // Whitespace before any content is formatting between blocks, not a word boundary.
            if ($current === [] && self::isWhitespaceNode($child)) {
                continue;
            }

            $current[] = $child;
        }

        $runs[] = $current;

        $wrappable = [];

        foreach ($runs as $run) {
            // Trailing whitespace only separated this run from the block that closed it.
            while ($run !== [] && self::isWhitespaceNode($run[count($run) - 1])) {
                array_pop($run);
            }

            /*
             * ⚠️ A RUN WITH NO TEXT HAS NO DIRECTION, so wrapping it would add markup for nothing.
             * The case that matters is `<figure><img><figcaption>…` — the commonest figure there is
             * — where recursing into the figure first wrapped the image in a paragraph. `dir="auto"`
             * on an image resolves from no characters at all.
             */
            if ($run !== [] && self::carriesText($run)) {
                $wrappable[] = $run;
            }
        }

        /*
         * ⚠️ TWO QUESTIONS, NOT ONE, and collapsing them put the original defect straight back. They
         * look like the same thing and are not:
         *
         *   `$carries`  does this container hold ANY direction, so it can serve ONE run itself?
         *   `$fixed`    is a FIXED direction in force, so a wrapper needs no `auto` of its own?
         *
         * A container whose own direction is `auto` answers YES to the first and NO to the second —
         * `auto` resolves from the container's whole content, which is right for one run and wrong for
         * three. Using one value for both made `<blockquote>English<p>عربي</p>עברית</blockquote>` give
         * its wrappers nothing, so all three inherited the blockquote's `auto` and resolved from
         * `English`: the round-two finding, reintroduced by the round-four fix.
         *
         * ⚠️ `$fixed` LOOKS THROUGH TO AN ANCESTOR because a container no longer carries a materialised
         * copy of one. `<figure dir="rtl"><figcaption>A<p>x</p>B</figcaption>` leaves the caption
         * undirected so an ancestor edit can still reach it, and a wrapper inside must inherit that
         * `rtl` rather than stamp `auto` over it.
         */
        $carries = self::ownDirection($wrapper);
        $fixed = self::fixedDirectionForChildren($wrapper);

        /*
         * ⚠️ A WRAPPER IS ONLY CORRECT WHERE NOTHING ELSE CAN CARRY THE DIRECTION, and wrapping
         * unconditionally broke documents that were already right. Review's second finding widened
         * this pass from `figure` to every flow-content container — and `blockquote`, `li` and
         * `figcaption` are BLOCKS, so each already carries a direction of its own. Wrapping their
         * single run put a `<p>` inside every `<li>` in every existing document: correct direction,
         * gratuitous markup, and a visible change to how every list renders, since a paragraph in a
         * list item brings block margins with it.
         *
         * The container carries ONE direction, so it can serve exactly one run. One run and a
         * direction to hold it needs nothing:
         *
         *   <li dir="auto">عربي</li>                      the li's own auto reads عربي — correct
         *   <li dir="auto">English<p>عربي</p>עברית</li>   the li's auto reads English, so the
         *                                                 HEBREW run inherits English's direction
         *   <figure>عربي<figcaption>…                     figure is not a block and carries none,
         *                                                 so even one run has nowhere to resolve
         *
         * ⚠️ AND `figure dir="rtl"` IS NOW LEFT ALONE ENTIRELY, which is a better answer to review's
         * other finding than propagating was. The run inherits `rtl` because inheritance was already
         * right; the defect was inserting a wrapper that broke it. Propagation still earns its place
         * for the multi-run case, where wrappers are unavoidable and must each carry the author's
         * choice rather than re-deriving one.
         */
        /*
         * ⚠️ ANY DIRECTION IN FORCE, OWN OR INHERITED. `$carries` alone was not enough once a container
         * stopped materialising its ancestor's: `<figure dir="rtl"><figcaption>ACME مرحبا</figcaption>`
         * leaves the caption undirected, so a skip keyed on the caption's OWN direction read null and
         * wrapped a single run in a paragraph for nothing — the round-two finding, reintroduced from the
         * other side. One run and a direction reaching it needs no wrapper, wherever that direction
         * comes from.
         */
        if (count($wrappable) < 2 && ($carries ?? $fixed) !== null) {
            return;
        }

        foreach ($wrappable as $run) {
            $paragraph = $document->createElement('p');

            /*
             * ⚠️ ONLY WHERE NOTHING ELSE CAN CARRY IT, which is the same rule as the block pass, and
             * making the two identical is what removed the last inconsistency. A wrapper under a
             * container that establishes a direction inherits it, exactly as the author's own `<p>`
             * beside it does — so stamping the value here would materialise it on half the children and
             * not the other half, and re-close the ancestor edit for that half.
             */
            if ($fixed === null) {
                $paragraph->setAttribute('dir', 'auto');
            }

            $wrapper->insertBefore($paragraph, $run[0]);

            foreach ($run as $node) {
                $paragraph->appendChild($node);
            }
        }
    }

    /**
     * The nearest FIXED direction above this element, looking through any `auto` on the way.
     *
     * ⚠️ DIFFERENT FROM `nearestDirection()` ON PURPOSE, and the difference is the point rather than an
     * inconsistency. That one stops at the nearest direction of any kind, because for STAMPING a block
     * the effective direction is what matters and an `auto` above means "resolve from content". This one
     * is asked whether a generated `auto` should yield, and every `auto` between may be generated too —
     * so none of them may block, and the walk passes through them.
     *
     * `<figure dir="rtl"><figcaption dir="auto"><p dir="auto">` needs that: both `auto`s were written by
     * this implementation, and stopping at the first would leave the inner one standing whichever order
     * the elements happened to be visited in.
     */
    private static function fixedAncestorDirection(DOMNode $element): ?string
    {
        for ($ancestor = $element->parentNode; $ancestor !== null; $ancestor = $ancestor->parentNode) {
            $direction = self::ownDirection($ancestor);

            if ($direction !== null && $direction !== 'auto') {
                return $direction;
            }
        }

        return null;
    }

    /**
     * The FIXED direction in force on this container's children, or null when none is.
     *
     * ⚠️ THE CONTAINER'S OWN `auto` BLOCKS ITS ANCESTOR'S, which review found the call site getting
     * wrong — and it is `nearestDirection()`'s rule applied one level lower rather than a new one. That
     * method stops at the nearest direction of any kind and reports `auto` as nothing, because `auto`
     * means "resolve from content" and nothing above it reaches the child. A container's own `auto` is
     * the nearest direction to its children, so it answers the same way.
     *
     * Measured before the fix: `<blockquote dir="rtl"><figure dir="auto">English<p>عربي</p>עברית</figure>`
     * read past the figure's `auto` to the blockquote's `rtl`, so both generated wrappers were left
     * undirected — and they then inherited the figure's `auto`, which resolves from the figure's whole
     * content and reads `English` first. The Hebrew run rendered left-to-right.
     *
     * ⚠️ DISTINCT FROM `$carries` AT THE CALL SITE, which is the distinction the caller's own docblock
     * insists on: `auto` on a container is enough to serve ONE run and not enough to serve three.
     */
    private static function fixedDirectionForChildren(DOMNode $container): ?string
    {
        $own = self::ownDirection($container);

        if ($own !== null) {
            return $own === 'auto' ? null : $own;
        }

        return self::nearestDirection($container);
    }

    /**
     * The direction this element inherits from its nearest directed ancestor, or null when it has none.
     *
     * ⚠️ THE NEAREST ONE WINS AND THE WALK STOPS THERE, because that is what inheritance does.
     * Continuing past a directed ancestor to find a fixed grandparent would give a child a direction
     * the browser would never have given it.
     *
     * ⚠️ `auto` NEEDS NO SPECIAL CASE, AND THE FIRST VERSION OF THIS HAD ONE. It mapped an `auto`
     * ancestor to null so the caller would default to `auto` — reasoning that `auto` is an instruction
     * to resolve from content rather than a direction to inherit. That reasoning is right and the
     * branch was still dead: both readings stamp `auto`, so it was a conditional asserting a
     * distinction the code could not act on. Found by reverting it and watching every test still pass.
     *
     * ⚠️ The parsing wrapper `div` carries no `dir`, so a top-level block walks to the top and gets
     * `auto` from the caller — the behaviour that existed before inheritance was considered at all.
     */
    private static function nearestDirection(DOMNode $element): ?string
    {
        for ($ancestor = $element->parentNode; $ancestor !== null; $ancestor = $ancestor->parentNode) {
            $direction = self::ownDirection($ancestor);

            if ($direction !== null) {
                // ⚠️ `auto` STOPS THE WALK AND REPORTS NOTHING. It is the nearest direction, so nothing
                // above it reaches the child — and it is not a direction to inherit, because it means
                // "resolve from content" and the child's content is its own. Both halves are needed:
                // walking past it would give a child a direction the browser never would, and returning
                // it would make the caller read `auto` as a decision.
                return $direction === 'auto' ? null : $direction;
            }
        }

        return null;
    }

    /**
     * The direction `$container` establishes for its own children, or null when it establishes none.
     *
     * ⚠️ NULL AND `auto` ARE DIFFERENT ANSWERS, and collapsing them is what made the first version of
     * this wrong. "This container resolves its children's direction" and "fall back to auto" look
     * alike at the point of use and mean opposite things at the point of decision: a container that
     * establishes a direction can serve a run without a wrapper, and one that does not cannot.
     *
     * ⚠️ THE SAME THREE VALUES AS THE BLOCK PASS, and the same reason `hasAttribute()` was not
     * enough there: `dir=""` and `dir="banana"` survive sanitising, because `dir` is allowed and its
     * value was never checked. An attribute that establishes no direction must read as null here, or
     * it suppresses a wrapper while doing nothing itself.
     */
    private static function ownDirection(DOMNode $container): ?string
    {
        if (! $container instanceof DOMElement) {
            return null;
        }

        $direction = strtolower($container->getAttribute('dir'));

        return in_array($direction, self::DIRECTIONS, true) ? $direction : null;
    }

    /**
     * The children of a list that are not list items, which is where malformed input puts loose text.
     *
     * ⚠️ NOT ONLY TEXT NODES: `<ul><strong>مرحبا</strong><li>…` puts an ELEMENT in that position, and
     * its content is exactly what a direction has to resolve from. The question is the node's place in
     * the list rather than its type, so the filter is "not an `li`".
     *
     * @return list<DOMNode>
     */
    private static function looseChildren(DOMNode $list): array
    {
        $loose = [];

        foreach ($list->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'li') {
                continue;
            }

            $loose[] = $child;
        }

        return $loose;
    }

    /**
     * Whether a run contains any text for a direction to resolve from.
     *
     * ⚠️ `textContent` RATHER THAN THE NODE TYPE, because the text may be nested: `<strong>مرحبا</strong>`
     * is an element whose content is what `dir="auto"` would read. An `<img>` has none, and neither
     * does a run of `<br>`.
     *
     * @param  list<DOMNode>  $run
     */
    private static function carriesText(array $run): bool
    {
        foreach ($run as $node) {
            if (trim($node->textContent) !== '') {
                return true;
            }
        }

        return false;
    }

    /** Whether this node is text that carries no content of its own. */
    private static function isWhitespaceNode(DOMNode $node): bool
    {
        return $node->nodeType === XML_TEXT_NODE && trim($node->textContent) === '';
    }

    /**
     * The document back as HTML, without the parsing aids this class adds.
     *
     * ⚠️ EXTRACTED RATHER THAN COPIED. `withBlockDirection()` needs exactly the same skip — the
     * charset `<meta>` is something these methods PREPEND, not content — and a second copy of that
     * rule is a second place for it to be wrong. The `div` wrapper needs no skip here: `sanitize()`
     * unwraps it because `div` is not an allowed tag, and `withBlockDirection()` runs on output that
     * has already been through that.
     */
    public static function serialize(DOMNode $parent): string
    {
        $document = $parent instanceof DOMDocument ? $parent : $parent->ownerDocument;

        if ($document === null) {
            return '';
        }

        $out = '';

        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'meta') {
                continue;
            }

            $out .= $document->saveHTML($child);
        }

        return $out;
    }
}
