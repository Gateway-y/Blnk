<?php

declare(strict_types=1);

namespace Blnk\Model;

/**
 * Chain hosts the transaction hash-chain constant and hashing function of the
 * Go `model` package (chain.go), per PORTING.md (standalone package funcs land
 * on a class named after the file).
 *
 * Ported from:
 *
 *   Copyright 2024 Blnk Finance Authors.
 *   Licensed under the Apache License, Version 2.0.
 */
final class Chain
{
    /**
     * ChainGenesisHash is the starting head of the transaction hash chain — 64
     * zeros. blnk.chain_state.head_hash begins here.
     */
    public const ChainGenesisHash = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Not instantiable: constants + static functions only. */
    private function __construct()
    {
    }

    /**
     * ComputeChainHash returns SHA256(prevHash || canonical fields). It is
     * deterministic and the single source of truth for the chain, shared by the
     * background chainer and the verifier. It seals the raw immutable fields
     * directly; the per-row HashTxn() value is deliberately excluded as it is just a
     * function of fields already sealed here.
     *
     * The timestamp is rendered like Go's
     * `r.CreatedAt.UTC().Format(time.RFC3339Nano)` (sub-second digits only when
     * non-zero, trailing zeros trimmed, "Z" suffix); PHP timestamps carry
     * microsecond rather than nanosecond resolution.
     */
    public static function computeChainHash(string $prevHash, ChainRow $r): string
    {
        $createdAtUTC = $r->createdAt?->setTimezone(new \DateTimeZone('UTC'));
        $canonical = implode('|', [
            $prevHash,
            $r->transactionID,
            $r->source,
            $r->destination,
            $r->amount,
            $r->preciseAmount,
            $r->currency,
            $r->status,
            $r->reference,
            ModelHelpers::goTimeString($createdAtUTC),
        ]);
        return hash('sha256', $canonical);
    }
}
