# Installation
```bash
composer require simple-discord/gateway
```

# Basic usage
```php
<?php
require __DIR__.'/vendor/autoload.php';

$loop = React\EventLoop\Factory::create();

// Get actual discord gateway endpoint
$gateway_url = json_decode(file_get_contents("https://discord.com/api/gateway"))->url;

// Init new Gateway instance
$gw = new Discord\Gateway($loop, [
	// Your discord bot token
	'url'		=> $gateway_url,
	'token'		=> 'efwefweoofjfwejopweo.wefwefwefwewef'
]);

// Called when gateway received new message
// See: https://discord.com/developers/docs/topics/gateway#messages
$gw->on('message', function ($type, $message) {
	echo "discord gw message: $type\n";
	var_dump($message);
});

// Called when gateway complete ready
$gw->on('ready', function () {
	echo "discord gw ready\n";
});

// Called when gateway connected to websocket
$gw->on('connected', function () {
	echo "discord gw connected\n";
});

// Called when gateway disconnected from websocket
$gw->on('disconnected', function () {
	echo "discord gw disconnected\n";
});

// Start gateway connection
$gw->connect();

$loop->run();
```

# Optional logging
```php
// Create your favorite LoggerInterface implementation
$logger = new Monolog\Logger('discord-gateway');
$logger->pushHandler(new Monolog\Handler\StreamHandler('php://stderr', Monolog\Logger::DEBUG));

// Pass logger to Gateway
$gw = new Discord\Gateway($loop, [
	'token'		=> '....',
	'logger'	=> $logger
]);
```
