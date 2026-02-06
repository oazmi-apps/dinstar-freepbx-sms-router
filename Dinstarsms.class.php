<?php

namespace FreePBX\modules;

use FreePBX\BMO;
use FreePBX\PDO;
use FreePBX_Helpers;


class Dinstarsms extends FreePBX_Helpers implements BMO
{
	public $freepbx = null;
	protected $database = null;
	protected $context = 'dinstar-sms-handler';

	public function __construct($freepbx = null)
	{
		if ($freepbx == null) {
			throw new \Exception("[Dinstarsms]: not given a FreePBX object.");
		}
		$this->freepbx 	= $freepbx;
		$this->database = $freepbx->Database;
		$this->updateAllExtensions();
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
		// $handler_url = 'http://localhost/admin/modules/dinstarsms/api/outbound.php';
		$handler_url = 'http://10.0.1.36:3000/freepbx';
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
		$ext->add($context, '_.', '', new \ext_set('CURLOPT(httphdr)', 'Content-Type: application/json'));
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
