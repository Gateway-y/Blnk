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

namespace Blnk\Api\Model;

use Blnk\Model\Account;
use Blnk\Model\ModelHelpers as CoreModelHelpers;

/**
 * CreateAccount is the request payload of POST /accounts
 * (Go: api/model/account.go `CreateAccount`).
 */
final class CreateAccount implements \JsonSerializable
{
    public string $bankName = '';

    public string $number = '';

    public string $currency = '';

    public string $identityId = '';

    public string $ledgerId = '';

    public string $balanceId = '';

    /** @var array<string, mixed>|null */
    public ?array $metaData = null;

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch (Gin bind error)
     */
    public static function fromArray(array $data, string $struct = 'CreateAccount'): self
    {
        $a = new self();
        $a->bankName = JsonBinding::string($data, 'bank_name', $struct);
        $a->number = JsonBinding::string($data, 'number', $struct);
        $a->currency = JsonBinding::string($data, 'currency', $struct);
        $a->identityId = JsonBinding::string($data, 'identity_id', $struct);
        $a->ledgerId = JsonBinding::string($data, 'ledger_id', $struct);
        $a->balanceId = JsonBinding::string($data, 'balance_id', $struct);
        $a->metaData = JsonBinding::map($data, 'meta_data', $struct);

        return $a;
    }

    /**
     * ValidateCreateAccount runs the ozzo-validation rules of model.go:
     * without a balance_id, ledger_id, identity_id and currency are required;
     * with a balance_id, ledger_id and currency must not be given.
     *
     * Go declares two `validation.Field` entries for ledger_id and for
     * currency; ozzo keys errors by field name, and the two rules of each
     * pair are mutually exclusive (BalanceId empty vs. set), so a single
     * closure per field is equivalent.
     *
     * @throws ValidationErrors
     */
    public function validateCreateAccount(): void
    {
        ModelHelpers::validateStruct([
            'ledger_id' => function (): void {
                if ($this->balanceId === '' && $this->ledgerId === '') { // validation.When(BalanceId == "", Required.Error(...))
                    throw new \RuntimeException('Ledger ID is required when Balance ID is not provided');
                }
                if ($this->balanceId !== '' && $this->ledgerId !== '') { // validation.By(...)
                    throw new \RuntimeException('either LedgerId or BalanceId must be provided, not both');
                }
            },
            'identity_id' => function (): void {
                if ($this->balanceId === '' && $this->identityId === '') { // validation.When(BalanceId == "", Required.Error(...))
                    throw new \RuntimeException('Identity ID is required when Balance ID is not provided');
                }
            },
            'currency' => function (): void {
                if ($this->balanceId === '' && $this->currency === '') { // validation.When(BalanceId == "", Required.Error(...))
                    throw new \RuntimeException('currency is required when Balance ID is not provided');
                }
                if ($this->balanceId !== '' && $this->currency !== '') { // validation.By(...)
                    throw new \RuntimeException('either Currency or BalanceId must be provided, not both');
                }
            },
        ]);
    }

    /**
     * ToAccount converts the request payload into the domain Account.
     */
    public function toAccount(): Account
    {
        $account = new Account();
        $account->balanceID = $this->balanceId;
        $account->ledgerID = $this->ledgerId;
        $account->identityID = $this->identityId;
        $account->currency = $this->currency;
        $account->number = $this->number;
        $account->bankName = $this->bankName;
        $account->metaData = $this->metaData;

        return $account;
    }

    public function jsonSerialize(): array
    {
        return [
            'bank_name' => $this->bankName,
            'number' => $this->number,
            'currency' => $this->currency,
            'identity_id' => $this->identityId,
            'ledger_id' => $this->ledgerId,
            'balance_id' => $this->balanceId,
            'meta_data' => CoreModelHelpers::mapToJson($this->metaData),
        ];
    }
}
