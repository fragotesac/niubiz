<?php

namespace Niubiz;

use \stdClass;

class Niubiz
{
	const ENDPOINT_TESTING_SEC = 'https://apisandbox.vnforappstest.com/api.security/v1/security';
	const ENDPOINT_PRODUCTION_SEC = 'https://apiprod.vnforapps.com/api.security/v1/security';
	const ENDPOINT_TESTING_TOK = 'https://apisandbox.vnforappstest.com/api.ecommerce/v2/ecommerce/token/session/';
	const ENDPOINT_PRODUCTION_TOK = 'https://apiprod.vnforapps.com/api.ecommerce/v2/ecommerce/token/session/';
	const JS_PAGO_WEB_TESTING = 'https://static-content-qas.vnforapps.com/v2/js/checkout.js?qa=true';
	const JS_PAGO_WEB_PRODUCTION = 'https://static-content.vnforapps.com/v2/js/checkout.js';
	const ENDPOINT_TESTING_AUTH = 'https://apisandbox.vnforappstest.com/api.authorization/v3/authorization/ecommerce/';
	const ENDPOINT_PRODUCTION_AUTH = 'https://apiprod.vnforapps.com/api.authorization/v3/authorization/ecommerce/';

	public function __construct(string $mercandId, string $username, string $password, bool $isProd = false)
	{
		$this->mercandId = $mercandId;
		$this->username = $username;
		$this->password = $password;
		$this->isProd = $isProd;
	}

	public function apiCall(string $url, string $method, array $data = []) : stdClass
	{
		$curl = curl_init();

		switch ($method) {
			case 'POST':
				curl_setopt($curl, CURLOPT_POST, 1);
				if (!empty($data)) {
					curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
				}

				break;
			case 'PUT':
				curl_setopt($curl, CURLOPT_PUT, 1);

				break;
			default:
				if (!empty($data)) {
					$url = sprintf("%s?%s", $url, http_build_query($data));
				}
		}

		curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		if (!empty($this->token)) {
			curl_setopt($curl, CURLOPT_HTTPHEADER, [
				'Authorization: ' . $this->token,
				'Content-Type: application/json'
			]);
		} else {
			curl_setopt($curl, CURLOPT_USERPWD, $this->username . ':' . $this->password);
		}
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		$result = curl_exec($curl);
		$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		return (object)[
			'http_code' => $httpCode,
			'result' => $result
		];
	}

	public function securityApiUrl()
	{
		if ($this->isProd) {
			return self::ENDPOINT_PRODUCTION_SEC;
		}

		return self::ENDPOINT_TESTING_SEC;
	}

	public function tokenSessionApiUrl()
	{
		if ($this->isProd) {
			return self::ENDPOINT_PRODUCTION_TOK . $this->mercandId;
		}

		return self::ENDPOINT_TESTING_TOK . $this->mercandId;
	}

	public function authorizeTransactionApiUrl()
	{
		if ($this->isProd) {
			return self::ENDPOINT_PRODUCTION_AUTH . $this->mercandId;
		}

		return self::ENDPOINT_TESTING_AUTH . $this->mercandId;
	}

	public function accessToken() : ?string
	{
		$token = $this->apiCall(
			$this->securityApiUrl(),
			'GET'
		)->result;

		if (in_array($token, ['Unauthorized access', 'Bad Request'])) {
			return null;
		}

		$this->token = $token;
		return $token;
	}

	public function sessionToken(float $amount, string $clientIp, string $channel, array $mdd = []) : ?stdClass
	{
		$data = [
			'channel' => $channel,
			'amount' => number_format($amount, 2, '.', ''),
			'antifraud' => [
				'clientIp' => $clientIp,
				'merchantDefineData' => $mdd
			]
		];

		$this->accessToken();
		$sessionToken = $this->apiCall($this->tokenSessionApiUrl(), 'POST', $data)->result;
		return json_decode($sessionToken);
	}

	public function webPaymentJs() : string
	{
		if ($this->isProd) {
			return self::JS_PAGO_WEB_PRODUCTION;
		}

		return self::JS_PAGO_WEB_TESTING;
	}

	public function formHtml(
		string $purchaseNumber, string $merchantLogo, float $amount, string $clientIp,
		string $action = '', string $channel = 'web', int $expirationMinutes = 60,
		string $formButtonColor = '#000000', string $timeouturl = 'about:blank'
	)
	{
		$sessionToken = $this->sessionToken($amount, $clientIp, $channel);
		if (!empty($sessionToken->errorCode)) {
			return $sessionToken->errorMessage;
		}

		return '<form action="' . $action . '" method="POST">
			  <script type="text/javascript" src="' . $this->webPaymentJs() . '"
			    data-sessiontoken="' . $sessionToken->sessionKey . '"
			    data-channel="' . $channel . '"
			    data-merchantid="' . $this->mercandId . '"
			    data-purchasenumber="' . $purchaseNumber . '"
			    data-amount="' . number_format($amount, 2, '.', '') . '"
			    data-expirationminutes="' . $expirationMinutes . '"
			    data-timeouturl="' . $timeouturl . '"
			    data-merchantlogo="' . $merchantLogo . '"
			    data-formbuttoncolor="' . $formButtonColor . '"
			  ></script>
			</form>';
	}

	public function autorizacionTransaccion(string $purchaseNumber, string $token, float $amount, string $currency = 'PEN', string $channel = 'web', string $captureType = 'manual') : ?stdClass
	{
		$data = [
			'channel' => $channel,
			'captureType' => $captureType,
			'order' => [
				'tokenId' => $token,
				'purchaseNumber' => $purchaseNumber,
				'amount' => $amount,
				'currency' => $currency
			]
		];

		$this->accessToken();
		$transaccion = $this->apiCall($this->authorizeTransactionApiUrl(), 'POST', $data);
		$transaccion->result = json_decode($transaccion->result);

		return $transaccion;
	}
}
