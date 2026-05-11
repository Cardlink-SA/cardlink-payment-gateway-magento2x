<?php

declare(strict_types=1);

namespace Cardlink\Checkout\Service;

/**
 * Utility class for XML parsing operations.
 *
 * Provides simple helper methods for extracting values from XML strings
 * without requiring full DOM/XPath setup each time.
 *
 * @author Cardlink S.A.
 */
class XmlUtil
{
    /**
     * Extract the text content of the first element matching the given tag name.
     *
     * Searches the XML string for the specified tag and returns its text value.
     * Returns null if the tag is not found or the XML cannot be parsed.
     *
     * @param string $xml Raw XML string to search
     * @param string $tagName Element name to look for (without namespace prefix)
     * @return string|null Text content of the first matching element, or null
     */
    public static function extractXmlValue(string $xml, string $tagName): ?string
    {
        if (empty($xml) || empty($tagName)) {
            return null;
        }

        // Suppress XML warnings and parse
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $doc = new \DOMDocument();
            if (!$doc->loadXML($xml)) {
                return null;
            }

            // Search with namespace awareness — try getElementsByTagNameNS first
            // for the VPOS namespace, then fall back to plain tag name.
            $nodes = $doc->getElementsByTagName($tagName);

            if ($nodes->length > 0) {
                return $nodes->item(0)->textContent;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

}
