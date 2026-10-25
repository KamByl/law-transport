<?php

namespace App\Service;

final class DrawingProtocolDocumentGenerator
{
    private const WORD_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * @param array<string, mixed> $drawing
     * @param array<int, array<string, mixed>> $winners
     */
    public function generate(string $templatePath, string $outputPath, array $drawing, array $winners): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Rozszerzenie ZIP jest wymagane do utworzenia protokołu.');
        }
        if (!is_file($templatePath) || !is_readable($templatePath)) {
            throw new \RuntimeException('Nie znaleziono szablonu protokołu.');
        }
        if ($winners === []) {
            throw new \InvalidArgumentException('Do tego losowania nie przypisano żadnych zwycięzców.');
        }

        $drawingDate = $this->parseDate((string) ($drawing['drawing_date'] ?? ''));
        $startDate = $this->parseDate((string) ($drawing['start_date'] ?? ''));
        $endDate = $this->parseDate((string) ($drawing['end_date'] ?? ''));
        if (!$drawingDate || !$startDate || !$endDate) {
            throw new \InvalidArgumentException('Losowanie musi mieć prawidłową datę losowania oraz zakres dat.');
        }

        if (!copy($templatePath, $outputPath)) {
            throw new \RuntimeException('Nie udało się przygotować pliku protokołu.');
        }

        $archive = new \ZipArchive();
        if ($archive->open($outputPath) !== true) {
            @unlink($outputPath);
            throw new \RuntimeException('Nie udało się otworzyć pliku protokołu.');
        }

        try {
            $documentXml = $archive->getFromName('word/document.xml');
            if (!is_string($documentXml)) {
                throw new \RuntimeException('Szablon protokołu nie zawiera dokumentu Word.');
            }

            $document = new \DOMDocument('1.0', 'UTF-8');
            $previousUseInternalErrors = libxml_use_internal_errors(true);
            try {
                if (!$document->loadXML($documentXml, LIBXML_NONET)) {
                    throw new \RuntimeException('Nie udało się odczytać treści szablonu protokołu.');
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previousUseInternalErrors);
            }

            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('w', self::WORD_NAMESPACE);

            $this->replaceParagraphStartingWith(
                $xpath,
                'Warszawa,',
                sprintf('Warszawa, %s r.', $drawingDate->format('d.m.Y'))
            );

            $directorDecision = trim((string) ($drawing['protocol_director_decision'] ?? ''));
            if ($directorDecision !== '') {
                $this->replaceProtocolDecision($xpath, $directorDecision);
            }

            $dailyDrawings = $this->parseStructuredTextItems($drawing['protocol_daily_drawings'] ?? null);
            if ($dailyDrawings !== []) {
                $this->replaceParagraphsBetween(
                    $xpath,
                    'Losowania dotyczyły dziennych:',
                    'Losowanie miało miejsce',
                    $dailyDrawings
                );
            }

            $this->replaceParagraphStartingWith(
                $xpath,
                'Losowanie miało miejsce',
                sprintf(
                    'Losowanie odbyło się %s r. spośród Losów uzyskanych na podstawie Zamówień potwierdzonych Zgłoszeniami dokonanymi i zapisanymi w bazie Loterii w dniach od %s r. do %s r.,',
                    $drawingDate->format('d.m.Y'),
                    $startDate->format('d.m.Y'),
                    $endDate->format('d.m.Y')
                )
            );
            $this->replaceWinnersTable($xpath, $winners);

            $updatedXml = $document->saveXML();
            if (!is_string($updatedXml) || !$archive->addFromString('word/document.xml', $updatedXml)) {
                throw new \RuntimeException('Nie udało się zapisać treści protokołu.');
            }
        } catch (\Throwable $exception) {
            $archive->close();
            @unlink($outputPath);
            throw $exception;
        }

        if (!$archive->close()) {
            @unlink($outputPath);
            throw new \RuntimeException('Nie udało się zamknąć pliku protokołu.');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $winners
     */
    private function replaceWinnersTable(\DOMXPath $xpath, array $winners): void
    {
        $table = $xpath->query('//w:tbl[1]')->item(0);
        if (!$table instanceof \DOMElement) {
            throw new \RuntimeException('Szablon protokołu nie zawiera tabeli laureatów.');
        }

        $rowNodes = $xpath->query('./w:tr', $table);
        if (!$rowNodes || $rowNodes->length < 2) {
            throw new \RuntimeException('Tabela laureatów w szablonie jest nieprawidłowa.');
        }

        $headerRow = $rowNodes->item(0);
        $dataRow = $rowNodes->item(1);
        $spacerRow = $rowNodes->length > 31 ? $rowNodes->item(31) : null;
        $headerTemplate = $headerRow instanceof \DOMElement ? $headerRow->cloneNode(true) : null;
        $dataTemplate = $dataRow instanceof \DOMElement ? $dataRow->cloneNode(true) : null;
        $spacerTemplate = $spacerRow instanceof \DOMElement
            ? $spacerRow->cloneNode(true)
            : ($dataTemplate instanceof \DOMElement ? $dataTemplate->cloneNode(true) : null);
        if (!$headerTemplate instanceof \DOMElement || !$dataTemplate instanceof \DOMElement || !$spacerTemplate instanceof \DOMElement) {
            throw new \RuntimeException('Nie udało się odczytać układu tabeli laureatów.');
        }

        $groups = [];
        foreach ($winners as $winner) {
            $prizeTypeId = trim((string) ($winner['prize_type_id'] ?? ''));
            $prizeName = trim((string) ($winner['prize_name'] ?? ''));
            $groupKey = $prizeTypeId !== '' ? $prizeTypeId : $prizeName;
            if ($groupKey === '') {
                $groupKey = 'bez-rodzaju';
            }
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'name' => $prizeName !== '' ? $prizeName : 'Nagroda',
                    'winners' => [],
                ];
            }
            $groups[$groupKey]['winners'][] = $winner;
        }

        while (($row = $xpath->query('./w:tr', $table)->item(0)) instanceof \DOMNode) {
            $table->removeChild($row);
        }

        $firstGroup = true;
        foreach ($groups as $group) {
            if (!$firstGroup) {
                for ($index = 0; $index < 2; ++$index) {
                    $spacerRow = $spacerTemplate->cloneNode(true);
                    if ($spacerRow instanceof \DOMElement) {
                        $table->appendChild($spacerRow);
                    }
                }
            }

            $headerRow = $headerTemplate->cloneNode(true);
            if ($headerRow instanceof \DOMElement) {
                $this->setRowCells($xpath, $headerRow, [
                    'Lp',
                    'Nagroda',
                    'Liczba porządkowa wylosowanego losu',
                ]);
                $this->markAsRepeatingHeader($xpath, $headerRow);
                $table->appendChild($headerRow);
            }

            $position = 1;
            foreach ($group['winners'] as $winner) {
                $dataRow = $dataTemplate->cloneNode(true);
                if (!$dataRow instanceof \DOMElement) {
                    continue;
                }
                $this->setRowCells($xpath, $dataRow, [
                    (string) $position,
                    $this->formatWinnerLabel((string) $group['name']),
                    trim((string) ($winner['public_ticket_id'] ?? '')),
                ]);
                $table->appendChild($dataRow);
                ++$position;
            }

            $firstGroup = false;
        }
    }

    /**
     * @param string[] $values
     */
    private function setRowCells(\DOMXPath $xpath, \DOMElement $row, array $values): void
    {
        $cells = $xpath->query('./w:tc', $row);
        if (!$cells || $cells->length < count($values)) {
            throw new \RuntimeException('Wiersz tabeli laureatów ma nieprawidłowy układ.');
        }

        foreach ($values as $index => $value) {
            $cell = $cells->item($index);
            if ($cell instanceof \DOMElement) {
                $this->setElementText($xpath, $cell, $value);
            }
        }
    }

    private function markAsRepeatingHeader(\DOMXPath $xpath, \DOMElement $row): void
    {
        $rowProperties = $xpath->query('./w:trPr', $row)->item(0);
        if (!$rowProperties instanceof \DOMElement) {
            $rowProperties = $row->ownerDocument->createElementNS(self::WORD_NAMESPACE, 'w:trPr');
            $row->insertBefore($rowProperties, $row->firstChild);
        }
        if (!($xpath->query('./w:tblHeader', $rowProperties)->item(0) instanceof \DOMElement)) {
            $headerProperty = $row->ownerDocument->createElementNS(self::WORD_NAMESPACE, 'w:tblHeader');
            $headerProperty->setAttributeNS(self::WORD_NAMESPACE, 'w:val', '1');
            $rowProperties->appendChild($headerProperty);
        }
    }

    private function replaceParagraphStartingWith(\DOMXPath $xpath, string $prefix, string $replacement): void
    {
        $paragraphs = $xpath->query('//w:body/w:p');
        if (!$paragraphs) {
            throw new \RuntimeException('Szablon protokołu nie zawiera oczekiwanej treści.');
        }

        foreach ($paragraphs as $paragraph) {
            if (!$paragraph instanceof \DOMElement) {
                continue;
            }
            $text = $this->readElementText($xpath, $paragraph);
            if (strncmp(trim($text), $prefix, strlen($prefix)) === 0) {
                $this->setElementText($xpath, $paragraph, $replacement);
                return;
            }
        }

        throw new \RuntimeException(sprintf('W szablonie protokołu brakuje pola: %s', $prefix));
    }

    private function replaceProtocolDecision(\DOMXPath $xpath, string $decision): void
    {
        $paragraphs = $xpath->query('//w:body/w:p');
        if (!$paragraphs) {
            throw new \RuntimeException('Szablon protokołu nie zawiera oczekiwanej treści.');
        }

        foreach ($paragraphs as $paragraph) {
            if (!$paragraph instanceof \DOMElement) {
                continue;
            }

            $text = trim($this->readElementText($xpath, $paragraph));
            if (strncmp($text, 'organizowanej przez', strlen('organizowanej przez')) !== 0) {
                continue;
            }

            $this->setElementText(
                $xpath,
                $paragraph,
                'organizowanej przez Nofsza Sp. z o. o. na podstawie'
            );

            $decisionParagraph = $paragraph->nextSibling;
            while ($decisionParagraph instanceof \DOMNode) {
                if ($decisionParagraph instanceof \DOMElement && $decisionParagraph->namespaceURI === self::WORD_NAMESPACE && $decisionParagraph->localName === 'p') {
                    $nextText = trim($this->readElementText($xpath, $decisionParagraph));
                    if ($nextText === '' || strncmp($nextText, 'Losowania w loterii', strlen('Losowania w loterii')) === 0) {
                        break;
                    }

                    $this->setElementText($xpath, $decisionParagraph, $decision);
                    return;
                }

                $decisionParagraph = $decisionParagraph->nextSibling;
            }

            throw new \RuntimeException('Szablon protokołu nie zawiera miejsca na decyzję Dyrektora Izby Administracji Skarbowej.');
        }

        throw new \RuntimeException('Szablon protokołu nie zawiera tytułu z podstawą decyzji.');
    }

    /**
     * @param string[] $replacements
     */
    private function replaceParagraphsBetween(
        \DOMXPath $xpath,
        string $startPrefix,
        string $endPrefix,
        array $replacements
    ): void {
        $paragraphs = $xpath->query('//w:body/w:p');
        if (!$paragraphs) {
            throw new \RuntimeException('Szablon protokołu nie zawiera oczekiwanej treści.');
        }

        $startParagraph = null;
        $endParagraph = null;
        foreach ($paragraphs as $paragraph) {
            if (!$paragraph instanceof \DOMElement) {
                continue;
            }

            $text = trim($this->readElementText($xpath, $paragraph));
            if ($startParagraph === null && strncmp($text, $startPrefix, strlen($startPrefix)) === 0) {
                $startParagraph = $paragraph;
                continue;
            }
            if ($startParagraph instanceof \DOMElement && strncmp($text, $endPrefix, strlen($endPrefix)) === 0) {
                $endParagraph = $paragraph;
                break;
            }
        }

        if (!$startParagraph instanceof \DOMElement || !$endParagraph instanceof \DOMElement) {
            throw new \RuntimeException(sprintf('W szablonie protokołu brakuje sekcji: %s', $startPrefix));
        }

        $paragraphsToRemove = [];
        $paragraphTemplate = null;
        $node = $startParagraph->nextSibling;
        while ($node instanceof \DOMNode && !$node->isSameNode($endParagraph)) {
            $nextNode = $node->nextSibling;
            if ($node instanceof \DOMElement && $node->namespaceURI === self::WORD_NAMESPACE && $node->localName === 'p') {
                $paragraphTemplate ??= $node->cloneNode(true);
                $paragraphsToRemove[] = $node;
            }
            $node = $nextNode;
        }

        if (!$paragraphTemplate instanceof \DOMElement) {
            throw new \RuntimeException(sprintf('Sekcja %s nie zawiera wzorca pozycji.', $startPrefix));
        }

        $parent = $endParagraph->parentNode;
        if (!$parent instanceof \DOMNode) {
            throw new \RuntimeException('Szablon protokołu ma nieprawidłową strukturę.');
        }

        foreach ($paragraphsToRemove as $paragraph) {
            $parent->removeChild($paragraph);
        }

        foreach ($replacements as $replacement) {
            $newParagraph = $paragraphTemplate->cloneNode(true);
            if (!$newParagraph instanceof \DOMElement) {
                continue;
            }
            $this->setElementText($xpath, $newParagraph, $replacement);
            $parent->insertBefore($newParagraph, $endParagraph);
        }
    }

    /**
     * @return string[]
     */
    private function parseStructuredTextItems($value): array
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return [];
            }
            $value = json_decode($value, true);
        }

        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $text = is_array($item) ? ($item['text'] ?? '') : $item;
            if (!is_scalar($text)) {
                continue;
            }

            $text = trim((string) $text);
            if ($text !== '') {
                $items[] = $text;
            }
        }

        return $items;
    }

    private function readElementText(\DOMXPath $xpath, \DOMElement $element): string
    {
        $text = '';
        $textNodes = $xpath->query('.//w:t', $element);
        if ($textNodes) {
            foreach ($textNodes as $textNode) {
                $text .= $textNode->textContent;
            }
        }

        return $text;
    }

    private function setElementText(\DOMXPath $xpath, \DOMElement $element, string $value): void
    {
        $textNodes = $xpath->query('.//w:t', $element);
        if (!$textNodes || $textNodes->length === 0) {
            throw new \RuntimeException('Szablon protokołu zawiera nieprawidłowe pole tekstowe.');
        }

        foreach ($textNodes as $index => $textNode) {
            $textNode->nodeValue = $index === 0 ? $value : '';
            if ($index === 0 && $textNode instanceof \DOMElement) {
                $textNode->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            }
        }
    }

    private function formatWinnerLabel(string $prizeName): string
    {
        $prizeName = trim($prizeName);
        if ($prizeName === '') {
            return 'Laureat';
        }

        if (preg_match('/^Nagroda dzienna\b(.*)$/ui', $prizeName, $matches) === 1) {
            return 'Laureat do nagrody dziennej' . $matches[1];
        }

        return 'Laureat – ' . $prizeName;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        foreach (['!Y-m-d', '!d.m.Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if (
                $date instanceof \DateTimeImmutable
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            ) {
                return $date;
            }
        }

        return null;
    }
}
