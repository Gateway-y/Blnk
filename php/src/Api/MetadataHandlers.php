<?php

declare(strict_types=1);

namespace Blnk\Api;

use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Model\ModelHelpers;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Port of api/metadata.go: the metadata handler of the Go `Api` struct,
 * composed into {@see Api}. The request struct is {@see MetadataRequest}.
 */
trait MetadataHandlers
{
    /**
     * UpdateMetadata handles HTTP requests to update metadata for various entity types.
     * It processes requests to update metadata for ledgers, transactions, balances, and identities.
     * The entity type is determined automatically from the entity ID prefix.
     *
     * Responses:
     * - 400 Bad Request: If the entity ID is missing or the request body is invalid.
     * - 404 Not Found: If the specified entity doesn't exist.
     * - 200 OK: If the metadata is successfully updated.
     */
    public function updateMetadata(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $entityID = Query::param($args, 'entity-id');

        if ($entityID === '') {
            return Errors::respondCode($response, ErrorCode::ErrMetaInvalidEntityID, 'entity ID is required', null);
        }

        try {
            $req = MetadataRequest::fromArray(Binding::shouldBindJSON($request, 'api.MetadataRequest'));
        } catch (\RuntimeException $err) {
            return Errors::respondCode($response, ErrorCode::ErrGenMalformedRequest, $err->getMessage(), null);
        }

        // Known conditions (entity not found, unsupported entity type, invalid
        // entity ID) classify to META_* codes; anything else is an internal
        // failure (e.g. database error) and falls back to a sanitized 500.
        try {
            $updatedMetadata = $this->blnk->updateMetadata($entityID, $req->metadata);
        } catch (\Throwable $err) {
            return Errors::respondError($response, $err);
        }

        return Json::write($response, 200, ['meta_data' => ModelHelpers::mapToJson($updatedMetadata)]);
    }
}
