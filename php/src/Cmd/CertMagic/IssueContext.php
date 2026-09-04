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

namespace Blnk\Cmd\CertMagic;

/**
 * IssueContext carries the values Go attaches to the context.Context of
 * {@see Issuer::issue()}: the attempt counter of the retry loop
 * (`AttemptsCtxKey`, 0 means first attempt) and, for renewals with the
 * same ACME CA, the certificate being replaced (`ctxKeyARIReplaces`).
 */
final class IssueContext
{
    /**
     * AttemptsCtxKey is the context key for the value
     * that holds the attempt counter. The value counts
     * how many times the operation has been attempted.
     * A value of 0 means first attempt.
     */
    public int $attempts = 0;

    /** ctxKeyARIReplaces: the leaf certificate this issuance replaces (renewals only). */
    public ?X509Certificate $ariReplaces = null;

    public function __construct(int $attempts = 0, ?X509Certificate $ariReplaces = null)
    {
        $this->attempts = $attempts;
        $this->ariReplaces = $ariReplaces;
    }
}
