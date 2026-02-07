<?php

// the implementation below is an AI port (claude sonnet 4.5) of my typescript file in `./digest_auth.ts`.
// why do we need to manually compute the digest authentication header? because curl fails to do it correctly with our dinstar sim gateway.
// basically, the _digest_ authentication requires _two_ requests:
// 1. querying the server what kind of security challenge it wants us to complete to authenticate.
// 2. computing the authentication token (aka the digest), and then sending the request to the sim gateway.
//
// the problem is, when a POST request is to be made (with some body), curl makes the first query request as POST with an empty body.
// this causes the gateway to close the tcp connection without responding; causing curl to just wait forever.
// the correct way for communicating involves sending a GET request for the initial challenge query,
// and then sending the POST request once we compute the digest token with our username and password.

namespace FreePBX\modules\Dinstarsms;

class DigestAuth
{
	/**
	 * Computes the digest authentication header string.
	 */
	public static function computeDigest(
		string $user,
		string $password,
		string $pathname,
		array $challenge,
		string $method
	): string {
		$realm = $challenge['realm'];
		$nonce = $challenge['nonce'];
		$qop = $challenge['qop'];
		$algorithm = $challenge['algorithm'];
		$nc = '00000001'; // nonce count
		$cnonce = substr(md5(rand()), 0, 10); // client's nonce

		// hash1 = md5(username:realm:password)
		$hash1 = hash($algorithm, "{$user}:{$realm}:{$password}");

		// hash2 = md5(method:digest_pathname)
		$hash2 = hash($algorithm, "{$method}:{$pathname}");

		// hash3 = response to challenge = md5(hash1:nonce:nc:cnonce:qop:hash2)
		$hash3 = hash($algorithm, "{$hash1}:{$nonce}:{$nc}:{$cnonce}:{$qop}:{$hash2}");

		return "Digest username=\"{$user}\", realm=\"{$realm}\", nonce=\"{$nonce}\", " .
			"uri=\"{$pathname}\", qop={$qop}, nc={$nc}, cnonce=\"{$cnonce}\", response=\"{$hash3}\"";
	}

	/**
	 * Parses comma-separated key="value" pairs from a string.
	 * Handles escaped quotes within values.
	 * 
	 * Example:
	 * - Input: 'name="Seto Kaiba", age="28", city="Domino City, Japan", profession="former \\"king of games\\""'
	 * - Output: ['name' => 'Seto Kaiba', 'age' => '28', 'city' => 'Domino City, Japan', 'profession' => 'former "king of games"']
	 */
	public static function parseCommaSeparatedKV(string $text): array
	{
		$kv_entries = [];
		// regex to find all key="value" pairs, including escaped quotes within values
		// (\w+) captures the key
		// ((?:\\.|[^"]*)) captures the value, allowing escaped characters or any non-quote character
		preg_match_all('/(\w+)="((?:\\\\.|[^"]*))"/', $text, $matches, PREG_SET_ORDER);
		foreach ($matches as $match) {
			// $match[1] is the key, $match[2] is the value inside quotes
			$key = $match[1];
			$value = $match[2];
			// unescape any internal quotes (i.e. `\"` becomes `"`)
			$unescaped_value = str_replace('\\"', '"', $value);
			$kv_entries[$key] = $unescaped_value;
		}
		return $kv_entries;
	}

	/**
	 * Parses the digest challenge text from WWW-Authenticate header.
	 * 
	 * Example: 'Digest realm="Web Server", qop="auth", nonce="...", algorithm="MD5"'
	 * Returns: ['realm' => 'Web Server', 'nonce' => '...', 'qop' => 'auth', 'algorithm' => 'md5']
	 */
	public static function parseDigestChallengeText(string $text): array
	{
		$cleaned = preg_replace('/^digest\s+/i', '', $text);
		$parsed = self::parseCommaSeparatedKV($cleaned);

		return [
			'realm' => $parsed['realm'] ?? '',
			'nonce' => $parsed['nonce'] ?? '',
			'qop' => $parsed['qop'] ?? '',
			'algorithm' => strtolower($parsed['algorithm'] ?? 'md5'),
		];
	}

	/**
	 * Gets the digest authentication header by making an initial request
	 * to obtain the challenge parameters.
	 */
	public static function getGatewayDigestAuth(
		string $url,
		string $username,
		string $password
	): string {
		// make initial request to get the challenge
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_TIMEOUT => 10,
		]);

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if ($http_code !== 401) {
			throw new \Exception("[getGatewayDigestAuth]: expected first response to be 401 (unauthorized), got {$http_code}.");
		}

		// extract www-authenticate header
		preg_match('/www-authenticate:\s*(.+)/i', $response, $matches);
		if (!isset($matches[1])) {
			throw new \Exception("[getGatewayDigestAuth]: expected a digest with challenge text, but got none.");
		}

		$challenge_text = trim($matches[1]);
		$challenge = self::parseDigestChallengeText($challenge_text);

		// extract pathname from URL
		$parsed_url = parse_url($url);
		$pathname = $parsed_url['path'] ?? '/';

		return self::computeDigest($username, $password, $pathname, $challenge, 'POST');
	}

	/**
	 * Sends an SMS using digest authentication.
	 */
	public static function sendSms(
		string $url,
		string $username,
		string $password,
		array $sms_data
	): array {
		// get the digest authentication header
		$digest_auth_header = self::getGatewayDigestAuth($url, $username, $password);

		// prepare the SMS payload
		$payload = [
			'text' => $sms_data['text'],
			'param' => [['number' => $sms_data['to']]],
			'port' => [$sms_data['port']],
			'encoding' => 'unicode',
		];

		// make the authenticated POST request
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
}
