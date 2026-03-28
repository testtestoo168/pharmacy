<?php

/**
 * ZATCA (Zakat, Tax and Customs Authority) e-invoicing service.
 *
 * Handles XML invoice signing, certificate hashing, and API submission.
 */
class ZATCAService
{
    private string $certPath;
    private string $privateKeyPath;
    private string $apiBaseUrl;

    public function __construct(string $certPath, string $privateKeyPath, string $apiBaseUrl)
    {
        $this->certPath = $certPath;
        $this->privateKeyPath = $privateKeyPath;
        $this->apiBaseUrl = rtrim($apiBaseUrl, '/');
    }

    /**
     * Compute the certificate hash for ZATCA invoice signing.
     *
     * ZATCA requires: base64( hex( SHA-256( DER-certificate ) ) )
     * NOT: base64( raw-SHA-256-bytes )
     *
     * @param string|null $certContent PEM certificate content. If null, reads from $this->certPath.
     * @return string The base64-encoded hex digest.
     */
    public function getCertificateHash(?string $certContent = null): string
    {
        if ($certContent === null) {
            $certContent = file_get_contents($this->certPath);
            if ($certContent === false) {
                throw new \RuntimeException("Cannot read certificate file: {$this->certPath}");
            }
        }

        // Extract DER bytes from PEM
        $derBytes = $this->pemToDer($certContent);

        // ZATCA spec: the hash is base64( hex-encoded-sha256 )
        $hexHash = hash('sha256', $derBytes);               // hex string of SHA-256
        $certificateHash = base64_encode($hexHash);          // base64 of the hex string

        return $certificateHash;
    }

    /**
     * Convert a PEM-encoded certificate to raw DER bytes.
     */
    private function pemToDer(string $pem): string
    {
        $lines = array_filter(
            explode("\n", $pem),
            fn(string $line) => strpos($line, '-----') !== 0 && trim($line) !== ''
        );

        $der = base64_decode(implode('', $lines), true);
        if ($der === false) {
            throw new \RuntimeException('Failed to decode PEM certificate to DER');
        }

        return $der;
    }

    /**
     * Build the signed properties <ds:SignedProperties> digest using the certificate hash.
     */
    public function getSignedPropertiesHash(string $signedPropertiesXml): string
    {
        return base64_encode(hash('sha256', $signedPropertiesXml, true));
    }

    /**
     * Build the invoice hash (SHA-256 of the canonicalized invoice XML).
     */
    public function getInvoiceHash(string $canonicalizedXml): string
    {
        return base64_encode(hash('sha256', $canonicalizedXml, true));
    }

    /**
     * Sign data with the private key.
     */
    public function signData(string $data): string
    {
        $privateKey = file_get_contents($this->privateKeyPath);
        if ($privateKey === false) {
            throw new \RuntimeException("Cannot read private key: {$this->privateKeyPath}");
        }

        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new \RuntimeException('Invalid private key');
        }

        $signature = '';
        if (!openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Signing failed: ' . openssl_error_string());
        }

        return base64_encode($signature);
    }

    /**
     * Submit a signed invoice to the ZATCA compliance/reporting API.
     *
     * @param string $signedInvoiceXml  The fully signed XML invoice.
     * @param string $invoiceHash       The invoice hash.
     * @param string $uuid              The invoice UUID.
     * @return array{status: int, body: string}
     */
    public function submitInvoice(string $signedInvoiceXml, string $invoiceHash, string $uuid): array
    {
        $payload = json_encode([
            'invoiceHash' => $invoiceHash,
            'uuid'        => $uuid,
            'invoice'     => base64_encode($signedInvoiceXml),
        ]);

        $certContent = file_get_contents($this->certPath);
        $privateKey  = file_get_contents($this->privateKeyPath);

        // ZATCA uses HTTP Basic auth with base64(cert):base64(secret)
        $authToken = base64_encode("{$certContent}:{$privateKey}");

        $ch = curl_init("{$this->apiBaseUrl}/invoices/reporting/single");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Accept-Version: V2',
                "Authorization: Basic {$authToken}",
            ],
        ]);

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => $response];
    }
}
