# Payflex for OpenCart 4

Payflex Buy Now Pay Later payment gateway for OpenCart 4.

## Requirements

- OpenCart 4.0.2.0 or later. Earlier 4.0.x releases call a different payment method API and the gateway will not appear at checkout.
- PHP 8.0 or later.
- Payflex merchant credentials. Contact Payflex for a client ID and secret.

## Install

1. Download `payflex.ocmod.zip` from the [latest release](https://github.com/PayFlexSA/payflex-opencart-4-extension/releases/latest).
2. In admin, go to Extensions > Installer and upload the zip.
3. Go to Extensions > Extensions, pick Payments, find Payflex and click Install.
4. Click Edit to configure.

Do not rename the zip. OpenCart takes the extension code from the filename, so anything other than `payflex.ocmod.zip` installs into the wrong directory and the module will not load.

Uninstalling removes the payment method but keeps your settings and the order history table, so reinstalling does not lose anything.

## Configure

Settings are split across four tabs.

- **General**: enable the gateway, choose sandbox or production, enter your client ID and secret.
- **Order Statuses**: map Payflex outcomes to OpenCart order statuses.
- **Product Widget**: optional instalment calculator on product pages.
- **CRON**: the secret token and the endpoint URL.

### CRON

If a customer abandons checkout on Payflex's side, the store is never told. A cron job reconciles those orders. Copy the URL from the CRON tab and run it every 5 minutes:

```
curl "https://yourstore.com/index.php?route=extension/payflex/payment/payflex.cron&cron_token=YOUR_TOKEN"
```

It only looks at orders between 30 minutes and 2 hours old that are still awaiting payment.

## Development

The repo root doubles as an OpenCart install root so the extension can be worked on in place. `.gitignore` is whitelist based, so a new file has to be un-ignored explicitly or it will not be committed.

Extension source is in `extension/payflex/`. To build a package locally:

```
./Payflex_output/create_zip.sh
```

## Releases

Set the version in both `VERSION` and `extension/payflex/install.json`, commit, then tag:

```
git tag v0.1.0
git push origin v0.1.0
```

GitHub Actions builds `payflex.ocmod.zip` and attaches it to the release. The build fails if the tag and the declared version disagree, or if the two version files disagree with each other.

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).

Copyright (C) 2026 Payflex.

## Support

https://www.payflex.co.za
