<?php

namespace App\Controller;

use App\Service\GraphicCodeImporter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class GraphicCodeController extends BaseController
{
    public function image(Request $request, array $parameters): Response
    {
        $token = strtolower(trim((string) ($parameters['token'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw $this->createNotFoundException('Nie znaleziono kodu graficznego.');
        }

        $connection = $this->entityManager->getConnection();
        if (!$connection->createSchemaManager()->tablesExist([GraphicCodeImporter::TABLE])) {
            throw $this->createNotFoundException('Nie znaleziono kodu graficznego.');
        }

        $row = $connection->createQueryBuilder()
            ->select('sciezka_pliku')
            ->from(GraphicCodeImporter::TABLE)
            ->where('token_www = :token')
            ->setParameter('token', $token)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($row) || $row === []) {
            throw $this->createNotFoundException('Nie znaleziono kodu graficznego.');
        }

        $absolutePath = (new GraphicCodeImporter())->resolveStoredPath((string) ($row['sciezka_pliku'] ?? ''));
        if ($absolutePath === null) {
            throw $this->createNotFoundException('Nie znaleziono kodu graficznego.');
        }

        $response = $this->createInlineFileResponse($absolutePath);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
