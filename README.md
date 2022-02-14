<p align="center"><img src="./src/icon.svg" width="100" height="100" alt="Scalapay for Craft Commerce icon"></p>

<h1 align="center">Scalapay for Craft Commerce</h1>

This plugin provides a [Scalapay](https://www.scalapay.com) integration for [Craft Commerce](https://craftcms.com/commerce).

## Requirements

This plugin requires Craft 3.1.5 and Craft Commerce 3.0.0 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for "Scalapay for Craft Commerce”. Then click on the “Install” button in its modal window.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require logisticdesign/craft-commerce-scalapay

# tell Craft to install the plugin
./craft plugin/install craft-commerce-scalapay
```

## Setup

To add a Scalapay payment gateway, go to Commerce → Settings → Gateways, create a new gateway, and set the gateway type to "Scalapay".

For Sandbox authentication credentials please refer to [Scalapay documentation](https://developers.scalapay.com/docs/testing).

> **Tip:** The Sandbox API Key and Live API Key gateway settings can be set to environment variables. See [Environmental Configuration](https://docs.craftcms.com/v3/config/environments.html) in the Craft docs to learn more about that.

