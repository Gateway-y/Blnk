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

namespace Blnk\Internal\Request;

use GuzzleHttp\Client;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Port of Go `internal/request` (`request.go`): the package-level `ToJsonReq`,
 * `Call`, and `BasicAuth` helpers, implemented with Guzzle.
 */
final class Request
{
    /** HTTP client timeout, mirroring Go's `http.Client{Timeout: 30 * time.Second}`. */
    public const CALL_TIMEOUT_SEC = 30;

    private function __construct()
    {
    }

    /**
     * ToJsonReq converts a value to a JSON-encoded HTTP request payload.
     * It serializes the provided payload to JSON format, ready to be used as a
     * request body (Go returns a *bytes.Buffer; PHP returns the JSON string).
     *
     * Parameters:
     * - $payload: The data structure to be serialized into JSON.
     *
     * @return string The JSON-encoded payload.
     * @throws \JsonException if the JSON marshalling process fails.
     */
    public static function toJsonReq(mixed $payload): string
    {
        // Marshal the payload into a JSON string
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Call makes an HTTP request using the provided request object and decodes
     * the response into the specified structure. It automatically sets the
     * request Content-Type to application/json and decodes the JSON response
     * body into the provided by-reference $response variable (as an
     * associative array, the equivalent of Go decoding into `interface{}`).
     *
     * Non-2xx statuses do not throw (mirroring Go's http.Client, which only
     * errors on transport failures); inspect the returned response's status.
     *
     * Parameters:
     * - $req: The prepared PSR-7 HTTP request to send.
     * - $response: The target variable to hold the decoded JSON response.
     *
     * @return ResponseInterface The raw HTTP response object.
     * @throws \GuzzleHttp\Exception\GuzzleException on transport failure (connection error, timeout).
     * @throws \JsonException if JSON decoding of the response body fails.
     */
    public static function call(RequestInterface $req, mixed &$response): ResponseInterface
    {
        // Set request content type to JSON
        $req = $req->withHeader('Content-Type', 'application/json');

        $client = new Client([
            'timeout' => self::CALL_TIMEOUT_SEC,
            'http_errors' => false,
        ]);

        // Send the HTTP request and capture the response
        $resp = $client->send($req);

        // Decode the JSON response into the provided response variable
        $response = json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return $resp;
    }

    /**
     * BasicAuth generates a basic HTTP authentication string by encoding the
     * provided username and password. The string is base64-encoded in the
     * format "username:password".
     *
     * Parameters:
     * - $username: The username for basic authentication.
     * - $password: The password for basic authentication.
     *
     * @return string A base64-encoded string of "username:password" (prepend
     *                "Basic " when building the Authorization header, as in Go).
     */
    public static function basicAuth(string $username, string $password): string
    {
        $auth = $username . ':' . $password;
        return base64_encode($auth);
    }
}
