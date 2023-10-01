<?php

/**
 * @author      Alex Bilbie <hello@alexbilbie.com>
 * @copyright   Copyright (c) Alex Bilbie
 * @license     http://mit-license.org/
 *
 * @link        https://github.com/thephpleague/oauth2-server
 */

declare(strict_types=1);

namespace League\OAuth2\Server\Exception;

use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function htmlspecialchars;
use function http_build_query;
use function sprintf;
use function strpos;
use function strstr;

class OAuthServerException extends Exception
{
    private int $httpStatusCode;

    private string $errorType;

    private ?string $hint = null;

    private ?string $redirectUri = null;

    /**
     * @var array<string, string>
     */
    private array $payload;

    private ServerRequestInterface $serverRequest;

    /**
     * Throw a new exception.
     *
     * @param string      $message        Error message
     * @param int         $code           Error code
     * @param string      $errorType      Error type
     * @param int         $httpStatusCode HTTP status code to send (default = 400)
     * @param null|string $hint           A helper hint
     * @param null|string $redirectUri    A HTTP URI to redirect the user back to
     * @param Throwable   $previous       Previous exception
     */
    final public function __construct(string $message, int $code, string $errorType, int $httpStatusCode = 400, ?string $hint = null, ?string $redirectUri = null, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->httpStatusCode = $httpStatusCode;
        $this->errorType = $errorType;
        $this->hint = $hint;
        $this->redirectUri = $redirectUri;
        $this->payload = [
            'error'             => $errorType,
            'error_description' => $message,
        ];
        if ($hint !== null) {
            $this->payload['hint'] = $hint;
        }
    }

    /**
     * Returns the current payload.
     *
     * @return array<string, string>
     */
    public function getPayload(): array
    {
        $payload = $this->payload;

        // The "message" property is deprecated and replaced by "error_description"
        // TODO: remove "message" property
        if (isset($payload['error_description']) && !isset($payload['message'])) {
            $payload['message'] = $payload['error_description'];
        }

        return $payload;
    }

    /**
     * Updates the current payload.
     *
     * @param array<string, string> $payload
     */
    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }

    /**
     * Set the server request that is responsible for generating the exception
     *
     */
    public function setServerRequest(ServerRequestInterface $serverRequest): void
    {
        $this->serverRequest = $serverRequest;
    }

    /**
     * Unsupported grant type error.
     *
     * @return static
     */
    public static function unsupportedGrantType(): static
    {
        $errorMessage = 'The authorization grant type is not supported by the authorization server.';
        $hint = 'Check that all required parameters have been provided';

        return new static($errorMessage, 2, 'unsupported_grant_type', 400, $hint);
    }

    /**
     * Invalid request error.
     *
     * @param string      $parameter The invalid parameter
     * @param Throwable   $previous  Previous exception
     *
     * @return static
     */
    public static function invalidRequest(string $parameter, ?string $hint = null, Throwable $previous = null): static
    {
        $errorMessage = 'The request is missing a required parameter, includes an invalid parameter value, ' .
            'includes a parameter more than once, or is otherwise malformed.';
        $hint = ($hint === null) ? sprintf('Check the `%s` parameter', $parameter) : $hint;

        return new static($errorMessage, 3, 'invalid_request', 400, $hint, null, $previous);
    }

    /**
     * Invalid client error.
     *
     *
     * @return static
     */
    public static function invalidClient(ServerRequestInterface $serverRequest): static
    {
        $exception = new static('Client authentication failed', 4, 'invalid_client', 401);

        $exception->setServerRequest($serverRequest);

        return $exception;
    }

    /**
     * Invalid scope error
     */
    public static function invalidScope(string $scope, string|null $redirectUri = null): static
    {
        $errorMessage = 'The requested scope is invalid, unknown, or malformed';

        if ($scope === '') {
            $hint = 'Specify a scope in the request or set a default scope';
        } else {
            $hint = sprintf(
                'Check the `%s` scope',
                htmlspecialchars($scope, ENT_QUOTES, 'UTF-8', false)
            );
        }

        return new static($errorMessage, 5, 'invalid_scope', 400, $hint, $redirectUri);
    }

    /**
     * Invalid credentials error.
     *
     * @return static
     */
    public static function invalidCredentials(): static
    {
        return new static('The user credentials were incorrect.', 6, 'invalid_grant', 400);
    }

    /**
     * Server error.
     *
     *
     * @return static
     *
     * @codeCoverageIgnore
     */
    public static function serverError(string $hint, Throwable $previous = null): static
    {
        return new static(
            'The authorization server encountered an unexpected condition which prevented it from fulfilling'
            . ' the request: ' . $hint,
            7,
            'server_error',
            500,
            null,
            null,
            $previous
        );
    }

    /**
     * Invalid refresh token.
     *
     *
     * @return static
     */
    public static function invalidRefreshToken(?string $hint = null, Throwable $previous = null): static
    {
        return new static('The refresh token is invalid.', 8, 'invalid_grant', 400, $hint, null, $previous);
    }

    /**
     * Access denied.
     *
     *
     * @return static
     */
    public static function accessDenied(?string $hint = null, ?string $redirectUri = null, Throwable $previous = null): static
    {
        return new static(
            'The resource owner or authorization server denied the request.',
            9,
            'access_denied',
            401,
            $hint,
            $redirectUri,
            $previous
        );
    }

    /**
     * Invalid grant.
     *
     *
     * @return static
     */
    public static function invalidGrant(string $hint = ''): static
    {
        return new static(
            'The provided authorization grant (e.g., authorization code, resource owner credentials) or refresh token '
                . 'is invalid, expired, revoked, does not match the redirection URI used in the authorization request, '
                . 'or was issued to another client.',
            10,
            'invalid_grant',
            400,
            $hint
        );
    }

    /**
     */
    public function getErrorType(): string
    {
        return $this->errorType;
    }

    /**
     * Generate a HTTP response.
     *
     * @param bool              $useFragment True if errors should be in the URI fragment instead of query string
     * @param int               $jsonOptions options passed to json_encode
     *
     */
    public function generateHttpResponse(ResponseInterface $response, bool $useFragment = false, int $jsonOptions = 0): ResponseInterface
    {
        $headers = $this->getHttpHeaders();

        $payload = $this->getPayload();

        if ($this->redirectUri !== null) {
            if ($useFragment === true) {
                $this->redirectUri .= (strstr($this->redirectUri, '#') === false) ? '#' : '&';
            } else {
                $this->redirectUri .= (strstr($this->redirectUri, '?') === false) ? '?' : '&';
            }

            return $response->withStatus(302)->withHeader('Location', $this->redirectUri . http_build_query($payload));
        }

        foreach ($headers as $header => $content) {
            $response = $response->withHeader($header, $content);
        }

        $jsonEncodedPayload = json_encode($payload, $jsonOptions);

        $responseBody = $jsonEncodedPayload === false ? 'JSON encoding of payload failed' : $jsonEncodedPayload;

        $response->getBody()->write($responseBody);

        return $response->withStatus($this->getHttpStatusCode());
    }

    /**
     * Get all headers that have to be send with the error response.
     *
     * @return array<string, string> Array with header values
     */
    public function getHttpHeaders(): array
    {
        $headers = [
            'Content-type' => 'application/json',
        ];

        // Add "WWW-Authenticate" header
        //
        // RFC 6749, section 5.2.:
        // "If the client attempted to authenticate via the 'Authorization'
        // request header field, the authorization server MUST
        // respond with an HTTP 401 (Unauthorized) status code and
        // include the "WWW-Authenticate" response header field
        // matching the authentication scheme used by the client.
        if ($this->errorType === 'invalid_client' && $this->requestHasAuthorizationHeader()) {
            $authScheme = strpos($this->serverRequest->getHeader('Authorization')[0], 'Bearer') === 0 ? 'Bearer' : 'Basic';

            $headers['WWW-Authenticate'] = $authScheme . ' realm="OAuth"';
        }

        return $headers;
    }

    /**
     * Check if the exception has an associated redirect URI.
     *
     * Returns whether the exception includes a redirect, since
     * getHttpStatusCode() doesn't return a 302 when there's a
     * redirect enabled. This helps when you want to override local
     * error pages but want to let redirects through.
     *
     */
    public function hasRedirect(): bool
    {
        return $this->redirectUri !== null;
    }

    /**
     * Returns the Redirect URI used for redirecting.
     *
     */
    public function getRedirectUri(): ?string
    {
        return $this->redirectUri;
    }

    /**
     * Returns the HTTP status code to send when the exceptions is output.
     *
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     */
    public function getHint(): ?string
    {
        return $this->hint;
    }

    /**
     * Check if the request has a non-empty 'Authorization' header value.
     *
     * Returns true if the header is present and not an empty string, false
     * otherwise.
     *
     */
    private function requestHasAuthorizationHeader(): bool
    {
        if (!$this->serverRequest->hasHeader('Authorization')) {
            return false;
        }

        $authorizationHeader = $this->serverRequest->getHeader('Authorization');

        // Common .htaccess configurations yield an empty string for the
        // 'Authorization' header when one is not provided by the client.
        // For practical purposes that case should be treated as though the
        // header isn't present.
        // See https://github.com/thephpleague/oauth2-server/issues/1162
        if ($authorizationHeader === [] || $authorizationHeader[0] === '') {
            return false;
        }

        return true;
    }
}
