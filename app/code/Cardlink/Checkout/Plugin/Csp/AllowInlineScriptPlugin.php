<?php

namespace Cardlink\Checkout\Plugin\Csp;

use Magento\Csp\Model\CompositePolicyCollector;
use Magento\Csp\Model\Policy\FetchPolicy;

/**
 * Adds 'unsafe-inline' to the final merged script-src CSP directive.
 *
 * Required because the Cardlink Apple Pay Direct script renders a button with
 * inline event handlers (onclick=...) which are blocked by the default CSP.
 *
 * We use reflection to set the inline-allow flag so the code is compatible
 * with any version of FetchPolicy regardless of getter/merge method names.
 */
class AllowInlineScriptPlugin
{
    /**
     * @param CompositePolicyCollector $subject
     * @param array $result
     * @return array
     */
    public function afterCollect(CompositePolicyCollector $subject, array $result): array
    {
        foreach ($result as $key => $policy) {
            if (!($policy instanceof FetchPolicy) || $policy->getId() !== 'script-src') {
                continue;
            }

            $newPolicy = clone $policy;
            $ref = new \ReflectionObject($newPolicy);

            foreach ($ref->getProperties() as $prop) {
                // Match the inline-allow property regardless of exact naming
                // (allowInline, inlineAllowed, isInlineAllowed, etc.)
                if (stripos($prop->getName(), 'inline') !== false) {
                    // Reflection reaches private members on its own since PHP 8.1, and
                    // PHP 8.5 deprecates the call. Magento's error handler would turn
                    // that deprecation into an exception and take the whole CSP
                    // collection - so every page render - down with it.
                    if (PHP_VERSION_ID < 80100) {
                        $prop->setAccessible(true);
                    }

                    $prop->setValue($newPolicy, true);
                    break;
                }
            }

            $result[$key] = $newPolicy;
            return $result;
        }

        return $result;
    }
}
