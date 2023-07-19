## Notification of WooCommerce orders in Eitaa application

**What is the Eitaa ?**

Eitaa is a messenger and it is designed to meet all the needs of Iranian users.
In Eitaa, you can easily chat with your friends, share your files, create groups and channels, and use advanced features of Eitaa to manage and personalize your software.

**What is this plugin developed for?**

This plugin sends WooCommerce orders to Eitaa group/channel.

**How to use Eitaa api?**

Visit the [Eitaayar.ir](https://eitaayar.ir/) website and read its documentation.

## Getting started

**What should I do after receiving the `API KEY` ?**

With the following URL format, You can communicate with the API and send or receive the required data.

`https://eitaayar.ir/api/API_KEY/METHOD_NAME`

## Setup

Setup for the new API integration :

In the plugin directory, go to `/class` folder and edit the `EitaaAPI.php` file.

```
    class EitaaAPI {

        protected static $ApiToken = 'YOUR_API_KEY';
        protected static $baseUrl = 'https://eitaayar.ir';
        protected static $chatID = 'YOUR_CHAT_ID_EITAA';

    ...
```

## Installation

**How to install this plugin ?**

You should go to WooCommerce plugins installation section and install the plugin file there.

_OR_

You should go to the plugins directory and put the plugin file there.

`YOUR_SITE/wp-content/plugins/`

## Release History

- 2023-07-05 - 1.0.0 - Stable release
