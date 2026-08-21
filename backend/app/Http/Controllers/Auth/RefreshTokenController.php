<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/v1/auth/refresh del catalogo MS-01.
 *
 * OAuth2 renueva por el mismo endpoint de token con grant_type=refresh_token.
 * El catalogo de la Fase 3 lo publica como endpoint propio, asi que se expone
 * como tal y se delega en el servidor de autorizacion, sin reimplementar nada.
 *
 * Forzar aqui el grant_type impide de paso que este endpoint sirva para pedir
 * otro tipo de concesion: por /refresh solo se renueva.
 */
final class RefreshTokenController extends Controller
{
    public function __construct(private readonly AccessTokenController $tokens) {}

    public function store(ServerRequestInterface $psrRequest, ResponseInterface $psrResponse): Response
    {
        $psrRequest = $psrRequest->withParsedBody(
            array_merge((array) $psrRequest->getParsedBody(), ['grant_type' => 'refresh_token'])
        );

        return $this->tokens->issueToken($psrRequest, $psrResponse);
    }
}
