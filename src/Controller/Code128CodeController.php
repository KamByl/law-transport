<?php

namespace App\Controller;

use App\Service\Code128BarcodeGenerator;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Code128CodeController extends BaseController
{
    public function jpg(Request $request, array $parameters): Response
    {
        $code = (string) ($parameters['code'] ?? '');
        $generator = new Code128BarcodeGenerator();

        try {
            $jpeg = $generator->generateJpeg($code, includeHeader: $request->query->getBoolean('header', true));
            $normalizedCode = $generator->normalize($code);
        } catch (InvalidArgumentException $exception) {
            return new Response($exception->getMessage(), Response::HTTP_BAD_REQUEST, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]);
        }

        return new Response($jpeg, Response::HTTP_OK, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => sprintf('inline; filename="%s.jpg"', preg_replace('/[^A-Za-z0-9._-]+/', '_', $normalizedCode) ?: 'code-128'),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
