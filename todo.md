# TODO

## bucket list

- [ ] use raylib gui instead of terminal for funzies?

## pre-version `0.4.0` todo list

- [ ] add multi-platform daemon/service support.

## pre-version `0.3.0` todo list

- [ ] use github actions to generate releases and release-binaries.

## pre-version `0.2.0` todo list

- [ ] get it to work with mms.
- [ ] in the configs page, add a dropdown for selecting a valid fqdn for the freepbx server
      (discovered via `ip a` (local ip, website domain name, or vpn ip)),
      in order to use it as the domain for external phone numbers (so that they appear as internal),
      or the ability to set a custom domain name.

## pre-version `0.1.4` todo list

- [ ] add the ability to set the `gateway port <---> extension number` mapping via the admin portal gui.

## pre-version `0.1.3` todo list

- [x] investigate why inbound messages are only forwarded to the most recently logged in device,
      for a given extension number, rather than being sent to all available devices with that extension.
      > FIXED: we need to specify each recipient's contact/device's uri, along with the extension number;
      > otherwise, asterisk will send the message only to the most recently logged in device with that extension,
      > leaving behind all other devices with the same extension that still have an active session.

## (2026-02-09) pre-version `0.1.2` todo list

- [x] add a configuration page.

## (2026-02-09) pre-version `0.1.1` todo list

- [x] manual port number and extension number mapping.
- [x] add installation instructions.

## (2026-02-08) pre-version `0.1.0` todo list

- [x] get it to work with sms.
