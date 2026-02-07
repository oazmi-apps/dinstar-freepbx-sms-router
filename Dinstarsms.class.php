<?php

namespace FreePBX\modules;

use FreePBX\BMO;
use FreePBX\PDO;
use FreePBX\FreePBX_Helpers;
use FreePBX\modules\Dinstarsms\DigestAuth;


class Dinstarsms extends FreePBX_Helpers implements BMO
{
	public $freepbx = null;
	protected $database = null;
	protected $modulename = 'dinstarsms';
	protected $context = 'dinstar-sms-handler';
	protected $sendsmsAjax = 'sendsms';
	protected $recievesmsAjax = 'recievesms';

	public function __construct($freepbx = null)
	{
		if ($freepbx == null) {
			throw new \Exception("[{$this->modulename}]: not given a FreePBX object.");
		}
		$this->freepbx 	= $freepbx;
		$this->database = $freepbx->Database;
		$this->updateAllExtensions();
	}

	/** unfortunately, because all freepbx api traffic goes through its slim-based authentication middleware,
	 * it is not possible to perform a request without a valid session; which is something our sim gateway cannot perform.
	 * 
	 * the **only** way I have found that successfully permits public access is via ajax (yuck, I know).
	 * here's the reference for this technique: "https://sangomakb.atlassian.net/wiki/spaces/FP/pages/10420542/FreePBX+Open+Source+-+BMO+Ajax+Calls#Manually-building-the-AJAX-URL"
	 * 
	 * this method simply _sets_ the permission for an incoming ajax command; it does not handle the request.
	 * only once freepbx verifies that the calling client has appropriate permission,
	 * does the request pass to the handler, which is performed by the `ajaxHandler` method.
	 * 
	 * the endpoint for ajax requests are: "http://${DOMAIN_NAME}/admin/ajax.php?module=${RAWNAME}&command=${COMMAND}"
	 * 
	 * @param $req - the command name (either `sendsms`, or `receivesms`).
	 * @param $setting - settings array to modify (which is where permit unauthenticated commands).
	 */
	public function ajaxRequest($req, &$setting)
	{
		switch ($req) {
			case $this->sendsmsAjax:
			case $this->recievesmsAjax:
				// disable authentication for this command
				$setting['authenticate'] = false;
				// (optional) allow remote access to this command (i.e. not just restricted to localhost).
				$setting['allowremote'] = true;
				return true;
			default:
				return false;
		}
	}

	/** this method handles the `sendsms`, or `receivesms` unauthenticated (public) ajax command,
	 * and then takes the appropriate action.
	 * 
	 * it gets called automatically by freepbx once it has verified that the user has the valid permissions.
	 */
	public function ajaxHandler()
	{
		switch ($_REQUEST['command']) {
			case $this->sendsmsAjax:
				return $this->handleSendSms();
			case $this->recievesmsAjax:
				return $this->handleReceiveSms();
			default:
				return false;
		}
	}

	protected function handleSendSms(): array
	{
		if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
			http_response_code(400);
			return ['status' => 'fail', 'message' => 'only POST method is supported.'];
		}
		$raw_input = file_get_contents('php://input');
		$json_data = json_decode($raw_input, true);
		if ($json_data === null && json_last_error() !== JSON_ERROR_NONE) {
			http_response_code(400);
			return ['status' => 'fail', 'message' => 'invalid json provided in POST request\'s body.'];
		}

		// gateway configuration.
		$gateway_url = 'https://192.168.86.245/api/send_sms';
		$gateway_username = 'admin';
		$gateway_password = 'admin123';
		$gateway_port = 0;

		// preparing the sms data.
		$sms_data = [
			'from' => $json_data['from'] ?? '',
			'to' => $json_data['to'],
			'port' => $gateway_port,
			'text' => urldecode($json_data['text']),
		];

		// sending the sms, using our custom digest authentication procedure.
		try {
			$result = DigestAuth::sendSms(
				$gateway_url,
				$gateway_username,
				$gateway_password,
				$sms_data
			);
			return $result;
		} catch (\Exception $err) {
			return [
				'status' => 'error',
				'message' => $err->getMessage(),
			];
		}
	}

	protected function handleReceiveSms(): array
	{
		return ['status' => 'not implemented yet.'];
	}

	/** needed for freepbx to discover that we perform a dialplan hook! */
	public function myDialplanHooks(): bool
	{
		return true;
	}

	/** this defines the custom message hook to capture outbound sms text messages,
	 * which will then be forwarded to our http endpoint.
	 */
	public function doDialplanHook(&$ext, $engine, $priority): void
	{
		$context = $this->context;
		$handler_url = "http://localhost/admin/ajax.php?module={$this->modulename}&command={$this->sendsmsAjax}";
		// no-op logging operation.
		$ext->add($context, '_.', '', new \ext_noop('SIP MESSAGE: from ${MESSAGE(from)} to ${MESSAGE(to)}.'));
		// first we extract variables from the SIP packet.
		$ext->add($context, '_.', '', new \ext_set('MSG_TO', '${MESSAGE(to)}'));
		$ext->add($context, '_.', '', new \ext_set('MSG_FROM', '${MESSAGE(from)}'));
		$ext->add($context, '_.', '', new \ext_set('RAW_MSG_BODY', '${MESSAGE(body)}'));
		// we also perform uri-encoding of the message body to prevent escaped characters from breaking the json.
		$ext->add($context, '_.', '', new \ext_set('MSG_BODY', '${URIENCODE(${RAW_MSG_BODY})}'));
		// next, we construct the json string that will be attached to the POST payload.
		$ext->add($context, '_.', '', new \ext_set('JSON_PAYLOAD', '{"to":"${MSG_TO}","from":"${MSG_FROM}","text":"${MSG_BODY}"}'));
		// now, we set the appropriate content-type for json.
		$ext->add($context, '_.', '', new \ext_set('CURLOPT(header)', 'Content-Type: application/json'));
		# lastly, we execute the POST request via curl (the presence of the second argument makes it into a post-request).
		$ext->add($context, '_.', '', new \ext_set('CURL_RESULT', '${CURL(' . $handler_url . ',${JSON_PAYLOAD})}'));
		// we also relay the message to the actual endpoint, should there be one (so that any custom user receives the message too via pjsip's standard behavior).
		// $ext->add($context, '_.', '', new \ext_messagesend('pjsip:${EXTERN}', '${CALLERID(num)}'));
		$ext->add($context, '_.', '', new \ext_hangup());
	}

	/** update database of users/extensions to use our custom messaging context. */
	public function updateAllExtensions()
	{
		$context = $this->context;
		$db = $this->database;
		$sql_pjsip = 'SELECT id FROM sip WHERE keyword = "sipdriver" AND data = "chan_pjsip"';
		$devices = $db->query($sql_pjsip)->fetchAll(\PDO::FETCH_COLUMN);
		// each "device" is actually just an extension number (user).
		foreach ($devices as $extension) {
			// update or insert the custom `message_context` setting.
			$sql_check = 'SELECT * FROM sip WHERE id = ? AND keyword = "message_context"';
			$check = $db->prepare($sql_check);
			$check->execute([$extension]);
			if ($check->rowCount() > 0) {
				// override existing `message_context` entry for this particular extension number.
				$sql_update = 'UPDATE sip SET data = ? WHERE id = ? AND keyword = "message_context"';
				$stmt = $db->prepare($sql_update);
				$stmt->execute([$context, $extension]);
			} else {
				// insert a new `message_context` entry for this particular extension number.
				$sql_insert = 'INSERT INTO sip (id, keyword, data, flags) VALUES (?, "message_context", ?, 0)';
				$stmt = $db->prepare($sql_insert);
				$stmt->execute([$extension, $context]);
			}
		}
	}

	public function install() {}
	public function uninstall() {}
	public function backup() {}
	public function restore($backup) {}
}
