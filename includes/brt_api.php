<?php
/**
 * BRT REST API Client
 * Docs: BrtRestApi-IT.zip — RestShipmentProd/
 */
class BrtApi
{
    private const BASE_SHIPMENT = 'https://api.brt.it/rest/v1/shipments';
    private const BASE_TRACKING = 'https://api.brt.it/rest/tracking';

    private string $userID;
    private string $password;
    private int    $departureDepot;
    private int    $senderCustomerCode;
    private string $freightType;   // DAP or EXW
    private int    $timeout;

    public function __construct(string $userID, string $password, int $departureDepot, int $senderCustomerCode, string $freightType = 'DAP', int $timeout = 15)
    {
        $this->userID             = $userID;
        $this->password           = $password;
        $this->departureDepot     = $departureDepot;
        $this->senderCustomerCode = $senderCustomerCode;
        $this->freightType        = $freightType;
        $this->timeout            = $timeout;
    }

    /**
     * Creates a new shipment and optionally requests PDF labels.
     *
     * @param array  $data         Fields matching createData (required: consigneeCompanyName,
     *                             consigneeAddress, consigneeZIPCode, consigneeCity,
     *                             consigneeCountryAbbreviationISOAlpha2, numberOfParcels, weightKG)
     * @param bool   $withLabel    Request PDF labels in response
     * @return array               ['success'=>bool, 'data'=>createResponse|null, 'error'=>string|null]
     */
    public function createShipment(array $data, bool $withLabel = true): array
    {
        $body = [
            'account' => [
                'userID'   => $this->userID,
                'password' => $this->password,
            ],
            'createData' => array_merge([
                'departureDepot'                       => $this->departureDepot,
                'senderCustomerCode'                   => $this->senderCustomerCode,
                'deliveryFreightTypeCode'               => $this->freightType,
                'consigneeCountryAbbreviationISOAlpha2' => 'IT',
                'numberOfParcels'                      => 1,
                'weightKG'                             => 1.0,
                'isCODMandatory'                       => '0',
            ], $data),
        ];

        if ($withLabel) {
            $body['isLabelRequired'] = '1';
            $body['labelParameters'] = [
                'outputType'    => 'PDF',
                'labelFormat'   => 'A6',
                'isLogoRequired'=> '1',
            ];
        }

        return $this->request('POST', self::BASE_SHIPMENT . '/shipment', $body);
    }

    /**
     * Deletes (cancels) an existing shipment.
     */
    public function deleteShipment(int $numericSenderReference, ?string $alphanumericSenderReference = null): array
    {
        $deleteData = [
            'senderCustomerCode'      => $this->senderCustomerCode,
            'numericSenderReference'  => $numericSenderReference,
        ];
        if ($alphanumericSenderReference !== null) {
            $deleteData['alphanumericSenderReference'] = $alphanumericSenderReference;
        }
        $body = [
            'account'    => ['userID' => $this->userID, 'password' => $this->password],
            'deleteData' => $deleteData,
        ];
        return $this->request('PUT', self::BASE_SHIPMENT . '/delete', $body);
    }

    /**
     * Tracks a parcel by its BRT parcelID.
     *
     * @return array ['success'=>bool, 'data'=>trackingResponse|null, 'error'=>string|null]
     */
    public function trackParcel(string $parcelId): array
    {
        $url = self::BASE_TRACKING . '/parcelID/' . rawurlencode($parcelId);
        $headers = [
            'userID: '   . $this->userID,
            'password: ' . $this->password,
            'Accept: application/json',
        ];
        return $this->request('GET', $url, null, $headers);
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function request(string $method, string $url, ?array $body, array $extraHeaders = []): array
    {
        $ch = curl_init($url);
        $headers = array_merge(['Content-Type: application/json', 'Accept: application/json'], $extraHeaders);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false,  // BRT uses a self-signed cert in the chain
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        // GET is default

        $raw    = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['success' => false, 'data' => null, 'error' => 'cURL error: ' . $curlErr];
        }

        $decoded = json_decode($raw, true);

        if ($decoded === null && $raw !== '') {
            return ['success' => false, 'data' => null, 'error' => "HTTP $httpCode — risposta non JSON: " . mb_substr($raw, 0, 500)];
        }

        // Check BRT executionMessage for errors (may be array or object)
        $execMsg = $decoded['createResponse']['executionMessage']
            ?? $decoded['confirmResponse']['executionMessage']
            ?? $decoded['deleteResponse']['executionMessage']
            ?? $decoded['routingResponse']['executionMessage']
            ?? $decoded['executionMessage']
            ?? null;

        // BRT may return an array of executionMessage entries; normalise to first non-zero
        if (is_array($execMsg) && isset($execMsg[0])) {
            foreach ($execMsg as $em) {
                if (isset($em['code']) && (int)$em['code'] !== 0) {
                    $execMsg = $em;
                    break;
                }
            }
        }

        if ($execMsg && isset($execMsg['code']) && (int)$execMsg['code'] !== 0) {
            $msg = $execMsg['message'] ?? ($execMsg['description'] ?? 'Errore BRT');
            $detail = $execMsg['errorList'] ?? $execMsg['details'] ?? null;
            if ($detail) {
                $msg .= ' — ' . (is_array($detail) ? implode('; ', array_column($detail, 'message') ?: $detail) : $detail);
            }
            return ['success' => false, 'data' => $decoded, 'error' => "BRT [{$execMsg['code']}]: $msg"];
        }

        if ($httpCode >= 400) {
            $errBody = $raw ? mb_substr($raw, 0, 300) : '';
            return ['success' => false, 'data' => $decoded, 'error' => "HTTP $httpCode" . ($errBody ? " — $errBody" : '')];
        }

        return ['success' => true, 'data' => $decoded, 'error' => null];
    }
}

/**
 * Instantiate BrtApi from DB settings.
 * Returns null if credentials are not configured.
 */
function getBrtApi(): ?BrtApi
{
    try {
        $db     = getDB();
        $userId = getSetting('brt_user_id');
        $pass   = getSetting('brt_password');
        $depot  = (int)getSetting('brt_departure_depot', '0');
        $sender = (int)getSetting('brt_sender_customer_code', '0');
        $freight= getSetting('brt_freight_type', 'DAP');

        if (!$userId || !$pass || !$depot || !$sender) {
            return null;
        }
        return new BrtApi($userId, $pass, $depot, $sender, $freight);
    } catch (Throwable $e) {
        return null;
    }
}
