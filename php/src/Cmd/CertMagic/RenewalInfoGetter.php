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

use Blnk\Cmd\CertMagic\Acme\RenewalInfo;

/**
 * RenewalInfoGetter is a type that can get ACME Renewal Information (ARI).
 * Users of this package that wrap the ACMEIssuer or use any other issuer
 * that supports ARI will need to implement this so that CertMagic can
 * update ARI which happens outside the normal issuance flow and is thus
 * not required by the Issuer interface (a type assertion is performed).
 */
interface RenewalInfoGetter
{
    /** @throws \RuntimeException */
    public function getRenewalInfo(Certificate $cert): RenewalInfo;
}
