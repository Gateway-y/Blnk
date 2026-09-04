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
 * ClientHelloInfo is the port's `*tls.ClientHelloInfo`: what the TLS
 * ClientHello of a connection announced (server name, ALPN protocols, cipher
 * suites, supported versions) plus the connection it arrived on.
 *
 * PHP's TLS stack offers no `GetCertificate` callback, so the HTTPS server
 * ({@see \Blnk\Cmd\HttpsServer}) peeks at the ClientHello record before
 * enabling crypto on the accepted socket, parses it with {@see parse()},
 * asks {@see Config::getCertificate()} for the certificate, installs it on
 * the stream context and only then performs the handshake — which yields the
 * same certificate selection (SNI, wildcards, TLS-ALPN challenge detection)
 * as Go's `tls.Config.GetCertificate`.
 */
final class ClientHelloInfo
{
    /** TLS record/handshake constants. */
    private const RecordTypeHandshake = 0x16;
    private const HandshakeTypeClientHello = 0x01;
    private const ExtServerName = 0;
    private const ExtALPN = 16;
    private const ExtSupportedVersions = 43;

    /** ServerName indicates the name of the server requested by the client (SNI). */
    public string $serverName = '';

    /**
     * SupportedProtos lists the application protocols supported by the client (ALPN).
     *
     * @var string[]
     */
    public array $supportedProtos = [];

    /**
     * CipherSuites lists the CipherSuites supported by the client (e.g. TLS_AES_128_GCM_SHA256).
     *
     * @var int[]
     */
    public array $cipherSuites = [];

    /**
     * SupportedVersions lists the TLS versions supported by the client.
     *
     * @var int[]
     */
    public array $supportedVersions = [];

    /**
     * Conn is the underlying net.Conn for the connection. Null for a
     * synthetic hello (tests, on-demand lookups by name).
     *
     * @var resource|null
     */
    public $conn = null;

    /** RemoteAddr/LocalAddr: values copied from the Conn as they are still useful/needed. */
    public string $remoteAddr = '';

    public string $localAddr = '';

    /**
     * @param string[] $supportedProtos
     * @param resource|null $conn
     */
    public function __construct(string $serverName = '', array $supportedProtos = [], $conn = null)
    {
        $this->serverName = $serverName;
        $this->supportedProtos = $supportedProtos;
        $this->conn = $conn;
        if (\is_resource($conn)) {
            $this->remoteAddr = (string) @stream_socket_get_name($conn, true);
            $this->localAddr = (string) @stream_socket_get_name($conn, false);
        }
    }

    /**
     * SupportsCertificate returns null if the provided certificate is supported by
     * the client that sent the ClientHello, an error message otherwise.
     *
     * PHP port: the handshake is performed by OpenSSL after selection; the
     * only compatibility check available beforehand is that the certificate
     * is not empty (Go additionally matches signature algorithms and curves).
     */
    public function supportsCertificate(Certificate $c): ?string
    {
        if ($c->empty()) {
            return 'certificate is empty';
        }
        return null;
    }

    /**
     * parse decodes a TLS ClientHello from the bytes peeked at the start of a
     * connection. Returns null when more bytes are needed (the record or the
     * handshake message is not complete yet); throws when the bytes are not a
     * TLS ClientHello at all (e.g. plain HTTP sent to the HTTPS port).
     *
     * @param resource|null $conn
     * @throws \RuntimeException
     */
    public static function parse(string $data, $conn = null): ?self
    {
        // collect the handshake bytes of the leading handshake records
        $handshake = '';
        $pos = 0;
        $len = \strlen($data);
        while (true) {
            if ($len - $pos < 5) {
                return null;
            }
            $type = \ord($data[$pos]);
            if ($type !== self::RecordTypeHandshake) {
                throw new \RuntimeException(sprintf('tls: first record does not look like a TLS handshake (type 0x%02x)', $type));
            }
            $recordLen = (\ord($data[$pos + 3]) << 8) | \ord($data[$pos + 4]);
            if ($len - $pos - 5 < $recordLen) {
                return null;
            }
            $handshake .= substr($data, $pos + 5, $recordLen);
            $pos += 5 + $recordLen;

            if (\strlen($handshake) >= 4) {
                $msgLen = (\ord($handshake[1]) << 16) | (\ord($handshake[2]) << 8) | \ord($handshake[3]);
                if (\strlen($handshake) - 4 >= $msgLen) {
                    break;
                }
            }
            if ($pos >= $len) {
                return null; // the ClientHello continues in a record we have not received yet
            }
        }

        if (\ord($handshake[0]) !== self::HandshakeTypeClientHello) {
            throw new \RuntimeException(sprintf('tls: unexpected handshake message type %d', \ord($handshake[0])));
        }
        $msgLen = (\ord($handshake[1]) << 16) | (\ord($handshake[2]) << 8) | \ord($handshake[3]);
        $body = substr($handshake, 4, $msgLen);
        $hello = new self('', [], $conn);

        $p = 0;
        $n = \strlen($body);
        $need = static function (int $count) use (&$p, $n): void {
            if ($p + $count > $n) {
                throw new \RuntimeException('tls: malformed ClientHello');
            }
        };

        $need(2 + 32);
        $p += 2; // legacy_version
        $p += 32; // random
        $need(1);
        $sessionIDLen = \ord($body[$p]);
        $p += 1 + $sessionIDLen;
        $need(2);
        $suitesLen = (\ord($body[$p]) << 8) | \ord($body[$p + 1]);
        $p += 2;
        $need($suitesLen);
        for ($i = 0; $i + 1 < $suitesLen; $i += 2) {
            $hello->cipherSuites[] = (\ord($body[$p + $i]) << 8) | \ord($body[$p + $i + 1]);
        }
        $p += $suitesLen;
        $need(1);
        $compLen = \ord($body[$p]);
        $p += 1 + $compLen;
        if ($p >= $n) {
            return $hello; // no extensions
        }
        $need(2);
        $extLen = (\ord($body[$p]) << 8) | \ord($body[$p + 1]);
        $p += 2;
        $need($extLen);
        $end = $p + $extLen;

        while ($p + 4 <= $end) {
            $extType = (\ord($body[$p]) << 8) | \ord($body[$p + 1]);
            $extDataLen = (\ord($body[$p + 2]) << 8) | \ord($body[$p + 3]);
            $p += 4;
            if ($p + $extDataLen > $end) {
                throw new \RuntimeException('tls: malformed ClientHello extension');
            }
            $ext = substr($body, $p, $extDataLen);
            $p += $extDataLen;

            switch ($extType) {
                case self::ExtServerName:
                    // ServerNameList: 2-byte length, then entries of (type, 2-byte length, name)
                    if (\strlen($ext) < 2) {
                        break;
                    }
                    $listLen = (\ord($ext[0]) << 8) | \ord($ext[1]);
                    $q = 2;
                    $listEnd = min(2 + $listLen, \strlen($ext));
                    while ($q + 3 <= $listEnd) {
                        $nameType = \ord($ext[$q]);
                        $nameLen = (\ord($ext[$q + 1]) << 8) | \ord($ext[$q + 2]);
                        $q += 3;
                        if ($q + $nameLen > $listEnd) {
                            break;
                        }
                        if ($nameType === 0 && $hello->serverName === '') {
                            $hello->serverName = substr($ext, $q, $nameLen);
                        }
                        $q += $nameLen;
                    }
                    break;

                case self::ExtALPN:
                    // ProtocolNameList: 2-byte length, then entries of (1-byte length, name)
                    if (\strlen($ext) < 2) {
                        break;
                    }
                    $listLen = (\ord($ext[0]) << 8) | \ord($ext[1]);
                    $q = 2;
                    $listEnd = min(2 + $listLen, \strlen($ext));
                    while ($q + 1 <= $listEnd) {
                        $protoLen = \ord($ext[$q]);
                        $q++;
                        if ($q + $protoLen > $listEnd) {
                            break;
                        }
                        $hello->supportedProtos[] = substr($ext, $q, $protoLen);
                        $q += $protoLen;
                    }
                    break;

                case self::ExtSupportedVersions:
                    if (\strlen($ext) < 1) {
                        break;
                    }
                    $listLen = \ord($ext[0]);
                    for ($q = 1; $q + 1 < 1 + $listLen && $q + 1 < \strlen($ext); $q += 2) {
                        $hello->supportedVersions[] = (\ord($ext[$q]) << 8) | \ord($ext[$q + 1]);
                    }
                    break;

                default:
                    // other extensions are of no interest to certificate selection
            }
        }

        return $hello;
    }

    /**
     * withoutConn returns the data from the ClientHelloInfo without the
     * pesky Conn field, which often causes an error when serializing because
     * the underlying type may be unserializable (Go: `clientHelloWithoutConn`).
     *
     * @return array<string, mixed>
     */
    public function withoutConn(): array
    {
        return [
            'CipherSuites' => $this->cipherSuites,
            'ServerName' => $this->serverName,
            'SupportedProtos' => $this->supportedProtos,
            'SupportedVersions' => $this->supportedVersions,
            'RemoteAddr' => $this->remoteAddr,
            'LocalAddr' => $this->localAddr,
        ];
    }
}
