<?php

declare(strict_types=1);

namespace Blnk\Internal\Tokenization;

/**
 * TokenizationService handles converting PII to tokens and back.
 *
 * Port of internal/tokenization/tokenization.go. The crypto is byte-compatible
 * with the Go implementation:
 *
 * - Standard mode: AES-256-GCM (Go crypto/aes + cipher.NewGCM defaults:
 *   12-byte random nonce, 16-byte tag) with the token laid out as
 *   base64(nonce || ciphertext || tag) — Go's gcm.Seal(nonce, nonce, ...) puts
 *   the nonce first and appends the tag to the ciphertext.
 * - Format-preserving mode: HMAC-SHA256(key, value) seed drives a
 *   format-preserving visible token, concatenated as
 *   "FPT:<visibleToken>:<standardToken>".
 *
 * Tokens produced by either implementation detokenize in the other.
 */
final class TokenizationService
{
    private const NONCE_SIZE = 12; // cipher.NewGCM standard nonce size
    private const TAG_LENGTH = 16; // GCM tag size appended by gcm.Seal

    /** TokenizableFields defines which fields can be tokenized in the Identity model. */
    public const TokenizableFields = [
        'FirstName',
        'LastName',
        'OtherNames',
        'EmailAddress',
        'PhoneNumber',
        'Street',
        'PostCode',
    ];

    private string $key;
    private bool $enabled;

    /**
     * newTokenizationService creates a new tokenization service.
     *
     * Parameters:
     * - $encryptionKey: The encryption key used for tokenization (raw bytes).
     */
    public function __construct(string $encryptionKey)
    {
        $this->key = $encryptionKey;
        $this->enabled = strlen($encryptionKey) === 32;
    }

    /**
     * @throws TokenizationDisabledException when the service has no valid 32-byte key
     */
    private function ensureEnabled(): void
    {
        if (!$this->enabled) {
            throw new TokenizationDisabledException();
        }
    }

    /**
     * tokenize converts a PII value to a token using the default standard mode.
     *
     * Parameters:
     * - $value: The original PII value to be tokenized.
     *
     * Returns the tokenized value.
     *
     * @throws TokenizationException if tokenization fails
     */
    public function tokenize(string $value): string
    {
        return $this->tokenizeWithMode($value, TokenizationMode::StandardMode);
    }

    /**
     * tokenizeWithMode converts a PII value to a token using the specified mode.
     *
     * Parameters:
     * - $value: The original PII value to be tokenized.
     * - $mode: The tokenization mode to use (TokenizationMode constant).
     *
     * Returns the tokenized value.
     *
     * @throws TokenizationException if tokenization fails
     */
    public function tokenizeWithMode(string $value, int $mode): string
    {
        $this->ensureEnabled();

        if ($mode === TokenizationMode::FormatPreservingMode) {
            return $this->formatPreservingTokenize($value);
        }

        // Standard tokenization using AES encryption
        // Create nonce
        $nonce = random_bytes(self::NONCE_SIZE);

        // Encrypt (AES-256-GCM; tag returned separately and appended, matching
        // gcm.Seal's ciphertext||tag output)
        $tag = '';
        $ciphertext = openssl_encrypt(
            $value,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LENGTH
        );
        if ($ciphertext === false) {
            throw new TokenizationException('cipher: encryption failed: ' . (openssl_error_string() ?: 'unknown error'));
        }

        // Return base64 encoded token (nonce || ciphertext || tag)
        return base64_encode($nonce . $ciphertext . $tag);
    }

    /**
     * detokenize converts a token back to the original PII value using automatic mode detection.
     *
     * Parameters:
     * - $token: The token to be converted back to PII.
     *
     * Returns the original PII value.
     *
     * @throws TokenizationException if detokenization fails
     */
    public function detokenize(string $token): string
    {
        // Auto-detect the token type based on prefix
        if (str_starts_with($token, 'FPT:')) {
            // Format-preserving token
            return $this->formatPreservingDetokenize($token);
        }

        // Standard token
        return $this->standardDetokenize($token);
    }

    /**
     * detokenizeWithMode converts a token back to the original PII value using the specified mode.
     *
     * Parameters:
     * - $token: The token to be converted back to PII.
     * - $mode: The tokenization mode to use (TokenizationMode constant).
     *
     * Returns the original PII value.
     *
     * @throws TokenizationException if detokenization fails
     */
    public function detokenizeWithMode(string $token, int $mode): string
    {
        if ($mode === TokenizationMode::FormatPreservingMode) {
            return $this->formatPreservingDetokenize($token);
        }

        return $this->standardDetokenize($token);
    }

    /**
     * standardDetokenize handles detokenization of standard tokens.
     *
     * @throws TokenizationException
     */
    private function standardDetokenize(string $token): string
    {
        // Decode the base64 token (strict, like Go's base64.StdEncoding)
        $data = base64_decode($token, true);
        if ($data === false) {
            throw new TokenizationException('illegal base64 data');
        }

        // Create cipher (Go: aes.NewCipher errors on a wrong-size key; OpenSSL
        // would silently pad/truncate, so check explicitly)
        if (strlen($this->key) !== 32) {
            throw new TokenizationException(sprintf('crypto/aes: invalid key size %d', strlen($this->key)));
        }

        // Extract nonce and ciphertext
        $nonceSize = self::NONCE_SIZE;
        if (strlen($data) < $nonceSize) {
            throw new TokenizationException('token too short');
        }

        $nonce = substr($data, 0, $nonceSize);
        $ciphertextWithTag = substr($data, $nonceSize);

        // gcm.Open also rejects input shorter than the GCM tag
        if (strlen($ciphertextWithTag) < self::TAG_LENGTH) {
            throw new TokenizationException('cipher: message authentication failed');
        }

        $ciphertext = substr($ciphertextWithTag, 0, -self::TAG_LENGTH);
        $tag = substr($ciphertextWithTag, -self::TAG_LENGTH);

        // Decrypt
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );
        if ($plaintext === false) {
            throw new TokenizationException('cipher: message authentication failed');
        }

        return $plaintext;
    }

    /**
     * formatPreservingTokenize tokenizes a value while preserving its format.
     * It uses a deterministic approach to generate a token with the same format.
     *
     * Parameters:
     * - $value: The original value to tokenize.
     *
     * Returns the tokenized value with the same format as the original.
     *
     * @throws TokenizationException if tokenization fails
     */
    private function formatPreservingTokenize(string $value): string
    {
        // 1. Create a deterministic seed using HMAC of the value
        $seed = hash_hmac('sha256', $value, $this->key, true);

        // 2. Use the seed to generate a format-preserving token
        $visibleToken = self::generateTokenWithFormat($seed, $value);

        // 3. Encrypt the original value using standard encryption
        $standardToken = $this->tokenize($value);

        // 4. Create the final token by combining the format-preserving token and its identifier
        // We'll prefix format-preserving tokens with FPT: to identify them
        return sprintf('FPT:%s:%s', $visibleToken, $standardToken);
    }

    /**
     * formatPreservingDetokenize detokenizes a format-preserving token.
     *
     * Parameters:
     * - $token: The token to detokenize.
     *
     * Returns the original value.
     *
     * @throws TokenizationException if detokenization fails
     */
    private function formatPreservingDetokenize(string $token): string
    {
        // Check if this is a format-preserving token
        if (!str_starts_with($token, 'FPT:')) {
            throw new TokenizationException('not a format-preserving token');
        }

        // Split the token
        $parts = explode(':', $token, 3);
        if (count($parts) < 3) {
            throw new TokenizationException('invalid format-preserving token format');
        }

        // Extract the standard token part
        $standardToken = $parts[2];

        // Use standard detokenization to get the original value
        return $this->standardDetokenize($standardToken);
    }

    /**
     * generateTokenWithFormat creates a token that matches the format of the original value.
     *
     * The value is iterated as Unicode code points (Go []rune) so multi-byte
     * characters are preserved as-is, exactly like the Go implementation.
     */
    private static function generateTokenWithFormat(string $seed, string $originalValue): string
    {
        $runes = mb_str_split($originalValue, 1, 'UTF-8');
        $result = '';
        $seedLen = strlen($seed);

        // Use the seed to generate random runes that preserve the format
        foreach ($runes as $i => $char) {
            $seedByte = ord($seed[$i % $seedLen]);

            if ($char >= 'A' && $char <= 'Z' && strlen($char) === 1) {
                // Uppercase letter
                $result .= chr(ord('A') + ($seedByte % 26));
            } elseif ($char >= 'a' && $char <= 'z' && strlen($char) === 1) {
                // Lowercase letter
                $result .= chr(ord('a') + ($seedByte % 26));
            } elseif ($char >= '0' && $char <= '9' && strlen($char) === 1) {
                // Digit
                $result .= chr(ord('0') + ($seedByte % 10));
            } else {
                // Preserve special characters (including UTF-8 characters)
                $result .= $char;
            }
        }

        return $result;
    }
}
