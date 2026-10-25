<?php

namespace App\Controller;

use App\Service\Ean13BarcodeGenerator;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class EanCodeController extends BaseController
{
    public function jpg(Request $request, array $parameters): Response
    {
        $code = (string) ($parameters['code'] ?? '');
        $generator = new Ean13BarcodeGenerator();

        try {
            $jpeg = $generator->generateJpeg($code, includeHeader: $request->query->getBoolean('header', true));
            $ean = $generator->normalize($code);
        } catch (InvalidArgumentException $exception) {
            return new Response($exception->getMessage(), Response::HTTP_BAD_REQUEST, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ]);
        }

        return new Response($jpeg, Response::HTTP_OK, [
            'Content-Type' => 'image/jpeg',
            'Content-Disposition' => sprintf('inline; filename="%s.jpg"', $ean),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
