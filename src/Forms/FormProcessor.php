<?php

declare(strict_types=1);

namespace WPStaticSecure\Forms;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

final class FormProcessor
{
    /** @var list<FormAdapter> */
    private array $adapters;

    /** @param list<FormAdapter> $adapters */
    public function __construct(array $adapters, private string $submissionEndpoint)
    {
        if ($adapters === []) {
            throw new InvalidArgumentException('At least one form adapter is required.');
        }
        foreach ($adapters as $adapter) {
            if (!$adapter instanceof FormAdapter) {
                throw new InvalidArgumentException('Form processors may contain only FormAdapter instances.');
            }
        }
        $this->adapters = array_values($adapters);
    }

    public function rewriteHtml(string $html): string
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new InvalidArgumentException('Unable to parse HTML document for form rewriting.');
        }

        $this->rewrite($document);
        $body = $document->saveHTML();
        if ($body === false) {
            throw new InvalidArgumentException('Unable to serialize HTML document after form rewriting.');
        }
        return $body;
    }

    /** @return list<FormDefinition> */
    public function rewrite(DOMDocument $document): array
    {
        $definitions = [];
        $seenIds = [];
        $xpath = new DOMXPath($document);

        foreach ($xpath->query('//form') ?: [] as $form) {
            if (!$form instanceof DOMElement) {
                continue;
            }

            $matches = array_values(array_filter(
                $this->adapters,
                static fn (FormAdapter $adapter): bool => $adapter->supports($form)
            ));
            if ($matches === []) {
                continue;
            }
            if (count($matches) !== 1) {
                throw new InvalidArgumentException('A form must match exactly one adapter.');
            }

            $adapter = $matches[0];
            $definition = $adapter->extractSchema($form);
            if (isset($seenIds[$definition->formId()])) {
                throw new InvalidArgumentException('Form identifiers must be unique within a document.');
            }
            $seenIds[$definition->formId()] = true;
            $adapter->rewrite($form, $definition, $this->submissionEndpoint);
            $definitions[] = $definition;
        }

        return $definitions;
    }
}
