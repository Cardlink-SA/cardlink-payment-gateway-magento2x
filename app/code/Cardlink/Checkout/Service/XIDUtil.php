<?php

declare(strict_types=1);

namespace Cardlink\Checkout\Service;

/**
 * Utility class for calculating the 3D-Secure XID (Transaction Identifier).
 *
 * The XID is a 20-byte value that uniquely identifies a 3DS transaction.
 * It is built from the VPOS transaction fields and Base64-encoded for use
 * in MPI form submissions and the second SaleRequest's ThreeDSecure element.
 *
 * Format (per Cardlink Google Pay Direct documentation):
 *   "VPOS" (4) + padCutLeft(txId, 7) (7) + "-" (1)
 *   + padCutLeft(trExtId, 6) (6) + padCutLeft(mpiCounts, 2) (2) = 20 bytes
 *
 * padCutLeft: left-pad with '0' if shorter than N, or take rightmost N chars
 * if longer.
 *
 * @author Cardlink S.A.
 */
class XIDUtil
{
    /**
     * Prefix used in XID construction.
     */
    private const XID_PREFIX = 'VPOS';

    /**
     * Calculate the XID from VPOS transaction identifiers.
     *
     * Builds a 20-byte string matching the documented Java reference impl:
     *   "VPOS" + padCutLeft(trId, '0', 7) + "-"
     *   + padCutLeft(trExtId, '0', 6) + padCutLeft(mpiCounts, '0', 2)
     *
     * Then Base64-encodes the ISO-8859-1 byte string.
     *
     * @param string $txId Transaction ID from the VPOS response
     * @param string $trExtId External transaction reference from the VPOS response
     * @param string $trMpiCounts MPI counts value from the VPOS response
     * @return string Base64-encoded XID string (28 chars)
     */
    public static function calculateXID(string $txId, string $trExtId, string $trMpiCounts): string
    {
        $raw = self::XID_PREFIX
            . self::padCutLeft($txId, 7)
            . '-'
            . self::padCutLeft($trExtId, 6)
            . self::padCutLeft($trMpiCounts, 2);

        return base64_encode($raw);
    }

    /**
     * Left-pad or left-cut a string to exactly $length characters.
     *
     * - If $str is shorter than $length: left-pad with '0'.
     * - If $str is longer than $length: take the rightmost $length characters.
     * - If $str is exactly $length: return unchanged.
     *
     * Matches the Java Misc.padCutStringLeft() behaviour.
     *
     * @param string $str Input string
     * @param int $length Desired output length
     * @return string Exactly $length characters
     */
    private static function padCutLeft(string $str, int $length): string
    {
        $len = strlen($str);
        if ($len > $length) {
            // Take the rightmost $length characters
            return substr($str, -$length);
        }
        if ($len < $length) {
            // Left-pad with '0'
            return str_pad($str, $length, '0', STR_PAD_LEFT);
        }
        return $str;
    }
}
