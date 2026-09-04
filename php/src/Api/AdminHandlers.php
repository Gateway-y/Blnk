<?php

/*
Copyright 2024 Blnk Finance Authors.

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.
*/

declare(strict_types=1);

namespace Blnk\Api;

use Blnk\Internal\ApiError\ApiErrorException;
use Blnk\Internal\ApiError\ErrorCode;
use Blnk\Internal\PgBackups\BackupManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Port of api/admin.go: the backup handlers of the Go `Api` struct, composed
 * into {@see Api}.
 */
trait AdminHandlers
{
    /**
     * BackupDB creates a backup of the database and stores it on disk.
     * It initializes a BackupManager and performs the backup operation.
     * If any error occurs during backup creation, it responds with an error message.
     *
     * Responses:
     * - 500 Internal Server Error: If there's an error in creating the backup or initializing the BackupManager.
     * - 200 OK: If the backup is successfully created and stored on disk.
     */
    public function backupDB(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $backupManager = new BackupManager();
        } catch (\Throwable $err) {
            return Errors::respondNestedAPIError($response, ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'error creating backup', $err), ErrorCode::ErrAdminBackupFailed);
        }
        try {
            $backupManager->backupToDisk();
        } catch (\Throwable $err) {
            return Errors::respondNestedAPIError($response, ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'error creating backup', $err), ErrorCode::ErrAdminBackupFailed);
        }

        return Json::write($response, 200, 'backup successful');
    }

    /**
     * BackupDBS3 creates a backup of the database and stores it in S3.
     * It initializes a BackupManager and performs the backup operation to S3.
     * If any error occurs during backup creation, it responds with an error message.
     *
     * Responses:
     * - 500 Internal Server Error: If there's an error in creating the backup or initializing the BackupManager.
     * - 200 OK: If the backup is successfully created and stored in S3.
     */
    public function backupDBS3(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $backupManager = new BackupManager();
        } catch (\Throwable $err) {
            return Errors::respondNestedAPIError($response, ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'error creating backup', $err), ErrorCode::ErrAdminBackupFailed);
        }
        try {
            $backupManager->backupToS3();
        } catch (\Throwable $err) {
            return Errors::respondNestedAPIError($response, ApiErrorException::newApiError(ErrorCode::ErrInternalServer, 'error creating backup', $err), ErrorCode::ErrAdminBackupFailed);
        }

        return Json::write($response, 200, 'backup successful');
    }
}
