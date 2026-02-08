<?php

namespace FreePBX\modules\Dinstarsms;

use FreePBX\modules\Dinstarsms\DigestAuth;


class DispatchMessage
{
	/** sends an sms using digest authentication. */
	public static function sendSms(
		string $url,
		string $username,
		string $password,
		array $sms_data
	): array {
		// get the digest authentication header.
		$digest_auth_header = DigestAuth::getGatewayDigestAuth($url, $username, $password);

		// prepare the sms payload.
		$payload = [
			'text' => $sms_data['text'],
			'param' => [['number' => $sms_data['to']]],
			'port' => [$sms_data['port']],
			'encoding' => 'unicode',
		];

		// make the authenticated POST request.
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($payload),
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				"Authorization: {$digest_auth_header}",
			],
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_TIMEOUT => 10,
		]);

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error_number = curl_errno($ch);
		$error_message = curl_error($ch);

		if ($error_number) {
			return [
				'status' => 'error',
				'message' => "CURL Error: {$error_message}",
				'error_number' => $error_number,
			];
		}

		if ($http_code < 200 || $http_code >= 300) {
			return [
				'status' => 'error',
				'message' => "Unexpected HTTP status: {$http_code}",
				'response' => $response,
			];
		}

		return [
			'status' => 'success',
			'message' => $response,
			'http_code' => $http_code,
		];
	}

	/** sends an sms message to a freepbx extension.
	 * 
	 * @param $freepbx - the instance of the freepbx object.
	 * @param string $default_domain - the domain name to attach to the `from` phone number if it is lacking one.
	 * @param array $sms_data - an object containing the fields `from`, `to` (extension number), and `text`.
	 * @return array status of the task.
	 */
	public static function receiveSms(&$freepbx, $default_domain, $sms_data): array
	{
		// first we obtain the domain stored for the recipient's extension, and then apply it to the `from` field.
		// this is done because because otherwise asterisk will set the domain to its local-ip-address (`192.168...`),
		// instead of the domain-name/vpn-ip of our freepbx (as identified by the softphones, such as `10.0.15.36`);
		// which would result in the softphone thinking that the received message comes from a _new_ contact, due to its mismatched domain. 
		$from = $sms_data['from'];
		$to = $sms_data['to'];
		if (!self::getExtensionDomainFromContact($freepbx, $from)) {
			// we will assign a domain to the `from` phone number, based on the recipient's number (`to`),
			// only if the sender does not already have domain attached to it.
			// (regular phone numbers, like `+16315554444`, don't have an domain attached to them.)
			$to_domain = self::getExtensionDomainFromContact($freepbx, $to) ?? $default_domain;
			$from = "sip:{$from}@{$to_domain}";
		}
		$result = self::sendSmsToExtension($freepbx, $to, $from, $sms_data['text']);
		return [
			'status' => 'success',
			'message' => 'sms forwarded to extension number.',
			'details' => $result,
		];
	}

	/** sends an sms message to a freepbx extension, using the _asterisk manager interface_ (AMI).
	 * 
	 * @param $freepbx - the instance of the freepbx object.
	 * @param string $to_extension - the destination extension (user) number.
	 * @param string $from_number - the originating phone number.
	 * @param string $message_text - the raw sms text content to send.
	 * @return array response from the _asterisk manager interface_.
	 */
	protected static function sendSmsToExtension(&$freepbx, string $to_extension, string $from_number, string $message_text): array
	{
		// this is the _asterisk manager interface_ (aka the AMI)
		$astman = $freepbx->astman;
		if (!$astman) {
			throw new \Exception('[sendSmsToExtension]: unable to discover the Asterisk Manager Interface.');
		}
		// we assume that the user-extension uses the pjsip technology and scheme.
		// TODO: while all new freepbx extensions are based on pjsip, it is not strictly necessary that all of them will be using that.
		// other sip technologies that are permitted in asterisk are: `chan_pjsip`, and `sip`.
		$destination = "pjsip:{$to_extension}";
		$ami_params = [
			'To' => $destination,
			'From' => $from_number,
			'Body' => $message_text,
		];
		// dispatching the sms message via the AMI.
		$response = $astman->send_request('MessageSend', $ami_params);
		if (!$response || (isset($response['Response']) && $response['Response'] === 'Error')) {
			$error_msg = $response['Message'] ?? 'unknown error';
			throw new \Exception("[sendSmsToExtension]: Failed to dispatch message via the Asterisk Manager Interface, due to: {$error_msg}.");
		}
		return $response;
	}

	/** try to extract domain from the extension's current PJSIP contact
	 * 
	 * TODO: purge this function. it works in the asterisk cli (`pjsip show extension <number>`), but not here.
	 * another thing to note is that pjsip returns the domain/ip of the softphone, not the domain by which _this_ server is identified by it.
	 * hence, this technique will not work, even if I get the cli command to work. for now, using a `$default_domain` is the best approach.
	 * 
	 * @param $freepbx - the instance of the freepbx object.
	 * @param string $extension - the extension number whose domain is to be found.
	 * @return string|null the domain from contact, or or null in case of failure.
	 */
	protected static function getExtensionDomainFromContact(&$freepbx, string $extension): ?string
	{
		// attempt at obtaining the domain name from the `$extension` itself (if it were a complete uri).
		if (preg_match('/@([^:;]+)/', $extension, $matches)) {
			return $matches[1];
		}
		$astman = $freepbx->astman;
		if (!$astman) {
			return null;
		}
		// obtain the pjsip endpoint contact info from asterisk.
		$response = $astman->send_request('PJSIPShowEndpoint', ['Endpoint' => $extension]);
		if (isset($response['ContactStatus'])) {
			$contact = $response['ContactStatus'];
			// parse domain from contact uri, like: `sip:1000@10.0.15.36:5060`
			if (preg_match('/@([^:;]+)/', $contact, $matches)) {
				return $matches[1];
			}
		}
		return null;
	}
}
