<?php

declare(strict_types=1);

namespace Blnk\Api;

use Blnk\Api\Model\JsonBinding;

/**
 * MetadataRequest represents the structure for metadata update requests.
 * It contains a required metadata field that holds key-value pairs for updating entity metadata.
 * (Go: api/metadata.go, `Metadata map[string]interface{} json:"meta_data" binding:"required"`.)
 */
final class MetadataRequest implements \JsonSerializable
{
    /** @var array<string, mixed> */
    public array $metadata = [];

    /**
     * Binds the JSON body; the Gin `binding:"required"` tag rejects a missing
     * or null meta_data (an empty object satisfies it, as in Go).
     *
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException on a JSON type mismatch or a missing meta_data
     */
    public static function fromArray(array $data, string $struct = 'MetadataRequest'): self
    {
        $r = new self();
        $metadata = JsonBinding::map($data, 'meta_data', $struct);
        if ($metadata === null) {
            throw JsonBinding::requiredError($struct, 'Metadata');
        }
        $r->metadata = $metadata;

        return $r;
    }

    public function jsonSerialize(): array
    {
        return ['meta_data' => count($this->metadata) === 0 ? new \stdClass() : $this->metadata];
    }
}
