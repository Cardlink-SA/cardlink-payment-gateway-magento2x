<?php

declare(strict_types=1);

namespace Cardlink\Checkout\Service;

/**
 * Manages Google Pay / Apple Pay transaction info keyed by XID.
 *
 * When the VPOS returns status PROCESSING (3DS required), the sale controller
 * stores the transaction details here so that:
 * 1. The create-xid endpoint can return the XID.
 * 2. The 3DS callback controller can look up the original order info after
 *    the MPI redirect completes.
 *
 * Storage strategy:
 *   - In-memory static map for same-request access (e.g. sale → create-xid).
 *   - Session-backed for same-session AJAX calls (create-xid, sign-data).
 *   - Cache-backed for cross-domain redirects (MPI → 3DS callback) where
 *     the session cookie is dropped due to SameSite=Lax browser policy.
 *
 * Transaction info structure:
 * [
 *   'trId'         => string,  // VPOS transaction ID (TxId)
 *   'paymentTotal'  => string, // Order amount
 *   'currency'      => string, // Currency code (EUR, etc.)
 *   'payMethod'     => string, // Card type from VPOS (visa, mastercard, etc.)
 *   'orderId'       => string, // Order/reference ID
 *   'mid'           => string, // Merchant ID
 *   'trExtId'       => string, // External transaction ID
 *   'trMpiCounts'   => string, // MPI counts
 *   'cardEncData'   => string, // Encrypted card data for 3DS
 *   'quoteId'       => int,    // Magento quote ID for order creation
 * ]
 *
 * @author Cardlink S.A.
 */
class GPayTransactionInfoManager
{
    /** Cache tag for GPay 3DS entries */
    private const CACHE_TAG = 'gpay_3ds';

    /** Cache TTL: 30 minutes (enough for a 3DS redirect round-trip) */
    private const CACHE_TTL = 1800;

    /**
     * In-memory map: XID (base64) => transaction info array.
     *
     * @var array<string, array>
     */
    private static $transactionMap = [];

    /**
     * Store transaction info for a given XID.
     *
     * @param string $xid Base64-encoded XID
     * @param array $info Transaction info array
     * @return void
     */
    public static function store(string $xid, array $info): void
    {
        self::$transactionMap[$xid] = $info;
    }

    /**
     * Retrieve transaction info for a given XID.
     *
     * @param string $xid Base64-encoded XID
     * @return array|null Transaction info or null if not found
     */
    public static function get(string $xid): ?array
    {
        return self::$transactionMap[$xid] ?? null;
    }

    /**
     * Remove transaction info for a given XID.
     *
     * @param string $xid Base64-encoded XID
     * @return void
     */
    public static function remove(string $xid): void
    {
        unset(self::$transactionMap[$xid]);
    }

    /**
     * Persist the current in-memory map to a Magento checkout session.
     *
     * Call this at the end of the `/sale` request to make the data available
     * to subsequent same-session requests (create-xid, sign-data).
     *
     * @param \Magento\Checkout\Model\Session $session
     * @return void
     */
    public static function persistToSession(\Magento\Checkout\Model\Session $session): void
    {
        $session->setData('gpay_transaction_map', self::$transactionMap);
    }

    /**
     * Restore the in-memory map from a Magento checkout session.
     *
     * @param \Magento\Checkout\Model\Session $session
     * @return void
     */
    public static function restoreFromSession(\Magento\Checkout\Model\Session $session): void
    {
        $data = $session->getData('gpay_transaction_map');
        if (is_array($data)) {
            self::$transactionMap = $data;
        }
    }

    // ── Cache-backed persistence (survives cross-domain MPI redirects) ──

    /**
     * Build a deterministic cache key from the XID.
     *
     * @param string $xid
     * @return string
     */
    private static function cacheKey(string $xid): string
    {
        return self::CACHE_TAG . '_' . md5($xid);
    }

    /**
     * Persist all in-memory entries to Magento's application cache.
     *
     * Call this alongside persistToSession() in the wallet sale controller.
     *
     * @param \Magento\Framework\App\CacheInterface $cache
     * @return void
     */
    public static function persistToCache(\Magento\Framework\App\CacheInterface $cache): void
    {
        foreach (self::$transactionMap as $xid => $info) {
            $cache->save(
                json_encode($info),
                self::cacheKey($xid),
                [self::CACHE_TAG],
                self::CACHE_TTL
            );
        }
    }

    /**
     * Restore a single entry from cache into the in-memory map.
     *
     * Call this in the 3DS callback controller when session restore
     * yields nothing (cross-domain redirect lost the session).
     *
     * @param \Magento\Framework\App\CacheInterface $cache
     * @param string $xid
     * @return array|null The restored info, or null if not found
     */
    public static function restoreFromCache(
        \Magento\Framework\App\CacheInterface $cache,
        string $xid
    ): ?array {
        $raw = $cache->load(self::cacheKey($xid));
        if ($raw === false || $raw === null) {
            return null;
        }
        $info = json_decode($raw, true);
        if (is_array($info)) {
            self::$transactionMap[$xid] = $info;
            return $info;
        }
        return null;
    }

    /**
     * Remove a single entry from cache.
     *
     * @param \Magento\Framework\App\CacheInterface $cache
     * @param string $xid
     * @return void
     */
    public static function removeFromCache(
        \Magento\Framework\App\CacheInterface $cache,
        string $xid
    ): void {
        $cache->remove(self::cacheKey($xid));
    }
}
