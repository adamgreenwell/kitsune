<?php

/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Kitsune\Core\Http\Api;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kitsune\Core\Models\EntryType;
use Kitsune\Core\Tenancy\Context;

/** @internal Schema discovery for an authenticated Site API client. */
final class ContentSchemaController
{
    public function __invoke(Request $request, Context $context): JsonResponse
    {
        $site = SiteApiRoutes::site($request, $context);
        $token = $request->attributes->get(SiteAccessToken::class);
        abort_unless($token instanceof SiteAccessToken, 401);

        $authorized = EntryType::visibleFor($site, $context->orgId())->filter(
            static fn (EntryType $type): bool => SiteTokens::allows($request, $type->handle, 'view')
                || ($type->is_media
                    ? SiteTokens::allows($request, $type->handle, 'create') && SiteTokens::allows($request, $type->handle, 'publish')
                    : SiteTokens::allows($request, $type->handle, 'create') || SiteTokens::allows($request, $type->handle, 'update')),
        );
        $entryTypes = (new Collection($authorized->values()->all()))->load('fields.fieldStorage');
        $schemas = $entryTypes->map(static function (EntryType $type) use ($request): array {
            $contract = EntryFieldContract::forType($type);
            $schema = ['handle' => $type->handle, 'fields' => $contract->schema()];

            $canCreate = SiteTokens::allows($request, $type->handle, 'create');
            $canPublish = SiteTokens::allows($request, $type->handle, 'publish');
            if ($type->is_media) {
                if ($canCreate && $canPublish) {
                    $schema['media_upload'] = ContentMediaController::uploadSchema($type);
                }
            } else {
                if ($canCreate) {
                    $schema['create'] = $contract->writePayload(partial: false, canPublish: $canPublish);
                }
                if (SiteTokens::allows($request, $type->handle, 'update')) {
                    $schema['update'] = $contract->writePayload(partial: true, canPublish: $canPublish);
                }
            }

            return $schema;
        })->all();

        // Only the private client may retain the schema. Authenticate again and rebuild the authorized
        // contract on each request before deciding whether that exact representation is unchanged.
        $response = response()->json(['data' => $schemas], headers: ['Cache-Control' => 'private, no-cache, must-revalidate']);
        $response->setVary('Authorization', false);
        $response->setEtag(hash('sha256', json_encode([
            $site->org_id, $site->id, $token->id, $token->actor_model, $token->actor_id, $token->abilities,
            $response->getContent(),
        ], JSON_THROW_ON_ERROR)));
        $response->isNotModified($request);

        return $response;
    }
}
