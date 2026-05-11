<?php

namespace Cardlink\Checkout\Controller\Payment;

use Cardlink\Checkout\Helper\Data;
use Cardlink\Checkout\Logger\Logger;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * POST /api/sign-data — signs the 3D-Secure MPI form data.
 *
 * Called by the frontend JS threeDSHandler after the XID is obtained.
 * Signs the MPI form fields using either:
 *   - v2.x: HMAC-SHA256 with the shared secret
 *   - v4.x: SHA256withRSA with the MPI private key
 *
 * Request JSON:
 *   {
 *     "mpiVersion":   "4.0",
 *     "pan":          "",
 *     "expiry":       "",
 *     "cardEncData":  "…",
 *     "devCat":       "0",
 *     "purchAmount":  "55.55",
 *     "exponent":     "2",
 *     "description":  "Order 1234567890",
 *     "currMpi":      "978",
 *     "merchantID":   "9000010866",
 *     "xidb64":       "…",
 *     "okUrl":        "https://…/3ds/success",
 *     "failUrl":      "https://…/3ds/failure",
 *     "recurFreq":    null,
 *     "recurEnd":     null,
 *     "installments": null
 *   }
 *
 * Response JSON:
 *   {
 *     "signature": "…",
 *     "purchaseAmountFormatted": "5555"
 *   }
 *
 * Magento route: cardlink_checkout/payment/googlepaysigndata
 *
 * @author Cardlink S.A.
 */
class GooglePaySignData extends Action implements CsrfAwareActionInterface
{
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    /**
     * @var Data
     */
    private $dataHelper;

    public function __construct(
        Context $context,
        Logger $logger,
        JsonFactory $jsonFactory,
        Data $dataHelper
    ) {
        $this->logger = $logger;
        $this->jsonFactory = $jsonFactory;
        $this->dataHelper = $dataHelper;
        parent::__construct($context);
    }

    /** @inheritDoc */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /** @inheritDoc */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $body = $this->getRequest()->getContent();
            $data = json_decode($body, true);

            if (!$data) {
                $result->setHttpResponseCode(400);
                $result->setData(['error' => 'Invalid JSON']);
                return $result;
            }

            $mid = $data['merchantID'] ?? $this->dataHelper->getGooglePayMerchantId();

            // Format the purchase amount: remove decimal separator,
            // left-pad with zeros to the appropriate length.
            // e.g., "55.55" with exponent 2 → "5555"
            $purchaseAmountFormatted = $this->formatPurchaseAmount(
                $data['purchAmount'] ?? '0',
                (int) ($data['exponent'] ?? 2)
            );

            // Determine the signing approach.
            // If the merchant has an MPI private key configured → RSA (v4.0).
            // Otherwise fall back to HMAC-SHA256 with the shared secret (v2.0).
            $mpiKey = $this->dataHelper->getGooglePayMpiPrivateKey();
            $secret = $this->dataHelper->getGooglePaySharedSecret();

            if (!empty($mpiKey) && strpos($mpiKey, '-----BEGIN') !== false) {
                // RSA signing available → use v4.0
                $mpiVersion = '4.0';
                $signature = $this->signWithRsa($data, $purchaseAmountFormatted, $mpiKey, $mpiVersion);
            } else {
                // Fall back to HMAC → v2.0
                $mpiVersion = '2.0';
                $signature = $this->signWithHmac($data, $purchaseAmountFormatted, $secret, $mpiVersion);
            }

            if (strpos($signature, 'Error') === 0) {
                $this->logger->error('GooglePaySignData: ' . $signature);
                $result->setHttpResponseCode(500);
                $result->setData(['error' => $signature]);
                return $result;
            }

            $result->setData([
                'signature' => $signature,
                'purchaseAmountFormatted' => $purchaseAmountFormatted,
                'mpiVersion' => $mpiVersion,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('GooglePaySignData error: ' . $e->getMessage());
            $result->setHttpResponseCode(500);
            $result->setData(['error' => 'Internal error signing data']);
        }

        return $result;
    }

    /**
     * Format the purchase amount for the MPI form.
     *
     * Converts a decimal amount (e.g., "55.55") to an integer string
     * representation according to the exponent (e.g., "5555" for exponent 2).
     *
     * @param string $amount Decimal amount string
     * @param int $exponent Number of decimal places (typically 2)
     * @return string Formatted amount without decimal separator
     */
    private function formatPurchaseAmount(string $amount, int $exponent): string
    {
        // Convert to float, multiply by 10^exponent, format as integer string
        $numericAmount = (float) $amount;
        $multiplier = pow(10, $exponent);
        $intAmount = (int) round($numericAmount * $multiplier);

        return (string) $intAmount;
    }

    /**
     * Build the signing payload string from MPI form fields.
     *
     * The fields are concatenated in the order expected by the VPOS MPI.
     *
     * @param array $data Request data
     * @param string $formattedAmount Formatted purchase amount
     * @return string Concatenated payload for signing
     */
    private function buildSignPayload(array $data, string $formattedAmount, ?string $mpiVersion = null): string
    {
        $fields = [
            $mpiVersion ?? $data['mpiVersion'] ?? '',
            $data['pan'] ?? '',
            $data['expiry'] ?? '',
            $data['cardEncData'] ?? '',
            $data['devCat'] ?? '0',
            $formattedAmount,
            $data['exponent'] ?? '2',
            $data['description'] ?? '',
            $data['currMpi'] ?? '978',
            $data['merchantID'] ?? '',
            $data['xidb64'] ?? '',
            $data['okUrl'] ?? '',
            $data['failUrl'] ?? '',
        ];

        // Optionally add recurring/installment fields if present
        if (!empty($data['recurFreq'])) {
            $fields[] = $data['recurFreq'];
        }
        if (!empty($data['recurEnd'])) {
            $fields[] = $data['recurEnd'];
        }
        if (!empty($data['installments'])) {
            $fields[] = $data['installments'];
        }

        return implode('', $fields);
    }

    /**
     * Sign the MPI form payload with RSA-SHA256 (v4.x).
     *
     * @param array $data Request fields
     * @param string $formattedAmount Formatted purchase amount
     * @param string $privateKeyPem PEM-encoded RSA private key
     * @param string $mpiVersion The actual MPI version to use in payload
     * @return string Base64-encoded signature or error string
     */
    private function signWithRsa(array $data, string $formattedAmount, string $privateKeyPem, string $mpiVersion): string
    {
        $payload = $this->buildSignPayload($data, $formattedAmount, $mpiVersion);

        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            return 'Error: Invalid private key — ' . openssl_error_string();
        }

        $signatureBytes = '';
        $success = openssl_sign($payload, $signatureBytes, $privateKey, OPENSSL_ALGO_SHA256);

        if (!$success) {
            return 'Error: Signing failed — ' . openssl_error_string();
        }

        return base64_encode($signatureBytes);
    }

    /**
     * Sign the MPI form payload with HMAC-SHA1 (v2.x).
     *
     * MPI v2.0 uses SHA-1 (produces 20-byte / 28-char base64 digest).
     *
     * @param array $data Request fields
     * @param string $formattedAmount Formatted purchase amount
     * @param string $sharedSecret Shared secret key
     * @param string $mpiVersion The actual MPI version to use in payload
     * @return string Base64-encoded HMAC signature
     */
    private function signWithHmac(array $data, string $formattedAmount, string $sharedSecret, string $mpiVersion): string
    {
        $payload = $this->buildSignPayload($data, $formattedAmount, $mpiVersion);
        return base64_encode(hash('sha1', $payload . $sharedSecret, true));
    }
}
