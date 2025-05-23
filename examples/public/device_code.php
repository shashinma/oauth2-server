<?php

/**
 * @author    Andrew Millington <andrew@noexceptions.io>
 * @copyright Copyright (c) Alex Bilbie
 * @license   http://mit-license.org/
 *
 * @link      https://github.com/thephpleague/oauth2-server
 */

declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use Laminas\Diactoros\Stream;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\DeviceCodeGrant;
use OAuth2ServerExamples\Repositories\AccessTokenRepository;
use OAuth2ServerExamples\Repositories\ClientRepository;
use OAuth2ServerExamples\Repositories\DeviceCodeRepository;
use OAuth2ServerExamples\Repositories\RefreshTokenRepository;
use OAuth2ServerExamples\Repositories\ScopeRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;

$app = new App([
    'settings' => [
        'displayErrorDetails' => true,
    ],
    AuthorizationServer::class => function () {
        // Init our repositories
        $clientRepository = new ClientRepository();
        $scopeRepository = new ScopeRepository();
        $accessTokenRepository = new AccessTokenRepository();
        $refreshTokenRepository = new RefreshTokenRepository();
        $deviceCodeRepository = new DeviceCodeRepository();

        $privateKeyPath = 'file://' . __DIR__ . '/../private.key';

        // Set up the authorization server
        $server = new AuthorizationServer(
            $clientRepository,
            $accessTokenRepository,
            $scopeRepository,
            $privateKeyPath,
            'lxZFUEsBCJ2Yb14IF2ygAHI5N4+ZAUXXaSeeJm6+twsUmIen'
        );

        // Enable the device code grant on the server with a token TTL of 1 hour
        $server->enableGrantType(
            new DeviceCodeGrant(
                $deviceCodeRepository,
                $refreshTokenRepository,
                new DateInterval('PT10M'),
                'http://foo/bar'
            ),
            new DateInterval('PT1H')
        );

        return $server;
    },
]);

$app->post('/device_authorization', function (ServerRequestInterface $request, ResponseInterface $response) use ($app) {
    /* @var \League\OAuth2\Server\AuthorizationServer $server */
    $server = $app->getContainer()->get(AuthorizationServer::class);

    try {
        $deviceCodeResponse = $server->respondToDeviceAuthorizationRequest($request, $response);

        return $deviceCodeResponse;

        // Extract the device code. Usually we would then assign the user ID to
        // the device code but for the purposes of this example, we've hard
        // coded it in the response above.
        // $deviceCode = json_decode((string) $deviceCodeResponse->getBody());

        // Once the user has logged in and approved the request, set the user on the device code
        // $server->completeDeviceAuthorizationRequest($deviceCode->user_code, 1);
    } catch (OAuthServerException $exception) {
        return $exception->generateHttpResponse($response);
    } catch (Exception $exception) {
        $body = new Stream('php://temp', 'r+');
        $body->write($exception->getMessage());

        return $response->withStatus(500)->withBody($body);
    }
});

$app->post('/access_token', function (ServerRequestInterface $request, ResponseInterface $response) use ($app) {
    /* @var \League\OAuth2\Server\AuthorizationServer $server */
    $server = $app->getContainer()->get(AuthorizationServer::class);

    try {
        return $server->respondToAccessTokenRequest($request, $response);
    } catch (OAuthServerException $exception) {
        return $exception->generateHttpResponse($response);
    } catch (Exception $exception) {
        $body = new Stream('php://temp', 'r+');
        $body->write($exception->getMessage());

        return $response->withStatus(500)->withBody($body);
    }
});

$app->run();
