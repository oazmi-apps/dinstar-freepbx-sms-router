<?php

namespace FreePBX\modules;

use FreePBX\BMO;
use FreePBX\DialplanHooks;


class Dinstarsms implements BMO, DialplanHooks
{
	public $freepbx = null;
	protected $context = 'dinstar-sms-handler';

	public function __construct($freepbx = null)
	{
		if ($freepbx == null) {
			throw new \Exception("[Dinstarsms]: not given a FreePBX object.");
		}
		$this->freepbx 	= $freepbx;
	}

	public function doDialplanHook(&$ext, $engine, $priority): void
	{
		$context = $this->context;
		// $handler_url = 'http://localhost/admin/modules/dinstarsms/api/outbound.php';
		$handler_url = 'http://10.0.1.36:3000/freepbx';

		$ext->add($context, '_.', '', new \ext_noop('Routing SIP MESSAGE to internal http handler via POST'));
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
		$ext->add($context, '_.', '', new \ext_hangup());
	}

	// this method will register our sms asterisk-handler context on pjsip configuration for each user/extension.
	public function genConfig(): void
	{
		$context = $this->context;
		// find each pjsip-based user/extension (we don't process chan-based users).
		$devices = $this->freepbx->Core->getDevices();
		foreach ($devices as $device) {
			if ($device['tech'] !== 'pjsip') {
				continue;
			}
			// TODO: we should check if our module is enabled for this particular user/extension (via the gui logic)
			// for this pjsip user, we first obtain its extension number, attain its settings object,
			// modify it, and then re-assign the new settings back to the user, based on their extension number.
			$extension_number = $device['id'];
			$device_settings = $this->freepbx->Core->getDevice($extension_number);
			if ($device_settings['message_context']['value'] !== $context) {
				$device_settings['message_context']['value'] = $context;
				// the `true` flag is there to prevent a recursive loop.
				$this->freepbx->Core->addDevice($extension_number, 'pjsip', $device_settings, true);
			}
		}
	}

	public function install() {}
	public function uninstall() {}
	public function backup() {}
	public function restore($backup) {}
}
