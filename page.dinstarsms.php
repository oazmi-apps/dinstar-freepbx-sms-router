<?php
if (!defined('FREEPBX_IS_AUTH')) {
	exit('[page.dinstarsms]: no direct script access allowed.');
}

// the `$module` is the existing instance of our module (not a new instance).
$module = \FreePBX::Dinstarsms();
$sipDomain = $module->getConfig('sipDomain');
$gatewayUrl = $module->getConfig('gatewayUrl');
$gatewayUsername = $module->getConfig('gatewayUsername');
$gatewayPassword = $module->getConfig('gatewayPassword');
?>

<div class="container-fluid">
	<h1>Dinstar SMS Routing</h1>

	<form class="fpbx-submit" action="" method="post" data-id="dinstarsms">
		<input type="hidden" name="action" value="save">

		<div class="section-title">
			<h3>SIP Domain</h3>
		</div>

		<div class="element-container">
			<div class="row">
				<div class="col-md-3">
					<label class="control-label" for="sipDomain">Gateway API URL</label>
					<i class="fa fa-question-circle fpbx-help-icon" data-for="sipDomain"></i>
				</div>
				<div class="col-md-9">
					<input type="text" class="form-control" id="sipDomain" name="sipDomain" value="<?php echo $sipDomain ?>">
					<span id="sipDomain-help" class="help-block fpbx-help-block">
						The Domain Name/IP that should be attached to inbound sms messages from external phone numbers.
					</span>
				</div>
			</div>
		</div>

		<div class="section-title">
			<h3>Gateway Credentials</h3>
		</div>

		<div class="element-container">
			<div class="row">
				<div class="col-md-3">
					<label class="control-label" for="gatewayUrl">Gateway API URL</label>
					<i class="fa fa-question-circle fpbx-help-icon" data-for="gatewayUrl"></i>
				</div>
				<div class="col-md-9">
					<input type="text" class="form-control" id="gatewayUrl" name="gatewayUrl" value="<?php echo $gatewayUrl ?>">
					<span id="gatewayUrl-help" class="help-block fpbx-help-block">
						The full http URL to the Dinstar <em>send_sms</em> API endpoint.
					</span>
				</div>
			</div>
		</div>

		<div class="element-container">
			<div class="row">
				<div class="col-md-3">
					<label class="control-label" for="gatewayUsername">Username</label>
				</div>
				<div class="col-md-9">
					<input type="text" class="form-control" id="gatewayUsername" name="gatewayUsername" value="<?php echo $gatewayUsername ?>">
				</div>
			</div>
		</div>

		<div class="element-container">
			<div class="row">
				<div class="col-md-3">
					<label class="control-label" for="gatewayPassword">Password</label>
				</div>
				<div class="col-md-9">
					<input type="text" class="form-control" id="gatewayPassword" name="gatewayPassword" value="<?php echo $gatewayPassword ?>">
				</div>
			</div>
		</div>

		<div class="fpbx-form-button-set">
			<button type="submit" class="btn btn-primary" name="submit">Submit</button>
		</div>
	</form>
</div>
